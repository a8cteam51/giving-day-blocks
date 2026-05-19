/**
 * Front-end hydration for giving-day/goal-progress.
 *
 * Reads `data-*` attributes from render.php, polls /campaign/{id}/summary
 * (faster while live, slower otherwise), and replaces the SSR inner
 * markup with a live, animated React tree that:
 *   - eases the bar fill via the rAF count-up loop (gated by `animateBar`)
 *   - counts the raised amount + percent up to the new value
 *     (gated by `animateNumbers`)
 *   - reflects the changing percent in `aria-valuenow` / `aria-valuetext`.
 *
 * Both gates are AND-ed with `prefers-reduced-motion: reduce` inside
 * useCountUp, so a visitor opting out of motion always sees an instant
 * snap regardless of editor settings.
 */
import { useEffect, useRef, useState, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { useCampaignStatus } from '../_shared/hooks/useCampaignStatus';
import { useCountUp } from '../_shared/hooks/useCountUp';
import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { clampPercent, computePercent, goalProgressEndpoint } from '../_shared/utils/goalProgress';

function useTargetSummary( path, options = {} ) {
	const { intervalMs = 30000, initialData = null } = options;
	const [ data, setData ] = useState( initialData );
	const backoffRef = useRef( intervalMs );

	useEffect( () => {
		if ( ! path ) {
			setData( null );
			return undefined;
		}
		let cancelled = false;
		let timerId = null;

		const schedule = ( ms ) => {
			if ( ! cancelled ) {
				timerId = window.setTimeout( run, ms );
			}
		};

		const run = async () => {
			if ( cancelled || document.visibilityState === 'hidden' ) {
				if ( ! cancelled ) {
					schedule( intervalMs );
				}
				return;
			}
			try {
				const payload = await apiFetch( { path } );
				if ( ! cancelled ) {
					setData( payload );
					backoffRef.current = intervalMs;
					schedule( intervalMs );
				}
			} catch ( _err ) {
				if ( ! cancelled ) {
					backoffRef.current = Math.min(
						backoffRef.current * 2,
						5 * 60 * 1000
					);
					schedule( backoffRef.current );
				}
			}
		};

		run();

		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				if ( timerId ) {
					window.clearTimeout( timerId );
				}
				run();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			cancelled = true;
			if ( timerId ) {
				window.clearTimeout( timerId );
			}
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ path, intervalMs ] );

	return { data };
}

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

function GoalProgress( { root } ) {
	const targetType = root.dataset.targetType || 'campaign';
	const targetIdRaw = root.dataset.targetId || root.dataset.campaignId || '0';
	const targetId = parseInt( targetIdRaw, 10 );
	const endpoint = goalProgressEndpoint( targetType, targetId );

	const campaignId = targetType === 'campaign' ? targetId : 0;
	const orientation =
		root.dataset.orientation === 'vertical' ? 'vertical' : 'horizontal';
	const animateBar = root.dataset.animateBar === '1';
	const animateNumbers = root.dataset.animateNumbers === '1';
	const showPercent = root.dataset.showPercent === '1';
	const showRaised = root.dataset.showRaised === '1';
	const showGoal = root.dataset.showGoal === '1';
	const showDonors = root.dataset.showDonorCount === '1';
	const labels = parseJSON( root.dataset.labels, {
		of: 'of',
		donors: 'donors',
	} );
	const initialData = parseJSON( root.dataset.initial, null );

	const { status } = useCampaignStatus( campaignId );
	const { data } = useTargetSummary( endpoint, {
		intervalMs: status === 'live' ? 15000 : 60000,
		initialData,
	} );

	const goal = Number( data?.goal ?? initialData?.goal ?? 0 );
	const raised = Number( data?.raised ?? initialData?.raised ?? 0 );
	const donors = Number( data?.donor_count ?? initialData?.donor_count ?? 0 );
	const currency = data?.currency || initialData?.currency || 'USD';

	// Prefer the server's clamped `percent` field; fall back to a recompute.
	const percentSource =
		data?.percent ?? initialData?.percent ?? computePercent( raised, goal );
	const percent = clampPercent( percentSource );

	// `initialValue: 0` runs a one-shot mount animation (bar fills from 0%,
	// numbers count up from 0) so visitors get the visual feedback even when
	// no donations have polled in yet. Each rAF loop is gated independently:
	// the bar uses its own loop driven by `animateBar`, the displayed numbers
	// use a parallel loop driven by `animateNumbers`. Both are short-circuited
	// to "snap to target" by useCountUp under `prefers-reduced-motion`.
	const animatedRaised = useCountUp( raised, {
		enabled: animateNumbers,
		durationMs: 700,
		initialValue: 0,
	} );
	const animatedPercentForNumbers = useCountUp( percent, {
		enabled: animateNumbers,
		durationMs: 700,
		initialValue: 0,
	} );
	const animatedPercentForBar = useCountUp( percent, {
		enabled: animateBar,
		durationMs: 700,
		initialValue: 0,
	} );

	const hasGoal = goal > 0;
	// Bar fill rides its own rAF loop so toggling "Animate numbers" off
	// (with bar animation on) still gives a smooth fill, and vice versa.
	const fillStyle =
		orientation === 'vertical'
			? { height: `${ animatedPercentForBar }%` }
			: { width: `${ animatedPercentForBar }%` };
	// Only build aria-valuetext when there's an actual goal to compare
	// against — otherwise the track drops its progressbar role entirely
	// (see below) and the raised live region alone carries the announcement.
	const ariaValueText = hasGoal
		? `${ formatCurrency( animatedRaised, currency ) } ${
				labels.of
		  } ${ formatCurrency( goal, currency ) }, ${ Math.round( percent ) }%`
		: undefined;

	// React is mounted directly into the SSR `.giving-day-goal-progress__inner`
	// element (see `hydrate` below), so this component must NOT re-emit that
	// wrapper — doing so would nest a duplicate `__inner` and break the
	// vertical-grid layout that targets the single inner.
	return (
		<>
			{ showRaised && (
				<p
					className="giving-day-goal-progress__raised"
					data-role="raised"
					aria-live="polite"
				>
					{ formatCurrency( animatedRaised, currency ) }
				</p>
			) }

			{ hasGoal ? (
				<div
					className="giving-day-goal-progress__track"
					role="progressbar"
					aria-valuenow={ Math.round( percent ) }
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuetext={ ariaValueText }
				>
					<span
						className="giving-day-goal-progress__fill"
						style={ fillStyle }
						data-role="fill"
						aria-hidden="true"
					/>
				</div>
			) : (
				<div
					className="giving-day-goal-progress__track"
					data-role="track-no-goal"
					aria-hidden="true"
				>
					<span
						className="giving-day-goal-progress__fill"
						style={ fillStyle }
						data-role="fill"
					/>
				</div>
			) }

			<div className="giving-day-goal-progress__meta">
				{ hasGoal && showGoal && (
					<span
						className="giving-day-goal-progress__goal"
						data-role="goal"
					>
						{ labels.of } { formatCurrency( goal, currency ) }
					</span>
				) }
				{ hasGoal && showPercent && (
					<span
						className="giving-day-goal-progress__percent"
						data-role="percent"
					>
						{ Math.round( animatedPercentForNumbers ) }%
					</span>
				) }
				{ showDonors && donors > 0 && (
					<span
						className="giving-day-goal-progress__donors"
						data-role="donors"
					>
						{ formatNumber( donors ) } { labels.donors }
					</span>
				) }
			</div>
		</>
	);
}

function hydrate( root ) {
	if ( root.dataset.gdHydrated === '1' ) {
		return;
	}
	root.dataset.gdHydrated = '1';
	const inner = root.querySelector( '.giving-day-goal-progress__inner' );
	if ( ! inner ) {
		return;
	}
	// Replace the SSR inner so React fully owns the live region from now on.
	inner.innerHTML = '';
	createRoot( inner ).render( <GoalProgress root={ root } /> );
}

function boot() {
	document
		.querySelectorAll(
			'.giving-day-goal-progress[data-target-id], .giving-day-goal-progress[data-campaign-id]'
		)
		.forEach( hydrate );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
