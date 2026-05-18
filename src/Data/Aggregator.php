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
use Team51\GivingDay\Services\OfflineDonations;
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
	 * Campaign post_meta key holding the frozen post-event results snapshot.
	 *
	 * Written by {@see self::snapshot_campaign()} once an event has ended and
	 * the configured grace window has passed. The payload is a JSON-encodable
	 * array containing campaign-wide totals, per-beneficiary totals + donor
	 * lists, and per-team totals + donor lists — see the method's docblock.
	 *
	 * Existence of this meta flips read paths to "frozen at snapshot time +
	 * adjustments" semantics (PLAN.md § Phase 11).
	 */
	public const META_RESULTS_SNAPSHOT = '_giving_results_snapshot';

	/**
	 * Campaign post_meta key holding the append-only adjustments log written
	 * after the snapshot is taken. Each entry captures a delta to a single
	 * beneficiary / team / campaign-wide total — see
	 * {@see self::record_adjustment()} for the shape.
	 *
	 * The snapshot is never mutated in place; adjustments are layered on at
	 * read time inside {@see self::apply_adjustments()}, so the snapshot
	 * preserves the "as promised to the partner" total for audit.
	 */
	public const META_RESULTS_ADJUSTMENTS = '_giving_results_adjustments_log';

	/**
	 * Wildcard sentinel used for adjustments that affect *only* the
	 * campaign-wide totals — e.g. a donation never tagged to a beneficiary
	 * or team. Stored on the adjustment entry's `beneficiary_id` / `team_id`
	 * to differentiate "applies to no specific entity" from "applies to
	 * everything."
	 */
	public const ADJUSTMENT_ENTITY_NONE = 0;

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
	 * After {@see self::snapshot_campaign()} has run for the campaign, the
	 * frozen `campaign` block from the snapshot is returned with any
	 * post-snapshot adjustments applied. This means refunds, late offline
	 * donations, and manual corrections recorded after the event do *not*
	 * silently rewrite the promised total — the snapshot stays as-is and
	 * adjustments are layered on read.
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
		$snapshot = self::read_snapshot( $campaign_id );
		if ( null !== $snapshot ) {
			return self::totals_from_snapshot( $campaign_id, $snapshot );
		}

		$ver = self::cache_version( $campaign_id );
		$key = sprintf( '%s%d_%d', self::TOTALS_TRANSIENT_PREFIX, $ver, $campaign_id );

		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$payload = self::compute_campaign_totals_live( $campaign_id );

		set_transient( $key, $payload, self::cache_ttl_seconds( $campaign_id, $preview_override ) );

		return $payload;
	}

	/**
	 * Live (un-snapshotted) computation of campaign-wide totals. Extracted
	 * from {@see self::totals_for_campaign()} so {@see self::snapshot_campaign()}
	 * and the snapshot read path can share one implementation.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed>
	 */
	private static function compute_campaign_totals_live( int $campaign_id ): array {
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

		return array(
			'campaign_id'   => $campaign_id,
			'raised'        => round( $raised, 2 ),
			'count'         => $count,
			'avg'           => $count > 0 ? round( $raised / $count, 2 ) : 0.0,
			'unique_donors' => count( $donor_keys ),
			'currency'      => $currency,
			'server_time'   => gmdate( 'c' ),
		);
	}

	/**
	 * Reads the frozen results snapshot for a campaign, or null when one
	 * has not been recorded yet.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed>|null
	 */
	public static function read_snapshot( int $campaign_id ): ?array {
		if ( $campaign_id <= 0 ) {
			return null;
		}
		$raw = get_post_meta( $campaign_id, self::META_RESULTS_SNAPSHOT, true );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Reads the append-only adjustments log for a campaign.
	 *
	 * Each adjustment is stored as its own non-unique post_meta row so
	 * concurrent writes via {@see self::record_adjustment()} can't race
	 * — two simultaneous handlers (e.g. a refund + a late offline donation
	 * landing in the same request cycle) both `INSERT` and neither
	 * clobbers the other. WP unserializes each value, so
	 * `get_post_meta( …, false )` returns a flat list of adjustment arrays
	 * in insertion (meta_id) order, which is also the chronological order
	 * the admin breakdown displays.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function read_adjustments( int $campaign_id ): array {
		if ( $campaign_id <= 0 ) {
			return array();
		}
		$raw = get_post_meta( $campaign_id, self::META_RESULTS_ADJUSTMENTS, false );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( $raw, 'is_array' ) );
	}

	/**
	 * Returns true when the campaign has been snapshotted.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function has_snapshot( int $campaign_id ): bool {
		return null !== self::read_snapshot( $campaign_id );
	}

	/**
	 * Computes and persists the per-campaign post-event results snapshot.
	 *
	 * Iterates every attributed order in the campaign once and produces:
	 *
	 *   - Campaign-wide totals: raised, count, avg, unique_donors.
	 *   - Per-beneficiary buckets: raised, donor count, average gift, donor list
	 *     (display name + amount + order_date; anonymous when the order carries
	 *     `_wpcomsp_donation_anonymous = 'yes'`).
	 *   - Per-team buckets: same shape as beneficiaries.
	 *
	 * Idempotent: when a snapshot already exists, returns it unchanged unless
	 * `$force` is true (used by the "Re-snapshot from live data" admin action,
	 * which also writes a `manual_correction` adjustment log entry capturing
	 * the deltas — handled by the caller).
	 *
	 * @param int  $campaign_id Campaign post ID.
	 * @param bool $force       When true, overwrites an existing snapshot.
	 * @return array<string, mixed>|null Snapshot payload on success; null when the campaign does not exist.
	 */
	public static function snapshot_campaign( int $campaign_id, bool $force = false ): ?array {
		if ( $campaign_id <= 0 || Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return null;
		}

		$existing = self::read_snapshot( $campaign_id );
		if ( null !== $existing && ! $force ) {
			return $existing;
		}

		$raised     = 0.0;
		$count      = 0;
		$donor_keys = array();

		$beneficiaries = array();
		$teams         = array();

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

			$beneficiary_id = (int) $order->get_meta( OrderAttribution::META_BENEFICIARY_ID );
			$team_id        = (int) $order->get_meta( OrderAttribution::META_TEAM_ID );

			$donor_entry = self::build_donor_list_entry( $order, $donation_total );

			// Always bucket the donation, even when the donor never picked a
			// Beneficiary / Team — the unassigned bucket (id=0) makes the
			// per-entity totals add up to the campaign total. Without it the
			// breakdown silently drops residual donations.
			self::accumulate_entity_bucket( $beneficiaries, $beneficiary_id, $donation_total, $donor_key, $donor_entry );
			self::accumulate_entity_bucket( $teams, $team_id, $donation_total, $donor_key, $donor_entry );
		}

		$currency_meta = (string) get_post_meta( $campaign_id, Campaign::META_CURRENCY, true );
		$currency      = '' !== $currency_meta ? $currency_meta : (string) get_option( 'woocommerce_currency', 'USD' );
		$now           = gmdate( 'c' );

		$payload = array(
			'snapshot_locked_at' => $now,
			'server_time'        => $now,
			'currency'           => $currency,
			'campaign'           => array(
				'campaign_id'   => $campaign_id,
				'raised'        => round( $raised, 2 ),
				'count'         => $count,
				'avg'           => $count > 0 ? round( $raised / $count, 2 ) : 0.0,
				'unique_donors' => count( $donor_keys ),
				'currency'      => $currency,
			),
			'beneficiaries'      => self::finalize_entity_buckets( $beneficiaries ),
			'teams'              => self::finalize_entity_buckets( $teams ),
		);

		update_post_meta( $campaign_id, self::META_RESULTS_SNAPSHOT, $payload );

		// Snapshot reads bypass the transient layer, but downstream callers
		// (e.g. Goal Progress blocks) keyed off the live cache also need to
		// see the change immediately. Bumping the version is cheap insurance.
		self::invalidate( $campaign_id );

		return $payload;
	}

	/**
	 * Appends an entry to the campaign's adjustments log. Entries are
	 * applied on read by {@see self::apply_adjustments()} so the snapshot's
	 * "as promised" total is preserved while current values reflect post-snapshot
	 * activity (refunds, late offline donations, manual corrections).
	 *
	 * Recognized `type` values:
	 *   - `refund`             — order moved out of a counting status after the snapshot.
	 *   - `restore`            — refunded order returned to a counting status.
	 *   - `offline_added`      — offline donation recorded after the snapshot.
	 *   - `manual_correction`  — organizer-recorded correction (e.g. re-snapshot diff).
	 *
	 * @param int                 $campaign_id Campaign post ID.
	 * @param array<string,mixed> $entry       Adjustment payload — must include `type`,
	 *                                         `delta_amount`. Optional: `delta_count`,
	 *                                         `beneficiary_id`, `team_id`, `reason`,
	 *                                         `order_id`.
	 */
	public static function record_adjustment( int $campaign_id, array $entry ): void {
		if ( $campaign_id <= 0 ) {
			return;
		}
		if ( ! self::has_snapshot( $campaign_id ) ) {
			// Adjustments only have meaning after a snapshot exists — before
			// that, regular cache invalidation captures the change.
			return;
		}

		$type = isset( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : '';
		if ( '' === $type ) {
			return;
		}

		$normalized = array(
			'at'             => gmdate( 'c' ),
			'type'           => $type,
			'delta_amount'   => isset( $entry['delta_amount'] ) ? round( (float) $entry['delta_amount'], 2 ) : 0.0,
			'delta_count'    => isset( $entry['delta_count'] ) ? (int) $entry['delta_count'] : 0,
			'beneficiary_id' => isset( $entry['beneficiary_id'] ) ? absint( $entry['beneficiary_id'] ) : self::ADJUSTMENT_ENTITY_NONE,
			'team_id'        => isset( $entry['team_id'] ) ? absint( $entry['team_id'] ) : self::ADJUSTMENT_ENTITY_NONE,
			'order_id'       => isset( $entry['order_id'] ) ? absint( $entry['order_id'] ) : 0,
			'reason'         => isset( $entry['reason'] ) ? sanitize_text_field( (string) $entry['reason'] ) : '',
		);

		// Atomic append as a non-unique meta row. The previous read-modify-write
		// of a single serialized array could lose entries when two adjustment
		// handlers (e.g. a refund + a late offline donation) ran concurrently:
		// both would read the same prior log, push their entry, and the second
		// write would clobber the first. add_post_meta is a single INSERT,
		// so concurrent writers all land.
		add_post_meta( $campaign_id, self::META_RESULTS_ADJUSTMENTS, $normalized, false );

		// Force a fresh read on the next totals lookup; transients holding
		// pre-adjustment numbers must not win.
		self::invalidate( $campaign_id );
	}

	/**
	 * Returns the full results payload for a campaign: campaign totals,
	 * per-beneficiary breakdown (with donor list), per-team breakdown.
	 *
	 * When a snapshot is present, the snapshot is returned with adjustments
	 * applied to totals (donor lists are never rewritten — they reflect the
	 * snapshot moment, with adjustments visible as a separate audit log).
	 * Otherwise the payload is computed live by running
	 * {@see self::snapshot_campaign()} *without persisting it* so callers
	 * (admin breakdown screen, CSV export, REST) always have a consistent
	 * shape regardless of snapshot state.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed>
	 */
	public static function results_for_campaign( int $campaign_id ): array {
		$snapshot = self::read_snapshot( $campaign_id );
		if ( null !== $snapshot ) {
			$snapshot['adjustments'] = self::read_adjustments( $campaign_id );
			$snapshot['current']     = array(
				'campaign'      => self::totals_from_snapshot( $campaign_id, $snapshot ),
				'beneficiaries' => self::apply_entity_adjustments( $snapshot['beneficiaries'] ?? array(), $snapshot['adjustments'], 'beneficiary_id' ),
				'teams'         => self::apply_entity_adjustments( $snapshot['teams'] ?? array(), $snapshot['adjustments'], 'team_id' ),
			);
			$snapshot['server_time'] = gmdate( 'c' );
			return $snapshot;
		}

		return self::compute_results_live( $campaign_id );
	}

	/**
	 * Live (un-snapshotted) full results payload. Identical shape to a
	 * snapshot so consumers can treat both the same.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed>
	 */
	private static function compute_results_live( int $campaign_id ): array {
		$raised     = 0.0;
		$count      = 0;
		$donor_keys = array();

		$beneficiaries = array();
		$teams         = array();

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

			$beneficiary_id = (int) $order->get_meta( OrderAttribution::META_BENEFICIARY_ID );
			$team_id        = (int) $order->get_meta( OrderAttribution::META_TEAM_ID );

			$donor_entry = self::build_donor_list_entry( $order, $donation_total );

			// id=0 = "Unassigned" — keeps the per-entity totals adding up to
			// the campaign total instead of silently dropping residuals.
			self::accumulate_entity_bucket( $beneficiaries, $beneficiary_id, $donation_total, $donor_key, $donor_entry );
			self::accumulate_entity_bucket( $teams, $team_id, $donation_total, $donor_key, $donor_entry );
		}

		$currency_meta = (string) get_post_meta( $campaign_id, Campaign::META_CURRENCY, true );
		$currency      = '' !== $currency_meta ? $currency_meta : (string) get_option( 'woocommerce_currency', 'USD' );
		$now           = gmdate( 'c' );

		return array(
			'snapshot_locked_at' => null,
			'server_time'        => $now,
			'currency'           => $currency,
			'campaign'           => array(
				'campaign_id'   => $campaign_id,
				'raised'        => round( $raised, 2 ),
				'count'         => $count,
				'avg'           => $count > 0 ? round( $raised / $count, 2 ) : 0.0,
				'unique_donors' => count( $donor_keys ),
				'currency'      => $currency,
			),
			'beneficiaries'      => self::finalize_entity_buckets( $beneficiaries ),
			'teams'              => self::finalize_entity_buckets( $teams ),
			'adjustments'        => array(),
			'current'            => null,
		);
	}

	/**
	 * Builds the per-donor row that goes into a beneficiary's or team's donor
	 * list. Display name is `{First} {L}.` unless the order is flagged
	 * anonymous via `_wpcomsp_donation_anonymous = 'yes'` (the meta key
	 * team51-donations already writes), in which case the donor renders as
	 * "Anonymous" and no name fields are stored.
	 *
	 * Email addresses are never stored in the snapshot or returned here.
	 *
	 * @param WC_Order $order   The donation order.
	 * @param float    $amount  Donation portion of the order total.
	 * @return array<string, mixed>
	 */
	private static function build_donor_list_entry( WC_Order $order, float $amount ): array {
		$is_anonymous = 'yes' === (string) $order->get_meta( '_wpcomsp_donation_anonymous' );
		$created      = $order->get_date_created();
		$created_iso  = $created instanceof \WC_DateTime ? $created->date( 'c' ) : '';

		if ( $is_anonymous ) {
			return array(
				'order_id'     => $order->get_id(),
				'display_name' => __( 'Anonymous', 'giving-day-blocks' ),
				'anonymous'    => true,
				'amount'       => round( $amount, 2 ),
				'order_date'   => $created_iso,
			);
		}

		$first        = trim( (string) $order->get_billing_first_name() );
		$last         = trim( (string) $order->get_billing_last_name() );
		$last_initial = '' !== $last ? mb_substr( $last, 0, 1 ) : '';

		if ( '' === $first && '' === $last ) {
			$display = __( 'Donor', 'giving-day-blocks' );
		} elseif ( '' === $last_initial ) {
			$display = $first;
		} else {
			$display = sprintf( '%s %s.', $first, $last_initial );
		}

		return array(
			'order_id'     => $order->get_id(),
			'display_name' => $display,
			'anonymous'    => false,
			'amount'       => round( $amount, 2 ),
			'order_date'   => $created_iso,
		);
	}

	/**
	 * Accumulates one order's contribution into a per-entity bucket
	 * (beneficiary or team). Buckets are keyed by entity ID and carry a
	 * running raised total, donor-key set, and donor list.
	 *
	 * @param array<int, array<string,mixed>> $buckets    Mutable bucket map keyed by entity ID.
	 * @param int                             $entity_id  Beneficiary or Team post ID.
	 * @param float                           $amount     Donation amount.
	 * @param string|null                     $donor_key  Result of donor_key_for_order, used for unique-donor count.
	 * @param array<string,mixed>             $donor_row  One row for the donor list.
	 */
	private static function accumulate_entity_bucket( array &$buckets, int $entity_id, float $amount, ?string $donor_key, array $donor_row ): void {
		if ( ! isset( $buckets[ $entity_id ] ) ) {
			$buckets[ $entity_id ] = array(
				'id'         => $entity_id,
				'raised'     => 0.0,
				'count'      => 0,
				'donor_keys' => array(),
				'donors'     => array(),
			);
		}
		$buckets[ $entity_id ]['raised'] += $amount;
		++$buckets[ $entity_id ]['count'];
		if ( null !== $donor_key ) {
			$buckets[ $entity_id ]['donor_keys'][ $donor_key ] = true;
		}
		$buckets[ $entity_id ]['donors'][] = $donor_row;
	}

	/**
	 * Converts the working bucket map into the storage shape: title resolved,
	 * donor list sorted by amount desc, unique_donors collapsed from the
	 * working key map, donor_keys dropped.
	 *
	 * @param array<int, array<string,mixed>> $buckets Mutable bucket map keyed by entity ID.
	 * @return array<int, array<string,mixed>>
	 */
	private static function finalize_entity_buckets( array $buckets ): array {
		$out = array();
		foreach ( $buckets as $entity_id => $bucket ) {
			$donors = $bucket['donors'];
			usort(
				$donors,
				static function ( $a, $b ) {
					if ( ( $a['amount'] ?? 0 ) === ( $b['amount'] ?? 0 ) ) {
						return 0;
					}
					return ( $b['amount'] ?? 0 ) <=> ( $a['amount'] ?? 0 );
				}
			);

			$out[] = array(
				'id'            => (int) $entity_id,
				'title'         => self::entity_title( (int) $entity_id ),
				'raised'        => round( (float) $bucket['raised'], 2 ),
				'count'         => (int) $bucket['count'],
				'avg'           => $bucket['count'] > 0 ? round( $bucket['raised'] / $bucket['count'], 2 ) : 0.0,
				'unique_donors' => count( $bucket['donor_keys'] ),
				'donors'        => $donors,
			);
		}

		// Sort by amount desc; unassigned (id=0) always sinks to the bottom
		// regardless of size so it never overshadows real beneficiaries / teams.
		usort(
			$out,
			static function ( $a, $b ) {
				$a_unassigned = 0 === (int) ( $a['id'] ?? 0 );
				$b_unassigned = 0 === (int) ( $b['id'] ?? 0 );
				if ( $a_unassigned !== $b_unassigned ) {
					return $a_unassigned ? 1 : -1;
				}
				if ( $a['raised'] === $b['raised'] ) {
					return strcmp( (string) $a['title'], (string) $b['title'] );
				}
				return $b['raised'] <=> $a['raised'];
			}
		);

		return $out;
	}

	/**
	 * Resolves the display title for an entity bucket. For id=0 (the
	 * synthetic "Unassigned" bucket) we render a localized label rather
	 * than calling `get_the_title(0)`, which would resolve to whatever
	 * post happens to be in the loop and produce nonsense.
	 *
	 * @param int $entity_id Beneficiary / Team post ID, or 0 for the unassigned bucket.
	 */
	private static function entity_title( int $entity_id ): string {
		if ( $entity_id <= 0 ) {
			return __( 'Unassigned', 'giving-day-blocks' );
		}
		return (string) get_the_title( $entity_id );
	}

	/**
	 * Returns the campaign-wide totals block with adjustments applied.
	 *
	 * @param int                 $campaign_id Campaign post ID.
	 * @param array<string,mixed> $snapshot    Raw snapshot payload.
	 * @return array<string,mixed>
	 */
	private static function totals_from_snapshot( int $campaign_id, array $snapshot ): array {
		$campaign = isset( $snapshot['campaign'] ) && is_array( $snapshot['campaign'] ) ? $snapshot['campaign'] : array();

		$adjustments  = self::read_adjustments( $campaign_id );
		$delta_amount = 0.0;
		$delta_count  = 0;
		foreach ( $adjustments as $entry ) {
			$delta_amount += isset( $entry['delta_amount'] ) ? (float) $entry['delta_amount'] : 0.0;
			$delta_count  += isset( $entry['delta_count'] ) ? (int) $entry['delta_count'] : 0;
		}

		$raised = (float) ( $campaign['raised'] ?? 0 ) + $delta_amount;
		$count  = (int) ( $campaign['count'] ?? 0 ) + $delta_count;

		return array(
			'campaign_id'   => $campaign_id,
			'raised'        => round( $raised, 2 ),
			'count'         => $count,
			'avg'           => $count > 0 ? round( $raised / $count, 2 ) : 0.0,
			'unique_donors' => (int) ( $campaign['unique_donors'] ?? 0 ),
			'currency'      => (string) ( $campaign['currency'] ?? $snapshot['currency'] ?? '' ),
			'server_time'   => gmdate( 'c' ),
		);
	}

	/**
	 * Layers per-entity adjustments on top of the frozen snapshot rows.
	 * Returns rows whose `raised` and `count` reflect the snapshot total plus
	 * any post-snapshot deltas attributed to that entity.
	 *
	 * @param list<array<string,mixed>> $rows        Snapshot rows for the entity type.
	 * @param list<array<string,mixed>> $adjustments Adjustment log entries.
	 * @param string                    $id_key      `beneficiary_id` or `team_id`.
	 * @return list<array<string,mixed>>
	 */
	private static function apply_entity_adjustments( array $rows, array $adjustments, string $id_key ): array {
		if ( empty( $adjustments ) ) {
			return $rows;
		}
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) ( $row['id'] ?? 0 ) ] = $row;
		}
		foreach ( $adjustments as $entry ) {
			if ( ! array_key_exists( $id_key, $entry ) ) {
				continue;
			}
			$entity_id = (int) $entry[ $id_key ];
			if ( $entity_id < 0 ) {
				continue;
			}
			if ( ! isset( $by_id[ $entity_id ] ) ) {
				$by_id[ $entity_id ] = array(
					'id'            => $entity_id,
					'title'         => self::entity_title( $entity_id ),
					'raised'        => 0.0,
					'count'         => 0,
					'avg'           => 0.0,
					'unique_donors' => 0,
					'donors'        => array(),
				);
			}
			$by_id[ $entity_id ]['raised'] = round(
				(float) ( $by_id[ $entity_id ]['raised'] ?? 0 ) + (float) ( $entry['delta_amount'] ?? 0 ),
				2
			);
			$by_id[ $entity_id ]['count']  = (int) ( $by_id[ $entity_id ]['count'] ?? 0 ) + (int) ( $entry['delta_count'] ?? 0 );
			$by_id[ $entity_id ]['avg']    = $by_id[ $entity_id ]['count'] > 0
				? round( $by_id[ $entity_id ]['raised'] / $by_id[ $entity_id ]['count'], 2 )
				: 0.0;
		}
		$out = array_values( $by_id );
		usort(
			$out,
			static function ( $a, $b ) {
				$a_unassigned = 0 === (int) ( $a['id'] ?? 0 );
				$b_unassigned = 0 === (int) ( $b['id'] ?? 0 );
				if ( $a_unassigned !== $b_unassigned ) {
					return $a_unassigned ? 1 : -1;
				}
				return ( $b['raised'] ?? 0 ) <=> ( $a['raised'] ?? 0 );
			}
		);
		return $out;
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

		$base_args = array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => self::COUNTING_STATUSES,
			'meta_key'   => OrderAttribution::META_CAMPAIGN_ID,
			'meta_value' => (string) $campaign_id,
			'orderby'    => 'date',
			'order'      => 'DESC',
		);

		// In-window orders: date_created scoped to the campaign event window.
		$args  = $base_args;
		$start = self::campaign_order_date_boundary( $campaign_id, Campaign::META_START_DATETIME );
		$end   = self::campaign_order_date_boundary( $campaign_id, Campaign::META_END_DATETIME );
		if ( null !== $start && null !== $end ) {
			$args['date_created'] = $start . '...' . $end;
		}
		$in_window_ids = wc_get_orders( $args );
		$in_window_ids = is_array( $in_window_ids ) ? $in_window_ids : array();

		// Offline-flagged orders bypass the window: admins explicitly attribute
		// them to the campaign, so a Saturday gala donation entered on Monday
		// (or any backdated/late entry) still counts.
		$offline_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'status'     => self::COUNTING_STATUSES,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => OrderAttribution::META_CAMPAIGN_ID,
						'value' => (string) $campaign_id,
					),
					array(
						'key'   => OfflineDonations::META_OFFLINE_FLAG,
						'value' => '1',
					),
				),
			)
		);
		$offline_ids = is_array( $offline_ids ) ? $offline_ids : array();

		$ids = array_values( array_unique( array_map( 'intval', array_merge( $in_window_ids, $offline_ids ) ) ) );

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
