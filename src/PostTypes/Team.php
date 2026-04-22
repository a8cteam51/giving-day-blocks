<?php
/**
 * Team custom post type.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_team` custom post type.
 *
 * A team belongs to exactly one Campaign and groups donations in the leaderboard.
 * The team's slug is the WordPress post slug; its image is the featured image.
 */
final class Team extends AbstractPostType {

	public const POST_TYPE = 'giving_team';
	public const REST_BASE = 'teams';

	public const META_CAMPAIGN_ID = '_giving_campaign_id';
	public const META_CAPTAIN_ID  = '_giving_team_captain_id';
	public const META_GOAL_AMOUNT = '_giving_team_goal_amount';

	public function get_post_type(): string {
		return self::POST_TYPE;
	}

	protected function get_post_type_args(): array {
		return array(
			'labels'              => array(
				'name'               => _x( 'Teams', 'post type general name', 'giving-day-blocks' ),
				'singular_name'      => _x( 'Team', 'post type singular name', 'giving-day-blocks' ),
				'menu_name'          => __( 'Teams', 'giving-day-blocks' ),
				'add_new'            => _x( 'Add New', 'team', 'giving-day-blocks' ),
				'add_new_item'       => __( 'Add New Team', 'giving-day-blocks' ),
				'new_item'           => __( 'New Team', 'giving-day-blocks' ),
				'edit_item'          => __( 'Edit Team', 'giving-day-blocks' ),
				'view_item'          => __( 'View Team', 'giving-day-blocks' ),
				'all_items'          => __( 'Teams', 'giving-day-blocks' ),
				'search_items'       => __( 'Search Teams', 'giving-day-blocks' ),
				'not_found'          => __( 'No teams found.', 'giving-day-blocks' ),
				'not_found_in_trash' => __( 'No teams found in trash.', 'giving-day-blocks' ),
			),
			'description'         => __( 'Teams competing inside a Giving Day campaign.', 'giving-day-blocks' ),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=' . Campaign::POST_TYPE,
			'show_in_rest'        => true,
			'rest_base'           => self::REST_BASE,
			'rest_namespace'      => self::REST_NAMESPACE,
			'menu_icon'           => 'dashicons-groups',
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
			self::META_CAMPAIGN_ID => array(
				'type'        => 'integer',
				'description' => __( 'ID of the parent Campaign.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_CAPTAIN_ID  => array(
				'type'        => 'integer',
				'description' => __( 'WordPress user ID of the team captain.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_GOAL_AMOUNT => array(
				'type'        => 'number',
				'description' => __( 'Optional team-level fundraising goal.', 'giving-day-blocks' ),
				'default'     => 0,
			),
		);
	}
}
