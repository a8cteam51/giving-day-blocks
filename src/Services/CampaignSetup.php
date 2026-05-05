<?php
/**
 * Campaign setup helpers.
 *
 * Encapsulates the per-campaign prerequisites that block real-world use
 * (donation product, dates, goal) so the same logic can be invoked from
 * the admin UI, the REST API, and on-demand recovery flows.
 *
 * @package Team51\GivingDay\Services
 * @since   0.3.0
 */

namespace Team51\GivingDay\Services;

use Team51\GivingDay\PostTypes\Campaign;
use WC_Product_Simple;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Setup utilities used by both the campaign editor sidebar and admin recovery flows.
 */
final class CampaignSetup {

	/**
	 * Creates a donation WC product for a campaign and attaches its ID to
	 * the campaign's `_giving_donation_product_ids` meta. Idempotent for
	 * the campaign-name attachment: if a previously-created donation
	 * product is already linked, returns its ID without creating a new one.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int|WP_Error Product ID on success.
	 */
	public static function create_donation_product( int $campaign_id ) {
		if ( $campaign_id <= 0 || Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return new WP_Error( 'giving_day_invalid_campaign', __( 'Invalid campaign.', 'giving-day-blocks' ) );
		}
		if ( ! class_exists( WC_Product_Simple::class ) ) {
			return new WP_Error( 'giving_day_wc_missing', __( 'WooCommerce is not active.', 'giving-day-blocks' ) );
		}

		// Reuse if a previous run already created and attached a product.
		$existing = self::existing_product_ids( $campaign_id );
		foreach ( $existing as $pid ) {
			if ( 'product' === get_post_type( $pid ) && 'trash' !== get_post_status( $pid ) ) {
				return $pid;
			}
		}

		$campaign_title = get_the_title( $campaign_id );
		$product_name   = sprintf(
			/* translators: %s: campaign title. */
			__( 'Donation — %s', 'giving-day-blocks' ),
			'' !== $campaign_title ? $campaign_title : '#' . $campaign_id
		);

		$product = new WC_Product_Simple();
		$product->set_name( $product_name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_featured( false );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product->set_manage_stock( false );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );
		$product->set_tax_status( 'none' );
		$product->set_description(
			sprintf(
				/* translators: %s: campaign title. */
				__( 'Auto-generated donation product for the "%s" Giving Day campaign.', 'giving-day-blocks' ),
				$campaign_title
			)
		);
		// Mark as a donation line item so the Aggregator (and any donation
		// plugin sniffing for the same flag) treats it as such automatically.
		$product->update_meta_data( '_wpcomsp_donation', '1' );
		$product->update_meta_data( '_giving_day_auto_created', '1' );

		$product_id = $product->save();
		if ( ! $product_id || $product_id instanceof WP_Error ) {
			return $product_id instanceof WP_Error
				? $product_id
				: new WP_Error( 'giving_day_product_create_failed', __( 'Could not create donation product.', 'giving-day-blocks' ) );
		}

		self::attach_product_id( $campaign_id, (int) $product_id );

		return (int) $product_id;
	}

	/**
	 * Returns a structured snapshot of a campaign's setup readiness.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed>
	 */
	public static function setup_status( int $campaign_id ): array {
		$start_dt   = (string) get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true );
		$end_dt     = (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true );
		$goal       = (float) get_post_meta( $campaign_id, Campaign::META_GOAL_AMOUNT, true );
		$products   = self::existing_product_ids( $campaign_id );
		$has_valid  = false;
		$first_name = '';
		foreach ( $products as $pid ) {
			if ( 'product' === get_post_type( $pid ) && 'trash' !== get_post_status( $pid ) ) {
				$has_valid  = true;
				$first_name = (string) get_the_title( $pid );
				break;
			}
		}

		return array(
			'startDatetime' => array(
				'ok'    => '' !== $start_dt,
				'value' => $start_dt,
			),
			'endDatetime'   => array(
				'ok'    => '' !== $end_dt,
				'value' => $end_dt,
			),
			'goalAmount'    => array(
				'ok'    => $goal > 0,
				'value' => $goal,
			),
			'donationProduct' => array(
				'ok'    => $has_valid,
				'count' => count( $products ),
				'name'  => $first_name,
			),
		);
	}

	/**
	 * Reads the campaign's currently-attached donation product IDs as ints.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<int, int>
	 */
	private static function existing_product_ids( int $campaign_id ): array {
		$raw = get_post_meta( $campaign_id, Campaign::META_DONATION_PRODUCTS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Adds a product ID to the campaign's donation-products meta, deduped.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $product_id  Product post ID.
	 */
	private static function attach_product_id( int $campaign_id, int $product_id ): void {
		$current   = self::existing_product_ids( $campaign_id );
		$current[] = $product_id;
		$current   = array_values( array_unique( $current ) );
		update_post_meta( $campaign_id, Campaign::META_DONATION_PRODUCTS, $current );
	}
}
