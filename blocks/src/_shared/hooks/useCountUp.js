/**
 * Animates a numeric value from its previous render to a new target with
 * a requestAnimationFrame loop and an ease-out curve. Respects
 * `prefers-reduced-motion` by snapping straight to the target.
 *
 * Returns the current animated value, which the caller passes through
 * `formatCurrency` / `formatNumber`. The hook intentionally returns a
 * raw number (not a string) so the caller controls formatting.
 *
 * @param {number}  target               The value to animate to.
 * @param {Object}  [options]            Behavior overrides.
 * @param {number}  [options.durationMs] Animation duration in ms (default 700).
 * @param {boolean} [options.enabled]    When false, snaps to target (default true).
 * @return {number} The current animated value.
 */
import { useEffect, useRef, useState } from '@wordpress/element';

export function useCountUp( target, options = {} ) {
	const { durationMs = 700, enabled = true } = options;
	const safeTarget = Number.isFinite( Number( target ) )
		? Number( target )
		: 0;

	const [ value, setValue ] = useState( safeTarget );
	const fromRef = useRef( safeTarget );
	const rafRef = useRef( null );

	useEffect( () => {
		if ( ! enabled || prefersReducedMotion() ) {
			fromRef.current = safeTarget;
			setValue( safeTarget );
			return undefined;
		}

		const from = fromRef.current;
		const delta = safeTarget - from;
		if ( delta === 0 ) {
			return undefined;
		}

		const startedAt = performance.now();
		const tick = ( now ) => {
			const elapsed = now - startedAt;
			const progress = Math.min( 1, elapsed / durationMs );
			const eased = easeOutCubic( progress );
			const next = from + delta * eased;
			setValue( next );
			if ( progress < 1 ) {
				rafRef.current = window.requestAnimationFrame( tick );
			} else {
				fromRef.current = safeTarget;
			}
		};
		rafRef.current = window.requestAnimationFrame( tick );

		return () => {
			if ( rafRef.current ) {
				window.cancelAnimationFrame( rafRef.current );
				rafRef.current = null;
			}
			fromRef.current = safeTarget;
		};
	}, [ safeTarget, durationMs, enabled ] );

	return value;
}

function easeOutCubic( t ) {
	const p = 1 - t;
	return 1 - p * p * p;
}

function prefersReducedMotion() {
	if ( typeof window === 'undefined' || ! window.matchMedia ) {
		return false;
	}
	return window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}
