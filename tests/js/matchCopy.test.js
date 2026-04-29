import { frontEndCopy } from '../../blocks/src/_shared/utils/matchCopy';
import {
	MATCH_TYPE_DOLLAR_FOR_DOLLAR,
	MATCH_TYPE_DONOR_UNLOCK,
	MATCH_STATE_SCHEDULED,
	MATCH_STATE_ACTIVE,
	MATCH_STATE_EXHAUSTED,
	MATCH_STATE_UNLOCKED,
	MATCH_STATE_COMPLETED,
} from '../../blocks/src/_shared/utils/matchProgress';

describe( 'frontEndCopy — dollar-for-dollar', () => {
	const base = {
		type: MATCH_TYPE_DOLLAR_FOR_DOLLAR,
		multiplier_label: '2×',
		cap: 10000,
		matched_so_far: 7500,
		remaining: 2500,
	};

	test( 'active state pitches the multiplier', () => {
		const copy = frontEndCopy( { ...base, state: MATCH_STATE_ACTIVE }, 'USD' );
		expect( copy.typeLabel ).toBe( 'Dollar-for-dollar' );
		expect( copy.headline ).toMatch( /goes 2× further/ );
		expect( copy.meta ).toMatch( /\$7,500/ );
		expect( copy.meta ).toMatch( /\$10,000/ );
		expect( copy.meta ).toMatch( /\$2,500/ );
	} );

	test( 'scheduled state previews the upcoming match', () => {
		const copy = frontEndCopy(
			{ ...base, state: MATCH_STATE_SCHEDULED },
			'USD'
		);
		expect( copy.headline ).toMatch( /starts soon/i );
	} );

	test( 'exhausted state celebrates the cap being hit', () => {
		const copy = frontEndCopy(
			{ ...base, state: MATCH_STATE_EXHAUSTED },
			'USD'
		);
		expect( copy.headline ).toMatch( /\$10,000 fully matched/ );
	} );

	test( 'completed state announces the end', () => {
		const copy = frontEndCopy(
			{ ...base, state: MATCH_STATE_COMPLETED },
			'USD'
		);
		expect( copy.headline ).toBe( 'Match ended.' );
	} );

	test( 'meta with cap=0 falls back to "matched so far" copy', () => {
		const copy = frontEndCopy(
			{
				...base,
				cap: 0,
				matched_so_far: 1234,
				state: MATCH_STATE_ACTIVE,
			},
			'USD'
		);
		expect( copy.meta ).toMatch( /\$1,234 matched so far/ );
	} );
} );

describe( 'frontEndCopy — donor-unlock', () => {
	const base = {
		type: MATCH_TYPE_DONOR_UNLOCK,
		donor_threshold: 100,
		donors_so_far: 73,
		donors_remaining: 27,
		unlock_amount: 5000,
	};

	test( 'active state shows donors-remaining and the unlock amount', () => {
		const copy = frontEndCopy( { ...base, state: MATCH_STATE_ACTIVE }, 'USD' );
		expect( copy.typeLabel ).toBe( 'Donor unlock' );
		expect( copy.headline ).toMatch( /27 more donors unlock \$5,000/ );
		expect( copy.meta ).toMatch( /73 of 100 donors/ );
	} );

	test( 'unlocked state celebrates the bonus', () => {
		const copy = frontEndCopy(
			{ ...base, state: MATCH_STATE_UNLOCKED },
			'USD'
		);
		expect( copy.headline ).toMatch( /Goal reached — \$5,000 unlocked/ );
	} );

	test( 'scheduled state nudges donors to participate', () => {
		const copy = frontEndCopy(
			{ ...base, state: MATCH_STATE_SCHEDULED },
			'USD'
		);
		expect( copy.headline ).toMatch( /unlock the bonus/i );
	} );
} );

describe( 'frontEndCopy — robustness', () => {
	test( 'returns empty strings for null match', () => {
		expect( frontEndCopy( null, 'USD' ) ).toEqual( {
			typeLabel: '',
			headline: '',
			meta: '',
		} );
	} );

	test( 'defaults to dollar-for-dollar for unknown types', () => {
		const copy = frontEndCopy(
			{
				type: 'totally-new-type',
				cap: 100,
				matched_so_far: 25,
				remaining: 75,
				multiplier_label: '3×',
				state: MATCH_STATE_ACTIVE,
			},
			'USD'
		);
		expect( copy.typeLabel ).toBe( 'Dollar-for-dollar' );
		expect( copy.headline ).toMatch( /3×/ );
	} );
} );
