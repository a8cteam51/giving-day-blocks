<?php
/**
 * Giving Day admin menu adjustments.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.1.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Challenge;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamGroup;

defined( 'ABSPATH' ) || exit;

/**
 * Tweaks the left-hand Giving Day submenu: drops "Add New Campaign" and
 * enforces a stable item order regardless of CPT registration order.
 */
final class Menu {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'adjust_giving_day_submenu' ), 999 );
	}

	/**
	 * @return void
	 */
	public function adjust_giving_day_submenu(): void {
		global $submenu;

		$parent = 'edit.php?post_type=' . Campaign::POST_TYPE;
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		remove_submenu_page( $parent, 'post-new.php?post_type=' . Campaign::POST_TYPE );

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$by_slug = array();
		foreach ( $submenu[ $parent ] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[2] ) || ! is_string( $row[2] ) ) {
				continue;
			}
			$key                = $this->normalize_submenu_slug( $row[2] );
			$by_slug[ $key ]    = $row;
			$by_slug[ $key ][2] = $row[2];
		}

		$desired = array(
			'edit.php?post_type=' . Campaign::POST_TYPE,
			'edit-tags.php?taxonomy=' . Cause::TAXONOMY . '&post_type=' . Beneficiary::POST_TYPE,
			'edit.php?post_type=' . Beneficiary::POST_TYPE,
			'edit.php?post_type=' . Team::POST_TYPE,
			'edit-tags.php?taxonomy=' . TeamGroup::TAXONOMY . '&post_type=' . Team::POST_TYPE,
			'edit.php?post_type=' . GivingMatch::POST_TYPE,
			'edit.php?post_type=' . Challenge::POST_TYPE,
			SettingsPage::PAGE_SLUG,
		);

		$ordered = array();
		foreach ( $desired as $slug ) {
			$key = $this->normalize_submenu_slug( $slug );
			if ( isset( $by_slug[ $key ] ) ) {
				$ordered[] = $by_slug[ $key ];
				unset( $by_slug[ $key ] );
			}
		}

		foreach ( $by_slug as $row ) {
			$ordered[] = $row;
		}

		$submenu[ $parent ] = $ordered;
	}

	private function normalize_submenu_slug( string $slug ): string {
		return str_replace( '&amp;', '&', $slug );
	}
}
