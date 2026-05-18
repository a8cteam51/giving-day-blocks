<?php
/**
 * Post-event results snapshot scheduler + adjustment listener.
 *
 * Owns the lifecycle of {@see Aggregator::META_RESULTS_SNAPSHOT}:
 *
 *   - Detects when a Campaign has passed `end_datetime + grace_hours` and
 *     locks the snapshot in via {@see Aggregator::snapshot_campaign()}.
 *   - Listens to WC order status changes and offline-donation additions
 *     and appends an entry to the adjustments log when those changes
 *     happen *after* the snapshot, so the promised total is preserved.
 *
 * The grace window default is 24 hours. Sites can override it sitewide via
 * the {@see self::OPTION_GRACE_HOURS} option (set by the Settings page) or
 * per-campaign via the `giving_day_snapshot_grace_hours` filter.
 *
 * @package Team51\GivingDay\Services
 * @since   0.4.0
 */

namespace Team51\GivingDay\Services;

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\Services\OfflineDonations as OfflineService;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton-style hook bundle for the snapshot lifecycle.
 */
final class ResultsSnapshot {

	/**
	 * Option name holding the sitewide grace window (in hours) between an
	 * event's end_datetime and the moment its snapshot locks in.
	 */
	public const OPTION_GRACE_HOURS = 'giving_day_snapshot_grace_hours';

	/**
	 * Default grace window when the option is unset.
	 */
	public const DEFAULT_GRACE_HOURS = 24;

	/**
	 * Transient throttling the `init` due-scan to once per
	 * {@see self::SCAN_INTERVAL_SECONDS}, so dropping a page does not run
	 * the scan on every request.
	 */
	private const SCAN_LOCK_TRANSIENT = 'giving_day_snapshot_scan_lock';

	/**
	 * How often the due-scan runs at most (5 minutes). Anything faster
	 * does not buy you anything — the soonest a campaign can become "due"
	 * after this fires is grace_hours * 3600 seconds.
	 */
	private const SCAN_INTERVAL_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Order statuses that count toward aggregates. Mirrors
	 * {@see Aggregator::COUNTING_STATUSES} — duplicated here as a private
	 * to avoid widening Aggregator's API for an implementation detail.
	 */
	private const COUNTING_STATUSES = array( 'processing', 'completed' );

	/**
	 * Wires every hook this service owns. Idempotent.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'maybe_scan_due_campaigns' ), 1500 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_order_status_changed' ), 30, 3 );
		add_action( 'giving_day_offline_donation_recorded', array( self::class, 'on_offline_donation_recorded' ), 10, 2 );
	}

	/**
	 * Returns the grace window for a campaign, in seconds.
	 *
	 * Per-campaign filter `giving_day_snapshot_grace_hours` runs last so a
	 * site can short-circuit the grace for, say, an internal dry-run
	 * campaign without changing the global default.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function grace_seconds( int $campaign_id ): int {
		$default = self::DEFAULT_GRACE_HOURS;
		$option  = get_option( self::OPTION_GRACE_HOURS, $default );
		$hours   = is_numeric( $option ) ? (int) $option : $default;

		/**
		 * Filters the grace window (in hours) between an event's `end_datetime`
		 * and the moment its snapshot locks in.
		 *
		 * @param int $hours       Current grace window in hours.
		 * @param int $campaign_id Campaign post ID.
		 */
		$hours = (int) apply_filters( 'giving_day_snapshot_grace_hours', $hours, $campaign_id );
		if ( $hours < 0 ) {
			$hours = 0;
		}
		return $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Reads a campaign's `end_datetime` meta as a Unix timestamp (UTC), or
	 * null when blank/unparseable.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function end_timestamp( int $campaign_id ): ?int {
		$raw = (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		return false === $ts ? null : $ts;
	}

	/**
	 * Returns the UTC Unix timestamp at which a campaign becomes eligible
	 * for automatic snapshot, or null when end_datetime is missing.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function due_timestamp( int $campaign_id ): ?int {
		$end = self::end_timestamp( $campaign_id );
		if ( null === $end ) {
			return null;
		}
		return $end + self::grace_seconds( $campaign_id );
	}

	/**
	 * Throttled scanner that locks in snapshots for any campaign whose
	 * `end_datetime + grace_hours` has passed. Cheap: a single meta-aware
	 * query for published Campaigns + a per-campaign check; bounded by
	 * the actual number of past Campaigns on the site.
	 */
	public static function maybe_scan_due_campaigns(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( get_transient( self::SCAN_LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::SCAN_LOCK_TRANSIENT, 1, self::SCAN_INTERVAL_SECONDS );

		self::run_due_scan();
	}

	/**
	 * Snapshots every Campaign whose due timestamp is in the past and
	 * which does not yet have a snapshot. Exposed so a CLI command or an
	 * admin button can force a sweep without waiting for the throttle.
	 *
	 * @return int Number of snapshots taken.
	 */
	public static function run_due_scan(): int {
		$ids = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( empty( $ids ) ) {
			return 0;
		}

		$now   = time();
		$taken = 0;
		foreach ( $ids as $id ) {
			$cid = (int) $id;
			if ( Aggregator::has_snapshot( $cid ) ) {
				continue;
			}
			$due = self::due_timestamp( $cid );
			if ( null === $due || $due > $now ) {
				continue;
			}
			Aggregator::snapshot_campaign( $cid );
			++$taken;
		}
		return $taken;
	}

	/**
	 * Adjustment listener for WC order status transitions.
	 *
	 * Aggregator::on_order_status_changed already bumps the live cache, but
	 * once a snapshot is in place we want refunds and re-completions to be
	 * recorded as deltas against the frozen total instead. Runs at priority
	 * 30 so it fires after the live invalidator at priority 20.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $status_from Previous status.
	 * @param string $status_to   New status.
	 */
	public static function on_order_status_changed( int $order_id, string $status_from, string $status_to ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$campaign_id = (int) $order->get_meta( OrderAttribution::META_CAMPAIGN_ID );
		if ( $campaign_id <= 0 || ! Aggregator::has_snapshot( $campaign_id ) ) {
			return;
		}

		$was_counting = in_array( $status_from, self::COUNTING_STATUSES, true );
		$now_counting = in_array( $status_to, self::COUNTING_STATUSES, true );
		if ( $was_counting === $now_counting ) {
			return;
		}

		$donation_amount = Aggregator::donation_total_for_order( $order );
		if ( $donation_amount <= 0 ) {
			return;
		}
		$sign = $now_counting ? 1 : -1;

		Aggregator::record_adjustment(
			$campaign_id,
			array(
				'type'           => $now_counting ? 'restore' : 'refund',
				'delta_amount'   => $sign * $donation_amount,
				'delta_count'    => $sign * 1,
				'beneficiary_id' => (int) $order->get_meta( OrderAttribution::META_BENEFICIARY_ID ),
				'team_id'        => (int) $order->get_meta( OrderAttribution::META_TEAM_ID ),
				'order_id'       => $order_id,
				'reason'         => sprintf( '%s → %s', $status_from, $status_to ),
			)
		);
	}

	/**
	 * Adjustment listener for offline donations recorded after the snapshot.
	 *
	 * Fired by {@see OfflineService::create_order()} via the
	 * `giving_day_offline_donation_recorded` action. Until that action is
	 * added to the service, this handler stays inert — the snapshot
	 * scanner will still pick up any missed offline donation on the next
	 * pass since offline donations also bump the live cache version.
	 *
	 * @param int $order_id    Newly created WC order ID.
	 * @param int $campaign_id Campaign the donation was attributed to.
	 */
	public static function on_offline_donation_recorded( int $order_id, int $campaign_id ): void {
		if ( $campaign_id <= 0 || ! Aggregator::has_snapshot( $campaign_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$donation_amount = Aggregator::donation_total_for_order( $order );
		if ( $donation_amount <= 0 ) {
			return;
		}

		Aggregator::record_adjustment(
			$campaign_id,
			array(
				'type'           => 'offline_added',
				'delta_amount'   => $donation_amount,
				'delta_count'    => 1,
				'beneficiary_id' => (int) $order->get_meta( OrderAttribution::META_BENEFICIARY_ID ),
				'team_id'        => (int) $order->get_meta( OrderAttribution::META_TEAM_ID ),
				'order_id'       => $order_id,
				'reason'         => (string) $order->get_meta( OfflineService::META_TENDER ),
			)
		);
	}
}
