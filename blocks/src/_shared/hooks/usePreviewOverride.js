/**
 * Reads the ?givingday=pre|live|post preview override from the URL.
 *
 * Ignored for anonymous visitors so a bookmarked URL can't force a state.
 * Logged-in detection uses the `logged-in` body class WordPress emits.
 *
 * @return {"scheduled"|"live"|"ended"|null}
 */
export function readPreviewOverride() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const loggedIn =
		typeof document !== 'undefined' &&
		document.body &&
		document.body.classList.contains( 'logged-in' );
	if ( ! loggedIn ) {
		return null;
	}

	const params = new URLSearchParams( window.location.search );
	const raw = params.get( 'givingday' );
	switch ( raw ) {
		case 'pre':
			return 'scheduled';
		case 'live':
			return 'live';
		case 'post':
			return 'ended';
		default:
			return null;
	}
}

/**
 * Appends the `givingday` parameter to a REST URL when a preview override is
 * active, so SSR and client agree.
 *
 * @param {string} path REST endpoint, e.g. "/giving-day/v1/campaign/1/countdown".
 * @return {string}
 */
export function withPreviewParam( path ) {
	const override = readPreviewOverride();
	if ( ! override ) {
		return path;
	}
	const short =
		override === 'scheduled'
			? 'pre'
			: override === 'ended'
			? 'post'
			: 'live';
	const sep = path.includes( '?' ) ? '&' : '?';
	return `${ path }${ sep }givingday=${ short }`;
}
