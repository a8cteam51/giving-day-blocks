<?php
/**
 * Injects "Import data" page-title-action buttons onto the Beneficiary,
 * Team, and Cause list screens. The Offline Donations admin page renders
 * its own link inline (full markup control) instead of going through this.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.5.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Services\Importer;
use Team51\GivingDay\Taxonomies\Cause;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a shortcut button next to each list screen's "Add New" / title.
 */
final class ImportShortcuts {

	/**
	 * Hooks the admin footer renderer.
	 */
	public function register(): void {
		add_action( 'admin_footer', array( $this, 'maybe_render' ) );
	}

	/**
	 * Emits a tiny script that splices an `.page-title-action` link next
	 * to the screen's `.wp-header-end` divider when the current screen is
	 * one of the three list screens we cover. No-ops otherwise.
	 *
	 * The DOM splice is unfortunately the cleanest path: core's CPT and
	 * taxonomy list screens render the title and "Add New" button server-
	 * side with no filter we can target to inject siblings.
	 */
	public function maybe_render(): void {
		if ( ! current_user_can( ImportPage::REQUIRED_CAP ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$type = $this->resolve_type_for_screen( $screen->id );
		if ( null === $type ) {
			return;
		}

		$url   = ImportPage::url_for_type( $type );
		$label = __( 'Import data', 'giving-day-blocks' );
		?>
		<script>
		( function () {
			var run = function () {
				var anchor = document.querySelector( '.wp-header-end' );
				if ( ! anchor || ! anchor.parentNode ) {
					return;
				}
				if ( anchor.parentNode.querySelector( '.giving-day-import-shortcut' ) ) {
					return;
				}
				var link = document.createElement( 'a' );
				link.href = <?php echo wp_json_encode( $url ); ?>;
				link.className = 'page-title-action giving-day-import-shortcut';
				link.textContent = <?php echo wp_json_encode( $label ); ?>;
				anchor.parentNode.insertBefore( link, anchor );
			};
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', run );
			} else {
				run();
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Maps a `WP_Screen->id` to the Importer type slug we should jump to.
	 *
	 * @param string $screen_id Current screen ID.
	 */
	private function resolve_type_for_screen( string $screen_id ): ?string {
		$map = array(
			'edit-' . Beneficiary::POST_TYPE => Importer::TYPE_BENEFICIARIES,
			'edit-' . Team::POST_TYPE        => Importer::TYPE_TEAMS,
			'edit-' . Cause::TAXONOMY        => Importer::TYPE_CAUSES,
		);
		return $map[ $screen_id ] ?? null;
	}
}
