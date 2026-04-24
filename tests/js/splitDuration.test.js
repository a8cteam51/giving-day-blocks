import { splitDuration } from '../../blocks/src/_shared/utils/splitDuration';

describe( 'splitDuration', () => {
	it( 'splits a positive millisecond remainder into d/h/m/s', () => {
		const ms = ( ( ( 2 * 24 + 3 ) * 60 + 4 ) * 60 + 5 ) * 1000;
		expect( splitDuration( ms ) ).toEqual( {
			days: 2,
			hours: 3,
			minutes: 4,
			seconds: 5,
			total: 2 * 86400 + 3 * 3600 + 4 * 60 + 5,
		} );
	} );

	it( 'clamps negatives to zero', () => {
		expect( splitDuration( -5000 ) ).toEqual( {
			days: 0,
			hours: 0,
			minutes: 0,
			seconds: 0,
			total: 0,
		} );
	} );

	it( 'returns zeros for null/NaN', () => {
		expect( splitDuration( null ) ).toEqual( {
			days: 0,
			hours: 0,
			minutes: 0,
			seconds: 0,
			total: 0,
		} );
		expect( splitDuration( NaN ) ).toEqual( {
			days: 0,
			hours: 0,
			minutes: 0,
			seconds: 0,
			total: 0,
		} );
	} );
} );
