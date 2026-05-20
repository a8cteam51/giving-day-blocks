/**
 * Single source of truth for "what does the goal-progress block point at?"
 *
 * Today the only supported target is a Campaign. The block exposes a
 * `campaignId` attribute for now (see PLAN.md § 5.1). Internally we still
 * normalize through a `{ type, id }` descriptor so adding Team and
 * Beneficiary targets later is a one-place change here + a matching PHP
 * branch in src/Data/GoalProgress.php.
 *
 * @typedef {{ type: 'campaign', id: number }} GoalTarget
 */

export const GOAL_TARGET_CAMPAIGN = 'campaign';

/**
 * Builds a target descriptor from the block's attributes. Returns null
 * when the block isn't pointed at anything resolvable yet.
 *
 * @param {Object} attributes Block attributes.
 * @return {GoalTarget|null} Target descriptor, or null when none resolvable.
 */
export function targetFromAttributes( attributes ) {
	if ( ! attributes ) {
		return null;
	}
	if (
		Number.isFinite( Number( attributes.campaignId ) ) &&
		Number( attributes.campaignId ) > 0
	) {
		return {
			type: GOAL_TARGET_CAMPAIGN,
			id: Number( attributes.campaignId ),
		};
	}
	return null;
}

/**
 * Resolves the REST path the view layer should poll for a given target.
 * Adding Team/Beneficiary later is a `case` in this switch + a matching
 * REST route on the server.
 *
 * @param {GoalTarget} target
 * @return {string|null} REST path, or null when the target is unsupported.
 */
export function summaryPathFor( target ) {
	if ( ! target || ! target.id ) {
		return null;
	}
	switch ( target.type ) {
		case GOAL_TARGET_CAMPAIGN:
			return `/giving-day/v1/campaign/${ target.id }/summary`;
		default:
			return null;
	}
}

export function goalProgressEndpoint( type, id ) {
	const numericId = Number( id );
	if ( ! Number.isInteger( numericId ) || numericId <= 0 ) {
		return null;
	}
	const base = '/giving-day/v1';
	switch ( type ) {
		case 'campaign':
			return `${ base }/campaign/${ numericId }/summary`;
		case 'team':
			return `${ base }/team/${ numericId }/summary`;
		case 'beneficiary':
			return `${ base }/beneficiary/${ numericId }/summary`;
		default:
			return null;
	}
}

/**
 * Clamps a percent for visual bar fill (0–100). Useful when the
 * underlying summary already reports a >100% raised total but we still
 * want the bar to cap at the goal line visually.
 *
 * @param {number} percent
 * @return {number} Clamped percent in [0, 100].
 */
export function clampPercent( percent ) {
	const n = Number( percent );
	if ( ! Number.isFinite( n ) ) {
		return 0;
	}
	return Math.max( 0, Math.min( 100, n ) );
}

/**
 * Computes percent from raised/goal, clamped at 0 when the goal is
 * unset. The REST endpoint already returns a `percent` field so callers
 * normally use this as a fallback for the editor preview.
 *
 * @param {number} raised
 * @param {number} goal
 * @return {number} Raw percent, may exceed 100.
 */
export function computePercent( raised, goal ) {
	const r = Number( raised ) || 0;
	const g = Number( goal ) || 0;
	if ( g <= 0 ) {
		return 0;
	}
	return ( r / g ) * 100;
}
