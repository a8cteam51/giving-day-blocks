<?php
/**
 * Match editor: Gutenberg sidebar panels for `giving_match` posts.
 *
 * Mirrors the Campaign editor (CampaignEditor) but for individual sponsor
 * matches. Drives the "How does this match work?" / "When is it running?"
 * / "Sponsor" panels described in PLAN.md § 5.6 and binds them to the
 * post meta registered server-side in PostTypes\GivingMatch.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.1.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\GivingMatch;

defined( 'ABSPATH' ) || exit;

final class MatchEditor {

	private const HANDLE = 'giving-day-blocks-match-editor';

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the sidebar bundle only on Match edit screens.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || GivingMatch::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = GIVING_DAY_BLOCKS_PATH . 'assets/build/admin-match/index.asset.php';
		if ( ! is_file( $asset_file ) ) {
			// Build artifact missing; bail quietly so the editor still
			// loads. Run `npm run build:match` (or full `npm run build`)
			// to regenerate.
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			GIVING_DAY_BLOCKS_URL . 'assets/build/admin-match/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? GIVING_DAY_BLOCKS_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'giving-day-blocks' );

		$style_file = GIVING_DAY_BLOCKS_PATH . 'assets/build/admin-match/index.css';
		if ( is_file( $style_file ) ) {
			wp_enqueue_style(
				self::HANDLE,
				GIVING_DAY_BLOCKS_URL . 'assets/build/admin-match/index.css',
				array( 'wp-components' ),
				$asset['version'] ?? GIVING_DAY_BLOCKS_VERSION
			);
		}

		wp_localize_script(
			self::HANDLE,
			'givingDayMatchEditor',
			array(
				'postType'      => GivingMatch::POST_TYPE,
				'campaignType'  => Campaign::POST_TYPE,
				'storeCurrency' => get_option( 'woocommerce_currency', 'USD' ),
				'metaKeys'      => array(
					'campaignId'      => GivingMatch::META_CAMPAIGN_ID,
					'matchType'       => GivingMatch::META_TYPE,
					'sponsorName'     => GivingMatch::META_SPONSOR_NAME,
					'sponsorLogoId'   => GivingMatch::META_SPONSOR_LOGO,
					'multiplier'      => GivingMatch::META_MULTIPLIER,
					'capAmount'       => GivingMatch::META_CAP_AMOUNT,
					'donorThreshold'  => GivingMatch::META_DONOR_THRESHOLD,
					'unlockAmount'    => GivingMatch::META_UNLOCK_AMOUNT,
					'startDatetime'   => GivingMatch::META_START_DATETIME,
					'endDatetime'     => GivingMatch::META_END_DATETIME,
					'active'          => GivingMatch::META_ACTIVE,
					'matchedOverride' => GivingMatch::META_MATCHED_OVERRIDE,
					'donorsOverride'  => GivingMatch::META_DONORS_OVERRIDE,
				),
				'matchTypes'    => array(
					'dollarForDollar' => GivingMatch::TYPE_DOLLAR_FOR_DOLLAR,
					'donorUnlock'     => GivingMatch::TYPE_DONOR_UNLOCK,
				),
			)
		);
	}
}
