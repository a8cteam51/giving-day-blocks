<?php
/**
 * Campaign color-palette resolution.
 *
 * Converts the brand-color meta registered on the Campaign CPT
 * (see PostTypes\Campaign::META_COLOR_*) into an inline
 * `style="--giving-day-primary: #X; …"` string that matches the
 * CSS custom properties declared in blocks/src/_shared/tokens.scss.
 *
 * Blocks apply the string to their outer wrapper, scoping the
 * override to that single instance and falling back to the
 * theme.json palette for any color the editor hasn't set.
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

final class Colors {

	/**
	 * Meta key → CSS custom property name.
	 *
	 * @var array<string,string>
	 */
	private const MAP = array(
		Campaign::META_COLOR_PRIMARY   => '--giving-day-primary',
		Campaign::META_COLOR_SECONDARY => '--giving-day-secondary',
		Campaign::META_COLOR_ACCENT    => '--giving-day-accent',
		Campaign::META_COLOR_SURFACE   => '--giving-day-surface',
		Campaign::META_COLOR_MUTED     => '--giving-day-muted',
	);

	/**
	 * Returns the palette as [ css_var => color ].
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string,string>
	 */
	public static function for_campaign( int $campaign_id ): array {
		$out = array();
		foreach ( self::MAP as $meta_key => $css_var ) {
			$value = get_post_meta( $campaign_id, $meta_key, true );
			if ( is_string( $value ) && '' !== $value ) {
				$out[ $css_var ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Returns an `--giving-day-*: X; …` string suitable for a `style="…"`
	 * attribute. Empty string when no colors are set so callers can safely
	 * concatenate without emitting a bare `style=""`.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return string
	 */
	public static function inline_style( int $campaign_id ): string {
		$pairs = array();
		foreach ( self::for_campaign( $campaign_id ) as $css_var => $color ) {
			$pairs[] = $css_var . ': ' . $color;
		}
		if ( empty( $pairs ) ) {
			return '';
		}
		return implode( '; ', $pairs ) . ';';
	}
}
