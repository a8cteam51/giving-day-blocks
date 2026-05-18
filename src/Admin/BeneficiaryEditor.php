<?php
/**
 * Beneficiary editor: Gutenberg sidebar panel for `giving_beneficiary` posts.
 *
 * Enqueues the React bundle that mounts a "Beneficiary details" document
 * settings panel on Beneficiary edit screens, binding to the meta
 * registered server-side in PostTypes\Beneficiary::get_meta_fields().
 *
 * @package Team51\GivingDay\Admin
 * @since   0.5.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Beneficiary document-settings sidebar on Beneficiary edit screens.
 */
final class BeneficiaryEditor {

	private const HANDLE = 'giving-day-blocks-beneficiary-editor';

	/**
	 * Hooks the editor-assets enqueuer.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the React bundle when the current screen is a Beneficiary post.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Beneficiary::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = GIVING_DAY_BLOCKS_PATH . 'assets/build/admin-beneficiary/index.asset.php';
		if ( ! is_file( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			GIVING_DAY_BLOCKS_URL . 'assets/build/admin-beneficiary/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? GIVING_DAY_BLOCKS_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'giving-day-blocks' );

		$style_file = GIVING_DAY_BLOCKS_PATH . 'assets/build/admin-beneficiary/index.css';
		if ( is_file( $style_file ) ) {
			wp_enqueue_style(
				self::HANDLE,
				GIVING_DAY_BLOCKS_URL . 'assets/build/admin-beneficiary/index.css',
				array( 'wp-components' ),
				$asset['version'] ?? GIVING_DAY_BLOCKS_VERSION
			);
		}

		wp_localize_script(
			self::HANDLE,
			'givingDayBeneficiaryEditor',
			array(
				'postType'      => Beneficiary::POST_TYPE,
				'campaignType'  => Campaign::POST_TYPE,
				'storeCurrency' => get_option( 'woocommerce_currency', 'USD' ),
				'metaKeys'      => array(
					'campaignIds' => Beneficiary::META_CAMPAIGN_IDS,
					'goalAmount'  => Beneficiary::META_GOAL_AMOUNT,
					'parentOrg'   => Beneficiary::META_PARENT_ORG,
				),
			)
		);
	}
}
