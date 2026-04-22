<?php
/**
 * Campaign custom post type.
 *
 * @package Team51\GivingDay\PostTypes
 * @since   0.1.0
 */

namespace Team51\GivingDay\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_campaign` custom post type and its event meta.
 *
 * A campaign represents a single Giving Day event: its pre-event window,
 * the event window, the fundraising goal, and the donation products that
 * feed it. Teams, Matches, and Challenges reference a Campaign via meta.
 */
final class Campaign extends AbstractPostType {

	public const POST_TYPE = 'giving_campaign';
	public const REST_BASE = 'campaigns';

	public const META_PRE_EVENT_START    = '_giving_pre_event_start';
	public const META_START_DATETIME     = '_giving_start_datetime';
	public const META_END_DATETIME       = '_giving_end_datetime';
	public const META_TIMEZONE           = '_giving_timezone';
	public const META_GOAL_AMOUNT        = '_giving_goal_amount';
	public const META_CURRENCY           = '_giving_currency';
	public const META_DONATION_PRODUCTS  = '_giving_donation_product_ids';
	public const META_STATUS_OVERRIDE    = '_giving_status_override';

	/**
	 * Allowed values for the optional manual status override.
	 */
	public const STATUSES = array( '', 'scheduled', 'live', 'ended' );

	public function get_post_type(): string {
		return self::POST_TYPE;
	}

	protected function get_post_type_args(): array {
		return array(
			'labels'              => array(
				'name'               => _x( 'Campaigns', 'post type general name', 'giving-day-blocks' ),
				'singular_name'      => _x( 'Campaign', 'post type singular name', 'giving-day-blocks' ),
				'menu_name'          => _x( 'Giving Day', 'admin menu', 'giving-day-blocks' ),
				'name_admin_bar'     => _x( 'Campaign', 'add new on admin bar', 'giving-day-blocks' ),
				'add_new'            => _x( 'Add New', 'campaign', 'giving-day-blocks' ),
				'add_new_item'       => __( 'Add New Campaign', 'giving-day-blocks' ),
				'new_item'           => __( 'New Campaign', 'giving-day-blocks' ),
				'edit_item'          => __( 'Edit Campaign', 'giving-day-blocks' ),
				'view_item'          => __( 'View Campaign', 'giving-day-blocks' ),
				'all_items'          => __( 'All Campaigns', 'giving-day-blocks' ),
				'search_items'       => __( 'Search Campaigns', 'giving-day-blocks' ),
				'not_found'          => __( 'No campaigns found.', 'giving-day-blocks' ),
				'not_found_in_trash' => __( 'No campaigns found in trash.', 'giving-day-blocks' ),
			),
			'description'         => __( 'Giving Day fundraiser campaigns.', 'giving-day-blocks' ),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'rest_base'           => self::REST_BASE,
			'rest_namespace'      => self::REST_NAMESPACE,
			'menu_position'       => 30,
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
			// ISO 8601 datetime strings. Timezone handling is governed by META_TIMEZONE.
			self::META_PRE_EVENT_START   => array(
				'type'        => 'string',
				'description' => __( 'When the pre-event countdown should start (ISO 8601).', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_START_DATETIME    => array(
				'type'        => 'string',
				'description' => __( 'When the event begins (ISO 8601).', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_END_DATETIME      => array(
				'type'        => 'string',
				'description' => __( 'When the event ends (ISO 8601).', 'giving-day-blocks' ),
				'default'     => '',
			),
			self::META_TIMEZONE          => array(
				'type'        => 'string',
				'description' => __( 'IANA timezone identifier (e.g. America/New_York). Defaults to the WordPress site timezone at the moment the campaign is created.', 'giving-day-blocks' ),
				'default'     => wp_timezone_string(),
			),
			self::META_GOAL_AMOUNT       => array(
				'type'        => 'number',
				'description' => __( 'Fundraising goal amount in the campaign currency.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_CURRENCY          => array(
				'type'        => 'string',
				'description' => __( '3-letter ISO currency code (e.g. USD). Defaults to the WooCommerce store currency, or USD if unavailable.', 'giving-day-blocks' ),
				'default'     => get_option( 'woocommerce_currency', 'USD' ),
			),
			self::META_DONATION_PRODUCTS => array(
				'type'         => 'array',
				'description'  => __( 'IDs of WooCommerce donation products that feed this campaign.', 'giving-day-blocks' ),
				'default'      => array(),
				'single'       => true,
				'show_in_rest' => self::rest_array_of_integers(),
			),
			self::META_STATUS_OVERRIDE   => array(
				'type'              => 'string',
				'description'       => __( 'Optional manual status override: scheduled, live, or ended. Blank to compute from dates.', 'giving-day-blocks' ),
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					$value = is_string( $value ) ? $value : '';
					return in_array( $value, self::STATUSES, true ) ? $value : '';
				},
			),
		);
	}
}
