/**
 * Builds the REST path for a campaign leaderboard request (query string only after base path).
 *
 * @param {number}  campaignId                  Campaign post ID.
 * @param {Object}  query                       Request fields.
 * @param {string}  query.dimension
 * @param {number}  [query.limit]
 * @param {number}  [query.filterTermId]
 * @param {number}  [query.groupByParentTermId]
 * @param {boolean} [query.anonymize]
 * @return {string} Full REST path including `?` query when needed.
 */
export function leaderboardRestPath( campaignId, query ) {
	const params = new URLSearchParams();
	params.set( 'dimension', query.dimension || 'top_teams' );
	params.set(
		'limit',
		String( query.limit && query.limit > 0 ? query.limit : 10 )
	);
	if ( query.filterTermId ) {
		params.set( 'filter_term_id', String( query.filterTermId ) );
	}
	if ( query.groupByParentTermId ) {
		params.set(
			'group_by_parent_term_id',
			String( query.groupByParentTermId )
		);
	}
	if ( query.anonymize ) {
		params.set( 'anonymize', 'true' );
	}
	const q = params.toString();
	return `/giving-day/v1/campaign/${ campaignId }/leaderboard${
		q ? `?${ q }` : ''
	}`;
}
