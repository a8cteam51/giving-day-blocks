<?php
/**
 * Giving match custom post type.
 *
 * Represents a sponsor's pledge to match donations inside a time window.
 * The class is named `GivingMatch` (not `Match`) because `match` is a
 * reserved keyword in PHP 8+; the name also mirrors the `giving_match`
 * post type slug.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_match` custom post type.
 *
 * A giving match is a sponsor-backed multiplier that applies to donations
 * inside a time window (e.g. "between 1pm and 3pm every donation is matched
 * 2x up to $10,000").
 */
final class GivingMatch extends AbstractPostType {

	public const POST_TYPE = 'giving_match';
	public const REST_BASE = 'matches';

	public const META_CAMPAIGN_ID    = '_giving_campaign_id';
	public const META_SPONSOR_NAME   = '_giving_match_sponsor_name';
	public const META_MULTIPLIER     = '_giving_match_multiplier';
	public const META_CAP_AMOUNT     = '_giving_match_cap_amount';
	public const META_START_DATETIME = '_giving_match_start_datetime';
	public const META_END_DATETIME   = '_giving_match_end_datetime';
	public const META_ACTIVE         = '_giving_match_active';

	public function get_post_type(): string {
		return self::POST_TYPE;
	}

	protected function get_post_type_args(): array {
		return array(
			'labels'              => array(
				'name'               => _x( 'Matches', 'post type general name', 'giving-day-blocks' ),
				'singular_name'      => _x( 'Match', 'post type singular name', 'giving-day-blocks' ),
				'menu_name'          => __( 'Matches', 'giving-day-blocks' ),
				'add_new'            => _x( 'Add New', 'match', 'giving-day-blocks' ),
				'add_new_item'       => __( 'Add New Match', 'giving-day-blocks' ),
				'new_item'           => __( 'New Match', 'giving-day-blocks' ),
				'edit_item'          => __( 'Edit Match', 'giving-day-blocks' ),
				'view_item'          => __( 'View Match', 'giving-day-blocks' ),
				'all_items'          => __( 'Matches', 'giving-day-blocks' ),
				'search_items'       => __( 'Search Matches', 'giving-day-blocks' ),
				'not_found'          => __( 'No matches found.', 'giving-day-blocks' ),
				'not_found_in_trash' => __( 'No matches found in trash.', 'giving-day-blocks' ),
			),
			'description'         => __( 'Sponsor match offers attached to a Giving Day campaign.', 'giving-day-blocks' ),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=' . Campaign::POST_TYPE,
			'show_in_rest'        => true,
			'rest_base'           => self::REST_BASE,
			'rest_namespace'      => self::REST_NAMESPACE,
			'menu_icon'           => 'dashicons-heart',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'exclude_from_search' => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);
	}

	protected function get_meta_fields(): array {
		return array(
			self::META_CAMPAIGN_ID    => array(
				'type'        => 'integer',
				'description' => __( 'ID of the parent Campaign.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_SPONSOR_NAME   => array(
				'type'        => 'string',
				'description' => __( 'Display name of the match sponsor.', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_MULTIPLIER     => array(
				'type'        => 'number',
				'description' => __( 'Match multiplier applied to eligible donations (e.g. 2 = 2x, dollar-for-dollar).', 'giving-day-blocks' ),
				'default'     => 2,
			),
			self::META_CAP_AMOUNT     => array(
				'type'        => 'number',
				'description' => __( 'Total sponsor-funded cap on matched dollars.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_START_DATETIME => array(
				'type'        => 'string',
				'description' => __( 'Start of the match window (ISO 8601).', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_END_DATETIME   => array(
				'type'        => 'string',
				'description' => __( 'End of the match window (ISO 8601).', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_ACTIVE         => array(
				'type'        => 'boolean',
				'description' => __( 'Manual active flag. Honored alongside the date window.', 'giving-day-blocks' ),
				'default'     => false,
			),
		);
	}
}
