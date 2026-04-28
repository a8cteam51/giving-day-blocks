/**
 * Test-time stand-in for `@wordpress/element`.
 *
 * In the browser bundle WordPress provides `wp.element` (a re-export of
 * React) via the dependency-extraction-webpack-plugin. Jest doesn't run
 * that plugin, so this file gives the test environment the same API by
 * forwarding to the real React modules.
 */
const React = require( 'react' );
const ReactDOMClient = require( 'react-dom/client' );

module.exports = {
	...React,
	createRoot: ReactDOMClient.createRoot,
	hydrateRoot: ReactDOMClient.hydrateRoot,
};
