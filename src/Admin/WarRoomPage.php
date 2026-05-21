<?php
/**
 * Giving Days → War Room admin page.
 *
 * Same dashboard the public-side `giving-day/war-room` block renders, mounted
 * inside wp-admin so organizers don't need a published page to use it. Both
 * surfaces share the React tree under `blocks/src/war-room/` and the same
 * `/giving-day/v1/campaign/{id}/warroom` REST endpoint.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.5.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the submenu page + asset enqueues.
 */
final class WarRoomPage {

	public const PAGE_SLUG    = 'giving-day-warroom';
	public const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Default set of panels shown by the admin dashboard. Mirrors the
	 * block.json default for `giving-day/war-room`, so opening the admin
	 * page renders the same layout as a fresh block insertion.
	 */
	private const DEFAULT_PANELS = array(
		'summary',
		'pace',
		'matches',
		'breakdown',
		'top_teams',
		'top_beneficiaries',
		'recent',
	);

	/**
	 * Hooks the menu + asset registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the submenu under Giving Days.
	 */
	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Campaign::POST_TYPE,
			__( 'War Room', 'giving-day-blocks' ),
			__( 'War Room', 'giving-day-blocks' ),
			self::REQUIRED_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the same view bundle the front-end block uses. The bundle
	 * picks up any `[data-giving-day-warroom]` shell on the page (admin or
	 * front-end), so no separate JS build is needed.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// add_submenu_page returns a parent-prefixed hook like
		// "giving_campaign_page_{PAGE_SLUG}", but the slug is stable.
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$build_dir = GIVING_DAY_BLOCKS_PATH . 'blocks/build/war-room';
		$build_url = GIVING_DAY_BLOCKS_URL . 'blocks/build/war-room';

		$asset_file = $build_dir . '/view.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
			'version'      => GIVING_DAY_BLOCKS_VERSION,
		);

		wp_enqueue_script(
			'giving-day-warroom-view',
			$build_url . '/view.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// The block style ships its compiled CSS as style-index.css; reuse
		// it so the admin surface looks identical to the front-end block.
		$style_file = $build_dir . '/style-index.css';
		if ( is_readable( $style_file ) ) {
			wp_enqueue_style(
				'giving-day-warroom-view',
				$build_url . '/style-index.css',
				array(),
				$asset['version']
			);
		}
	}

	/**
	 * Renders the page chrome: campaign picker + the React shell that
	 * view.js hydrates.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view the war room.', 'giving-day-blocks' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$campaign_id = isset( $_GET['campaign'] ) ? absint( wp_unslash( $_GET['campaign'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 0 === $campaign_id ) {
			$campaign_id = (int) get_option( 'giving_day_active_campaign_id', 0 );
		}

		echo '<div class="wrap giving-day-warroom-admin">';
		echo '<h1>' . esc_html__( 'War Room', 'giving-day-blocks' ) . '</h1>';

		$this->render_campaign_switcher( $campaign_id );

		if ( $campaign_id <= 0 ) {
			echo '<p>' . esc_html__( 'Pick a campaign to load the dashboard.', 'giving-day-blocks' ) . '</p>';
			echo '</div>';
			return;
		}

		$campaign = get_post( $campaign_id );
		if ( ! $campaign || Campaign::POST_TYPE !== $campaign->post_type ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Campaign not found.', 'giving-day-blocks' ) );
			echo '</div>';
			return;
		}

		$initial = Aggregator::warroom_payload( $campaign_id );

		printf(
			'<div class="giving-day-warroom" data-giving-day-warroom="1" data-campaign-id="%d" data-panels="%s" data-refresh-ms="10000" data-initial="%s"><p class="giving-day-warroom__loading">%s</p></div>',
			(int) $campaign_id,
			esc_attr( wp_json_encode( self::DEFAULT_PANELS ) ),
			esc_attr( wp_json_encode( $initial ) ),
			esc_html__( 'Loading war room…', 'giving-day-blocks' )
		);

		echo '</div>';
	}

	/**
	 * Renders a small campaign-switcher form so admins can flip between
	 * campaigns without leaving the page.
	 *
	 * @param int $current_id Currently-selected campaign ID.
	 */
	private function render_campaign_switcher( int $current_id ): void {
		$campaigns = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $campaigns ) ) {
			echo '<p>' . esc_html__( 'No published campaigns yet.', 'giving-day-blocks' ) . '</p>';
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" class="giving-day-warroom-admin__switcher">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( Campaign::POST_TYPE ) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<label for="giving-day-warroom-campaign" class="screen-reader-text">' . esc_html__( 'Select campaign', 'giving-day-blocks' ) . '</label>';
		echo '<select id="giving-day-warroom-campaign" name="campaign" onchange="this.form.submit()">';
		echo '<option value="0">' . esc_html__( '— Select campaign —', 'giving-day-blocks' ) . '</option>';
		foreach ( $campaigns as $c ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $c->ID,
				selected( $current_id, (int) $c->ID, false ),
				esc_html( get_the_title( $c ) )
			);
		}
		echo '</select>';
		echo '<noscript><button type="submit" class="button">' . esc_html__( 'Go', 'giving-day-blocks' ) . '</button></noscript>';
		echo '</form>';
	}
}
