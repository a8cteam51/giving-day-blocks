import {
	shouldHideDays,
	PREVIEW_REMAINING_MS,
} from '../../blocks/src/_shared/utils/eventDuration';

describe( 'shouldHideDays', () => {
	test( 'returns false when either date is missing', () => {
		expect( shouldHideDays( '', '2026-05-01T00:00:00Z' ) ).toBe( false );
		expect( shouldHideDays( '2026-05-01T00:00:00Z', '' ) ).toBe( false );
		expect( shouldHideDays( null, null ) ).toBe( false );
	} );

	test( 'returns false for unparseable input', () => {
		expect( shouldHideDays( 'not-a-date', '2026-05-01T00:00:00Z' ) ).toBe( false );
	} );

	test( 'returns false when end <= start', () => {
		expect(
			shouldHideDays( '2026-05-01T12:00:00Z', '2026-05-01T10:00:00Z' )
		).toBe( false );
	} );

	test( 'returns true for exactly 24h window', () => {
		expect(
			shouldHideDays( '2026-05-01T00:00:00Z', '2026-05-02T00:00:00Z' )
		).toBe( true );
	} );

	test( 'returns true for sub-24h window', () => {
		expect(
			shouldHideDays( '2026-05-01T00:00:00Z', '2026-05-01T12:00:00Z' )
		).toBe( true );
	} );

	test( 'returns false for multi-day event', () => {
		expect(
			shouldHideDays( '2026-05-01T00:00:00Z', '2026-05-04T00:00:00Z' )
		).toBe( false );
	} );
} );

describe( 'PREVIEW_REMAINING_MS', () => {
	test( 'is exactly 23:59:59 in milliseconds', () => {
		expect( PREVIEW_REMAINING_MS ).toBe( 86399 * 1000 );
	} );
} );
