import {
	MATCH_TYPE_DOLLAR_FOR_DOLLAR,
	MATCH_TYPE_DONOR_UNLOCK,
	MATCH_STATE_SCHEDULED,
	MATCH_STATE_ACTIVE,
	MATCH_STATE_EXHAUSTED,
	MATCH_STATE_UNLOCKED,
	MATCH_STATE_COMPLETED,
	matchProgressPath,
	activeMatchesPath,
	matchPercent,
	shouldRender,
} from '../../blocks/src/_shared/utils/matchProgress';

describe( 'matchProgressPath', () => {
	test( 'builds the per-match REST path', () => {
		expect( matchProgressPath( 7 ) ).toBe(
			'/giving-day/v1/match/7/progress'
		);
	} );

	test( 'rejects zero / negative / non-numeric ids', () => {
		expect( matchProgressPath( 0 ) ).toBeNull();
		expect( matchProgressPath( -1 ) ).toBeNull();
		expect( matchProgressPath( 'nope' ) ).toBeNull();
		expect( matchProgressPath( null ) ).toBeNull();
	} );
} );

describe( 'activeMatchesPath', () => {
	test( 'builds the per-campaign REST path', () => {
		expect( activeMatchesPath( 12 ) ).toBe(
			'/giving-day/v1/campaign/12/active-matches'
		);
	} );

	test( 'rejects invalid ids', () => {
		expect( activeMatchesPath( 0 ) ).toBeNull();
		expect( activeMatchesPath( undefined ) ).toBeNull();
	} );
} );

describe( 'matchPercent', () => {
	test( 'dollar-for-dollar: matched / cap, clamped 0..100', () => {
		expect(
			matchPercent( {
				type: MATCH_TYPE_DOLLAR_FOR_DOLLAR,
				cap: 1000,
				matched_so_far: 250,
			} )
		).toBe( 25 );
		expect(
			matchPercent( {
				type: MATCH_TYPE_DOLLAR_FOR_DOLLAR,
				cap: 1000,
				matched_so_far: 5000,
			} )
		).toBe( 100 );
		expect(
			matchPercent( {
				type: MATCH_TYPE_DOLLAR_FOR_DOLLAR,
				cap: 0,
				matched_so_far: 999,
			} )
		).toBe( 0 );
	} );

	test( 'donor-unlock: donors / threshold, clamped 0..100', () => {
		expect(
			matchPercent( {
				type: MATCH_TYPE_DONOR_UNLOCK,
				donor_threshold: 100,
				donors_so_far: 73,
			} )
		).toBe( 73 );
		expect(
			matchPercent( {
				type: MATCH_TYPE_DONOR_UNLOCK,
				donor_threshold: 100,
				donors_so_far: 200,
			} )
		).toBe( 100 );
		expect(
			matchPercent( {
				type: MATCH_TYPE_DONOR_UNLOCK,
				donor_threshold: 0,
				donors_so_far: 5,
			} )
		).toBe( 0 );
	} );

	test( 'returns 0 for null / non-object input', () => {
		expect( matchPercent( null ) ).toBe( 0 );
		expect( matchPercent( undefined ) ).toBe( 0 );
		expect( matchPercent( 'nope' ) ).toBe( 0 );
	} );
} );

describe( 'shouldRender', () => {
	const baseActive = { state: MATCH_STATE_ACTIVE };

	test( 'always shows an active match', () => {
		expect(
			shouldRender( baseActive, {
				hideWhenComplete: 'hide',
				showOutsideWindow: 'hide',
			} )
		).toBe( true );
	} );

	test( 'hides scheduled by default', () => {
		expect(
			shouldRender(
				{ state: MATCH_STATE_SCHEDULED },
				{ showOutsideWindow: 'hide' }
			)
		).toBe( false );
	} );

	test( 'shows scheduled when opted in', () => {
		expect(
			shouldRender(
				{ state: MATCH_STATE_SCHEDULED },
				{ showOutsideWindow: 'show-scheduled' }
			)
		).toBe( true );
	} );

	test( 'hides completed by default, shows when opted in', () => {
		expect(
			shouldRender(
				{ state: MATCH_STATE_COMPLETED },
				{ showOutsideWindow: 'hide' }
			)
		).toBe( false );
		expect(
			shouldRender(
				{ state: MATCH_STATE_COMPLETED },
				{ showOutsideWindow: 'show-ended' }
			)
		).toBe( true );
	} );

	test( 'exhausted/unlocked: hide vs. "show goal reached"', () => {
		expect(
			shouldRender(
				{ state: MATCH_STATE_EXHAUSTED },
				{ hideWhenComplete: 'hide' }
			)
		).toBe( false );
		expect(
			shouldRender(
				{ state: MATCH_STATE_EXHAUSTED },
				{ hideWhenComplete: 'show-goal-reached' }
			)
		).toBe( true );
		expect(
			shouldRender(
				{ state: MATCH_STATE_UNLOCKED },
				{ hideWhenComplete: 'show-goal-reached' }
			)
		).toBe( true );
	} );

	test( 'returns false when match is null', () => {
		expect( shouldRender( null ) ).toBe( false );
	} );
} );
