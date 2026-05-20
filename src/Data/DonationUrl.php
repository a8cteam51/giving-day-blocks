<?php
/**
 * Builds donation URLs with the appropriate gd_* designation param.
 *
 * Base URL resolution order:
 *   1. apply_filters( 'giving_day_donation_page_url', $default )
 *   2. option giving_day_donation_page_id (resolved via get_permalink)
 *   3. home_url( '/donate' )
 *
 * @package Team51\GivingDay\Data
 * @since   1.1.0
 */

namespace Team51\GivingDay\Data;

defined( 'ABSPATH' ) || exit;

final class DonationUrl {

	public const OPTION_PAGE_ID = 'giving_day_donation_page_id';
	public const FILTER_URL     = 'giving_day_donation_page_url';

	public const TYPE_TEAM        = 'team';
	public const TYPE_BENEFICIARY = 'beneficiary';
	public const TYPE_NONE        = 'none';

	/**
	 * @param string $type 'team' | 'beneficiary' | 'none'
	 * @param int    $id   Entity post ID; ignored for type=none.
	 * @return string Absolute URL with the designation query arg appended when applicable.
	 */
	public static function build( string $type, int $id = 0 ): string {
		$base = self::resolve_base_url();

		if ( self::TYPE_TEAM === $type && $id > 0 ) {
			return add_query_arg( array( 'gd_team' => $id ), $base );
		}
		if ( self::TYPE_BENEFICIARY === $type && $id > 0 ) {
			return add_query_arg( array( 'gd_beneficiary' => $id ), $base );
		}
		return $base;
	}

	private static function resolve_base_url(): string {
		$default = home_url( '/donate' );

		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id > 0 ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				$default = $permalink;
			}
		}

		return (string) apply_filters( self::FILTER_URL, $default );
	}
}
