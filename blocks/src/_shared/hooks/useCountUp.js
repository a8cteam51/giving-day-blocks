/**
 * Animates a numeric value from its previous render to a new target with
 * a requestAnimationFrame loop and an ease-out curve. Respects
 * `prefers-reduced-motion` by snapping straight to the target.
 *
 * Returns the current animated value, which the caller passes through
 * `formatCurrency` / `formatNumber`. The hook intentionally returns a
 * raw number (not a string) so the caller controls formatting.
 *
 * @param {number}  target                 The value to animate to.
 * @param {Object}  [options]              Behavior overrides.
 * @param {number}  [options.durationMs]   Animation duration in ms (default 700).
 * @param {boolean} [options.enabled]      When false, snaps to target (default true).
 * @param {number}  [options.initialValue] Optional starting value for a one-shot
 *                                         mount animation (e.g., 0 to count up
 *                                         from zero on first render). Ignored
 *                                         when motion is disabled.
 * @return {number} The current animated value.
 */
import { useEffect, useRef, useState } from '@wordpress/element';

export function useCountUp( target, options = {} ) {
	const { durationMs = 700, enabled = true, initialValue } = options;
	const safeTarget = Number.isFinite( Number( target ) )
		? Number( target )
		: 0;

	// `initialValue` only seeds the *first* render and only when motion is
	// allowed. It lets callers opt into an initial animation from
	// `initialValue` → target (e.g., a goal bar that fills from 0% on page
	// load) without affecting the response to later target changes.
	const seed =
		Number.isFinite( Number( initialValue ) ) &&
		enabled &&
		! prefersReducedMotion()
			? Number( initialValue )
			: safeTarget;

	const [ value, setValue ] = useState( seed );
	const fromRef = useRef( seed );
	const valueRef = useRef( seed );
	const rafRef = useRef( null );

	useEffect( () => {
		if ( ! enabled || prefersReducedMotion() ) {
			fromRef.current = safeTarget;
			valueRef.current = safeTarget;
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
			valueRef.current = next;
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
			// Hand off the actual on-screen value so a retarget mid-flight
			// continues from where the animation visibly is, not from the
			// previous target.
			fromRef.current = valueRef.current;
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
