<?php
/**
 * Giving match custom post type.
 *
 * Represents a sponsor's pledge to amplify donations during a window of
 * a Giving Day campaign. Two real-world mechanics are modeled as a
 * discriminated record (PLAN.md § 4.1, § 5.6):
 *
 *   match_type = 'dollar_for_dollar'
 *     → multiplier + cap_amount; every donation in the window is matched
 *       at multiplier until the running matched-dollar total hits the cap.
 *
 *   match_type = 'donor_unlock'
 *     → donor_threshold + unlock_amount; the full unlock_amount is
 *       contributed all at once when the count of unique donors during
 *       the window reaches donor_threshold.
 *
 * Hybrid setups (one dollar-for-dollar + one donor-unlock running in
 * parallel) are modeled as two separate `giving_match` posts on the same
 * Campaign — deliberately not a third match_type.
 *
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
 */
final class GivingMatch extends AbstractPostType {

	public const POST_TYPE = 'giving_match';
	public const REST_BASE = 'matches';

	public const TYPE_DOLLAR_FOR_DOLLAR = 'dollar_for_dollar';
	public const TYPE_DONOR_UNLOCK      = 'donor_unlock';

	/**
	 * Allowed values for META_TYPE. New entries land here first and any
	 * sanitize_callback / front-end branch that doesn't yet handle them
	 * is expected to gracefully fall back to dollar-for-dollar.
	 */
	public const TYPES = array(
		self::TYPE_DOLLAR_FOR_DOLLAR,
		self::TYPE_DONOR_UNLOCK,
	);

	public const META_CAMPAIGN_ID     = '_giving_campaign_id';
	public const META_TYPE            = '_giving_match_type';
	public const META_SPONSOR_NAME    = '_giving_match_sponsor_name';
	public const META_SPONSOR_LOGO    = '_giving_match_sponsor_logo_id';
	public const META_MULTIPLIER      = '_giving_match_multiplier';
	public const META_CAP_AMOUNT      = '_giving_match_cap_amount';
	public const META_DONOR_THRESHOLD = '_giving_match_donor_threshold';
	public const META_UNLOCK_AMOUNT   = '_giving_match_unlock_amount';
	public const META_START_DATETIME  = '_giving_match_start_datetime';
	public const META_END_DATETIME    = '_giving_match_end_datetime';
	public const META_ACTIVE          = '_giving_match_active';

	// Dev scaffolding mirroring the override pattern on the Campaign CPT.
	// Lets blocks render real-looking progress before the WC order Aggregator
	// (PLAN.md § 4.3) lands. Removed once the Aggregator can compute these
	// from order data. Each override is interpreted only when the match's
	// META_TYPE selects it.
	public const META_MATCHED_OVERRIDE = '_giving_match_matched_override';
	public const META_DONORS_OVERRIDE  = '_giving_match_donors_override';

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
			self::META_CAMPAIGN_ID      => array(
				'type'        => 'integer',
				'description' => __( 'ID of the parent Campaign.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_TYPE             => array(
				'type'              => 'string',
				'description'       => __( 'Match mechanic. dollar_for_dollar = donations are multiplied; donor_unlock = a flat amount is contributed once a donor count is reached.', 'giving-day-blocks' ),
				'default'           => self::TYPE_DOLLAR_FOR_DOLLAR,
				'sanitize_callback' => static function ( $value ) {
					$value = is_string( $value ) ? $value : '';
					return in_array( $value, self::TYPES, true ) ? $value : self::TYPE_DOLLAR_FOR_DOLLAR;
				},
			),
			self::META_SPONSOR_NAME     => array(
				'type'        => 'string',
				'description' => __( 'Display name of the match sponsor.', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_SPONSOR_LOGO     => array(
				'type'        => 'integer',
				'description' => __( 'Attachment ID of the sponsor logo.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_MULTIPLIER       => array(
				'type'        => 'number',
				'description' => __( 'Match multiplier applied to eligible donations (e.g. 2 = 2x, dollar-for-dollar). Used when match_type = dollar_for_dollar.', 'giving-day-blocks' ),
				'default'     => 2,
			),
			self::META_CAP_AMOUNT       => array(
				'type'        => 'number',
				'description' => __( 'Total sponsor-funded cap on matched dollars. Used when match_type = dollar_for_dollar.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_DONOR_THRESHOLD  => array(
				'type'        => 'integer',
				'description' => __( 'Number of unique donors required to unlock the sponsor amount. Used when match_type = donor_unlock.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_UNLOCK_AMOUNT    => array(
				'type'        => 'number',
				'description' => __( 'Flat amount the sponsor contributes once the donor threshold is reached. Used when match_type = donor_unlock.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_START_DATETIME   => array(
				'type'        => 'string',
				'description' => __( 'Start of the match window (ISO 8601). Empty means the match runs the full event window.', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_END_DATETIME     => array(
				'type'        => 'string',
				'description' => __( 'End of the match window (ISO 8601). Empty means the match runs the full event window.', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_ACTIVE           => array(
				'type'        => 'boolean',
				'description' => __( 'Manual active flag. Honored alongside the date window.', 'giving-day-blocks' ),
				'default'     => true,
			),
			self::META_MATCHED_OVERRIDE => array(
				'type'        => 'number',
				'description' => __( 'Dev override: matched-so-far dollars (dollar_for_dollar). Removed once the WC order Aggregator is in place.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_DONORS_OVERRIDE  => array(
				'type'        => 'integer',
				'description' => __( 'Dev override: unique donors so far (donor_unlock). Removed once the WC order Aggregator is in place.', 'giving-day-blocks' ),
				'default'     => 0,
			),
		);
	}
}
