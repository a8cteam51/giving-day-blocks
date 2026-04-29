<?php
/**
 * Order attribution listener.
 *
 * Hooked on woocommerce_checkout_create_order — reads the session
 * context set by Data\Context and writes _giving_campaign_id,
 * _giving_team_id, and _giving_beneficiary_id as order meta.
 *
 * A `giving_day_attribute_order` filter fires before persistence so
 * external code (CLI importers, off-site payment callbacks, etc.) can
 * override or enrich the attribution.
 *
 * @package Team51\GivingDay\Integrations
 * @since   0.1.0
 */

namespace Team51\GivingDay\Integrations;

use Team51\GivingDay\Data\Context;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderAttribution {

	public const META_CAMPAIGN_ID    = '_giving_campaign_id';
	public const META_TEAM_ID        = '_giving_team_id';
	public const META_BENEFICIARY_ID = '_giving_beneficiary_id';

	/**
	 * Registers the checkout hook.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order' ), 20, 1 );
	}

	/**
	 * Writes attribution meta onto a newly created WC order.
	 *
	 * @param WC_Order $order The order being created.
	 */
	public function tag_order( WC_Order $order ): void {
		$attribution = Context::get();

		/**
		 * Filters the attribution data before it is written to the order.
		 *
		 * Return an array with keys campaign_id, team_id, beneficiary_id.
		 * Set campaign_id to 0 to leave the order unattributed.
		 *
		 * @param array{campaign_id: int, team_id: int, beneficiary_id: int} $attribution
		 * @param WC_Order $order
		 */
		$attribution = apply_filters( 'giving_day_attribute_order', $attribution, $order );

		if ( ! is_array( $attribution ) ) {
			return;
		}

		$campaign_id    = isset( $attribution['campaign_id'] ) ? absint( $attribution['campaign_id'] ) : 0;
		$team_id        = isset( $attribution['team_id'] ) ? absint( $attribution['team_id'] ) : 0;
		$beneficiary_id = isset( $attribution['beneficiary_id'] ) ? absint( $attribution['beneficiary_id'] ) : 0;

		if ( 0 === $campaign_id ) {
			return;
		}

		$order->update_meta_data( self::META_CAMPAIGN_ID, $campaign_id );

		if ( $team_id > 0 ) {
			$order->update_meta_data( self::META_TEAM_ID, $team_id );
		}
		if ( $beneficiary_id > 0 ) {
			$order->update_meta_data( self::META_BENEFICIARY_ID, $beneficiary_id );
		}
	}
}
