/**
 * Pure helpers for the Match My Gift block.
 *
 * Kept out of view.js / render.php so the discriminated-union shape lives
 * in one place that's also easy to unit-test without spinning up a React
 * tree or a WP runtime. Mirrors the payload that
 * src/Data/MatchProgress.php emits for /match/{id}/progress and
 * /campaign/{id}/active-matches.
 */

export const MATCH_TYPE_DOLLAR_FOR_DOLLAR = 'dollar_for_dollar';
export const MATCH_TYPE_DONOR_UNLOCK = 'donor_unlock';

export const MATCH_STATE_SCHEDULED = 'scheduled';
export const MATCH_STATE_ACTIVE = 'active';
export const MATCH_STATE_EXHAUSTED = 'exhausted';
export const MATCH_STATE_UNLOCKED = 'unlocked';
export const MATCH_STATE_COMPLETED = 'completed';

/**
 * Returns the REST path for a single match's progress endpoint.
 *
 * @param {number} matchId
 * @return {string|null} Null when the id is not a positive integer.
 */
export function matchProgressPath( matchId ) {
	const id = Number( matchId );
	if ( ! Number.isFinite( id ) || id <= 0 ) {
		return null;
	}
	return `/giving-day/v1/match/${ id }/progress`;
}

/**
 * Returns the REST path for the campaign's active-matches list.
 *
 * @param {number} campaignId
 * @return {string|null}
 */
export function activeMatchesPath( campaignId ) {
	const id = Number( campaignId );
	if ( ! Number.isFinite( id ) || id <= 0 ) {
		return null;
	}
	return `/giving-day/v1/campaign/${ id }/active-matches`;
}

/**
 * Computes the percent (0..100) for a match-progress payload, dispatching
 * on `type`. Returns 0 when the payload's denominator is missing/zero.
 *
 * @param {Object} match The progress payload.
 * @return {number}
 */
export function matchPercent( match ) {
	if ( ! match || typeof match !== 'object' ) {
		return 0;
	}
	if ( match.type === MATCH_TYPE_DONOR_UNLOCK ) {
		const threshold = Number( match.donor_threshold ) || 0;
		const donors = Number( match.donors_so_far ) || 0;
		if ( threshold <= 0 ) {
			return 0;
		}
		return clamp( ( donors / threshold ) * 100 );
	}
	const cap = Number( match.cap ) || 0;
	const matched = Number( match.matched_so_far ) || 0;
	if ( cap <= 0 ) {
		return 0;
	}
	return clamp( ( matched / cap ) * 100 );
}

/**
 * Returns true when the match should be rendered (or kept rendered) on the
 * page given the block's display preferences.
 *
 * Mirrors src/Data/MatchProgress.php::should_render() so SSR and client-side
 * hydration agree on visibility.
 *
 * @param {Object} match The progress payload.
 * @param {Object} options
 * @param {string} options.hideWhenComplete   'hide' | 'show-goal-reached'
 * @param {string} options.showOutsideWindow  'hide' | 'show-scheduled' | 'show-ended'
 * @return {boolean}
 */
export function shouldRender( match, options = {} ) {
	if ( ! match ) {
		return false;
	}
	const { hideWhenComplete = 'hide', showOutsideWindow = 'hide' } = options;
	const state = match.state || MATCH_STATE_ACTIVE;

	if ( state === MATCH_STATE_SCHEDULED ) {
		return showOutsideWindow === 'show-scheduled';
	}
	if ( state === MATCH_STATE_COMPLETED ) {
		return showOutsideWindow === 'show-ended';
	}
	if (
		state === MATCH_STATE_EXHAUSTED ||
		state === MATCH_STATE_UNLOCKED
	) {
		return hideWhenComplete === 'show-goal-reached';
	}
	return true;
}

function clamp( n ) {
	if ( ! Number.isFinite( n ) ) {
		return 0;
	}
	return Math.max( 0, Math.min( 100, n ) );
}
