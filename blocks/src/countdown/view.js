/**
 * Front-end hydration for giving-day/countdown.
 *
 * Reads data attributes emitted by render.php, subscribes to the Campaign
 * status + summary endpoints, and replaces the server-rendered body with
 * a live, drift-corrected countdown / totals view.
 */
import { createRoot, useRef } from '@wordpress/element';

import { useCampaignStatus, splitDuration } from '../_shared/hooks/useCampaignStatus';
import { useCampaignSummary } from '../_shared/hooks/useCampaignSummary';
import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';
import { readPreviewOverride } from '../_shared/hooks/usePreviewOverride';
import {
	shouldHideDays,
	PREVIEW_REMAINING_MS,
} from '../_shared/utils/eventDuration';

function pad( n ) {
	return String( n ).padStart( 2, '0' );
}

function Countdown( { remainingMs, labels, hideDays = false } ) {
	const { days, hours, minutes, seconds } = splitDuration( remainingMs );
	return (
		<div className="giving-day-countdown__countdown" aria-live="polite">
			{ ! hideDays && (
				<div className="giving-day-countdown__unit">
					<span className="giving-day-countdown__value">{ days }</span>
					<span className="giving-day-countdown__label">{ labels.days }</span>
				</div>
			) }
			<div className="giving-day-countdown__unit">
				<span className="giving-day-countdown__value">{ pad( hours ) }</span>
				<span className="giving-day-countdown__label">{ labels.hours }</span>
			</div>
			<div className="giving-day-countdown__unit">
				<span className="giving-day-countdown__value">{ pad( minutes ) }</span>
				<span className="giving-day-countdown__label">{ labels.minutes }</span>
			</div>
			<div className="giving-day-countdown__unit">
				<span className="giving-day-countdown__value">{ pad( seconds ) }</span>
				<span className="giving-day-countdown__label">{ labels.seconds }</span>
			</div>
		</div>
	);
}

function App( { root } ) {
	const campaignId = Number( root.dataset.campaignId );
	const hidePostEvent = root.dataset.hidePostEvent === '1';
	const preHeadline = root.dataset.preHeadline || '';
	const liveHeadline = root.dataset.liveHeadline || '';
	const endedHeadline = root.dataset.endedHeadline || '';
	const showDonorCount = root.dataset.showDonorCount === '1';
	const labels = JSON.parse( root.dataset.labels || '{}' );

	const { status, msRemaining, now } = useCampaignStatus( campaignId );
	const { data: summary } = useCampaignSummary( campaignId, {
		intervalMs: status === 'live' ? 15000 : 60000,
	} );

	// Logged-in preview requests (?givingday=live) start the countdown at
	// 23:59:59 on first paint and tick down from there, regardless of the
	// campaign's real end. Pre preview keeps the real remaining time so
	// editors see an accurate "days until event" count. Stored in a ref so
	// the target is stable across re-renders; useCampaignStatus' rAF loop
	// drives the tick via `now`.
	const isPreview = !! readPreviewOverride();
	const livePreviewTargetRef = useRef( null );
	if (
		isPreview &&
		status === 'live' &&
		livePreviewTargetRef.current === null
	) {
		livePreviewTargetRef.current = Date.now() + PREVIEW_REMAINING_MS;
	}

	if ( ! campaignId || status === 'loading' ) {
		return null;
	}
	if ( status === 'ended' && hidePostEvent ) {
		root.setAttribute( 'hidden', 'hidden' );
		return null;
	}
	root.removeAttribute( 'hidden' );
	root.setAttribute( 'data-status', status );

	const goal = summary?.goal ?? 0;
	const raised = summary?.raised ?? 0;
	const donors = summary?.donor_count ?? 0;
	const currency = summary?.currency || 'USD';

	const hideDays = shouldHideDays( root.dataset.start, root.dataset.end );
	const liveRemaining =
		isPreview && livePreviewTargetRef.current !== null
			? Math.max( 0, livePreviewTargetRef.current - now )
			: msRemaining;

	if ( status === 'scheduled' ) {
		return (
			<>
				<p className="giving-day-countdown__headline">{ preHeadline }</p>
				<Countdown remainingMs={ msRemaining } labels={ labels } />
			</>
		);
	}

	if ( status === 'live' ) {
		return (
			<>
				<p className="giving-day-countdown__headline">{ liveHeadline }</p>
				<Countdown
					remainingMs={ liveRemaining }
					labels={ labels }
					hideDays={ hideDays }
				/>
			</>
		);
	}

	return (
		<>
			<p className="giving-day-countdown__headline">{ endedHeadline }</p>
			<p className="giving-day-countdown__final">
				{ formatCurrency( raised, currency ) }
			</p>
			<p className="giving-day-countdown__final-meta">
				{ labels.raisedTowardGoalOf } { formatCurrency( goal, currency ) }
			</p>
			{ showDonorCount && donors > 0 && (
				<p className="giving-day-countdown__donors">
					{ formatNumber( donors ) } { labels.donors }
				</p>
			) }
		</>
	);
}

function hydrate( root ) {
	if ( root.dataset.gdHydrated === '1' ) {
		return;
	}
	root.dataset.gdHydrated = '1';
	const body = root.querySelector( '.giving-day-countdown__body' );
	if ( ! body ) {
		return;
	}
	body.innerHTML = '';
	createRoot( body ).render( <App root={ root } /> );
}

function boot() {
	const nodes = document.querySelectorAll( '.giving-day-countdown[data-campaign-id]' );
	nodes.forEach( hydrate );

	// If a logged-in user loaded the page with ?givingday=, re-render on
	// history navigation so tests in the admin bar work.
	if ( readPreviewOverride() ) {
		window.addEventListener( 'popstate', () => {
			document
				.querySelectorAll( '.giving-day-countdown[data-campaign-id]' )
				.forEach( ( el ) => delete el.dataset.gdHydrated );
			document
				.querySelectorAll( '.giving-day-countdown[data-campaign-id]' )
				.forEach( hydrate );
		} );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
