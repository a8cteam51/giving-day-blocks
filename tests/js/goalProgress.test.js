import {
	GOAL_TARGET_CAMPAIGN,
	clampPercent,
	computePercent,
	goalProgressEndpoint,
	summaryPathFor,
	targetFromAttributes,
} from '../../blocks/src/_shared/utils/goalProgress';

describe( 'targetFromAttributes', () => {
	test( 'returns null without a campaignId', () => {
		expect( targetFromAttributes( {} ) ).toBeNull();
		expect( targetFromAttributes( null ) ).toBeNull();
	} );

	test( 'maps a numeric campaignId to a campaign target', () => {
		expect( targetFromAttributes( { campaignId: 42 } ) ).toEqual( {
			type: GOAL_TARGET_CAMPAIGN,
			id: 42,
		} );
	} );

	test( 'rejects zero / negative ids', () => {
		expect( targetFromAttributes( { campaignId: 0 } ) ).toBeNull();
		expect( targetFromAttributes( { campaignId: -1 } ) ).toBeNull();
	} );
} );

describe( 'summaryPathFor', () => {
	test( 'builds the campaign summary REST path', () => {
		const target = { type: GOAL_TARGET_CAMPAIGN, id: 7 };
		expect( summaryPathFor( target ) ).toBe(
			'/giving-day/v1/campaign/7/summary'
		);
	} );

	test( 'returns null for unknown target types', () => {
		expect( summaryPathFor( { type: 'team', id: 1 } ) ).toBeNull();
		expect( summaryPathFor( null ) ).toBeNull();
	} );
} );

describe( 'clampPercent', () => {
	test( 'clamps below 0 and above 100', () => {
		expect( clampPercent( -5 ) ).toBe( 0 );
		expect( clampPercent( 250 ) ).toBe( 100 );
		expect( clampPercent( 42 ) ).toBe( 42 );
	} );

	test( 'returns 0 for non-numeric input', () => {
		expect( clampPercent( 'nope' ) ).toBe( 0 );
		expect( clampPercent( NaN ) ).toBe( 0 );
		expect( clampPercent( undefined ) ).toBe( 0 );
	} );
} );

describe( 'computePercent', () => {
	test( 'returns 0 when goal is 0 / unset', () => {
		expect( computePercent( 1000, 0 ) ).toBe( 0 );
		expect( computePercent( 1000, null ) ).toBe( 0 );
	} );

	test( 'returns the raw percentage (uncapped)', () => {
		expect( computePercent( 50, 100 ) ).toBe( 50 );
		expect( computePercent( 150, 100 ) ).toBe( 150 );
	} );
} );

describe( 'goalProgressEndpoint', () => {
	it( 'builds the campaign endpoint by default', () => {
		expect( goalProgressEndpoint( 'campaign', 12 ) ).toBe( '/giving-day/v1/campaign/12/summary' );
	} );

	it( 'builds the team endpoint', () => {
		expect( goalProgressEndpoint( 'team', 701 ) ).toBe( '/giving-day/v1/team/701/summary' );
	} );

	it( 'builds the beneficiary endpoint', () => {
		expect( goalProgressEndpoint( 'beneficiary', 612 ) ).toBe( '/giving-day/v1/beneficiary/612/summary' );
	} );

	it( 'returns null for unknown type', () => {
		expect( goalProgressEndpoint( 'unknown', 1 ) ).toBeNull();
	} );

	it( 'returns null when id is missing or zero', () => {
		expect( goalProgressEndpoint( 'team', 0 ) ).toBeNull();
	} );

	it( 'returns null for non-integer ids (parseInt would have accepted these)', () => {
		expect( goalProgressEndpoint( 'team', '123abc' ) ).toBeNull();
		expect( goalProgressEndpoint( 'team', 1.5 ) ).toBeNull();
	} );
} );
