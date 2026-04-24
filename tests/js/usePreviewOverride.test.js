import {
	readPreviewOverride,
	withPreviewParam,
} from '../../blocks/src/_shared/hooks/usePreviewOverride';

function setUrl( search ) {
	window.history.replaceState( {}, '', `/test/${ search }` );
}

describe( 'readPreviewOverride', () => {
	afterEach( () => {
		document.body.className = '';
		setUrl( '' );
	} );

	it( 'returns null when the user is not logged in, even with the param', () => {
		setUrl( '?givingday=pre' );
		expect( readPreviewOverride() ).toBeNull();
	} );

	it( 'maps short codes to canonical statuses for logged-in users', () => {
		document.body.classList.add( 'logged-in' );
		setUrl( '?givingday=pre' );
		expect( readPreviewOverride() ).toBe( 'scheduled' );
		setUrl( '?givingday=live' );
		expect( readPreviewOverride() ).toBe( 'live' );
		setUrl( '?givingday=post' );
		expect( readPreviewOverride() ).toBe( 'ended' );
	} );

	it( 'returns null for invalid values', () => {
		document.body.classList.add( 'logged-in' );
		setUrl( '?givingday=whatever' );
		expect( readPreviewOverride() ).toBeNull();
		setUrl( '' );
		expect( readPreviewOverride() ).toBeNull();
	} );
} );

describe( 'withPreviewParam', () => {
	afterEach( () => {
		document.body.className = '';
		setUrl( '' );
	} );

	it( 'is a passthrough without a logged-in session', () => {
		setUrl( '?givingday=pre' );
		expect( withPreviewParam( '/giving-day/v1/campaign/1/countdown' ) ).toBe(
			'/giving-day/v1/campaign/1/countdown'
		);
	} );

	it( 'appends a short code for logged-in users', () => {
		document.body.classList.add( 'logged-in' );
		setUrl( '?givingday=post' );
		expect( withPreviewParam( '/giving-day/v1/campaign/1/summary' ) ).toBe(
			'/giving-day/v1/campaign/1/summary?givingday=post'
		);
	} );

	it( 'respects an existing query string', () => {
		document.body.classList.add( 'logged-in' );
		setUrl( '?givingday=live' );
		expect( withPreviewParam( '/x?foo=1' ) ).toBe( '/x?foo=1&givingday=live' );
	} );
} );
