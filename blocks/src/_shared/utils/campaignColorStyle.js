/**
 * Builds an inline style object that maps per-campaign color meta to the
 * `--giving-day-*` CSS custom properties consumed by tokens.scss. Mirrors
 * src/Data/Colors.php so SSR and editor previews stay consistent.
 *
 * Empty values are skipped so the theme.json fallback in tokens.scss wins.
 *
 * @param {object} meta Flat meta object from useEntityProp / REST.
 * @return {object} React-ready style object (may be empty).
 */
const META_TO_VAR = {
	_giving_color_primary: '--giving-day-primary',
	_giving_color_secondary: '--giving-day-secondary',
	_giving_color_accent: '--giving-day-accent',
	_giving_color_surface: '--giving-day-surface',
	_giving_color_muted: '--giving-day-muted',
};

export function campaignColorStyle( meta ) {
	if ( ! meta || typeof meta !== 'object' ) {
		return {};
	}
	const style = {};
	for ( const [ key, cssVar ] of Object.entries( META_TO_VAR ) ) {
		const value = meta[ key ];
		if ( typeof value === 'string' && value.trim() !== '' ) {
			style[ cssVar ] = value;
		}
	}
	return style;
}
