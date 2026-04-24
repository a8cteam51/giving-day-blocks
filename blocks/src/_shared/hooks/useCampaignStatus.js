import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { withPreviewParam } from './usePreviewOverride';
import { splitDuration } from '../utils/splitDuration';

export { splitDuration };

/**
 * Subscribes to /campaign/{id}/countdown and keeps a drift-corrected status
 * + millisecond countdown value in sync. Pauses when the tab is hidden.
 *
 * @param {number} campaignId
 * @param {object} [options]
 * @param {number} [options.refreshMs=30000] How often to re-fetch server_time.
 * @param {object} [options.initialData] SSR-provided payload to avoid a first flash.
 * @returns {{
 *   status: "scheduled"|"live"|"ended"|"idle"|"loading",
 *   data: object|null,
 *   error: Error|null,
 *   now: number,
 *   targetMs: number|null,
 *   msRemaining: number|null,
 * }}
 */
export function useCampaignStatus( campaignId, options = {} ) {
	const { refreshMs = 30000, initialData = null } = options;

	const [ data, setData ] = useState( initialData );
	const [ error, setError ] = useState( null );
	const [ now, setNow ] = useState( () => Date.now() );
	const offsetRef = useRef( 0 );
	const rafRef = useRef( null );

	useEffect( () => {
		if ( ! campaignId ) {
			setData( null );
			return undefined;
		}

		let cancelled = false;
		const fetchPayload = async () => {
			try {
				const payload = await apiFetch( {
					path: withPreviewParam(
						`/giving-day/v1/campaign/${ campaignId }/countdown`
					),
				} );
				if ( cancelled ) {
					return;
				}
				const serverMs = Date.parse( payload.server_time );
				if ( Number.isFinite( serverMs ) ) {
					offsetRef.current = serverMs - Date.now();
				}
				setData( payload );
				setError( null );
			} catch ( err ) {
				if ( ! cancelled ) {
					setError( err instanceof Error ? err : new Error( String( err ) ) );
				}
			}
		};

		fetchPayload();
		const interval = setInterval( () => {
			if ( document.visibilityState === 'visible' ) {
				fetchPayload();
			}
		}, refreshMs );

		const onVisibility = () => {
			if ( document.visibilityState === 'visible' ) {
				fetchPayload();
			}
		};
		document.addEventListener( 'visibilitychange', onVisibility );

		return () => {
			cancelled = true;
			clearInterval( interval );
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ campaignId, refreshMs ] );

	useEffect( () => {
		const tick = () => {
			setNow( Date.now() + offsetRef.current );
			rafRef.current = window.requestAnimationFrame( tick );
		};
		rafRef.current = window.requestAnimationFrame( tick );
		return () => {
			if ( rafRef.current ) {
				window.cancelAnimationFrame( rafRef.current );
			}
		};
	}, [] );

	const { status, targetMs, msRemaining } = useMemo( () => {
		if ( ! data ) {
			return { status: 'loading', targetMs: null, msRemaining: null };
		}
		const s = data.status || 'idle';
		const startMs = data.start ? Date.parse( data.start ) : NaN;
		const endMs = data.end ? Date.parse( data.end ) : NaN;
		const preMs = data.pre_event_start ? Date.parse( data.pre_event_start ) : NaN;

		let target = null;
		if ( s === 'scheduled' ) {
			target = Number.isFinite( startMs ) ? startMs : null;
		} else if ( s === 'live' ) {
			target = Number.isFinite( endMs ) ? endMs : null;
		} else if ( s === 'ended' ) {
			target = Number.isFinite( endMs ) ? endMs : null;
		} else if ( s === 'idle' ) {
			target = Number.isFinite( preMs ) ? preMs : null;
		}
		return {
			status: s,
			targetMs: target,
			msRemaining: target ? Math.max( 0, target - now ) : null,
		};
	}, [ data, now ] );

	return { status, data, error, now, targetMs, msRemaining };
}
