/**
 * Pure time-math helpers used by the countdown UI. Kept separate from the
 *
 * @wordpress/element hook so it can be unit-tested without WP runtime deps.
 */

/**
 * Splits a millisecond remainder into days/hours/minutes/seconds parts.
 *
 * Clamps negatives to zero; returns zeros for null/NaN.
 *
 * @param {number|null} ms
 * @return {{ days: number, hours: number, minutes: number, seconds: number, total: number }}
 */
export function splitDuration( ms ) {
	if ( ms === null || ms === undefined || ! Number.isFinite( ms ) ) {
		return { days: 0, hours: 0, minutes: 0, seconds: 0, total: 0 };
	}
	const total = Math.max( 0, Math.floor( ms / 1000 ) );
	const days = Math.floor( total / 86400 );
	const hours = Math.floor( ( total % 86400 ) / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	const seconds = total % 60;
	return { days, hours, minutes, seconds, total };
}
