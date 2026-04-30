import { leaderboardRestPath } from '../../blocks/src/_shared/utils/leaderboardPath';

describe( 'leaderboardRestPath', () => {
	it( 'includes dimension and limit', () => {
		const path = leaderboardRestPath( 42, {
			dimension: 'top_teams',
			limit: 7,
		} );
		expect( path ).toBe(
			'/giving-day/v1/campaign/42/leaderboard?dimension=top_teams&limit=7'
		);
	} );

	it( 'adds optional filters and anonymize', () => {
		const path = leaderboardRestPath( 1, {
			dimension: 'top_donors',
			limit: 10,
			filterTermId: 5,
			groupByParentTermId: 9,
			anonymize: true,
		} );
		expect( path ).toContain( 'filter_term_id=5' );
		expect( path ).toContain( 'group_by_parent_term_id=9' );
		expect( path ).toContain( 'anonymize=true' );
	} );
} );
