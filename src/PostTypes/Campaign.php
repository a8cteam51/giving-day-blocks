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
	public const META_RAISED_OVERRIDE    = '_giving_raised_override';
	public const META_DONOR_COUNT_OVERRIDE = '_giving_donor_count_override';
	public const META_COLOR_PRIMARY      = '_giving_color_primary';
	public const META_COLOR_SECONDARY    = '_giving_color_secondary';
	public const META_COLOR_ACCENT       = '_giving_color_accent';
	public const META_COLOR_SURFACE      = '_giving_color_surface';
	public const META_COLOR_MUTED        = '_giving_color_muted';

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

			// Temporary scaffolding: lets blocks render real "raised" / "donor count" values
			// before the WC order Aggregator (PLAN.md § 4.3) is built. When the Aggregator
			// lands, it will prefer real data and fall back to these overrides.
			self::META_RAISED_OVERRIDE   => array(
				'type'        => 'number',
				'description' => __( 'Dev override for "raised so far". Null/0 until set. Removed once the WC order Aggregator is in place.', 'giving-day-blocks' ),
				'default'     => 0,
			),
			self::META_DONOR_COUNT_OVERRIDE => array(
				'type'        => 'integer',
				'description' => __( 'Dev override for donor count. Removed once the WC order Aggregator is in place.', 'giving-day-blocks' ),
				'default'     => 0,
			),

			// Per-campaign brand palette. Each key maps 1:1 to a CSS custom
			// property declared in blocks/src/_shared/tokens.scss so the
			// blocks pick up the override without any SCSS changes. Empty
			// values leave the theme.json fallback in place.
			self::META_COLOR_PRIMARY     => array(
				'type'              => 'string',
				'description'       => __( 'Primary brand color (CSS --giving-day-primary).', 'giving-day-blocks' ),
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_css_color' ),
			),
			self::META_COLOR_SECONDARY   => array(
				'type'              => 'string',
				'description'       => __( 'Secondary brand color (CSS --giving-day-secondary).', 'giving-day-blocks' ),
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_css_color' ),
			),
			self::META_COLOR_ACCENT      => array(
				'type'              => 'string',
				'description'       => __( 'Accent color (CSS --giving-day-accent).', 'giving-day-blocks' ),
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_css_color' ),
			),
			self::META_COLOR_SURFACE     => array(
				'type'              => 'string',
				'description'       => __( 'Block surface background (CSS --giving-day-surface).', 'giving-day-blocks' ),
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_css_color' ),
			),
			self::META_COLOR_MUTED       => array(
				'type'              => 'string',
				'description'       => __( 'Muted / secondary text color (CSS --giving-day-muted).', 'giving-day-blocks' ),
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_css_color' ),
			),
		);
	}

	/**
	 * Accepts empty strings, 3/4/6/8-digit hex colors, rgb[a]/hsl[a], and
	 * common CSS color keywords. Rejects anything else to keep this safe to
	 * emit inside an inline style attribute.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string Sanitized color or empty string.
	 */
	public static function sanitize_css_color( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/]+\s*\)$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^[a-zA-Z]+$/', $value ) ) {
			return strtolower( $value );
		}
		return '';
	}
}
