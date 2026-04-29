/**
 * Front-end hydration for giving-day/match-my-gift.
 *
 * Reads the SSR `data-*` attributes (incl. `data-initial`, the resolved
 * match payload) so the React mount matches what was already on screen.
 * Then polls /campaign/{id}/active-matches (auto mode) or
 * /match/{id}/progress (specific) and re-renders the headline / progress
 * bar / countdown.
 *
 * The block respects the `prefers-reduced-motion` media query through
 * `useCountUp`, mirroring how goal-progress animates its bar fill.
 */
import { __, sprintf } from '@wordpress/i18n';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';

import { useCountUp } from '../_shared/hooks/useCountUp';
import { useMatchProgress } from '../_shared/hooks/useMatchProgress';
import { frontEndCopy } from '../_shared/utils/matchCopy';
import {
	MATCH_STATE_ACTIVE,
	MATCH_STATE_SCHEDULED,
	matchPercent,
	shouldRender,
} from '../_shared/utils/matchProgress';
import { splitDuration } from '../_shared/utils/splitDuration';

function parseJSON( raw, fallback ) {
	if ( ! raw ) {
		return fallback;
	}
	try {
		const parsed = JSON.parse( raw );
		return parsed ?? fallback;
	} catch ( e ) {
		return fallback;
	}
}

function MatchView( { root } ) {
	const campaignId = Number( root.dataset.campaignId ) || 0;
	const initialMatchId = Number( root.dataset.matchId ) || 0;
	const selection =
		root.dataset.matchSelection === 'specific' ? 'specific' : 'auto';
	const showCountdown = root.dataset.showCountdown === '1';
	const hideWhenComplete = root.dataset.hideWhenComplete || 'hide';
	const showOutsideWindow = root.dataset.showOutsideWindow || 'hide';
	const currency = root.dataset.currency || 'USD';
	const initialData = parseJSON( root.dataset.initial, null );

	const { data } = useMatchProgress( {
		selection,
		matchId: selection === 'specific' ? initialMatchId : undefined,
		campaignId,
		intervalMs: 15000,
		initialData,
	} );

	const match = data || initialData;
	const visible =
		!! match && shouldRender( match, { hideWhenComplete, showOutsideWindow } );

	useEffect( () => {
		if ( visible ) {
			root.removeAttribute( 'hidden' );
		} else {
			root.setAttribute( 'hidden', 'hidden' );
		}
		if ( match?.state ) {
			// Keep the wrapper class in sync so SCSS can react to state
			// transitions (e.g. dim a completed match) without us having
			// to schedule a separate effect per state.
			const next = `giving-day-match--state-${ match.state }`;
			Array.from( root.classList )
				.filter( ( c ) => c.startsWith( 'giving-day-match--state-' ) )
				.forEach( ( c ) => root.classList.remove( c ) );
			root.classList.add( next );
		}
	}, [ root, visible, match?.state ] );

	const targetPct = matchPercent( match );
	const animatedPct = useCountUp( targetPct, {
		enabled: true,
		durationMs: 700,
		initialValue: 0,
	} );

	if ( ! match ) {
		return null;
	}

	const copy = frontEndCopy( match, currency );

	return (
		<>
			<header className="giving-day-match__header">
				<p className="giving-day-match__type" data-role="type">
					{ copy.typeLabel }
				</p>
				{ match.title && (
					<h3 className="giving-day-match__title" data-role="title">
						{ match.title }
					</h3>
				) }
				{ ( match.sponsor_name || match.sponsor_logo_url ) && (
					<p className="giving-day-match__sponsor" data-role="sponsor">
						{ match.sponsor_logo_url && (
							<img
								className="giving-day-match__logo"
								src={ match.sponsor_logo_url }
								alt=""
							/>
						) }
						{ match.sponsor_name && (
							<span>
								{ __(
									'Sponsored by',
									'giving-day-blocks'
								) }{ ' ' }
								<strong>{ match.sponsor_name }</strong>
							</span>
						) }
					</p>
				) }
			</header>

			<p
				className="giving-day-match__headline"
				data-role="headline"
				aria-live="polite"
			>
				{ copy.headline }
			</p>

			<div
				className="giving-day-match__track"
				role="progressbar"
				aria-valuenow={ Math.round( animatedPct ) }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuetext={ copy.meta }
				data-role="track"
			>
				<span
					className="giving-day-match__fill"
					style={ { width: `${ Math.round( animatedPct ) }%` } }
					data-role="fill"
					aria-hidden="true"
				/>
			</div>

			<p className="giving-day-match__meta" data-role="meta">
				{ copy.meta }
			</p>

			{ showCountdown && (
				<MatchCountdown match={ match } />
			) }
		</>
	);
}

function MatchCountdown( { match } ) {
	const isActive = match.state === MATCH_STATE_ACTIVE;
	const isScheduled = match.state === MATCH_STATE_SCHEDULED;
	const target = isActive
		? match.window_end
		: isScheduled
		? match.window_start
		: null;
	const serverTime = match.server_time;

	const offset = useMemo( () => {
		if ( ! serverTime ) {
			return 0;
		}
		const serverMs = Date.parse( serverTime );
		if ( ! Number.isFinite( serverMs ) ) {
			return 0;
		}
		return serverMs - Date.now();
	}, [ serverTime ] );

	const [ now, setNow ] = useState( () => Date.now() + offset );
	useEffect( () => {
		if ( ! target ) {
			return undefined;
		}
		let raf;
		const tick = () => {
			setNow( Date.now() + offset );
			raf = window.requestAnimationFrame( tick );
		};
		raf = window.requestAnimationFrame( tick );
		return () => {
			if ( raf ) {
				window.cancelAnimationFrame( raf );
			}
		};
	}, [ target, offset ] );

	if ( ! target || ( ! isActive && ! isScheduled ) ) {
		return null;
	}

	const targetMs = Date.parse( target );
	if ( ! Number.isFinite( targetMs ) ) {
		return null;
	}
	const remaining = Math.max( 0, targetMs - now );
	const { days, hours, minutes, seconds } = splitDuration( remaining );
	const labelKey = isActive ? 'ends-in' : 'starts-in';
	const text =
		labelKey === 'ends-in'
			? sprintf(
					/* translators: 1: days, 2: hours, 3: minutes, 4: seconds. */
					__(
						'Ends in %1$sd %2$sh %3$sm %4$ss',
						'giving-day-blocks'
					),
					days,
					String( hours ).padStart( 2, '0' ),
					String( minutes ).padStart( 2, '0' ),
					String( seconds ).padStart( 2, '0' )
			  )
			: sprintf(
					/* translators: 1: days, 2: hours, 3: minutes, 4: seconds. */
					__(
						'Starts in %1$sd %2$sh %3$sm %4$ss',
						'giving-day-blocks'
					),
					days,
					String( hours ).padStart( 2, '0' ),
					String( minutes ).padStart( 2, '0' ),
					String( seconds ).padStart( 2, '0' )
			  );

	return (
		<p
			className={
				isActive
					? 'giving-day-match__countdown'
					: 'giving-day-match__countdown giving-day-match__countdown--scheduled'
			}
			data-role="countdown"
			aria-live="polite"
		>
			{ text }
		</p>
	);
}

function hydrate( root ) {
	if ( root.dataset.gdHydrated === '1' ) {
		return;
	}
	root.dataset.gdHydrated = '1';
	const inner = root.querySelector( '.giving-day-match__inner' );
	if ( ! inner ) {
		return;
	}
	inner.innerHTML = '';
	createRoot( inner ).render( <MatchView root={ root } /> );
}

function boot() {
	document
		.querySelectorAll( '.giving-day-match[data-match-id]' )
		.forEach( hydrate );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
