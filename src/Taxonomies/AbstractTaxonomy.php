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
	}

	/**
	 * Registers the taxonomy with WordPress.
	 */
	public function register_taxonomy(): void {
		register_taxonomy( $this->get_taxonomy(), $this->get_object_types(), $this->get_taxonomy_args() );
	}
}
