<?php
/**
 * The Giving Day Blocks bootstrap file.
 *
 * @since       0.1.0
 * @version     0.1.0
 * @author      Team51 Special Projects
 * @license     GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:             Giving Day Blocks
 * Plugin URI:              https://github.com/a8cteam51/giving-day-blocks
 * Description:             An out-of-the-box Giving Day product for WooCommerce. Blocks, data layer, and admin dashboard for running a fundraiser event. Requires team51-donations.
 * Version:                 0.1.0
 * Requires at least:       6.4
 * Requires PHP:            8.1
 * Author:                  Team51 Special Projects
 * Author URI:              https://wpspecialprojects.wordpress.com
 * License:                 GPL v3 or later
 * License URI:             https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:             giving-day-blocks
 * Domain Path:             /languages
 * Requires Plugins:        team51-donations
 * WC requires at least:    7.5
 **/

defined( 'ABSPATH' ) || exit;

define( 'GIVING_DAY_BLOCKS_VERSION', '0.1.0' );
define( 'GIVING_DAY_BLOCKS_BASENAME', plugin_basename( __FILE__ ) );
define( 'GIVING_DAY_BLOCKS_PATH', plugin_dir_path( __FILE__ ) );
define( 'GIVING_DAY_BLOCKS_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'giving-day-blocks',
			false,
			dirname( GIVING_DAY_BLOCKS_BASENAME ) . '/languages'
		);
	}
);

/**
 * Returns an array of unmet dependencies, keyed by a short identifier.
 *
 * Each entry is an array shape: [ 'label' => string, 'reason' => string ].
 *
 * @return array<string, array{label: string, reason: string}>
 */
function giving_day_blocks_get_unmet_dependencies(): array {
	$unmet = array();

	if ( ! is_file( GIVING_DAY_BLOCKS_PATH . 'vendor/autoload.php' ) ) {
		$unmet['autoload'] = array(
			'label'  => __( 'Composer autoloader', 'giving-day-blocks' ),
			'reason' => __( 'The plugin appears to be corrupted. Run `composer install` inside the plugin folder, or reinstall the plugin.', 'giving-day-blocks' ),
		);
	}

	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		$unmet['woocommerce'] = array(
			'label'  => __( 'WooCommerce', 'giving-day-blocks' ),
			'reason' => __( 'Install and activate WooCommerce 7.5 or newer.', 'giving-day-blocks' ),
		);
	}

	// team51-donations identifies itself via the WPCOMSP_DONATIONS_* constants defined in its bootstrap file.
	if ( ! defined( 'WPCOMSP_DONATIONS_METADATA' ) ) {
		$unmet['team51-donations'] = array(
			'label'  => __( 'Team51 Donations (team51-donations)', 'giving-day-blocks' ),
			'reason' => __( 'Install and activate team51-donations. Giving Day Blocks tags donation orders produced by that plugin with campaign metadata.', 'giving-day-blocks' ),
		);
	}

	return $unmet;
}

add_action(
	'plugins_loaded',
	static function () {
		$unmet = giving_day_blocks_get_unmet_dependencies();

		if ( ! empty( $unmet ) ) {
			add_action(
				'admin_notices',
				static function () use ( $unmet ) {
					$items = array();
					foreach ( $unmet as $dependency ) {
						$items[] = sprintf(
							'<li><strong>%s</strong> — %s</li>',
							esc_html( $dependency['label'] ),
							esc_html( $dependency['reason'] )
						);
					}

					printf(
						'<div class="notice notice-error"><p>%s</p><ul style="list-style:disc;margin-left:1.5em">%s</ul></div>',
						esc_html__( 'Giving Day Blocks is inactive because one or more requirements are not met:', 'giving-day-blocks' ),
						implode( '', $items ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item is escaped above.
					);
				}
			);
			return;
		}

		require_once GIVING_DAY_BLOCKS_PATH . 'vendor/autoload.php';

		\Team51\GivingDay\Plugin::get_instance()->initialize();
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Flag a "seed sample data on first run" marker at activation time.
 *
 * The activation callback fires after `init` has already run for the
 * current admin request, so our CPTs/taxonomies aren't registered yet —
 * the actual seeding runs on the next request via `MockData::maybe_seed()`
 * (hooked on `admin_init`). We just raise an option flag here.
 */
register_activation_hook(
	__FILE__,
	static function () {
		// Option name duplicated (rather than referencing the class constant)
		// so activation never depends on the composer autoloader having been
		// loaded yet — this runs before plugins_loaded on a clean boot.
		if ( false === get_option( 'giving_day_blocks_seeded_ids', false ) ) {
			update_option( 'giving_day_blocks_pending_seed', 1, false );
		}
	}
);
