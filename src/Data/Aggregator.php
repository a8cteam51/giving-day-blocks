<?php
/**
 * WC order aggregation for Giving Day campaigns.
 *
 * The Aggregator is the single source of truth for "how much did this
 * campaign raise, from how many donors, in how many donations" — computed
 * by querying WooCommerce orders tagged with `_giving_campaign_id` (and
 * the related `_giving_team_id` / `_giving_beneficiary_id` meta written
 * by {@see \Team51\GivingDay\Integrations\OrderAttribution}).
 *
 * It also exposes the order-iteration / donation-line-item / donor-key
 * helpers the {@see Leaderboard} class needs, so both consumers share one
 * WC_Order_Query path and one cache invalidation key.
 *
 * Cache strategy:
 *
 *   - A per-campaign version integer in `option:giving_day_lb_ver_{id}`
 *     is bumped on `woocommerce_order_status_changed` for any tagged
 *     order. The version is included in every transient key, so a single
 *     bump invalidates every aggregate (totals + every leaderboard slice)
 *     for that campaign without iterating transients.
 *   - Per-payload transients (totals: `gd_totals_*`; leaderboard: `gd_lb_*`)
 *     hold the computed payload for `cache_ttl_seconds()` — 15s live, 5m
 *     scheduled, 24h ended (matching PLAN.md § 4.3).
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Campaign;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

final class Aggregator {

	/**
	 * Option-name prefix for the per-campaign cache version integer.
	 *
	 * Kept on the legacy `lb` (leaderboard) prefix for backwards compat —
	 * the same key is now consumed by every aggregate (totals + every
	 * leaderboard slice). Renaming would force a one-time cache miss on
	 * every existing site for no functional gain.
	 */
	private const VERSION_OPTION_PREFIX = 'giving_day_lb_ver_';

	/**
	 * Transient prefix for `totals_for_campaign()` payloads.
	 */
	private const TOTALS_TRANSIENT_PREFIX = 'gd_totals_';

	/**
	 * Order statuses that contribute to aggregates. Matches WooCommerce's
	 * own "paid" set: `processing` and `completed`.
	 */
	private const COUNTING_STATUSES = array( 'processing', 'completed' );

	/**
	 * Order-status transitions that should bust aggregate caches. Includes
	 * both "now counts" (processing, completed) and "no longer counts"
	 * (refunded, cancelled, failed) so a refund or void invalidates the
	 * stale total.
	 */
	private const INVALIDATING_STATUSES = array( 'processing', 'completed', 'refunded', 'cancelled', 'failed' );

	/**
	 * Registers cache invalidation. Idempotent.
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_order_status_changed' ), 20, 3 );
	}

	/**
	 * Bumps a campaign's cache version when one of its tagged orders
	 * transitions to a status that affects aggregates.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $status_from Previous status (unused; kept for hook signature).
	 * @param string $status_to   New status.
	 */
	public static function on_order_status_changed( int $order_id, string $status_from, string $status_to ): void {
		unset( $status_from );
		if ( ! in_array( $status_to, self::INVALIDATING_STATUSES, true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$campaign_id = (int) $order->get_meta( OrderAttribution::META_CAMPAIGN_ID );
		if ( $campaign_id > 0 ) {
			self::invalidate( $campaign_id );
		}
	}

	/**
	 * Bumps the cache version for a campaign so every transient that
	 * embeds the version misses on its next read.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function invalidate( int $campaign_id ): void {
		if ( $campaign_id <= 0 ) {
			return;
		}
		$key = self::VERSION_OPTION_PREFIX . $campaign_id;
		$ver = (int) get_option( $key, 0 );
		update_option( $key, $ver + 1, false );
	}

	/**
	 * Returns the current cache version integer for a campaign. Callers
	 * embed it into their own transient keys.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function cache_version( int $campaign_id ): int {
		return (int) get_option( self::VERSION_OPTION_PREFIX . $campaign_id, 0 );
	}

	/**
	 * Resolves the transient TTL in seconds for an aggregate, based on the
	 * campaign's resolved status.
	 *
	 * @param int         $campaign_id      Campaign post ID.
	 * @param string|null $preview_override Optional `?givingday=` mapping for logged-in REST callers.
	 */
	public static function cache_ttl_seconds( int $campaign_id, ?string $preview_override = null ): int {
		$status = Status::resolve( $campaign_id, $preview_override );
		if ( Status::LIVE === $status ) {
			return 15;
		}
		if ( Status::ENDED === $status ) {
			return DAY_IN_SECONDS;
		}
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Computes campaign-wide totals: raised, donation count, average gift,
	 * unique donors, currency. All values reflect real WC orders attributed
	 * to the campaign and in a counting status.
	 *
	 * Shape:
	 *   array{
	 *     campaign_id:   int,
	 *     raised:        float,
	 *     count:         int,
	 *     avg:           float,
	 *     unique_donors: int,
	 *     currency:      string,
	 *     server_time:   string,
	 *   }
	 *
	 * @param int         $campaign_id      Campaign post ID.
	 * @param string|null $preview_override Optional preview status mapping (REST `?givingday=`).
	 * @return array<string, mixed>
	 */
	public static function totals_for_campaign( int $campaign_id, ?string $preview_override = null ): array {
		$ver = self::cache_version( $campaign_id );
		$key = sprintf( '%s%d_%d', self::TOTALS_TRANSIENT_PREFIX, $ver, $campaign_id );

		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$raised     = 0.0;
		$count      = 0;
		$donor_keys = array();

		foreach ( self::each_attributed_order( $campaign_id ) as $order ) {
			$donation_total = self::donation_total_for_order( $order );
			if ( $donation_total <= 0 ) {
				continue;
			}
			$raised += $donation_total;
			++$count;

			$donor_key = self::donor_key_for_order( $order );
			if ( null !== $donor_key ) {
				$donor_keys[ $donor_key ] = true;
			}
		}

		$currency_meta = (string) get_post_meta( $campaign_id, Campaign::META_CURRENCY, true );
		$currency      = '' !== $currency_meta ? $currency_meta : (string) get_option( 'woocommerce_currency', 'USD' );

		$payload = array(
			'campaign_id'   => $campaign_id,
			'raised'        => round( $raised, 2 ),
			'count'         => $count,
			'avg'           => $count > 0 ? round( $raised / $count, 2 ) : 0.0,
			'unique_donors' => count( $donor_keys ),
			'currency'      => $currency,
			'server_time'   => gmdate( 'c' ),
		);

		set_transient( $key, $payload, self::cache_ttl_seconds( $campaign_id, $preview_override ) );

		return $payload;
	}

	/**
	 * Yields every WC order tagged with this campaign's ID and in a
	 * counting status, scoped to the campaign's start/end window when
	 * both meta values are present.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return \Generator<int, WC_Order>
	 */
	public static function each_attributed_order( int $campaign_id ): \Generator {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$args = array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => self::COUNTING_STATUSES,
			'meta_key'   => OrderAttribution::META_CAMPAIGN_ID,
			'meta_value' => (string) $campaign_id,
			'orderby'    => 'date',
			'order'      => 'DESC',
		);

		$start = self::campaign_order_date_boundary( $campaign_id, Campaign::META_START_DATETIME );
		$end   = self::campaign_order_date_boundary( $campaign_id, Campaign::META_END_DATETIME );
		if ( null !== $start && null !== $end ) {
			$args['date_created'] = $start . '...' . $end;
		}

		$ids = wc_get_orders( $args );
		if ( ! is_array( $ids ) ) {
			return;
		}

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				yield $order;
			}
		}
	}

	/**
	 * Sums donation line items for an order. A line item counts as a
	 * donation when `_wpcomsp_donation` is truthy (default for the
	 * team51-donations plugin); when that meta is absent, the item is
	 * treated as a donation by default. The
	 * `giving_day_is_donation_line_item` filter is the pluggable hook
	 * for sites using a different donation source (PLAN.md § 4.2.3).
	 */
	public static function donation_total_for_order( WC_Order $order ): float {
		$total = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$has_donation_flag = metadata_exists( 'order_item', $item->get_id(), '_wpcomsp_donation' );
			$default_donation  = $has_donation_flag ? (bool) $item->get_meta( '_wpcomsp_donation' ) : true;

			/**
			 * Filters whether a line item counts as a donation for Giving Day aggregates.
			 *
			 * @param bool                  $is_donation Default from `_wpcomsp_donation` when set; otherwise true (PLAN.md § 4.2.3).
			 * @param WC_Order_Item_Product $item        Line item.
			 * @param WC_Order              $order       Order.
			 */
			$is_donation = (bool) apply_filters( 'giving_day_is_donation_line_item', $default_donation, $item, $order );
			if ( ! $is_donation ) {
				continue;
			}
			$total += (float) $item->get_total();
		}
		return $total;
	}

	/**
	 * Returns a stable per-donor identity for an order, used to count
	 * unique donors. Logged-in users dedupe by user ID; guest orders
	 * dedupe by lowercased billing email (hashed so the bucket key is
	 * fixed-width). Returns null when the order has neither.
	 */
	public static function donor_key_for_order( WC_Order $order ): ?string {
		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			return 'u:' . $user_id;
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $email ) {
			return null;
		}
		return 'e:' . md5( $email );
	}

	/**
	 * Reads a Campaign datetime meta and returns it as a Unix-timestamp
	 * string suitable for `wc_get_orders`'s `date_created` range syntax.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $meta_key    META_START_DATETIME or META_END_DATETIME.
	 */
	private static function campaign_order_date_boundary( int $campaign_id, string $meta_key ): ?string {
		$raw = (string) get_post_meta( $campaign_id, $meta_key, true );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		return false === $ts ? null : (string) $ts;
	}
}
