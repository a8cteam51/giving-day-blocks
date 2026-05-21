import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Short relative-time label for the War Room recent-donations feed.
 * Falls back to the locale date string for anything older than a day.
 *
 * @param {string|number|Date} input ISO string, unix seconds, or Date.
 * @param {Date}               [now] Reference "now" (testing override).
 * @return {string} Short human-readable label.
 */
export function relativeTime( input, now = new Date() ) {
	if ( ! input ) {
		return '';
	}
	const then =
		input instanceof Date
			? input
			: new Date( typeof input === 'number' ? input * 1000 : input );
	if ( Number.isNaN( then.getTime() ) ) {
		return '';
	}
	const diffSeconds = Math.max( 0, Math.floor( ( now - then ) / 1000 ) );
	if ( diffSeconds < 45 ) {
		return __( 'just now', 'giving-day-blocks' );
	}
	if ( diffSeconds < 90 ) {
		return __( '1m ago', 'giving-day-blocks' );
	}
	if ( diffSeconds < 3600 ) {
		const minutes = Math.round( diffSeconds / 60 );
		return sprintf(
			/* translators: %d: number of minutes */
			_n( '%dm ago', '%dm ago', minutes, 'giving-day-blocks' ),
			minutes
		);
	}
	if ( diffSeconds < 86400 ) {
		const hours = Math.round( diffSeconds / 3600 );
		return sprintf(
			/* translators: %d: number of hours */
			_n( '%dh ago', '%dh ago', hours, 'giving-day-blocks' ),
			hours
		);
	}
	try {
		return then.toLocaleString(
			document.documentElement.lang || undefined,
			{
				month: 'short',
				day: 'numeric',
				hour: 'numeric',
				minute: '2-digit',
			}
		);
	} catch ( e ) {
		return then.toISOString();
	}
}
