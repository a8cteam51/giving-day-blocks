<?php
/**
 * Campaign editor: Gutenberg sidebar panel.
 *
 * Enqueues the React bundle that mounts a "Campaign details" document
 * settings panel on `giving_campaign` edit screens. The panel binds to
 * the meta registered in PostTypes\Campaign::get_meta_fields() via
 * @wordpress/core-data's useEntityProp.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.1.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

final class CampaignEditor {

	private const HANDLE = 'giving-day-blocks-campaign-editor';

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the sidebar bundle only on Campaign edit screens.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Campaign::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = GIVING_DAY_BLOCKS_PATH . 'assets/build/admin-campaign/index.asset.php';
		if ( ! is_file( $asset_file ) ) {
			// Build artifact missing; bail quietly so the editor still loads.
			// Developer runs `npm run build` (or `build:admin`) to regenerate.
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			GIVING_DAY_BLOCKS_URL . 'assets/build/admin-campaign/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? GIVING_DAY_BLOCKS_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'giving-day-blocks' );

		$style_file = GIVING_DAY_BLOCKS_PATH . 'assets/build/admin-campaign/index.css';
		if ( is_file( $style_file ) ) {
			wp_enqueue_style(
				self::HANDLE,
				GIVING_DAY_BLOCKS_URL . 'assets/build/admin-campaign/index.css',
				array( 'wp-components' ),
				$asset['version'] ?? GIVING_DAY_BLOCKS_VERSION
			);
		}

		wp_localize_script(
			self::HANDLE,
			'givingDayCampaignEditor',
			array(
				'postType'       => Campaign::POST_TYPE,
				'storeCurrency'  => get_option( 'woocommerce_currency', 'USD' ),
				'defaultTimezone' => wp_timezone_string(),
				'metaKeys'       => array(
					'preEventStart'       => Campaign::META_PRE_EVENT_START,
					'startDatetime'       => Campaign::META_START_DATETIME,
					'endDatetime'         => Campaign::META_END_DATETIME,
					'timezone'            => Campaign::META_TIMEZONE,
					'goalAmount'          => Campaign::META_GOAL_AMOUNT,
					'currency'            => Campaign::META_CURRENCY,
					'donationProducts'    => Campaign::META_DONATION_PRODUCTS,
					'statusOverride'      => Campaign::META_STATUS_OVERRIDE,
					'colorPrimary'        => Campaign::META_COLOR_PRIMARY,
					'colorSecondary'      => Campaign::META_COLOR_SECONDARY,
					'colorAccent'         => Campaign::META_COLOR_ACCENT,
					'colorSurface'        => Campaign::META_COLOR_SURFACE,
					'colorMuted'          => Campaign::META_COLOR_MUTED,
				),
			)
		);
	}
}
