/**
 * Behavior we want to lock in:
 *   - When `enabled: false`, the hook returns the target value immediately,
 *     even on first render and on subsequent target changes.
 *   - When `prefers-reduced-motion: reduce` matches, the hook snaps the
 *     same way it does when explicitly disabled.
 *   - Non-numeric input is coerced to 0.
 *
 * We deliberately do NOT exercise the rAF mid-animation values: that would
 * require mocking rAF + performance.now and asserting on intermediate
 * frames, which adds maintenance cost without catching real regressions.
 *
 * Uses raw react-dom + React.act() because the project doesn't ship
 * @testing-library/react. The `IS_REACT_ACT_ENVIRONMENT` flag silences
 * React 18's "act(...) not configured" warning that would otherwise be
 * intercepted by @wordpress/jest-console.
 */
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { useCountUp } from '../../blocks/src/_shared/hooks/useCountUp';

function mountProbe( { target, enabled = true, initialValue } = {} ) {
	const seen = [];
	const Probe = ( { value, on } ) => {
		const out = useCountUp( value, {
			enabled: on,
			durationMs: 50,
			initialValue,
		} );
		seen.push( out );
		return null;
	};
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	act( () => {
		root.render( <Probe value={ target } on={ enabled } /> );
	} );
	const rerender = ( nextTarget, nextEnabled = enabled ) => {
		act( () => {
			root.render( <Probe value={ nextTarget } on={ nextEnabled } /> );
		} );
	};
	const unmount = () => {
		act( () => {
			root.unmount();
		} );
	};
	return { seen, rerender, unmount };
}

describe( 'useCountUp', () => {
	test( 'returns the target value on first render when disabled', () => {
		const { seen, unmount } = mountProbe( {
			target: 1234,
			enabled: false,
		} );
		expect( seen[ seen.length - 1 ] ).toBe( 1234 );
		unmount();
	} );

	test( 'snaps to the new target when disabled', () => {
		const { seen, rerender, unmount } = mountProbe( {
			target: 100,
			enabled: false,
		} );
		rerender( 200, false );
		expect( seen[ seen.length - 1 ] ).toBe( 200 );
		unmount();
	} );

	test( 'respects prefers-reduced-motion: reduce', () => {
		const original = window.matchMedia;
		window.matchMedia = ( query ) => ( {
			matches: query.includes( 'reduce' ),
			media: query,
			onchange: null,
			addEventListener: () => {},
			removeEventListener: () => {},
			dispatchEvent: () => false,
			addListener: () => {},
			removeListener: () => {},
		} );

		try {
			const { seen, rerender, unmount } = mountProbe( {
				target: 0,
				enabled: true,
			} );
			rerender( 999, true );
			expect( seen[ seen.length - 1 ] ).toBe( 999 );
			unmount();
		} finally {
			window.matchMedia = original;
		}
	} );

	test( 'treats non-numeric targets as zero', () => {
		const { seen, unmount } = mountProbe( {
			target: 'nope',
			enabled: false,
		} );
		expect( seen[ seen.length - 1 ] ).toBe( 0 );
		unmount();
	} );

	test( 'ignores initialValue when disabled and snaps to target', () => {
		const { seen, unmount } = mountProbe( {
			target: 1234,
			enabled: false,
			initialValue: 0,
		} );
		// With motion disabled the hook must not seed from initialValue —
		// the consumer expects the final value on the very first paint.
		expect( seen[ 0 ] ).toBe( 1234 );
		expect( seen[ seen.length - 1 ] ).toBe( 1234 );
		unmount();
	} );

	test( 'ignores initialValue under prefers-reduced-motion', () => {
		const original = window.matchMedia;
		window.matchMedia = ( query ) => ( {
			matches: query.includes( 'reduce' ),
			media: query,
			onchange: null,
			addEventListener: () => {},
			removeEventListener: () => {},
			dispatchEvent: () => false,
			addListener: () => {},
			removeListener: () => {},
		} );

		try {
			const { seen, unmount } = mountProbe( {
				target: 500,
				enabled: true,
				initialValue: 0,
			} );
			expect( seen[ 0 ] ).toBe( 500 );
			expect( seen[ seen.length - 1 ] ).toBe( 500 );
			unmount();
		} finally {
			window.matchMedia = original;
		}
	} );

	test( 'seeds first render from initialValue when motion is allowed', () => {
		const { seen, unmount } = mountProbe( {
			target: 100,
			enabled: true,
			initialValue: 0,
		} );
		// First render must reflect the seed so the bar/numbers start at
		// zero before the rAF loop animates toward the real target.
		expect( seen[ 0 ] ).toBe( 0 );
		unmount();
	} );
} );
