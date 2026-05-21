import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { withPreviewParam } from './usePreviewOverride';

/**
 * Polls /campaign/{id}/warroom, pausing on hidden tabs and backing off on
 * errors. Mirrors useCampaignSummary but for the fat War Room payload.
 *
 * @param {number} campaignId
 * @param {Object} [options]
 * @param {number} [options.intervalMs=10000]
 * @param {Object} [options.initialData]
 * @return {{ data: object|null, error: Error|null, loading: boolean }} Hook state.
 */
export function useWarRoom( campaignId, options = {} ) {
	const { intervalMs = 10000, initialData = null } = options;
	const safeIntervalMs = Math.max( 1000, Number( intervalMs ) || 10000 );
	const [ data, setData ] = useState( initialData );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( ! initialData );
	const backoffRef = useRef( safeIntervalMs );

	useEffect( () => {
		if ( ! campaignId ) {
			setData( null );
			setError( null );
			setLoading( false );
			return undefined;
		}
		setData( initialData ?? null );
		setError( null );
		setLoading( true );
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
				schedule( safeIntervalMs );
				return;
			}
			try {
				const payload = await apiFetch( {
					path: withPreviewParam(
						`/giving-day/v1/campaign/${ campaignId }/warroom`
					),
				} );
				if ( cancelled ) {
					return;
				}
				setData( payload );
				setError( null );
				setLoading( false );
				backoffRef.current = safeIntervalMs;
				schedule( safeIntervalMs );
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
	}, [ campaignId, safeIntervalMs, initialData ] );

	return { data, error, loading };
}
