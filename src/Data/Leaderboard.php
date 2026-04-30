<?php
/**
 * Campaign leaderboard aggregation from WooCommerce orders.
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamGroup;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Computes leaderboards for a campaign with transient caching.
 */
final class Leaderboard {

	public const DIMENSION_DONORS        = 'top_donors';
	public const DIMENSION_TEAMS        = 'top_teams';
	public const DIMENSION_BENEFICIARIES = 'top_beneficiaries';
	public const DIMENSION_CAUSES      = 'top_causes';

	/**
	 * @var string[]
	 */
	private const DIMENSIONS = array(
		self::DIMENSION_DONORS,
		self::DIMENSION_TEAMS,
		self::DIMENSION_BENEFICIARIES,
		self::DIMENSION_CAUSES,
	);

	/**
	 * Registers cache invalidation hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_order_status_changed' ), 20, 3 );
	}

	/**
	 * Bumps invalidation version when a tagged order transitions to a state that
	 * affects aggregates.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $status_from Previous status.
	 * @param string $status_to   New status.
	 */
	public static function on_order_status_changed( int $order_id, string $status_from, string $status_to ): void {
		unset( $status_from );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! in_array( $status_to, array( 'processing', 'completed', 'refunded', 'cancelled', 'failed' ), true ) ) {
			return;
		}
		$campaign_id = (int) $order->get_meta( OrderAttribution::META_CAMPAIGN_ID );
		if ( $campaign_id > 0 ) {
			self::invalidate( $campaign_id );
		}
	}

	/**
	 * Bumps cache version for a campaign so all leaderboard transients miss.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public static function invalidate( int $campaign_id ): void {
		if ( $campaign_id <= 0 ) {
			return;
		}
		$key = 'giving_day_lb_ver_' . $campaign_id;
		$ver = (int) get_option( $key, 0 );
		update_option( $key, $ver + 1, false );
	}

	/**
	 * Returns leaderboard payload (flat rows or grouped).
	 *
	 * @param int                  $campaign_id      Campaign ID.
	 * @param string               $dimension        One of DIMENSION_* constants.
	 * @param int                  $limit            Max rows per list (1–100).
	 * @param array<string, mixed> $args             filter_term_id, group_by_parent_term_id.
	 * @param string|null          $preview_override Optional status override (same as REST `?givingday=`).
	 * @return array<string, mixed>
	 */
	public static function fetch( int $campaign_id, string $dimension, int $limit, array $args = array(), ?string $preview_override = null ): array {
		if ( ! in_array( $dimension, self::DIMENSIONS, true ) ) {
			return array(
				'error' => 'invalid_dimension',
			);
		}

		$limit = max( 1, min( 100, $limit ) );
		$filter_term_id       = isset( $args['filter_term_id'] ) ? absint( $args['filter_term_id'] ) : 0;
		$group_by_parent_id   = isset( $args['group_by_parent_term_id'] ) ? absint( $args['group_by_parent_term_id'] ) : 0;

		$ver = (int) get_option( 'giving_day_lb_ver_' . $campaign_id, 0 );
		$key = sprintf(
			'gd_lb_%d_%s_%d_%d_%d_%d',
			$ver,
			$dimension,
			$campaign_id,
			$limit,
			$filter_term_id,
			$group_by_parent_id
		);

		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$ttl = self::cache_ttl_seconds( $campaign_id, $preview_override );

		if ( $group_by_parent_id > 0 ) {
			$payload = self::fetch_grouped( $campaign_id, $dimension, $limit, $group_by_parent_id );
		} else {
			$rows    = self::compute_rows( $campaign_id, $dimension, $limit, $filter_term_id );
			$payload = array(
				'campaign_id' => $campaign_id,
				'dimension'   => $dimension,
				'rows'        => $rows,
			);
		}

		$currency_meta = get_post_meta( $campaign_id, Campaign::META_CURRENCY, true );
		$payload['currency']    = (string) ( $currency_meta !== '' ? $currency_meta : get_option( 'woocommerce_currency', 'USD' ) );
		$payload['server_time'] = gmdate( 'c' );

		set_transient( $key, $payload, $ttl );

		return $payload;
	}

	/**
	 * @param int         $campaign_id      Campaign ID.
	 * @param string|null $preview_override Optional preview status for logged-in REST callers.
	 * @return int TTL in seconds.
	 */
	private static function cache_ttl_seconds( int $campaign_id, ?string $preview_override = null ): int {
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
	 * @param int    $campaign_id        Campaign ID.
	 * @param string $dimension         Dimension slug.
	 * @param int    $limit             Row limit.
	 * @param int    $filter_term_id    Optional taxonomy filter.
	 * @param int    $group_by_parent_id Parent term ID for team category grouping.
	 * @return array<string, mixed>
	 */
	private static function fetch_grouped( int $campaign_id, string $dimension, int $limit, int $group_by_parent_id ): array {
		$parent = get_term( $group_by_parent_id, TeamGroup::TAXONOMY );
		if ( ! $parent instanceof \WP_Term || is_wp_error( $parent ) ) {
			return array(
				'campaign_id' => $campaign_id,
				'dimension'   => $dimension,
				'groups'      => array(),
				'error'       => 'invalid_group_parent',
			);
		}

		$children = get_terms(
			array(
				'taxonomy'   => TeamGroup::TAXONOMY,
				'parent'     => $group_by_parent_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$groups = array();
		if ( is_array( $children ) ) {
			foreach ( $children as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$child_filter = $term->term_id;
				$rows         = self::compute_rows( $campaign_id, $dimension, $limit, $child_filter );
				$groups[]     = array(
					'term' => self::serialize_term( $term ),
					'rows' => $rows,
				);
			}
		}

		return array(
			'campaign_id' => $campaign_id,
			'dimension'   => $dimension,
			'groups'      => $groups,
		);
	}

	/**
	 * @param int    $campaign_id     Campaign ID.
	 * @param string $dimension       Dimension.
	 * @param int    $limit           Max rows.
	 * @param int    $filter_term_id  Optional taxonomy filter (team category or cause, by dimension).
	 * @return list<array<string, mixed>>
	 */
	private static function compute_rows( int $campaign_id, string $dimension, int $limit, int $filter_term_id ): array {
		$filter_term_id = self::normalize_filter_term_id( $dimension, $filter_term_id );
		$buckets         = array();

		foreach ( self::each_attributed_order( $campaign_id ) as $order ) {
			$donation_total = self::donation_total_for_order( $order );
			if ( $donation_total <= 0 ) {
				continue;
			}

			$team_id        = (int) $order->get_meta( OrderAttribution::META_TEAM_ID );
			$beneficiary_id = (int) $order->get_meta( OrderAttribution::META_BENEFICIARY_ID );

			switch ( $dimension ) {
				case self::DIMENSION_DONORS:
					if ( $filter_term_id > 0 ) {
						if ( $team_id <= 0 || ! self::team_has_term( $team_id, $filter_term_id ) ) {
							break;
						}
					}
					self::accumulate_donor( $order, $buckets, $donation_total );
					break;
				case self::DIMENSION_TEAMS:
					if ( $team_id <= 0 ) {
						break;
					}
					if ( $filter_term_id > 0 && ! self::team_has_term( $team_id, $filter_term_id ) ) {
						break;
					}
					$key = 't:' . $team_id;
					if ( ! isset( $buckets[ $key ] ) ) {
						$buckets[ $key ] = array(
							'id'     => $team_id,
							'amount' => 0.0,
						);
					}
					$buckets[ $key ]['amount'] += $donation_total;
					break;
				case self::DIMENSION_BENEFICIARIES:
					if ( $beneficiary_id <= 0 ) {
						break;
					}
					if ( $filter_term_id > 0 && ! self::beneficiary_in_cause_subtree( $beneficiary_id, $filter_term_id ) ) {
						break;
					}
					$key = 'b:' . $beneficiary_id;
					if ( ! isset( $buckets[ $key ] ) ) {
						$buckets[ $key ] = array(
							'id'     => $beneficiary_id,
							'amount' => 0.0,
						);
					}
					$buckets[ $key ]['amount'] += $donation_total;
					break;
				case self::DIMENSION_CAUSES:
					if ( $beneficiary_id <= 0 ) {
						break;
					}
					$cause_id = self::primary_cause_term_id( $beneficiary_id );
					if ( null === $cause_id ) {
						break;
					}
					if ( $filter_term_id > 0 && ! self::term_is_descendant_or_self( $cause_id, $filter_term_id ) ) {
						break;
					}
					$key = 'c:' . $cause_id;
					if ( ! isset( $buckets[ $key ] ) ) {
						$buckets[ $key ] = array(
							'id'     => $cause_id,
							'amount' => 0.0,
						);
					}
					$buckets[ $key ]['amount'] += $donation_total;
					break;
			}
		}

		uasort(
			$buckets,
			static function ( $a, $b ) {
				if ( $a['amount'] === $b['amount'] ) {
					return $a['id'] <=> $b['id'];
				}
				return $b['amount'] <=> $a['amount'];
			}
		);

		$slice = array_slice( array_values( $buckets ), 0, $limit );
		$rows  = array();
		$rank  = 1;
		foreach ( $slice as $row ) {
			$rows[] = self::decorate_row( $dimension, $row['id'], (float) $row['amount'], $rank );
			++$rank;
		}

		return $rows;
	}

	/**
	 * @param WC_Order $order Order.
	 * @param array<string, array{id:int, amount:float}> $buckets Mutable buckets.
	 * @param float $donation_total Amount to add.
	 */
	private static function accumulate_donor( WC_Order $order, array &$buckets, float $donation_total ): void {
		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			$key = 'u:' . $user_id;
		} else {
			$email = strtolower( trim( (string) $order->get_billing_email() ) );
			if ( '' === $email ) {
				return;
			}
			$key = 'e:' . md5( $email );
		}
		if ( ! isset( $buckets[ $key ] ) ) {
			$buckets[ $key ] = array(
				'id'     => $user_id > 0 ? $user_id : 0,
				'amount' => 0.0,
				'_key'   => $key,
				'_email' => $user_id > 0 ? '' : (string) $order->get_billing_email(),
			);
		}
		$buckets[ $key ]['amount'] += $donation_total;
	}

	/**
	 * @param string $dimension One of DIMENSION_*.
	 * @param int    $id        Entity ID (or 0 for guest donor bucket).
	 * @param float  $amount    Total raised.
	 * @param int    $rank      1-based rank.
	 * @return array<string, mixed>
	 */
	private static function decorate_row( string $dimension, int $id, float $amount, int $rank ): array {
		$row = array(
			'rank'   => $rank,
			'id'     => $id,
			'amount' => round( $amount, 2 ),
			'label'  => '',
		);

		switch ( $dimension ) {
			case self::DIMENSION_DONORS:
				if ( $id > 0 ) {
					$user = get_userdata( $id );
					$row['label']    = $user ? $user->display_name : __( 'Donor', 'giving-day-blocks' );
					$row['avatar_url'] = $user ? get_avatar_url( $user->ID, array( 'size' => 96 ) ) : '';
				} else {
					$row['label'] = __( 'Donor', 'giving-day-blocks' );
					$row['avatar_url'] = '';
				}
				break;
			case self::DIMENSION_TEAMS:
				$row['label'] = $id > 0 ? get_the_title( $id ) : '';
				$row['label'] = $row['label'] !== '' ? $row['label'] : __( 'Team', 'giving-day-blocks' );
				$row['avatar_url'] = $id > 0 ? get_the_post_thumbnail_url( $id, 'thumbnail' ) : '';
				break;
			case self::DIMENSION_BENEFICIARIES:
				$row['label'] = $id > 0 ? get_the_title( $id ) : '';
				$row['label'] = $row['label'] !== '' ? $row['label'] : __( 'Beneficiary', 'giving-day-blocks' );
				$row['avatar_url'] = $id > 0 ? get_the_post_thumbnail_url( $id, 'thumbnail' ) : '';
				break;
			case self::DIMENSION_CAUSES:
				$term = get_term( $id, Cause::TAXONOMY );
				if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
					$row['label'] = $term->name;
				} else {
					$row['label'] = __( 'Cause', 'giving-day-blocks' );
				}
				$row['avatar_url'] = '';
				break;
		}

		return $row;
	}

	/**
	 * Applies anonymization to row labels and avatars for donor dimension.
	 *
	 * @param list<array<string, mixed>> $rows Rows from decorate_row.
	 * @param bool                       $anonymize Whether to anonymize.
	 * @return list<array<string, mixed>>
	 */
	public static function apply_anonymize( array $rows, bool $anonymize ): array {
		if ( ! $anonymize ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			$row['label']      = __( 'Anonymous', 'giving-day-blocks' );
			$row['avatar_url'] = '';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * @param int $campaign_id Campaign ID.
	 * @return \Generator<int, WC_Order>
	 */
	private static function each_attributed_order( int $campaign_id ): \Generator {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$args = array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array( 'processing', 'completed' ),
			'meta_key'   => OrderAttribution::META_CAMPAIGN_ID,
			'meta_value' => (string) $campaign_id,
			'orderby'    => 'date',
			'order'      => 'DESC',
		);

		$start = self::campaign_order_date_start( $campaign_id );
		$end   = self::campaign_order_date_end( $campaign_id );
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

	private static function campaign_order_date_start( int $campaign_id ): ?string {
		$raw = (string) get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		return false === $ts ? null : (string) $ts;
	}

	private static function campaign_order_date_end( int $campaign_id ): ?string {
		$raw = (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		return false === $ts ? null : (string) $ts;
	}

	private static function donation_total_for_order( WC_Order $order ): float {
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
			 * @param bool                    $is_donation Default from `_wpcomsp_donation` when set; otherwise true (PLAN.md § 4.2.3).
			 * @param WC_Order_Item_Product $item        Line item.
			 * @param WC_Order               $order       Order.
			 */
			$is_donation = (bool) apply_filters( 'giving_day_is_donation_line_item', $default_donation, $item, $order );
			if ( ! $is_donation ) {
				continue;
			}
			$total += (float) $item->get_total();
		}
		return $total;
	}

	private static function team_has_term( int $team_id, int $term_id ): bool {
		$terms = get_the_terms( $team_id, TeamGroup::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( self::term_is_descendant_or_self( (int) $term->term_id, $term_id, TeamGroup::TAXONOMY ) ) {
				return true;
			}
		}
		return false;
	}

	private static function beneficiary_in_cause_subtree( int $beneficiary_id, int $cause_term_id ): bool {
		$terms = get_the_terms( $beneficiary_id, Cause::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( self::term_is_descendant_or_self( (int) $term->term_id, $cause_term_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drops mismatched taxonomy filters so a team-group ID is not applied to cause dimensions.
	 *
	 * @param string $dimension      One of DIMENSION_*.
	 * @param int    $filter_term_id Raw filter from request.
	 * @return int Sanitized filter or 0.
	 */
	private static function normalize_filter_term_id( string $dimension, int $filter_term_id ): int {
		if ( $filter_term_id <= 0 ) {
			return 0;
		}
		if ( self::DIMENSION_TEAMS === $dimension || self::DIMENSION_DONORS === $dimension ) {
			$t = get_term( $filter_term_id, TeamGroup::TAXONOMY );
			if ( ! $t instanceof \WP_Term || is_wp_error( $t ) ) {
				return 0;
			}
			return $filter_term_id;
		}
		if ( self::DIMENSION_BENEFICIARIES === $dimension || self::DIMENSION_CAUSES === $dimension ) {
			$t = get_term( $filter_term_id, Cause::TAXONOMY );
			if ( ! $t instanceof \WP_Term || is_wp_error( $t ) ) {
				return 0;
			}
			return $filter_term_id;
		}
		return 0;
	}

	/**
	 * True when $term_id is $ancestor_id or a descendant of $ancestor_id.
	 */
	private static function term_is_descendant_or_self( int $term_id, int $ancestor_id, ?string $taxonomy = null ): bool {
		$taxonomy = $taxonomy ?? Cause::TAXONOMY;
		if ( $term_id === $ancestor_id ) {
			return true;
		}
		$anc = get_ancestors( $term_id, $taxonomy );
		return in_array( $ancestor_id, $anc, true );
	}

	/**
	 * Picks the deepest assigned cause term for a beneficiary (tie-break lowest term_id).
	 */
	private static function primary_cause_term_id( int $beneficiary_id ): ?int {
		$terms = get_the_terms( $beneficiary_id, Cause::TAXONOMY );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}
		$best_id    = null;
		$best_depth = -1;
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$depth = count( get_ancestors( $term->term_id, Cause::TAXONOMY ) );
			if ( $depth > $best_depth || ( $depth === $best_depth && ( null === $best_id || (int) $term->term_id < $best_id ) ) ) {
				$best_depth = $depth;
				$best_id    = (int) $term->term_id;
			}
		}
		return $best_id;
	}

	private static function serialize_term( \WP_Term $term ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
		);
	}
}
