/**
 * Test-time stand-in for `@wordpress/api-fetch`.
 *
 * Tests that exercise hooks importing this module can override the
 * default jest mock per-test (e.g. `jest.mock('@wordpress/api-fetch')`).
 * The default returns a never-resolving promise so unmocked tests don't
 * accidentally hit the network or trigger React state updates.
 */
const apiFetch = jest.fn( () => new Promise( () => {} ) );

module.exports = apiFetch;
module.exports.default = apiFetch;
