/**
 * Front-end hydration for giving-day/goal-progress.
 *
 * Reads `data-*` attributes from render.php, polls /campaign/{id}/summary
 * (faster while live, slower otherwise), and replaces the SSR inner
 * markup with a live, animated React tree that:
 *   - eases the bar fill (CSS transition)
 *   - counts the raised amount up to the new value (rAF, useCountUp)
 *   - reflects the changing percent in `aria-valuenow` / `aria-valuetext`.
 *
 * Animation is disabled automatically when the user has
 * `prefers-reduced-motion: reduce`, when the `animate` attribute is off,
 * or when the editor / theme has set `--giving-day-disable-anim: 1`.
 */
import { createRoot } from '@wordpress/element';

import { useCampaignStatus } from '../_shared/hooks/useCampaignStatus';
import { useCampaignSummary } from '../_shared/hooks/useCampaignSummary';
import { useCountUp } from '../_shared/hooks/useCountUp';
import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { clampPercent, computePercent } from '../_shared/utils/goalProgress';

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
	const campaignId = Number( root.dataset.campaignId );
	const orientation =
		root.dataset.orientation === 'vertical' ? 'vertical' : 'horizontal';
	const animate = root.dataset.animate === '1';
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
	const { data } = useCampaignSummary( campaignId, {
		// Stay tight during the event, calmer when scheduled / ended.
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

	const animatedRaised = useCountUp( raised, {
		enabled: animate,
		durationMs: 700,
	} );
	const animatedPercent = useCountUp( percent, {
		enabled: animate,
		durationMs: 700,
	} );

	const hasGoal = goal > 0;
	const fillStyle =
		orientation === 'vertical'
			? { height: `${ percent }%` }
			: { width: `${ percent }%` };
	const ariaValueText = hasGoal
		? `${ formatCurrency( animatedRaised, currency ) } ${
				labels.of
		  } ${ formatCurrency( goal, currency ) }, ${ Math.round( percent ) }%`
		: `${ formatCurrency( animatedRaised, currency ) }`;

	return (
		<div className="giving-day-goal-progress__inner">
			{ showRaised && (
				<p
					className="giving-day-goal-progress__raised"
					data-role="raised"
					aria-live="polite"
				>
					{ formatCurrency( animatedRaised, currency ) }
				</p>
			) }

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
						{ Math.round( animatedPercent ) }%
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
		</div>
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
		.querySelectorAll( '.giving-day-goal-progress[data-campaign-id]' )
		.forEach( hydrate );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
