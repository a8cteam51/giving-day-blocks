/**
 * Front-end hydration for giving-day/leaderboard.
 */
import { createRoot } from '@wordpress/element';

import { useLeaderboard } from '../_shared/hooks/useLeaderboard';
import { useCampaignStatus } from '../_shared/hooks/useCampaignStatus';

import LeaderboardBody from './LeaderboardBody';

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

function parseQuery( root ) {
	const raw = root.dataset.query;
	const q = parseJSON( raw, {} );
	return {
		dimension: root.dataset.dimension || q.dimension || 'top_teams',
		limit: parseInt( root.dataset.limit || String( q.limit || 10 ), 10 ),
		filterTermId: parseInt(
			root.dataset.filterTermId || String( q.filterTermId || 0 ),
			10
		),
		groupByParentTermId: parseInt(
			root.dataset.groupByParent || String( q.groupByParentTermId || 0 ),
			10
		),
		anonymize: root.dataset.anonymize === '1' || !! q.anonymize,
	};
}

function LeaderboardView( { root } ) {
	const campaignId = parseInt( root.dataset.campaignId || '0', 10 );
	const showAmount = root.dataset.showAmount === '1';
	const showAvatar = root.dataset.showAvatar === '1';
	const refreshMs = parseInt( root.dataset.refreshMs || '15000', 10 );
	const query = parseQuery( root );
	const initialData = parseJSON( root.dataset.initial, null );

	const { status } = useCampaignStatus( campaignId );
	let intervalMs = 60000;
	if ( refreshMs > 0 ) {
		intervalMs = refreshMs;
	} else if ( status === 'live' ) {
		intervalMs = 15000;
	}

	const { data } = useLeaderboard( campaignId, query, {
		intervalMs,
		initialData,
	} );

	const rows = data?.rows;
	const groups = data?.groups;
	const currency = data?.currency || 'USD';

	return (
		<LeaderboardBody
			rows={ rows }
			groups={ groups }
			currency={ currency }
			showAmount={ showAmount }
			showAvatar={ showAvatar }
			loading={ false }
		/>
	);
}

function hydrate( root ) {
	if ( root.dataset.gdHydrated === '1' ) {
		return;
	}
	root.dataset.gdHydrated = '1';
	const inner = root.querySelector( '.giving-day-leaderboard__inner' );
	if ( ! inner ) {
		return;
	}
	inner.innerHTML = '';
	createRoot( inner ).render( <LeaderboardView root={ root } /> );
}

function boot() {
	document
		.querySelectorAll( '.giving-day-leaderboard[data-campaign-id]' )
		.forEach( hydrate );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
