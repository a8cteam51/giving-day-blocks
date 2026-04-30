import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { leaderboardRestPath } from '../utils/leaderboardPath';
import { withPreviewParam } from './usePreviewOverride';

/**
 * Polls campaign leaderboard REST, pausing when the tab is hidden.
 *
 * @param {number}      campaignId                 Campaign post ID.
 * @param {Object}      query                      Stable query object (dimension, limit, filterTermId, groupByParentTermId, anonymize).
 * @param {Object}      [options]                  Hook options.
 * @param {number}      [options.intervalMs=15000] Poll interval in ms.
 * @param {Object|null} [options.initialData]      Seed payload from SSR.
 * @return {{ data: object|null, error: Error|null, loading: boolean }} Latest payload, fetch error, and loading flag.
 */
export function useLeaderboard( campaignId, query, options = {} ) {
	const { intervalMs = 15000, initialData = null } = options;
	const [ data, setData ] = useState( initialData );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( ! initialData );
	const backoffRef = useRef( intervalMs );
	const queryKey = JSON.stringify( query || {} );

	useEffect( () => {
		if ( ! campaignId ) {
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
			if ( cancelled ) {
				return;
			}
			if ( document.visibilityState === 'hidden' ) {
				schedule( intervalMs );
				return;
			}
			try {
				const path = withPreviewParam(
					leaderboardRestPath( campaignId, query )
				);
				const payload = await apiFetch( { path } );
				if ( cancelled ) {
					return;
				}
				setData( payload );
				setError( null );
				setLoading( false );
				backoffRef.current = intervalMs;
				schedule( intervalMs );
			} catch ( err ) {
				if ( cancelled ) {
					return;
				}
				setError(
					err instanceof Error ? err : new Error( String( err ) )
				);
				setLoading( false );
				backoffRef.current = Math.min(
					backoffRef.current * 2,
					5 * 60 * 1000
				);
				schedule( backoffRef.current );
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
		// queryKey mirrors `query`; listing `query` would duplicate fetches when object identity changes.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional stable key via queryKey
	}, [ campaignId, intervalMs, queryKey ] );

	return { data, error, loading };
}
