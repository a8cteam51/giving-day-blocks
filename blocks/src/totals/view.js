/**
 * Front-end hydration for giving-day/totals.
 *
 * Re-fetches the summary on an interval when `mode=auto` so the number keeps
 * updating during the live event. In `mode=final-only`, the block is
 * hidden server-side until the event ends; view.js just keeps it in sync
 * with state transitions.
 */
import { createRoot } from '@wordpress/element';

import { useCampaignSummary } from '../_shared/hooks/useCampaignSummary';
import { useCampaignStatus } from '../_shared/hooks/useCampaignStatus';
import { formatCurrency, formatNumber } from '../_shared/utils/formatCurrency';

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

function Totals( { root } ) {
	const campaignId = Number( root.dataset.campaignId );
	const mode = root.dataset.mode || 'auto';
	const showGoal = root.dataset.showGoal === '1';
	const showDonors = root.dataset.showDonorCount === '1';
	const headline = root.dataset.headline || '';
	const subhead = root.dataset.subhead || '';
	const labels = parseJSON( root.dataset.labels, {} );
	const initialData = parseJSON( root.dataset.initial, null );

	const { status } = useCampaignStatus( campaignId );
	const { data } = useCampaignSummary( campaignId, {
		intervalMs: status === 'live' ? 15000 : 60000,
		initialData,
	} );

	if ( mode === 'final-only' && status !== 'ended' ) {
		root.setAttribute( 'hidden', 'hidden' );
		return null;
	}
	root.removeAttribute( 'hidden' );

	if ( ! data ) {
		return null;
	}
	const {
		raised = 0,
		goal = 0,
		currency = 'USD',
		donor_count: donors = 0,
	} = data;

	return (
		<>
			{ headline && (
				<p className="giving-day-totals__headline">{ headline }</p>
			) }
			<p className="giving-day-totals__amount" aria-live="polite">
				{ formatCurrency( raised, currency ) }
			</p>
			{ showGoal && goal > 0 && (
				<p className="giving-day-totals__goal">
					{ labels.of } { formatCurrency( goal, currency ) }
				</p>
			) }
			{ showDonors && donors > 0 && (
				<p className="giving-day-totals__donors">
					{ formatNumber( donors ) } { labels.donors }
				</p>
			) }
			{ subhead && (
				<p className="giving-day-totals__subhead">{ subhead }</p>
			) }
		</>
	);
}

function hydrate( root ) {
	if ( root.dataset.gdHydrated === '1' ) {
		return;
	}
	root.dataset.gdHydrated = '1';
	const body = root.querySelector( '.giving-day-totals__body' );
	if ( ! body ) {
		return;
	}
	body.innerHTML = '';
	createRoot( body ).render( <Totals root={ root } /> );
}

function boot() {
	document
		.querySelectorAll( '.giving-day-totals[data-campaign-id]' )
		.forEach( hydrate );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
