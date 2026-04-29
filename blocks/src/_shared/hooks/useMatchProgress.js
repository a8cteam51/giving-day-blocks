import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { withPreviewParam } from './usePreviewOverride';
import {
	activeMatchesPath,
	matchProgressPath,
} from '../utils/matchProgress';

/**
 * Polls a match's progress on an interval. Pauses on hidden tabs and
 * backs off on errors. Mirrors the shape of useCampaignSummary so blocks
 * can pick whichever fits.
 *
 * Two modes:
 *   - 'specific':  poll /match/{id}/progress directly.
 *   - 'auto':      poll /campaign/{id}/active-matches and surface the
 *                  first row (sorted server-side, end-soonest first).
 *
 * @param {Object} options
 * @param {"auto"|"specific"} options.selection
 * @param {number}  [options.matchId]    Required when selection==="specific".
 * @param {number}  [options.campaignId] Required when selection==="auto".
 * @param {number}  [options.intervalMs] Default 15000.
 * @param {Object}  [options.initialData] Seed payload (from SSR data-initial).
 * @return {{ data: object|null, error: Error|null, loading: boolean }}
 */
export function useMatchProgress( options = {} ) {
	const {
		selection = 'auto',
		matchId,
		campaignId,
		intervalMs = 15000,
		initialData = null,
	} = options;

	const [ data, setData ] = useState( initialData );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( ! initialData );
	const backoffRef = useRef( intervalMs );

	useEffect( () => {
		const path =
			selection === 'specific'
				? matchProgressPath( matchId )
				: activeMatchesPath( campaignId );
		if ( ! path ) {
			setData( null );
			return undefined;
		}
		let cancelled = false;
		let timerId = null;

		const schedule = ( ms ) => {
			if ( cancelled ) {
				return;
			}
			timerId = window.setTimeout( run, ms );
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
				const payload = await apiFetch( {
					path: withPreviewParam( path ),
				} );
				if ( cancelled ) {
					return;
				}
				let next = null;
				if ( selection === 'specific' ) {
					next = payload || null;
				} else {
					const list = Array.isArray( payload?.matches )
						? payload.matches
						: [];
					next = list[ 0 ] || null;
				}
				setData( next );
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
	}, [ selection, matchId, campaignId, intervalMs ] );

	return { data, error, loading };
}
