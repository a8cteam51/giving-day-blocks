<?php
/**
 * Session-based donation attribution context.
 *
 * When a visitor lands on a page that contains any Giving Day block (or
 * views a Campaign / Team / Beneficiary single post), the rendering
 * path calls Context::set() to write the active campaign / team /
 * beneficiary IDs into the WC session. At checkout, the order-
 * attribution listener (Integrations\OrderAttribution) reads this
 * context and persists it as order meta — the donation form itself
 * never needs to know about campaigns.
 *
 * This keeps the Giving Day plugin fully decoupled from whatever
 * donation form is in use (team51-donations, GiveWP, Stripe Checkout,
 * etc.) — any form that produces a WC order inherits attribution
 * automatically.
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Team;

defined( 'ABSPATH' ) || exit;

final class Context {

	private const SESSION_KEY = 'giving_day_context';

	/**
	 * URL query parameters that can set context from external links.
	 */
	private const QUERY_PARAMS = array(
		'giving_campaign'    => 'campaign_id',
		'giving_team'        => 'team_id',
		'giving_beneficiary' => 'beneficiary_id',
	);

	/**
	 * Sets the active attribution context for this invocation.
	 *
	 * Replaces the stored payload (no merge with prior session values), so a
	 * campaign-only caller clears team/beneficiary from a previous page view.
	 * Later calls in the same request overwrite the full snapshot again.
	 *
	 * @param int      $campaign_id    Campaign post ID (0 = omit from payload).
	 * @param int|null $team_id        Team post ID (>0 = set, null = clear to 0).
	 * @param int|null $beneficiary_id Beneficiary post ID (>0 = set, null = clear to 0).
	 */
	public static function set( int $campaign_id, ?int $team_id = null, ?int $beneficiary_id = null ): void {
		$session = self::session();
		if ( null === $session ) {
			return;
		}

		$current = array();
		if ( $campaign_id > 0 ) {
			$current['campaign_id'] = $campaign_id;
		}
		if ( null !== $team_id ) {
			$current['team_id'] = ( $team_id > 0 ) ? $team_id : 0;
		}
		if ( null !== $beneficiary_id ) {
			$current['beneficiary_id'] = ( $beneficiary_id > 0 ) ? $beneficiary_id : 0;
		}

		$session->set( self::SESSION_KEY, wp_json_encode( $current ) );
	}

	/**
	 * Returns the current attribution context from the WC session.
	 *
	 * @return array{campaign_id: int, team_id: int, beneficiary_id: int}
	 */
	public static function get(): array {
		$session = self::session();
		if ( null === $session ) {
			return self::empty_context();
		}

		return self::decode( $session->get( self::SESSION_KEY ) );
	}

	/**
	 * Clears the context. Called on woocommerce_thankyou so a second
	 * purchase in the same browser session doesn't inherit a stale
	 * campaign.
	 */
	public static function clear(): void {
		$session = self::session();
		if ( null === $session ) {
			return;
		}

		$session->set( self::SESSION_KEY, '' );
	}

	/**
	 * Hooks into template_redirect to set context from URL query
	 * parameters and from Campaign / Team / Beneficiary single-post
	 * views.
	 */
	public static function register_hooks(): void {
		add_action( 'template_redirect', array( __CLASS__, 'set_from_request' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear' ) );
	}

	/**
	 * Reads URL query parameters and single-post context.
	 *
	 * @internal Hooked on template_redirect.
	 */
	public static function set_from_request(): void {
		$campaign_id    = 0;
		$team_id        = null;
		$beneficiary_id = null;

		foreach ( self::QUERY_PARAMS as $param => $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = isset( $_GET[ $param ] ) ? absint( wp_unslash( $_GET[ $param ] ) ) : 0;
			if ( $raw <= 0 ) {
				continue;
			}
			if ( 'campaign_id' === $key && self::is_valid_post( $raw, Campaign::POST_TYPE ) ) {
				$campaign_id = $raw;
			} elseif ( 'team_id' === $key && self::is_valid_post( $raw, Team::POST_TYPE ) ) {
				$team_id = $raw;
			} elseif ( 'beneficiary_id' === $key && self::is_valid_post( $raw, Beneficiary::POST_TYPE ) ) {
				$beneficiary_id = $raw;
			}
		}

		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post ) {
			if ( Campaign::POST_TYPE === $queried->post_type ) {
				$campaign_id = $queried->ID;
			} elseif ( Team::POST_TYPE === $queried->post_type ) {
				$team_id       = $queried->ID;
				$team_campaign = self::first_campaign_id_from_meta( $queried->ID, '_giving_team_campaigns' );
				if ( $team_campaign > 0 && 0 === $campaign_id ) {
					$campaign_id = $team_campaign;
				}
			} elseif ( Beneficiary::POST_TYPE === $queried->post_type ) {
				$beneficiary_id = $queried->ID;
				$ben_campaign   = self::first_campaign_id_from_meta( $queried->ID, '_giving_beneficiary_campaigns' );
				if ( $ben_campaign > 0 && 0 === $campaign_id ) {
					$campaign_id = $ben_campaign;
				}
			}
		}

		self::normalize_query_attribution( $campaign_id, $team_id, $beneficiary_id );

		if ( $campaign_id > 0 || null !== $team_id || null !== $beneficiary_id ) {
			self::set( $campaign_id, $team_id, $beneficiary_id );
		}
	}

	/**
	 * Aligns team/beneficiary with campaign meta and drops inconsistent IDs.
	 *
	 * @param int      $campaign_id    Updated by reference.
	 * @param int|null $team_id        Updated by reference.
	 * @param int|null $beneficiary_id Updated by reference.
	 */
	private static function normalize_query_attribution( int &$campaign_id, ?int &$team_id, ?int &$beneficiary_id ): void {
		if ( null !== $team_id && $team_id > 0 ) {
			$team_campaigns = self::campaign_ids_from_meta( $team_id, '_giving_team_campaigns' );
			if ( empty( $team_campaigns ) ) {
				$team_id = null;
			} elseif ( $campaign_id > 0 ) {
				if ( ! in_array( $campaign_id, $team_campaigns, true ) ) {
					$team_id = null;
				}
			} else {
				$campaign_id = $team_campaigns[0];
			}
		}

		if ( null !== $beneficiary_id && $beneficiary_id > 0 ) {
			$ben_campaigns = self::campaign_ids_from_meta( $beneficiary_id, '_giving_beneficiary_campaigns' );
			if ( empty( $ben_campaigns ) ) {
				$beneficiary_id = null;
			} elseif ( $campaign_id > 0 ) {
				if ( ! in_array( $campaign_id, $ben_campaigns, true ) ) {
					$beneficiary_id = null;
				}
			} else {
				$campaign_id = $ben_campaigns[0];
			}
		}
	}

	/**
	 * Reads a `_giving_*_campaigns` meta value as a list of int IDs.
	 * Tolerant of legacy scalar storage.
	 *
	 * @return array<int, int>
	 */
	private static function campaign_ids_from_meta( int $post_id, string $meta_key ): array {
		$raw = get_post_meta( $post_id, $meta_key, true );
		if ( is_array( $raw ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $raw ) ) );
			return $ids;
		}
		if ( is_scalar( $raw ) && (int) $raw > 0 ) {
			return array( (int) $raw );
		}
		return array();
	}

	/**
	 * Convenience for callers that only need a single campaign ID
	 * (Team / Beneficiary singles default to the first associated campaign).
	 */
	private static function first_campaign_id_from_meta( int $post_id, string $meta_key ): int {
		$ids = self::campaign_ids_from_meta( $post_id, $meta_key );
		return $ids[0] ?? 0;
	}

	/**
	 * @return array{campaign_id: int, team_id: int, beneficiary_id: int}
	 */
	private static function empty_context(): array {
		return array(
			'campaign_id'    => 0,
			'team_id'        => 0,
			'beneficiary_id' => 0,
		);
	}

	/**
	 * @param mixed $raw Session value (string|null).
	 * @return array{campaign_id: int, team_id: int, beneficiary_id: int}
	 */
	private static function decode( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::empty_context();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return self::empty_context();
		}

		return array(
			'campaign_id'    => isset( $data['campaign_id'] ) ? absint( $data['campaign_id'] ) : 0,
			'team_id'        => isset( $data['team_id'] ) ? absint( $data['team_id'] ) : 0,
			'beneficiary_id' => isset( $data['beneficiary_id'] ) ? absint( $data['beneficiary_id'] ) : 0,
		);
	}

	/**
	 * @return \WC_Session_Handler|null
	 */
	private static function session() {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return null;
		}
		return WC()->session;
	}

	/**
	 * Validates that a post ID is published and of the expected type.
	 */
	private static function is_valid_post( int $post_id, string $expected_type ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& $expected_type === $post->post_type
			&& 'publish' === $post->post_status;
	}
}
