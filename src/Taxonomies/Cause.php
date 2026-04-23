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

	public function get_taxonomy(): string {
		return self::TAXONOMY;
	}

	public function get_object_types(): array {
		return array( Beneficiary::POST_TYPE );
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
