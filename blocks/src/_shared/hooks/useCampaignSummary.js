import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { withPreviewParam } from './usePreviewOverride';

/**
 * Polls /campaign/{id}/summary on an interval, pausing on hidden tabs and
 * backing off on errors.
 *
 * @param {number} campaignId
 * @param {Object} [options]
 * @param {number} [options.intervalMs=30000]
 * @param {Object} [options.initialData]
 * @return {{ data: object|null, error: Error|null, loading: boolean }}
 */
export function useCampaignSummary( campaignId, options = {} ) {
	const { intervalMs = 30000, initialData = null } = options;
	const [ data, setData ] = useState( initialData );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( ! initialData );
	const backoffRef = useRef( intervalMs );

	useEffect( () => {
		if ( ! campaignId ) {
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
					path: withPreviewParam(
						`/giving-day/v1/campaign/${ campaignId }/summary`
					),
				} );
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
	}, [ campaignId, intervalMs ] );

	return { data, error, loading };
}
