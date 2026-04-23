<?php
/**
 * Base class for the plugin's taxonomies.
 *
 * @package Team51\GivingDay\Taxonomies
 * @since   0.1.0
 */

namespace Team51\GivingDay\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Shared scaffolding for Giving Day taxonomies.
 */
abstract class AbstractTaxonomy {

	/**
	 * REST namespace used by every Giving Day taxonomy.
	 */
	public const REST_NAMESPACE = 'giving-day/v1';

	/**
	 * Returns the taxonomy slug.
	 */
	abstract public function get_taxonomy(): string;

	/**
	 * Returns the list of post types this taxonomy attaches to.
	 *
	 * @return string[]
	 */
	abstract public function get_object_types(): array;

	/**
	 * Returns the arguments passed to `register_taxonomy()`.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function get_taxonomy_args(): array;

	/**
	 * Hooks the registration callback onto `init`.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_submenu' ) );
		add_filter( 'parent_file', array( $this, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ) );
	}

	/**
	 * Registers the taxonomy with WordPress.
	 */
	public function register_taxonomy(): void {
		register_taxonomy( $this->get_taxonomy(), $this->get_object_types(), $this->get_taxonomy_args() );
	}

	/**
	 * Ensures the taxonomy appears in the admin menu when its attached post
	 * types have been redirected to a different parent menu via `show_in_menu`.
	 *
	 * WordPress auto-adds a taxonomy submenu under `edit.php?post_type={pt}`.
	 * When the post type's `show_in_menu` points to another slug (e.g. the
	 * Giving Day top-level menu), that default anchor no longer renders, so
	 * the taxonomy link disappears. This hook re-attaches it under the menu
	 * the post type actually lives in.
	 */
	public function register_admin_submenu(): void {
		$taxonomy_object = get_taxonomy( $this->get_taxonomy() );
		if ( ! $taxonomy_object || empty( $taxonomy_object->show_ui ) ) {
			return;
		}

		foreach ( $this->get_object_types() as $object_type ) {
			$post_type_object = get_post_type_object( $object_type );
			if ( ! $post_type_object || empty( $post_type_object->show_ui ) ) {
				continue;
			}

			if ( ! is_string( $post_type_object->show_in_menu ) ) {
				continue;
			}

			$parent_slug = $post_type_object->show_in_menu;
			$menu_slug   = 'edit-tags.php?taxonomy=' . $this->get_taxonomy() . '&post_type=' . $object_type;
			$label       = $taxonomy_object->labels->menu_name ?? $taxonomy_object->labels->name;

			add_submenu_page(
				$parent_slug,
				$taxonomy_object->labels->name,
				$label,
				$taxonomy_object->cap->manage_terms,
				$menu_slug
			);
		}
	}

	/**
	 * Keeps the correct top-level menu expanded and highlighted when editing
	 * terms of this taxonomy.
	 *
	 * Without this, WordPress derives the parent menu from the taxonomy's
	 * first attached post type, which breaks when that post type was moved
	 * under a different parent via `show_in_menu`.
	 *
	 * @param string $parent_file The parent menu slug WP computed.
	 * @return string
	 */
	public function filter_parent_file( string $parent_file ): string {
		$parent_slug = $this->get_parent_menu_slug();
		if ( null === $parent_slug ) {
			return $parent_file;
		}

		if ( $this->is_current_screen_taxonomy() ) {
			return $parent_slug;
		}

		return $parent_file;
	}

	/**
	 * Ensures the taxonomy submenu item is highlighted as the active one.
	 *
	 * @param string|null $submenu_file The submenu slug WP computed.
	 * @return string|null
	 */
	public function filter_submenu_file( $submenu_file ) {
		if ( null !== $this->get_parent_menu_slug() && $this->is_current_screen_taxonomy() ) {
			$object_types = $this->get_object_types();
			$object_type  = reset( $object_types );
			return 'edit-tags.php?taxonomy=' . $this->get_taxonomy() . '&post_type=' . $object_type;
		}

		return $submenu_file;
	}

	/**
	 * Returns the redirected parent menu slug shared by this taxonomy's
	 * attached post types, or null if no redirect is in effect.
	 */
	private function get_parent_menu_slug(): ?string {
		foreach ( $this->get_object_types() as $object_type ) {
			$post_type_object = get_post_type_object( $object_type );
			if ( $post_type_object && is_string( $post_type_object->show_in_menu ) ) {
				return $post_type_object->show_in_menu;
			}
		}
		return null;
	}

	/**
	 * Whether the current admin screen is the edit-terms screen for this taxonomy.
	 */
	private function is_current_screen_taxonomy(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen && $screen->taxonomy === $this->get_taxonomy();
	}
}
