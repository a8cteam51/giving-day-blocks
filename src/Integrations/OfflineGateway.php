<?php
/**
 * Offline donation payment gateway.
 *
 * Registered with WooCommerce so admin-recorded cash/check donations have a
 * real payment_method on the order (which makes the WC reports, refunds, and
 * order list filters all work normally). Deliberately never available at
 * checkout — `is_available()` always returns false and the gateway can't be
 * toggled on from WC > Settings > Payments.
 *
 * @package Team51\GivingDay\Integrations
 * @since   0.3.0
 */

namespace Team51\GivingDay\Integrations;

use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal WC gateway used only by {@see Services\OfflineDonations} to stamp a
 * recognizable payment method on admin-recorded donations.
 */
final class OfflineGateway extends WC_Payment_Gateway {

	public const ID = 'giving_day_offline';

	/**
	 * Sets the gateway identity. Settings/form fields are intentionally empty
	 * because admins shouldn't be able to enable this for storefront checkout.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Giving Day — Offline Donation', 'giving-day-blocks' );
		$this->method_description = __( 'Used by the Giving Days → Offline Donations admin screen to record cash/check donations as real WooCommerce orders. This gateway is never available at checkout.', 'giving-day-blocks' );
		$this->title              = __( 'Offline Donation', 'giving-day-blocks' );
		$this->description        = '';
		$this->has_fields         = false;
		$this->enabled            = 'no';
		$this->supports           = array( 'products' );
	}

	/**
	 * Hard-coded unavailable. The admin screen sets the payment method directly
	 * on the order — this method is what keeps it off the storefront checkout.
	 */
	public function is_available(): bool {
		return false;
	}

	/**
	 * Suppresses the standard "Enable / Disable" toggle so admins can't flip
	 * this gateway on by accident from WC settings.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array();
	}

	/**
	 * Registers the gateway with WooCommerce.
	 */
	public static function register(): void {
		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = self::class;
				return $gateways;
			}
		);
	}
}
