<?php
/**
 * Base class for the plugin's custom post types.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Shared scaffolding for Giving Day CPTs.
 *
 * Each subclass defines its post type slug, registration args, and the list of
 * post meta fields to expose in REST. The base class wires them up on `init`.
 */
abstract class AbstractPostType {

	/**
	 * REST namespace used by every Giving Day CPT and endpoint.
	 */
	public const REST_NAMESPACE = 'giving-day/v1';

	/**
	 * Returns the post type slug (e.g. `giving_campaign`).
	 *
	 * @return string
	 */
	abstract public function get_post_type(): string;

	/**
	 * Returns the arguments passed to `register_post_type()`.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function get_post_type_args(): array;

	/**
	 * Returns the list of meta fields to register for this post type.
	 *
	 * Each entry is: meta_key => args (mirrors the second argument to
	 * `register_post_meta()`), with `show_in_rest` defaulting to true.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_meta_fields(): array {
		return array();
	}

	/**
	 * Hooks the registration callbacks onto `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ), 11 );
	}

	/**
	 * Registers the post type with WordPress.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type( $this->get_post_type(), $this->get_post_type_args() );
	}

	/**
	 * Registers post meta for the post type.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( $this->get_meta_fields() as $meta_key => $args ) {
			$args = wp_parse_args(
				$args,
				array(
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static fn() => current_user_can( 'manage_options' ),
				)
			);

			register_post_meta( $this->get_post_type(), $meta_key, $args );
		}
	}

	/**
	 * Convenience: build the `show_in_rest.schema` array for an array of integers.
	 *
	 * @return array<string, mixed>
	 */
	protected static function rest_array_of_integers(): array {
		return array(
			'schema' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
		);
	}
}
