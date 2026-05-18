/**
 * Front-end hydration for giving-day/leaderboard.
 */
import { createRoot, useMemo, useState } from '@wordpress/element';

import { useLeaderboard } from '../_shared/hooks/useLeaderboard';
import { useCampaignStatus } from '../_shared/hooks/useCampaignStatus';

import LeaderboardBody from './LeaderboardBody';

// Matches the REST endpoint's hard cap; see REST.php leaderboard route.
const EXPANDED_LIMIT = 100;

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
	const baseQuery = parseQuery( root );
	const initialData = parseJSON( root.dataset.initial, null );
	const collapsedLimit = baseQuery.limit;

	const [ expanded, setExpanded ] = useState( false );

	const query = useMemo(
		() => ( {
			...baseQuery,
			limit: expanded ? EXPANDED_LIMIT : collapsedLimit,
		} ),
		// baseQuery comes from the DOM once; expansion only toggles `limit`.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ expanded ]
	);

	const { status } = useCampaignStatus( campaignId );
	let intervalMs = 60000;
	if ( refreshMs > 0 ) {
		intervalMs = refreshMs;
	} else if ( status === 'live' ) {
		intervalMs = 15000;
	}

	const { data, loading } = useLeaderboard( campaignId, query, {
		intervalMs,
		initialData,
	} );

	const rows = data?.rows;
	const groups = data?.groups;
	const total = data?.total;
	const currency = data?.currency || 'USD';

	return (
		<LeaderboardBody
			rows={ rows }
			groups={ groups }
			total={ total }
			currency={ currency }
			showAmount={ showAmount }
			showAvatar={ showAvatar }
			loading={ loading }
			expanded={ expanded }
			onToggleExpand={ () => setExpanded( ( v ) => ! v ) }
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
