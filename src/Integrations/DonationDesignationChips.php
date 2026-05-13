<?php
/**
 * Donation form designation chips.
 *
 * Consumer-side bridge between this plugin and the team51-donations Custom
 * Fields API. Registers two `appearance: chip` fields on the donation form
 * (`giving_day_beneficiary` and `giving_day_team`) and persists the donor's
 * selections to the same `_giving_team_id` / `_giving_beneficiary_id` order
 * meta the {@see OrderAttribution} listener writes — so the Aggregator's
 * slicing is unchanged whether attribution came from URL/session context or
 * from an explicit chip choice.
 *
 * Pre-fill priority is handled entirely by team51-donations:
 *   url_param > default_callback > empty.
 *
 * The chip's `default_callback` reads {@see Context::get()} so a donor who
 * arrived via a Beneficiary / Team single-post page (or a Giving Day block
 * that set Context) sees the chip arrive pre-filled.
 *
 * Order-of-execution: this listener writes order meta at priority 10 of
 * `woocommerce_checkout_create_order` (where team51-donations fires the
 * per-field action). {@see OrderAttribution::tag_order} runs at priority 20
 * and is additive — it never overwrites a value the chip already wrote, so
 * the donor's explicit selection wins.
 *
 * @package Team51\GivingDay\Integrations
 * @since   0.4.0
 */

namespace Team51\GivingDay\Integrations;

use Team51\GivingDay\Data\Context;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\REST;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the donation form's beneficiary + team designation chips and
 * routes their submitted values into the same order meta the
 * Order Attribution listener writes.
 */
final class DonationDesignationChips {

	public const FIELD_BENEFICIARY = 'giving_day_beneficiary';
	public const FIELD_TEAM        = 'giving_day_team';

	/**
	 * Hooks registration onto `init` (after team51-donations bootstraps) and
	 * subscribes to the team51-donations persistence action.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_register_fields' ), 20 );
		add_action( 'wpcomsp_donations_field_value_received', array( $this, 'persist_value' ), 10, 3 );
	}

	/**
	 * Registers the two designation chips with team51-donations.
	 *
	 * Gated on:
	 *   1. The team51-donations Custom Fields API exists.
	 *   2. The `giving_day_blocks_register_donation_chips` filter returns true.
	 *
	 * @internal Hooked on `init` priority 20.
	 */
	public function maybe_register_fields(): void {
		if ( ! function_exists( 'wpcomsp_donations_register_field' ) ) {
			return;
		}

		/**
		 * Filters whether the donation form designation chips should be
		 * auto-registered. Default: true.
		 *
		 * Return false to suppress registration (e.g. when a site builds its
		 * own designation UI or wants to bypass team51-donations entirely).
		 *
		 * @since 0.4.0
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'giving_day_blocks_register_donation_chips', true ) ) {
			return;
		}

		wpcomsp_donations_register_field(
			array(
				'id'                => self::FIELD_BENEFICIARY,
				'type'              => 'typeahead',
				'label'             => __( 'Designate your gift', 'giving-day-blocks' ),
				'placeholder'       => __( 'Search funds and beneficiaries…', 'giving-day-blocks' ),
				'chip_placeholder'  => __( 'General fund', 'giving-day-blocks' ),
				'chip_value_prefix' => __( 'You are giving to', 'giving-day-blocks' ),
				'options_endpoint'  => rest_url( REST::NAMESPACE . '/beneficiaries' ),
				'url_param'         => 'giving_beneficiary',
				'sanitize_callback' => 'absint',
				'validate_callback' => array( $this, 'validate_beneficiary' ),
				'default_callback'  => array( $this, 'default_beneficiary' ),
				'priority'          => 10,
			)
		);

		wpcomsp_donations_register_field(
			array(
				'id'                => self::FIELD_TEAM,
				'type'              => 'typeahead',
				'label'             => __( 'Are you part of a team?', 'giving-day-blocks' ),
				'placeholder'       => __( 'Search teams…', 'giving-day-blocks' ),
				'chip_placeholder'  => __( 'No team selected', 'giving-day-blocks' ),
				'chip_action_label' => __( 'add a team', 'giving-day-blocks' ),
				'chip_value_prefix' => __( 'You are giving on behalf of', 'giving-day-blocks' ),
				'options_endpoint'  => rest_url( REST::NAMESPACE . '/teams' ),
				'url_param'         => 'giving_team',
				'sanitize_callback' => 'absint',
				'validate_callback' => array( $this, 'validate_team' ),
				'default_callback'  => array( $this, 'default_team' ),
				'priority'          => 20,
			)
		);
	}

	/**
	 * Default beneficiary value for the chip — reads from session context.
	 *
	 * @return int|null
	 */
	public function default_beneficiary(): ?int {
		$id = (int) ( Context::get()['beneficiary_id'] ?? 0 );
		return $id > 0 ? $id : null;
	}

	/**
	 * Default team value for the chip — reads from session context.
	 *
	 * @return int|null
	 */
	public function default_team(): ?int {
		$id = (int) ( Context::get()['team_id'] ?? 0 );
		return $id > 0 ? $id : null;
	}

	/**
	 * Validates the beneficiary chip's submitted value.
	 *
	 * Accepts empty (donor left it on "General fund") and any published
	 * Beneficiary post ID. Anything else is rejected with a WP_Error that
	 * team51-donations surfaces as a checkout notice.
	 *
	 * @param mixed $value Sanitized value (absint output).
	 * @return true|\WP_Error
	 */
	public function validate_beneficiary( $value ) {
		$id = (int) $value;
		if ( $id <= 0 ) {
			return true;
		}
		$post = get_post( $id );
		if ( $post && Beneficiary::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			return true;
		}
		return new \WP_Error(
			'giving_day_invalid_beneficiary',
			__( 'That beneficiary is no longer available — please pick another or leave the field empty.', 'giving-day-blocks' )
		);
	}

	/**
	 * Validates the team chip's submitted value.
	 *
	 * @param mixed $value Sanitized value (absint output).
	 * @return true|\WP_Error
	 */
	public function validate_team( $value ) {
		$id = (int) $value;
		if ( $id <= 0 ) {
			return true;
		}
		$post = get_post( $id );
		if ( $post && Team::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			return true;
		}
		return new \WP_Error(
			'giving_day_invalid_team',
			__( 'That team is no longer available — please pick another or leave the field empty.', 'giving-day-blocks' )
		);
	}

	/**
	 * Persists a chip value to the order.
	 *
	 * Runs inside team51-donations' Submission::fire_order_action, hooked on
	 * woocommerce_checkout_create_order at priority 10. OrderAttribution
	 * runs at priority 20 and is additive (won't overwrite what we write
	 * here), so donor-explicit chip values win over Context-derived
	 * attribution.
	 *
	 * @param string   $field_id Field id fired by team51-donations.
	 * @param mixed    $value    Sanitized value (already run through absint).
	 * @param WC_Order $order    Order being created.
	 */
	public function persist_value( string $field_id, $value, WC_Order $order ): void {
		$id = (int) $value;
		if ( $id <= 0 ) {
			return;
		}

		if ( self::FIELD_BENEFICIARY === $field_id ) {
			$order->update_meta_data( OrderAttribution::META_BENEFICIARY_ID, $id );
			$this->maybe_reconcile_campaign( $order, $id, Beneficiary::META_CAMPAIGN_IDS );
			return;
		}

		if ( self::FIELD_TEAM === $field_id ) {
			$order->update_meta_data( OrderAttribution::META_TEAM_ID, $id );
			$this->maybe_reconcile_campaign( $order, $id, Team::META_CAMPAIGN_IDS );
		}
	}

	/**
	 * If the order does not yet have a campaign_id, derive one from the chip
	 * value's `_giving_*_campaigns` meta so the order is attributable to a
	 * Campaign even when the donor never visited any campaign-scoped surface.
	 *
	 * @param WC_Order $order    Order being created.
	 * @param int      $post_id  Beneficiary or Team post ID.
	 * @param string   $meta_key Meta key holding the post's participating campaign IDs.
	 */
	private function maybe_reconcile_campaign( WC_Order $order, int $post_id, string $meta_key ): void {
		$existing = (int) $order->get_meta( OrderAttribution::META_CAMPAIGN_ID, true );
		if ( $existing > 0 ) {
			return;
		}

		$campaigns = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_array( $campaigns ) || empty( $campaigns ) ) {
			return;
		}

		$first = (int) reset( $campaigns );
		if ( $first > 0 ) {
			$order->update_meta_data( OrderAttribution::META_CAMPAIGN_ID, $first );
		}
	}
}
