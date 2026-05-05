<?php
/**
 * Offline donations service.
 *
 * Creates real WooCommerce orders for cash/check/etc. donations recorded
 * by an admin in the Giving Days → Offline Donations screen. The order
 * carries the same campaign/team/beneficiary attribution meta that
 * online checkouts produce, so the Aggregator picks it up with no
 * special-casing.
 *
 * @package Team51\GivingDay\Services
 * @since   0.3.0
 */

namespace Team51\GivingDay\Services;

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\Integrations\OfflineGateway;
use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Team;
use WC_DateTime;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds WC orders from admin-supplied offline donation data.
 */
final class OfflineDonations {

	public const META_OFFLINE_FLAG = '_giving_offline_donation';
	public const META_TENDER       = '_giving_offline_tender';
	public const META_REFERENCE    = '_giving_offline_reference';
	public const TENDER_CASH       = 'cash';
	public const TENDER_CHECK      = 'check';
	public const TENDER_OTHER      = 'other';

	/**
	 * Allowed tender values mapped to their human label (used as the
	 * order's payment_method_title when no reference is supplied).
	 *
	 * @return array<string, string>
	 */
	public static function tender_choices(): array {
		return array(
			self::TENDER_CASH  => __( 'Cash', 'giving-day-blocks' ),
			self::TENDER_CHECK => __( 'Check', 'giving-day-blocks' ),
			self::TENDER_OTHER => __( 'Other', 'giving-day-blocks' ),
		);
	}

	/**
	 * Creates an offline donation order.
	 *
	 * Required keys: campaign_id (int), amount (float), donor_name (string),
	 * donor_email (string), tender (string — one of the TENDER_* constants).
	 * Optional: reference, team_id, beneficiary_id, donor_phone,
	 * billing_address_1, billing_address_2, billing_city, billing_state,
	 * billing_postcode, billing_country, donation_date (Y-m-d\TH:i),
	 * admin_notes.
	 *
	 * @param array<string, mixed> $args Form data, validated below.
	 * @return int|\WP_Error Order ID on success.
	 */
	public static function create_order( array $args ) {
		$campaign_id = isset( $args['campaign_id'] ) ? absint( $args['campaign_id'] ) : 0;
		$amount      = isset( $args['amount'] ) ? (float) $args['amount'] : 0.0;
		$donor_name  = isset( $args['donor_name'] ) ? trim( (string) $args['donor_name'] ) : '';
		$donor_email = isset( $args['donor_email'] ) ? sanitize_email( (string) $args['donor_email'] ) : '';
		$tender      = isset( $args['tender'] ) ? sanitize_key( (string) $args['tender'] ) : '';

		if ( $campaign_id <= 0 || Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return new WP_Error( 'giving_day_offline_invalid_campaign', __( 'Pick a campaign before recording the donation.', 'giving-day-blocks' ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'giving_day_offline_invalid_amount', __( 'Donation amount must be greater than zero.', 'giving-day-blocks' ) );
		}
		if ( '' === $donor_name ) {
			return new WP_Error( 'giving_day_offline_missing_name', __( 'Donor name is required.', 'giving-day-blocks' ) );
		}
		if ( '' === $donor_email || ! is_email( $donor_email ) ) {
			return new WP_Error( 'giving_day_offline_invalid_email', __( 'Provide a valid donor email address.', 'giving-day-blocks' ) );
		}
		if ( ! array_key_exists( $tender, self::tender_choices() ) ) {
			return new WP_Error( 'giving_day_offline_invalid_tender', __( 'Pick a tender type (cash, check, or other).', 'giving-day-blocks' ) );
		}

		$product_id = self::resolve_donation_product_id( $campaign_id );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'giving_day_offline_missing_product', __( 'The campaign\'s donation product is missing or trashed.', 'giving-day-blocks' ) );
		}

		$team_id = self::validate_associated_post( $args['team_id'] ?? 0, Team::POST_TYPE, Team::META_CAMPAIGN_IDS, $campaign_id, 'team' );
		if ( is_wp_error( $team_id ) ) {
			return $team_id;
		}
		$beneficiary_id = self::validate_associated_post( $args['beneficiary_id'] ?? 0, Beneficiary::POST_TYPE, Beneficiary::META_CAMPAIGN_IDS, $campaign_id, 'beneficiary' );
		if ( is_wp_error( $beneficiary_id ) ) {
			return $beneficiary_id;
		}

		$order = wc_create_order(
			array(
				'status'      => 'completed',
				'created_via' => 'giving_day_offline',
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Force the line price to the entered amount so the recorded total
		// matches what was collected, regardless of the donation product's
		// own price (donation products are typically priced at 0).
		$order->add_product(
			$product,
			1,
			array(
				'subtotal' => $amount,
				'total'    => $amount,
			)
		);

		$order->set_billing_first_name( $donor_name );
		$order->set_billing_email( $donor_email );

		$optional_billing = array(
			'set_billing_phone'     => $args['donor_phone'] ?? '',
			'set_billing_address_1' => $args['billing_address_1'] ?? '',
			'set_billing_address_2' => $args['billing_address_2'] ?? '',
			'set_billing_city'      => $args['billing_city'] ?? '',
			'set_billing_state'     => $args['billing_state'] ?? '',
			'set_billing_postcode'  => $args['billing_postcode'] ?? '',
			'set_billing_country'   => $args['billing_country'] ?? '',
		);
		foreach ( $optional_billing as $setter => $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value && method_exists( $order, $setter ) ) {
				$order->{$setter}( $value );
			}
		}

		$reference = isset( $args['reference'] ) ? trim( (string) $args['reference'] ) : '';
		$order->set_payment_method( OfflineGateway::ID );
		$order->set_payment_method_title( self::payment_method_title( $tender, $reference ) );

		$donation_date = self::parse_date( $args['donation_date'] ?? '' );
		if ( $donation_date instanceof WC_DateTime ) {
			$order->set_date_created( $donation_date );
		}

		$currency = (string) get_post_meta( $campaign_id, Campaign::META_CURRENCY, true );
		if ( '' !== $currency ) {
			$order->set_currency( $currency );
		}

		$order->update_meta_data( self::META_OFFLINE_FLAG, '1' );
		$order->update_meta_data( self::META_TENDER, $tender );
		if ( '' !== $reference ) {
			$order->update_meta_data( self::META_REFERENCE, $reference );
		}

		$order->update_meta_data( OrderAttribution::META_CAMPAIGN_ID, $campaign_id );
		if ( $team_id > 0 ) {
			$order->update_meta_data( OrderAttribution::META_TEAM_ID, $team_id );
		}
		if ( $beneficiary_id > 0 ) {
			$order->update_meta_data( OrderAttribution::META_BENEFICIARY_ID, $beneficiary_id );
		}

		$order->calculate_totals( false );
		$order->set_total( $amount );
		$order->save();

		$admin_notes = isset( $args['admin_notes'] ) ? trim( (string) $args['admin_notes'] ) : '';
		$order->add_order_note(
			'' === $admin_notes
				? __( 'Recorded via Giving Days → Offline Donations.', 'giving-day-blocks' )
				: sprintf(
					/* translators: %s: free-form note typed by the admin who recorded the donation. */
					__( 'Recorded via Giving Days → Offline Donations. Note: %s', 'giving-day-blocks' ),
					$admin_notes
				)
		);

		Aggregator::invalidate( $campaign_id );

		return $order->get_id();
	}

	/**
	 * Pulls the first donation product from a campaign's
	 * `_giving_donation_product_ids` meta.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int|WP_Error
	 */
	private static function resolve_donation_product_id( int $campaign_id ) {
		$ids = get_post_meta( $campaign_id, Campaign::META_DONATION_PRODUCTS, true );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error(
				'giving_day_offline_no_product',
				__( 'This campaign has no donation product configured. Add one on the campaign edit screen first.', 'giving-day-blocks' )
			);
		}

		return $ids[0];
	}

	/**
	 * Verifies an optional team/beneficiary ID actually belongs to the campaign.
	 *
	 * @param mixed  $raw_id      Raw input value (string/int).
	 * @param string $post_type   Expected post type.
	 * @param string $meta_key    Meta key holding the campaign IDs the post is linked to.
	 * @param int    $campaign_id Campaign the donation is being recorded against.
	 * @param string $context     "team" or "beneficiary" — used in error codes/messages.
	 * @return int|WP_Error 0 when not provided; the validated ID on success; WP_Error on mismatch.
	 */
	private static function validate_associated_post( $raw_id, string $post_type, string $meta_key, int $campaign_id, string $context ) {
		$id = absint( $raw_id );
		if ( $id <= 0 ) {
			return 0;
		}
		if ( get_post_type( $id ) !== $post_type ) {
			return new WP_Error(
				'giving_day_offline_invalid_' . $context,
				sprintf(
					/* translators: %s: team or beneficiary. */
					__( 'The selected %s no longer exists.', 'giving-day-blocks' ),
					$context
				)
			);
		}
		$campaigns = get_post_meta( $id, $meta_key, true );
		$campaigns = is_array( $campaigns ) ? array_map( 'absint', $campaigns ) : array( absint( $campaigns ) );
		if ( ! in_array( $campaign_id, $campaigns, true ) ) {
			return new WP_Error(
				'giving_day_offline_unrelated_' . $context,
				sprintf(
					/* translators: %s: team or beneficiary. */
					__( 'The selected %s is not associated with this campaign.', 'giving-day-blocks' ),
					$context
				)
			);
		}
		return $id;
	}

	/**
	 * Builds the human label stored as the order's payment_method_title.
	 *
	 * @param string $tender    One of the TENDER_* constants.
	 * @param string $reference Optional free-form reference (e.g. check number).
	 */
	private static function payment_method_title( string $tender, string $reference ): string {
		$labels = self::tender_choices();
		$label  = $labels[ $tender ] ?? $labels[ self::TENDER_OTHER ];
		if ( '' === $reference ) {
			return $label;
		}
		return sprintf(
			/* translators: 1: tender label (Cash/Check/Other), 2: reference number. */
			__( '%1$s — ref %2$s', 'giving-day-blocks' ),
			$label,
			$reference
		);
	}

	/**
	 * Accepts a `datetime-local` string ("Y-m-d\TH:i") in the site timezone and
	 * returns a WC_DateTime, or null when blank/unparseable.
	 *
	 * @param string $raw Raw value submitted by the form.
	 */
	private static function parse_date( string $raw ): ?WC_DateTime {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$normalized = str_replace( 'T', ' ', $raw );
		$timestamp  = strtotime( $normalized );
		if ( false === $timestamp ) {
			return null;
		}
		$datetime = new WC_DateTime( '@' . $timestamp, new \DateTimeZone( 'UTC' ) );
		$datetime->setTimezone( wp_timezone() );
		return $datetime;
	}
}
