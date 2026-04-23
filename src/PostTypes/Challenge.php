<?php
/**
 * Challenge custom post type.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_challenge` custom post type.
 *
 * A challenge is a time-boxed mini-event inside a Campaign (e.g. "get 50
 * donations in the next hour and a sponsor unlocks $10,000").
 *
 * Challenges are long-lived templates that can be reused across multiple
 * Campaigns (e.g. "Power Hour" runs every year). Participation is recorded
 * in the `_giving_challenge_campaigns` array meta. Windows are stored as
 * ISO 8601 durations relative to each Campaign's start, so the same
 * challenge can run at "+4h to +5h" regardless of the year.
 *
 * Challenges come in three flavors, controlled by `_giving_challenge_type`:
 *
 * - `donation_count` — threshold is a count of donations
 * - `amount`         — threshold is a dollar amount raised
 * - `team`           — threshold is a count of teams reaching a sub-goal
 */
final class Challenge extends AbstractPostType {

	public const POST_TYPE = 'giving_challenge';
	public const REST_BASE = 'challenges';

	public const META_CAMPAIGN_IDS         = '_giving_challenge_campaigns';
	public const META_TYPE                 = '_giving_challenge_type';
	public const META_THRESHOLD            = '_giving_challenge_threshold';
	public const META_REWARD_LABEL         = '_giving_challenge_reward_label';
	public const META_REWARD_AMOUNT        = '_giving_challenge_reward_amount';
	public const META_WINDOW_OFFSET_START  = '_giving_challenge_window_offset_start';
	public const META_WINDOW_OFFSET_END    = '_giving_challenge_window_offset_end';

	/**
	 * Allowed challenge types.
	 */
	public const TYPES = array( 'donation_count', 'amount', 'team' );

	public function get_post_type(): string {
		return self::POST_TYPE;
	}

	protected function get_post_type_args(): array {
		return array(
			'labels'              => array(
				'name'               => _x( 'Challenges', 'post type general name', 'giving-day-blocks' ),
				'singular_name'      => _x( 'Challenge', 'post type singular name', 'giving-day-blocks' ),
				'menu_name'          => __( 'Challenges', 'giving-day-blocks' ),
				'add_new'            => _x( 'Add New', 'challenge', 'giving-day-blocks' ),
				'add_new_item'       => __( 'Add New Challenge', 'giving-day-blocks' ),
				'new_item'           => __( 'New Challenge', 'giving-day-blocks' ),
				'edit_item'          => __( 'Edit Challenge', 'giving-day-blocks' ),
				'view_item'          => __( 'View Challenge', 'giving-day-blocks' ),
				'all_items'          => __( 'Challenges', 'giving-day-blocks' ),
				'search_items'       => __( 'Search Challenges', 'giving-day-blocks' ),
				'not_found'          => __( 'No challenges found.', 'giving-day-blocks' ),
				'not_found_in_trash' => __( 'No challenges found in trash.', 'giving-day-blocks' ),
			),
			'description'         => __( 'Time-boxed mini-events inside a Giving Day campaign.', 'giving-day-blocks' ),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=' . Campaign::POST_TYPE,
			'show_in_rest'        => true,
			'rest_base'           => self::REST_BASE,
			'rest_namespace'      => self::REST_NAMESPACE,
			'menu_icon'           => 'dashicons-flag',
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
			self::META_CAMPAIGN_IDS        => array(
				'type'         => 'array',
				'description'  => __( 'IDs of the Campaigns this challenge is attached to. A challenge can be reused across multiple Campaigns.', 'giving-day-blocks' ),
				'default'      => array(),
				'single'       => true,
				'show_in_rest' => self::rest_array_of_integers(),
			),
			self::META_TYPE                => array(
				'type'              => 'string',
				'description'       => __( 'Challenge type: donation_count, amount, or team.', 'giving-day-blocks' ),
				'default'           => 'donation_count',
				'sanitize_callback' => static function ( $value ) {
					$value = is_string( $value ) ? $value : '';
					return in_array( $value, self::TYPES, true ) ? $value : 'donation_count';
				},
			),
			self::META_THRESHOLD           => array(
				'type'        => 'number',
				'description' => __( 'Threshold the challenge must reach (count or amount depending on type).', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_REWARD_LABEL        => array(
				'type'        => 'string',
				'description' => __( 'Short description of what unlocks when the challenge succeeds.', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_REWARD_AMOUNT       => array(
				'type'        => 'number',
				'description' => __( 'Optional dollar value of the unlocked reward.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_WINDOW_OFFSET_START => array(
				'type'        => 'string',
				'description' => __( 'Challenge window start, as an ISO 8601 duration relative to the Campaign start (e.g. "PT4H" = 4 hours in).', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_WINDOW_OFFSET_END   => array(
				'type'        => 'string',
				'description' => __( 'Challenge window end, as an ISO 8601 duration relative to the Campaign start (e.g. "PT5H" = 5 hours in).', 'giving-day-blocks' ),
				'default'     => '',
			),
		);
	}
}
