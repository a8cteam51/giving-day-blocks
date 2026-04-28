/**
 * Format an amount using Intl.NumberFormat, falling back gracefully.
 *
 * @param {number} amount
 * @param {string} currency ISO 4217 code (e.g. "USD").
 * @param {string} [locale] BCP-47 locale. Defaults to the document language.
 * @return {string}
 */
export function formatCurrency( amount, currency = 'USD', locale ) {
	const safeAmount = Number.isFinite( Number( amount ) )
		? Number( amount )
		: 0;
	const resolvedLocale =
		locale ||
		( typeof document !== 'undefined'
			? document.documentElement.lang
			: undefined ) ||
		undefined;
	try {
		return new Intl.NumberFormat( resolvedLocale, {
			style: 'currency',
			currency,
			maximumFractionDigits: Number.isInteger( safeAmount ) ? 0 : 2,
		} ).format( safeAmount );
	} catch ( err ) {
		return `${ currency } ${ safeAmount.toLocaleString() }`;
	}
}

export function formatNumber( value, locale ) {
	const n = Number( value );
	if ( ! Number.isFinite( n ) ) {
		return '0';
	}
	const resolvedLocale =
		locale ||
		( typeof document !== 'undefined'
			? document.documentElement.lang
			: undefined ) ||
		undefined;
	try {
		return new Intl.NumberFormat( resolvedLocale ).format( n );
	} catch ( err ) {
		return String( n );
	}
}
