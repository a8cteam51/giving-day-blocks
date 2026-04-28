import {
	formatCurrency,
	formatNumber,
} from '../../blocks/src/_shared/utils/formatCurrency';

describe( 'formatCurrency', () => {
	it( 'formats whole dollars without decimals', () => {
		const out = formatCurrency( 1234, 'USD', 'en-US' );
		expect( out ).toContain( '1,234' );
		expect( out ).toMatch( /\$/ );
	} );

	it( 'formats fractional amounts with two decimals', () => {
		const out = formatCurrency( 12.5, 'USD', 'en-US' );
		expect( out ).toContain( '12.50' );
	} );

	it( 'falls back gracefully on an unknown currency', () => {
		const out = formatCurrency( 10, 'ZZZ', 'en-US' );
		expect( out ).toContain( '10' );
	} );

	it( 'treats non-numeric input as zero', () => {
		const out = formatCurrency( 'nope', 'USD', 'en-US' );
		expect( out ).toMatch( /0/ );
	} );
} );

describe( 'formatNumber', () => {
	it( 'formats integers with locale separators', () => {
		expect( formatNumber( 1000, 'en-US' ) ).toBe( '1,000' );
	} );

	it( 'returns 0 for invalid input', () => {
		expect( formatNumber( 'x', 'en-US' ) ).toBe( '0' );
	} );
} );
