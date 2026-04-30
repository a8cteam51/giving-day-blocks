<?php
/**
 * Beneficiary / Fund custom post type.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_beneficiary` custom post type.
 *
 * A Beneficiary is the *destination* of a donation — the entity money is booked
 * against. The post type is named "Beneficiary" because that word is the only
 * one that reads accurately across both audiences this plugin targets:
 *
 *  - Universities / hospitals / schools — where a Beneficiary is typically a
 *    named *fund* or *program* (e.g. "Smith Scholarship Fund", "Robotics Lab").
 *  - Community foundations — where a Beneficiary is typically an external
 *    *nonprofit* (e.g. "Local Food Bank", "Animal Shelter").
 *
 * The admin UI labels surface the dual reading as **"Beneficiary / Fund"** so
 * higher-ed organizers immediately recognize the screen as the one where their
 * funds live, while community-foundation organizers still find the term they
 * expect. The internal slug stays `giving_beneficiary` so REST clients and
 * stored data are stable across both segments.
 *
 * **Hierarchy / per-unit goals.** The CPT is `hierarchical => true` so a single
 * model can cover both flat and nested setups without a second CPT:
 *
 *  - Universities can model `College of Arts & Sciences → Dean's Excellence Fund`
 *    by setting the parent post on each child fund. Per-unit totals roll up
 *    through the hierarchy at query time.
 *  - Community foundations skip the hierarchy entirely and create flat,
 *    top-level posts. Zero overhead.
 *
 * The `_giving_beneficiary_parent_org` string field stays as a lightweight
 * **display fallback** for the flat case: when there is no parent post,
 * surfaces (block render, leaderboards) can show this free-form label
 * (e.g. "Member of: Health Coalition of Travis County") without forcing a
 * second post. {@see Beneficiary::display_unit_label()} resolves the right
 * label for either shape.
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
				'name'               => _x( 'Beneficiaries / Funds', 'post type general name', 'giving-day-blocks' ),
				'singular_name'      => _x( 'Beneficiary / Fund', 'post type singular name', 'giving-day-blocks' ),
				'menu_name'          => __( 'Beneficiaries / Funds', 'giving-day-blocks' ),
				'add_new'            => _x( 'Add New', 'beneficiary', 'giving-day-blocks' ),
				'add_new_item'       => __( 'Add New Beneficiary / Fund', 'giving-day-blocks' ),
				'new_item'           => __( 'New Beneficiary / Fund', 'giving-day-blocks' ),
				'edit_item'          => __( 'Edit Beneficiary / Fund', 'giving-day-blocks' ),
				'view_item'          => __( 'View Beneficiary / Fund', 'giving-day-blocks' ),
				'all_items'          => __( 'Beneficiaries / Funds', 'giving-day-blocks' ),
				'search_items'       => __( 'Search Beneficiaries / Funds', 'giving-day-blocks' ),
				'not_found'          => __( 'No beneficiaries / funds found.', 'giving-day-blocks' ),
				'not_found_in_trash' => __( 'No beneficiaries / funds found in trash.', 'giving-day-blocks' ),
				'parent_item_colon'  => __( 'Parent Beneficiary / Fund:', 'giving-day-blocks' ),
				'attributes'         => __( 'Beneficiary / Fund Attributes', 'giving-day-blocks' ),
				'item_published'     => __( 'Beneficiary / Fund published.', 'giving-day-blocks' ),
				'item_updated'       => __( 'Beneficiary / Fund updated.', 'giving-day-blocks' ),
			),
			'description'         => __( 'Donation destinations — funds, programs, or partner nonprofits. Use the parent attribute to nest funds under a unit (e.g. a college or coalition) and roll up per-unit totals.', 'giving-day-blocks' ),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=' . Campaign::POST_TYPE,
			'show_in_rest'        => true,
			'rest_base'           => self::REST_BASE,
			'rest_namespace'      => self::REST_NAMESPACE,
			'menu_icon'           => 'dashicons-awards',
			'hierarchical'        => true,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'page-attributes' ),
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
				'description'  => __( 'IDs of the Campaigns this beneficiary / fund is accepting donations for.', 'giving-day-blocks' ),
				'default'      => array(),
				'single'       => true,
				'show_in_rest' => self::rest_array_of_integers(),
			),
			self::META_GOAL_AMOUNT  => array(
				'type'        => 'number',
				'description' => __( 'Optional fundraising goal for this Beneficiary / Fund. Parent posts roll up children at query time.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_PARENT_ORG   => array(
				'type'        => 'string',
				'description' => __( 'Optional display label for a parent organization (e.g. "College of Arts & Sciences"). Used as a fallback for flat (non-hierarchical) setups; ignored when a parent post is set.', 'giving-day-blocks' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Resolves the human-readable "unit" label for a Beneficiary.
	 *
	 * When a parent post is set, returns its title (the hierarchy is the
	 * source of truth). When the post is top-level, falls back to the
	 * free-form `_giving_beneficiary_parent_org` meta. Returns an empty
	 * string when neither is available.
	 *
	 * @param int $beneficiary_id Beneficiary post ID.
	 * @return string
	 */
	public static function display_unit_label( int $beneficiary_id ): string {
		if ( $beneficiary_id <= 0 ) {
			return '';
		}

		$parent_id = (int) wp_get_post_parent_id( $beneficiary_id );
		if ( $parent_id > 0 ) {
			$title = get_the_title( $parent_id );
			if ( is_string( $title ) && '' !== $title ) {
				return $title;
			}
		}

		$fallback = get_post_meta( $beneficiary_id, self::META_PARENT_ORG, true );
		return is_string( $fallback ) ? $fallback : '';
	}
}
