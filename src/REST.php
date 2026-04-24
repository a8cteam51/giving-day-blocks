<?php
/**
 * REST API routes for giving-day-blocks.
 *
 * Registers a minimal first slice under `giving-day/v1`:
 *
 *   GET /campaign/{id}/countdown
 *   GET /campaign/{id}/summary
 *
 * Both are public-read, return `server_time`, and honor the
 * `?givingday=pre|live|post` preview override for logged-in users
 * (see Data\Status). The Aggregator (PLAN.md § 4.3) will replace the
 * "raised" / "donor_count" scaffolding in a later phase.
 *
 * @package Team51\GivingDay
 * @since   0.1.0
 */

namespace Team51\GivingDay;

use Team51\GivingDay\Data\Colors;
use Team51\GivingDay\Data\Status;
use Team51\GivingDay\PostTypes\Campaign;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class REST {

	public const NAMESPACE = 'giving-day/v1';

	/**
	 * Hooks route registration onto `rest_api_init`.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the endpoints.
	 */
	public function register_routes(): void {
		$args = array(
			'id'        => array(
				'description'       => __( 'Campaign post ID.', 'giving-day-blocks' ),
				'type'              => 'integer',
				'required'          => true,
				'validate_callback' => static fn( $value ) => is_numeric( $value ) && (int) $value > 0,
			),
			'givingday' => array(
				'description' => __( 'Preview override: pre, live, or post. Honored only for logged-in users.', 'giving-day-blocks' ),
				'type'        => 'string',
				'enum'        => array( 'pre', 'live', 'post' ),
				'required'    => false,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/countdown',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => $args,
				'callback'            => array( $this, 'get_countdown' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => $args,
				'callback'            => array( $this, 'get_summary' ),
			)
		);
	}

	/**
	 * GET /campaign/{id}/countdown
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_countdown( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$campaign    = $this->locate_readable_campaign( $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$override = $this->preview_override_from_request( $request );
		$status   = Status::resolve( $campaign_id, $override );

		$payload = array(
			'id'              => $campaign_id,
			'pre_event_start' => (string) get_post_meta( $campaign_id, Campaign::META_PRE_EVENT_START, true ),
			'start'           => (string) get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true ),
			'end'             => (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true ),
			'timezone'        => (string) get_post_meta( $campaign_id, Campaign::META_TIMEZONE, true ),
			'status'          => $status,
			'server_time'     => gmdate( 'c' ),
		);

		return $this->respond( $payload );
	}

	/**
	 * GET /campaign/{id}/summary
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$campaign    = $this->locate_readable_campaign( $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$override = $this->preview_override_from_request( $request );
		$status   = Status::resolve( $campaign_id, $override );

		$goal       = (float) get_post_meta( $campaign_id, Campaign::META_GOAL_AMOUNT, true );
		$currency_meta = get_post_meta( $campaign_id, Campaign::META_CURRENCY, true );
		$currency      = (string) ( $currency_meta !== '' ? $currency_meta : get_option( 'woocommerce_currency', 'USD' ) );
		$raised     = (float) get_post_meta( $campaign_id, Campaign::META_RAISED_OVERRIDE, true );
		$donors     = (int) get_post_meta( $campaign_id, Campaign::META_DONOR_COUNT_OVERRIDE, true );
		$percent    = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;

		$payload = array(
			'id'            => $campaign_id,
			'title'         => get_the_title( $campaign_id ),
			'status'        => $status,
			'goal'          => $goal,
			'raised'        => $raised,
			'percent'       => round( $percent, 2 ),
			'currency'      => $currency,
			'donor_count'   => $donors,
			'pre_event_start' => (string) get_post_meta( $campaign_id, Campaign::META_PRE_EVENT_START, true ),
			'start'         => (string) get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true ),
			'end'           => (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true ),
			'timezone'      => (string) get_post_meta( $campaign_id, Campaign::META_TIMEZONE, true ),
			'colors'        => Colors::for_campaign( $campaign_id ),
			'server_time'   => gmdate( 'c' ),
		);

		return $this->respond( $payload );
	}

	/**
	 * Loads a campaign the current caller is allowed to read.
	 *
	 * Returns the post when it exists, is a Campaign, and is either
	 * published or readable by the current user. Otherwise returns a
	 * generic 404 — we deliberately never surface a 403 for unpublished
	 * IDs so anonymous callers cannot enumerate draft/private campaigns
	 * by probing status codes.
	 *
	 * Published campaigns are public. Any other status (draft, private,
	 * pending, future, trash, auto-draft) requires the `read_post` meta
	 * capability for that specific post, which WordPress maps through
	 * `map_meta_cap()` to `edit_post` or `read_private_{cpt}` depending
	 * on status.
	 *
	 * @param int $campaign_id
	 * @return WP_Post|WP_Error
	 */
	private function locate_readable_campaign( int $campaign_id ) {
		$campaign = get_post( $campaign_id );

		if (
			! $campaign
			|| Campaign::POST_TYPE !== $campaign->post_type
			|| ( 'publish' !== $campaign->post_status && ! current_user_can( 'read_post', $campaign->ID ) )
		) {
			return new WP_Error(
				'giving_day_blocks_campaign_not_found',
				__( 'Campaign not found.', 'giving-day-blocks' ),
				array( 'status' => 404 )
			);
		}

		return $campaign;
	}

	/**
	 * Translates a `?givingday=` short code from the request into a canonical
	 * status. Returns null for absent/invalid values or when the user isn't
	 * logged in.
	 *
	 * @param WP_REST_Request $request
	 * @return string|null
	 */
	private function preview_override_from_request( WP_REST_Request $request ): ?string {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$raw = $request->get_param( 'givingday' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$map = array(
			'pre'  => Status::SCHEDULED,
			'live' => Status::LIVE,
			'post' => Status::ENDED,
		);
		return $map[ $raw ] ?? null;
	}

	/**
	 * Wraps a payload in a no-store response.
	 *
	 * @param array<string,mixed> $data
	 * @return WP_REST_Response
	 */
	private function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
