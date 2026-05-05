<?php
/**
 * Cause taxonomy.
 *
 * @package Team51\GivingDay\Taxonomies
 * @since   0.1.0
 */

namespace Team51\GivingDay\Taxonomies;

use Team51\GivingDay\PostTypes\Beneficiary;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_cause` hierarchical taxonomy on Beneficiaries.
 *
 * Used to power the "Give to a cause" browsing flow: top-level causes like
 * "Science" or "Arts" contain sub-causes, and each Beneficiary is tagged
 * with one or more terms. Donors can click a cause and see all beneficiaries
 * under it.
 */
final class Cause extends AbstractTaxonomy {

	public const TAXONOMY  = 'giving_cause';
	public const REST_BASE = 'causes';

	/**
	 * Term meta key for the Cause Area image (attachment ID).
	 *
	 * Surfaced as a card thumbnail in the front-end Cause Areas Browser block.
	 * The internal "Cause" wording is kept on the taxonomy/slug; the
	 * "Cause Area" wording lives on the front-end block UI only.
	 */
	public const META_IMAGE_ID = '_giving_cause_image_id';

	public function get_taxonomy(): string {
		return self::TAXONOMY;
	}

	public function get_object_types(): array {
		return array( Beneficiary::POST_TYPE );
	}

	/**
	 * Hooks taxonomy and term-meta registration.
	 */
	public function register(): void {
		parent::register();
		add_action( 'init', array( $this, 'register_term_meta' ) );
	}

	/**
	 * Registers the Cause Area image term meta with REST exposure.
	 */
	public function register_term_meta(): void {
		register_term_meta(
			self::TAXONOMY,
			self::META_IMAGE_ID,
			array(
				'type'              => 'integer',
				'description'       => __( 'Attachment ID for the Cause Area image displayed in the Cause Areas Browser block.', 'giving-day-blocks' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static function () {
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	/**
	 * Returns the attachment ID associated with a Cause term, or 0.
	 *
	 * @param int $term_id Cause term ID.
	 */
	public static function get_image_id( int $term_id ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}
		return (int) get_term_meta( $term_id, self::META_IMAGE_ID, true );
	}

	/**
	 * Returns a URL to the Cause term's image at the given size, or '' when none.
	 *
	 * @param int    $term_id Cause term ID.
	 * @param string $size    Image size keyword.
	 */
	public static function get_image_url( int $term_id, string $size = 'medium' ): string {
		$attachment_id = self::get_image_id( $term_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $attachment_id, $size );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Counts published Beneficiaries tagged with the term.
	 *
	 * The term object's own `count` field reflects only direct tagging, so we
	 * pre-expand descendant term IDs ourselves and run a single tax_query with
	 * `include_children => false`. This avoids WP_Query expanding the tree a
	 * second time per term and keeps the cost predictable when the grid renders
	 * many cards.
	 *
	 * @param int  $term_id          Cause term ID.
	 * @param bool $include_children Whether to roll up descendant terms.
	 */
	public static function count_beneficiaries( int $term_id, bool $include_children = true ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}

		$term_ids = array( $term_id );
		if ( $include_children ) {
			$descendants = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'child_of'   => $term_id,
					'fields'     => 'ids',
					'hide_empty' => false,
				)
			);
			if ( is_array( $descendants ) ) {
				foreach ( $descendants as $descendant_id ) {
					$term_ids[] = (int) $descendant_id;
				}
			}
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Beneficiary::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a single tax query is required to count posts; we pre-expanded descendants to avoid WP re-expanding them.
					array(
						'taxonomy'         => self::TAXONOMY,
						'field'            => 'term_id',
						'terms'            => $term_ids,
						'include_children' => false,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	protected function get_taxonomy_args(): array {
		return array(
			'labels'            => array(
				'name'                     => _x( 'Beneficiary Causes', 'taxonomy general name', 'giving-day-blocks' ),
				'singular_name'            => _x( 'Beneficiary Cause', 'taxonomy singular name', 'giving-day-blocks' ),
				'menu_name'                => __( 'Causes', 'giving-day-blocks' ),
				'all_items'                => __( 'All Beneficiary Causes', 'giving-day-blocks' ),
				'parent_item'              => __( 'Parent Cause', 'giving-day-blocks' ),
				'edit_item'                => __( 'Edit Beneficiary Cause', 'giving-day-blocks' ),
				'add_new_item'             => __( 'Add New Beneficiary Cause', 'giving-day-blocks' ),
				'search_items'             => __( 'Search Beneficiary Causes', 'giving-day-blocks' ),
				'not_found'                => __( 'No causes found.', 'giving-day-blocks' ),
				'parent_field_description' => __( 'Assign a parent Cause to build a hierarchy for Beneficiaries (e.g. use "Science" as the parent of "Geology", "Space").', 'giving-day-blocks' ),
				'name_field_description'   => __( 'The name is how this Cause appears on your site when Beneficiaries are browsed by cause.', 'giving-day-blocks' ),
				'slug_field_description'   => __( 'The URL-friendly version of the Cause name.', 'giving-day-blocks' ),
				'desc_field_description'   => __( 'Optional description for this Cause. May be shown when browsing Beneficiaries by cause.', 'giving-day-blocks' ),
			),
			'description'       => __( 'Hierarchical categories for Beneficiaries (e.g. Science > Space).', 'giving-day-blocks' ),
			'hierarchical'      => true,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => self::REST_BASE,
			'rest_namespace'    => self::REST_NAMESPACE,
			'rewrite'           => false,
		);
	}
}
