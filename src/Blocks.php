<?php
/**
 * Registers the plugin's Gutenberg blocks from the compiled block manifest.
 *
 * Each block's source lives in `blocks/src/<name>/` and is built into
 * `blocks/build/<name>/` by `npm run build`. Registration uses the
 * `wp_register_block_types_from_metadata_collection` manifest when
 * available (WP 6.7+), falling back to per-folder registration.
 *
 * @package Team51\GivingDay
 * @since   0.1.0
 */

namespace Team51\GivingDay;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	/**
	 * Hooks block registration onto `init`.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
	}

	/**
	 * Registers every built block under blocks/build/.
	 */
	public function register_blocks(): void {
		$build_dir = GIVING_DAY_BLOCKS_PATH . 'blocks/build';
		if ( ! is_dir( $build_dir ) ) {
			return;
		}

		$manifest = $build_dir . '/blocks-manifest.php';
		if (
			is_file( $manifest )
			&& function_exists( 'wp_register_block_types_from_metadata_collection' )
		) {
			wp_register_block_types_from_metadata_collection( $build_dir, $manifest );
			return;
		}

		$entries = glob( $build_dir . '/*/block.json' );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $entry ) {
			register_block_type_from_metadata( dirname( $entry ) );
		}
	}
}
