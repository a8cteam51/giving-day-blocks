/**
 * Jest configuration.
 *
 * Uses @wordpress/jest-preset-default for jsdom + the WordPress-friendly
 * babel/sass setup. Unit tests live alongside the shared utilities and
 * the block sources.
 *
 * `moduleNameMapper` resolves `@wordpress/element` to React because the
 * production bundle relies on `wp.element` (provided globally by WP at
 * runtime) but Jest needs a real implementation. Other `@wordpress/*`
 * packages used at test time get explicit no-op mocks under
 * `tests/__mocks__/` so we can exercise hooks without pulling the full
 * WP test harness.
 */
const defaultPreset = require( '@wordpress/jest-preset-default/jest-preset' );

module.exports = {
	...defaultPreset,
	testMatch: [
		'<rootDir>/tests/**/*.test.[jt]s?(x)',
		'<rootDir>/blocks/src/**/*.test.[jt]s?(x)',
	],
	moduleNameMapper: {
		...( defaultPreset.moduleNameMapper || {} ),
		'^@wordpress/element$':
			'<rootDir>/tests/__mocks__/@wordpress/element.js',
		'^@wordpress/api-fetch$':
			'<rootDir>/tests/__mocks__/@wordpress/api-fetch.js',
	},
};
