<?php
/**
 * Campaign status resolution.
 *
 * Resolves a Campaign's current lifecycle state (`scheduled | live | ended`)
 * from, in order of precedence:
 *
 *   1. The `?givingday=pre|live|post` preview override (logged-in users only).
 *   2. The `_giving_status_override` post meta.
 *   3. Comparison of the current server time to the Campaign's pre-event /
 *      start / end datetimes.
 *
 * The same ladder runs in PHP (REST, block render.php) and JavaScript
 * (useCampaignStatus hook) so SSR and hydration always agree.
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for computing and previewing Campaign status.
 */
final class Status {

	public const SCHEDULED = 'scheduled';
	public const LIVE      = 'live';
	public const ENDED     = 'ended';
	public const IDLE      = 'idle';

	/**
	 * Map of the short `?givingday=` values to canonical statuses.
	 */
	private const PREVIEW_MAP = array(
		'pre'  => self::SCHEDULED,
		'live' => self::LIVE,
		'post' => self::ENDED,
	);

	/**
	 * Reads the `?givingday=` preview override from the current request.
	 *
	 * Honored only for logged-in users so anonymous visitors who land on a
	 * bookmarked URL never see a simulated state.
	 *
	 * @return string|null One of SCHEDULED/LIVE/ENDED, or null when absent/ignored.
	 */
	public static function preview_override_from_request(): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		if ( ! isset( $_GET['givingday'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview flag.
			return null;
		}

		$raw = is_string( $_GET['givingday'] ) ? sanitize_key( wp_unslash( $_GET['givingday'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::PREVIEW_MAP[ $raw ] ?? null;
	}

	/**
	 * Resolves a campaign's current status.
	 *
	 * @param int         $campaign_id      Campaign post ID.
	 * @param string|null $preview_override Optional explicit preview value
	 *                                      (e.g. coming from REST query arg).
	 *                                      When null, the request override is used.
	 * @return string One of SCHEDULED / LIVE / ENDED / IDLE.
	 */
	public static function resolve( int $campaign_id, ?string $preview_override = null ): string {
		$override = $preview_override ?? self::preview_override_from_request();
		if ( null !== $override ) {
			return $override;
		}

		$meta = get_post_meta( $campaign_id, Campaign::META_STATUS_OVERRIDE, true );
		if ( is_string( $meta ) && in_array( $meta, array( self::SCHEDULED, self::LIVE, self::ENDED ), true ) ) {
			return $meta;
		}

		return self::compute_from_dates( $campaign_id );
	}

	/**
	 * Computes status by comparing the current server time to the campaign
	 * datetimes. Returns IDLE when the pre-event window hasn't opened yet.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return string
	 */
	public static function compute_from_dates( int $campaign_id ): string {
		$now       = time();
		$pre_start = self::parse_timestamp( get_post_meta( $campaign_id, Campaign::META_PRE_EVENT_START, true ) );
		$start     = self::parse_timestamp( get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true ) );
		$end       = self::parse_timestamp( get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true ) );

		if ( null !== $end && $now >= $end ) {
			return self::ENDED;
		}
		if ( null !== $start && $now >= $start ) {
			return self::LIVE;
		}
		if ( null !== $pre_start && $now >= $pre_start ) {
			return self::SCHEDULED;
		}
		// Fallback: if there's a start in the future but no pre-event window, still show Scheduled.
		if ( null !== $start && $now < $start ) {
			return self::SCHEDULED;
		}

		return self::IDLE;
	}

	/**
	 * Parses an ISO 8601 / strtotime-compatible string into a unix timestamp.
	 *
	 * @param mixed $value Raw meta value.
	 * @return int|null
	 */
	private static function parse_timestamp( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : $ts;
	}
}
