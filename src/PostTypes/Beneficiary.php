<?php
/**
 * Beneficiary custom post type.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_beneficiary` custom post type.
 *
 * A Beneficiary is a donation destination — the "what is being funded." In a
 * university context these are things like "Carl Sagan Institute" or
 * "Cornell AgriTech." Beneficiaries are long-lived (they persist across years)
 * and participate in one or more Campaigns via the `_giving_beneficiary_campaigns`
 * array meta.
 *
 * Beneficiaries are classified with the `giving_cause` hierarchical taxonomy
 * (e.g. Science > Animal Health), which powers cause-based browsing flows.
 *
 * Distinct from Teams: Teams are *who* is raising (groups of fundraisers);
 * Beneficiaries are *what* is being funded (destinations). A donation order
 * can be tagged with both.
 */
final class Beneficiary extends AbstractPostType {

	public const POST_TYPE = 'giving_beneficiary';
	public const REST_BASE = 'beneficiaries';

	public const META_CAMPAIGN_IDS = '_giving_beneficiary_campaigns';
	public const META_GOAL_AMOUNT  = '_giving_beneficiary_goal_amount';
	public const META_PARENT_ORG   = '_giving_beneficiary_parent_org';

	public function get_post_type(): string {
		return self::POST_TYPE;
	}

	protected function get_post_type_args(): array {
		return array(
			'labels'              => array(
				'name'               => _x( 'Beneficiaries', 'post type general name', 'giving-day-blocks' ),
				'singular_name'      => _x( 'Beneficiary', 'post type singular name', 'giving-day-blocks' ),
				'menu_name'          => __( 'Beneficiaries', 'giving-day-blocks' ),
				'add_new'            => _x( 'Add New', 'beneficiary', 'giving-day-blocks' ),
				'add_new_item'       => __( 'Add New Beneficiary', 'giving-day-blocks' ),
				'new_item'           => __( 'New Beneficiary', 'giving-day-blocks' ),
				'edit_item'          => __( 'Edit Beneficiary', 'giving-day-blocks' ),
				'view_item'          => __( 'View Beneficiary', 'giving-day-blocks' ),
				'all_items'          => __( 'Beneficiaries', 'giving-day-blocks' ),
				'search_items'       => __( 'Search Beneficiaries', 'giving-day-blocks' ),
				'not_found'          => __( 'No beneficiaries found.', 'giving-day-blocks' ),
				'not_found_in_trash' => __( 'No beneficiaries found in trash.', 'giving-day-blocks' ),
			),
			'description'         => __( 'Donation destinations (e.g. funds, institutes, programs).', 'giving-day-blocks' ),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=' . Campaign::POST_TYPE,
			'show_in_rest'        => true,
			'rest_base'           => self::REST_BASE,
			'rest_namespace'      => self::REST_NAMESPACE,
			'menu_icon'           => 'dashicons-awards',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'exclude_from_search' => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);
	}

	protected function get_meta_fields(): array {
		return array(
			self::META_CAMPAIGN_IDS => array(
				'type'         => 'array',
				'description'  => __( 'IDs of the Campaigns this beneficiary is accepting donations for.', 'giving-day-blocks' ),
				'default'      => array(),
				'single'       => true,
				'show_in_rest' => self::rest_array_of_integers(),
			),
			self::META_GOAL_AMOUNT  => array(
				'type'        => 'number',
				'description' => __( 'Optional beneficiary-level fundraising goal.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_PARENT_ORG   => array(
				'type'        => 'string',
				'description' => __( 'Optional display name of a parent organization (e.g. "College of Arts & Sciences"). Free-form.', 'giving-day-blocks' ),
				'default'     => '',
			),
		);
	}
}
