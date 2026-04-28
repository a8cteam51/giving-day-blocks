/**
 * Returns true when a campaign's total event duration (start → end)
 * is 24 hours or less, in which case the Live countdown should drop
 * the "days" unit. Pre-event always keeps days regardless of duration.
 *
 * Accepts any Date-parseable strings (ISO 8601 is typical). Returns
 * false for missing or invalid input so the Live state keeps showing
 * days by default until real meta is provided.
 *
 * @param {string|null|undefined} startIso Campaign start datetime.
 * @param {string|null|undefined} endIso   Campaign end datetime.
 * @return {boolean}
 */
export function shouldHideDays( startIso, endIso ) {
	if ( ! startIso || ! endIso ) {
		return false;
	}
	const startMs = Date.parse( startIso );
	const endMs = Date.parse( endIso );
	if ( ! Number.isFinite( startMs ) || ! Number.isFinite( endMs ) ) {
		return false;
	}
	const duration = endMs - startMs;
	if ( duration <= 0 ) {
		return false;
	}
	return duration <= 24 * 60 * 60 * 1000;
}

/**
 * Canned remaining time for preview (editor canvas or `?givingday=`).
 * 23:59:59 in milliseconds — chosen so short-duration events render
 * the exact moment the days unit would drop off.
 */
export const PREVIEW_REMAINING_MS = ( 24 * 60 * 60 - 1 ) * 1000;
