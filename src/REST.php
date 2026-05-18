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

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\Data\Colors;
use Team51\GivingDay\Data\Context;
use Team51\GivingDay\Data\Leaderboard;
use Team51\GivingDay\Data\MatchProgress;
use Team51\GivingDay\Data\Status;
use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamGroup;
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

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/active-matches',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => $args['id'],
				),
				'callback'            => array( $this, 'get_active_matches' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/match/(?P<id>\d+)/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'description'       => __( 'Match post ID.', 'giving-day-blocks' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => static fn( $value ) => is_numeric( $value ) && (int) $value > 0,
					),
				),
				'callback'            => array( $this, 'get_match_progress' ),
			)
		);

		$leaderboard_args = array_merge(
			$args,
			array(
				'dimension'               => array(
					'description' => __( 'Leaderboard dimension.', 'giving-day-blocks' ),
					'type'        => 'string',
					'required'    => true,
					'enum'        => array(
						Leaderboard::DIMENSION_DONORS,
						Leaderboard::DIMENSION_TEAMS,
						Leaderboard::DIMENSION_BENEFICIARIES,
						Leaderboard::DIMENSION_CAUSES,
					),
				),
				'limit'                   => array(
					'description' => __( 'Maximum rows per list (1–100).', 'giving-day-blocks' ),
					'type'        => 'integer',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'filter_term_id'          => array(
					'description' => __( 'Optional team group or cause term ID to narrow results.', 'giving-day-blocks' ),
					'type'        => 'integer',
					'default'     => 0,
				),
				'group_by_parent_term_id' => array(
					'description' => __( 'Optional parent team group term ID; returns one sub-list per child term.', 'giving-day-blocks' ),
					'type'        => 'integer',
					'default'     => 0,
				),
				'anonymize'               => array(
					'description' => __( 'When true, donor names and avatars are redacted for top_donors.', 'giving-day-blocks' ),
					'type'        => 'boolean',
					'default'     => false,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/leaderboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => $leaderboard_args,
				'callback'            => array( $this, 'get_leaderboard' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/team-groups',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$args,
					array(
						'parent' => array(
							'description' => __( 'Parent term ID; use 0 for top-level terms.', 'giving-day-blocks' ),
							'type'        => 'integer',
							'default'     => 0,
						),
					)
				),
				'callback'            => array( $this, 'get_team_groups' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/setup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'check_campaign_edit' ),
				'args'                => array( 'id' => $args['id'] ),
				'callback'            => array( $this, 'get_campaign_setup' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/results',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'check_view_results' ),
				'args'                => array( 'id' => $args['id'] ),
				'callback'            => array( $this, 'get_campaign_results' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/campaign/(?P<id>\d+)/setup/donation-product',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'check_campaign_edit_and_products' ),
				'args'                => array( 'id' => $args['id'] ),
				'callback'            => array( $this, 'create_campaign_donation_product' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/context',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'args'                => array(
					'team_id'        => array(
						'description'       => __( 'Team post ID, or 0 to clear.', 'giving-day-blocks' ),
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'beneficiary_id' => array(
						'description'       => __( 'Beneficiary post ID, or 0 to clear.', 'giving-day-blocks' ),
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
				'callback'            => array( $this, 'update_context' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cause-areas',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => array(
					'parent' => array(
						'description'       => __( 'Parent term ID; 0 returns top-level Cause Areas.', 'giving-day-blocks' ),
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
				'callback'            => array( $this, 'get_cause_areas' ),
			)
		);

		$designation_args = array(
			'q'           => array(
				'description'       => __( 'Optional free-text query (matches post title).', 'giving-day-blocks' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'       => array(
				'description' => __( 'Maximum rows to return (1–50).', 'giving-day-blocks' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 50,
			),
			'id'          => array(
				'description'       => __( 'Resolve a single record by ID (used for URL-prefill label resolution). When set, q/limit/campaign_id are ignored.', 'giving-day-blocks' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'campaign_id' => array(
				'description'       => __( 'Scope results to this campaign. Defaults to the currently-live campaign.', 'giving-day-blocks' ),
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => $designation_args,
				'callback'            => array( $this, 'get_designation_teams' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/beneficiaries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => $designation_args,
				'callback'            => array( $this, 'get_designation_beneficiaries' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cause-areas/beneficiaries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'args'                => array(
					'cause_id' => array(
						'description'       => __( 'Cause Area term ID; 0 means search across all Beneficiaries.', 'giving-day-blocks' ),
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'description'       => __( 'Optional free-text query (matches title and excerpt).', 'giving-day-blocks' ),
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page' => array(
						'description' => __( 'Maximum number of Beneficiaries to return (1–100).', 'giving-day-blocks' ),
						'type'        => 'integer',
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 100,
					),
				),
				'callback'            => array( $this, 'get_cause_area_beneficiaries' ),
			)
		);
	}

	/**
	 * Permission callback: requires `edit_post` on the target campaign.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_campaign_edit( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'giving_day_forbidden', __( 'You cannot edit this campaign.', 'giving-day-blocks' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission callback: requires `manage_woocommerce`. Used for the
	 * post-event results endpoint which surfaces donor lists (display name
	 * + amount, no email). Same cap the War Room and OrderAttribution box
	 * gate on, so any user who can see WooCommerce order data already has
	 * everything this endpoint exposes.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_view_results( WP_REST_Request $request ) {
		unset( $request );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'giving_day_forbidden_results', __( 'You cannot view campaign results.', 'giving-day-blocks' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission callback: requires `edit_post` on the campaign AND
	 * `edit_products` capability for creating donation products.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_campaign_edit_and_products( WP_REST_Request $request ) {
		$check = $this->check_campaign_edit( $request );
		if ( true !== $check ) {
			return $check;
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			return new WP_Error( 'giving_day_forbidden_products', __( 'You cannot create products.', 'giving-day-blocks' ), array( 'status' => 403 ) );
		}
		return true;
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

		$goal     = (float) get_post_meta( $campaign_id, Campaign::META_GOAL_AMOUNT, true );
		$totals   = Aggregator::totals_for_campaign( $campaign_id, $override );
		$currency = isset( $totals['currency'] ) ? (string) $totals['currency'] : (string) get_option( 'woocommerce_currency', 'USD' );
		$raised   = isset( $totals['raised'] ) ? (float) $totals['raised'] : 0.0;
		$donors   = isset( $totals['unique_donors'] ) ? (int) $totals['unique_donors'] : 0;
		$percent  = $goal > 0 ? max( 0, min( 100, ( $raised / $goal ) * 100 ) ) : 0;

		$payload = array(
			'id'              => $campaign_id,
			'title'           => get_the_title( $campaign_id ),
			'status'          => $status,
			'goal'            => $goal,
			'raised'          => $raised,
			'percent'         => round( $percent, 2 ),
			'currency'        => $currency,
			'donor_count'     => $donors,
			'pre_event_start' => (string) get_post_meta( $campaign_id, Campaign::META_PRE_EVENT_START, true ),
			'start'           => (string) get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true ),
			'end'             => (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true ),
			'timezone'        => (string) get_post_meta( $campaign_id, Campaign::META_TIMEZONE, true ),
			'colors'          => Colors::for_campaign( $campaign_id ),
			'server_time'     => gmdate( 'c' ),
		);

		return $this->respond( $payload );
	}

	/**
	 * GET /campaign/{id}/active-matches
	 *
	 * Returns the list of matches whose window contains the current
	 * server time and whose `active` flag is true, ordered by end-soonest
	 * first. Each entry is a full match-progress payload so the common
	 * single-match case doesn't need a second round-trip.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_active_matches( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$campaign    = $this->locate_readable_campaign( $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$matches = MatchProgress::active_for_campaign( $campaign_id );

		return $this->respond(
			array(
				'campaign_id' => $campaign_id,
				'matches'     => $matches,
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * GET /match/{id}/progress
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_match_progress( WP_REST_Request $request ) {
		$match_id = (int) $request['id'];
		$payload  = MatchProgress::resolve( $match_id );

		if ( null === $payload ) {
			return new WP_Error(
				'giving_day_blocks_match_not_found',
				__( 'Match not found.', 'giving-day-blocks' ),
				array( 'status' => 404 )
			);
		}

		return $this->respond( $payload );
	}

	/**
	 * GET /campaign/{id}/leaderboard
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_leaderboard( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$campaign    = $this->locate_readable_campaign( $campaign_id );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$dimension = (string) $request->get_param( 'dimension' );
		$limit     = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 10;
		}

		$args = array(
			'filter_term_id'          => (int) $request->get_param( 'filter_term_id' ),
			'group_by_parent_term_id' => (int) $request->get_param( 'group_by_parent_term_id' ),
		);

		$preview = $this->preview_override_from_request( $request );

		$payload = Leaderboard::fetch( $campaign_id, $dimension, $limit, $args, $preview );
		if ( isset( $payload['error'] ) && 'invalid_dimension' === $payload['error'] ) {
			return new WP_Error(
				'giving_day_blocks_invalid_leaderboard_dimension',
				__( 'Invalid leaderboard dimension.', 'giving-day-blocks' ),
				array( 'status' => 400 )
			);
		}

		$anonymize = filter_var(
			$request->get_param( 'anonymize' ),
			FILTER_VALIDATE_BOOLEAN,
			FILTER_NULL_ON_FAILURE
		);
		$anonymize = (bool) $anonymize;
		if ( Leaderboard::DIMENSION_DONORS === $dimension && $anonymize ) {
			if ( isset( $payload['rows'] ) && is_array( $payload['rows'] ) ) {
				$payload['rows'] = Leaderboard::apply_anonymize( $payload['rows'], true );
			}
			if ( isset( $payload['groups'] ) && is_array( $payload['groups'] ) ) {
				foreach ( $payload['groups'] as &$group ) {
					if ( isset( $group['rows'] ) && is_array( $group['rows'] ) ) {
						$group['rows'] = Leaderboard::apply_anonymize( $group['rows'], true );
					}
				}
				unset( $group );
			}
		}

		return $this->respond( $payload );
	}

	/**
	 * GET /campaign/{id}/team-groups
	 *
	 * Lightweight `giving_team_group` listing for editor tooling (not the taxonomy
	 * REST collection at `…/team-groups`). Scoped under a campaign for consistent
	 * URL layout and the same read rules as other campaign GET endpoints.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_team_groups( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		$readable    = $this->locate_readable_campaign( $campaign_id );
		if ( is_wp_error( $readable ) ) {
			return $readable;
		}

		$parent = (int) $request->get_param( 'parent' );
		if ( $parent < 0 ) {
			$parent = 0;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => TeamGroup::TAXONOMY,
				'parent'     => $parent,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
					continue;
				}
				$out[] = array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'parent' => (int) $term->parent,
				);
			}
		}

		return $this->respond(
			array(
				'terms'       => $out,
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * GET /campaign/{id}/setup — readiness checklist for the editor UI.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign_setup( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		if ( Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return new WP_Error(
				'giving_day_blocks_campaign_not_found',
				__( 'Campaign not found.', 'giving-day-blocks' ),
				array( 'status' => 404 )
			);
		}

		return $this->respond(
			array(
				'campaign_id' => $campaign_id,
				'status'      => \Team51\GivingDay\Services\CampaignSetup::setup_status( $campaign_id ),
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * GET /campaign/{id}/results — full post-event payload.
	 *
	 * Returns {@see Aggregator::results_for_campaign()} which is either the
	 * frozen snapshot (with adjustments layered on a `current` block) or
	 * the live computation when no snapshot exists yet. Gated by
	 * `manage_woocommerce` because the response includes donor display
	 * names (no email).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_campaign_results( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		if ( Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return new WP_Error(
				'giving_day_blocks_campaign_not_found',
				__( 'Campaign not found.', 'giving-day-blocks' ),
				array( 'status' => 404 )
			);
		}

		return $this->respond( Aggregator::results_for_campaign( $campaign_id ) );
	}

	/**
	 * POST /campaign/{id}/setup/donation-product — creates a donation
	 * product and attaches it to the campaign. Returns the new product
	 * info.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_campaign_donation_product( WP_REST_Request $request ) {
		$campaign_id = (int) $request['id'];
		if ( Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			return new WP_Error(
				'giving_day_blocks_campaign_not_found',
				__( 'Campaign not found.', 'giving-day-blocks' ),
				array( 'status' => 404 )
			);
		}

		$result = \Team51\GivingDay\Services\CampaignSetup::create_donation_product( $campaign_id );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$product = wc_get_product( $result );
		return $this->respond(
			array(
				'product_id'   => (int) $result,
				'product_name' => $product ? $product->get_name() : '',
				'status'       => \Team51\GivingDay\Services\CampaignSetup::setup_status( $campaign_id ),
				'server_time'  => gmdate( 'c' ),
			)
		);
	}

	/**
	 * GET /cause-areas
	 *
	 * Returns the children of `parent` (default 0 = top-level Cause Areas)
	 * with the per-card data the front-end Cause Areas Browser needs:
	 * image URL, descendant flag, and a beneficiary count that includes
	 * descendants so non-leaf cards still feel populated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_cause_areas( WP_REST_Request $request ) {
		$parent = (int) $request->get_param( 'parent' );
		if ( $parent < 0 ) {
			$parent = 0;
		}

		// Single fetch of the entire Cause hierarchy. Walking the parent map
		// in PHP avoids per-term `get_terms()` calls (one for child detection,
		// one for descendant enumeration inside count_beneficiaries) that
		// previously made this an O(N) endpoint as the taxonomy grew.
		$all_terms = get_terms(
			array(
				'taxonomy'   => Cause::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $all_terms ) ) {
			$all_terms = array();
		}

		$by_parent = array();
		foreach ( $all_terms as $t ) {
			if ( ! $t instanceof \WP_Term ) {
				continue;
			}
			$by_parent[ (int) $t->parent ][] = (int) $t->term_id;
		}

		$items = array();
		foreach ( $all_terms as $term ) {
			if ( ! $term instanceof \WP_Term || (int) $term->parent !== $parent ) {
				continue;
			}

			$term_id        = (int) $term->term_id;
			$descendant_ids = self::collect_descendant_term_ids( $term_id, $by_parent );
			$has_children   = ! empty( $by_parent[ $term_id ] );

			$items[] = array(
				'id'                => $term_id,
				'name'              => self::decode_text( $term->name ),
				'slug'              => $term->slug,
				'description'       => self::decode_text( $term->description ),
				'parent'            => (int) $term->parent,
				'image_url'         => Cause::get_image_url( $term_id ),
				'has_children'      => $has_children,
				'beneficiary_count' => Cause::count_beneficiaries_in_terms(
					array_merge( array( $term_id ), $descendant_ids )
				),
			);
		}

		return $this->respond(
			array(
				'parent'      => $parent,
				'terms'       => $items,
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Walks a parent → child-IDs map to collect every descendant of a term.
	 *
	 * @param int              $term_id   Term whose descendants to collect.
	 * @param array<int,int[]> $by_parent Parent term ID → list of child term IDs.
	 * @return int[]
	 */
	private static function collect_descendant_term_ids( int $term_id, array $by_parent ): array {
		if ( empty( $by_parent[ $term_id ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $by_parent[ $term_id ] as $child_id ) {
			$out[] = (int) $child_id;
			$out   = array_merge( $out, self::collect_descendant_term_ids( (int) $child_id, $by_parent ) );
		}
		return $out;
	}

	/**
	 * GET /cause-areas/beneficiaries
	 *
	 * Returns Beneficiary cards for the front-end browser. With cause_id=0
	 * and a search term, returns a global match across all Beneficiaries
	 * (used for the root-level search shortcut). With cause_id>0, results
	 * are scoped to that term and its descendants.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_cause_area_beneficiaries( WP_REST_Request $request ) {
		$cause_id = (int) $request->get_param( 'cause_id' );
		$search   = trim( (string) $request->get_param( 'search' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = 50;
		}

		$query_args = array(
			'post_type'              => Beneficiary::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( $cause_id > 0 ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the browser block is term-scoped by design.
				array(
					'taxonomy'         => Cause::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => array( $cause_id ),
					'include_children' => true,
				),
			);
		}

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new \WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$thumb_id = (int) get_post_thumbnail_id( $post->ID );
			$thumb    = $thumb_id > 0 ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';

			$items[] = array(
				'id'               => (int) $post->ID,
				'title'            => self::decode_text( get_the_title( $post ) ),
				'excerpt'          => self::decode_text( wp_strip_all_tags( (string) get_the_excerpt( $post ) ) ),
				'permalink'        => (string) get_permalink( $post ),
				'thumbnail_url'    => is_string( $thumb ) ? $thumb : '',
				'parent_org_label' => self::decode_text( Beneficiary::display_unit_label( (int) $post->ID ) ),
			);
		}

		return $this->respond(
			array(
				'cause_id'    => $cause_id,
				'search'      => $search,
				'items'       => $items,
				'server_time' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * POST /context — merges the given team / beneficiary IDs into the
	 * visitor's session context.
	 *
	 * Called from the donation form's chip JS so that the admin-bar debug
	 * widget (and any other Context reader) reflects the donor's pick in real
	 * time. The donation form's server-side submit path also writes Context
	 * via the same merge logic (see DonationDesignationChips::sync_request_to_context),
	 * so attribution survives whether the donor clicks Donate immediately
	 * after picking, navigates around, or abandons the page.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function update_context( WP_REST_Request $request ): WP_REST_Response {
		$existing    = Context::get();
		$campaign_id = (int) ( $existing['campaign_id'] ?? 0 );
		$team_id     = (int) ( $existing['team_id'] ?? 0 );
		$beneficiary = (int) ( $existing['beneficiary_id'] ?? 0 );

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		if ( array_key_exists( 'team_id', $params ) ) {
			$candidate = absint( $params['team_id'] );
			if ( $candidate > 0 ) {
				$post    = get_post( $candidate );
				$team_id = ( $post instanceof WP_Post && \Team51\GivingDay\PostTypes\Team::POST_TYPE === $post->post_type && 'publish' === $post->post_status )
					? $candidate
					: $team_id;
			} else {
				$team_id = 0;
			}
		}

		if ( array_key_exists( 'beneficiary_id', $params ) ) {
			$candidate = absint( $params['beneficiary_id'] );
			if ( $candidate > 0 ) {
				$post        = get_post( $candidate );
				$beneficiary = ( $post instanceof WP_Post && Beneficiary::POST_TYPE === $post->post_type && 'publish' === $post->post_status )
					? $candidate
					: $beneficiary;
			} else {
				$beneficiary = 0;
			}
		}

		Context::set( $campaign_id, $team_id, $beneficiary );

		return $this->respond(
			array(
				'campaign_id'    => $campaign_id,
				'team_id'        => $team_id,
				'beneficiary_id' => $beneficiary,
				'server_time'    => gmdate( 'c' ),
			)
		);
	}

	/**
	 * GET /teams — chip typeahead source for the Team designation field.
	 *
	 * Returns a flat list of `{ id, label }` rows matching the
	 * team51-donations Custom Fields typeahead contract. Scoped to the
	 * currently-live campaign by default (override with `campaign_id`).
	 * Passing `id` returns the single matching record for URL-prefill
	 * label resolution.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_designation_teams( WP_REST_Request $request ) {
		return $this->respond_designation_list(
			$request,
			Team::POST_TYPE,
			Team::META_CAMPAIGN_IDS,
			false
		);
	}

	/**
	 * GET /beneficiaries — chip typeahead source for the Beneficiary
	 * designation field. Labels include the parent unit when present so
	 * donors can disambiguate similarly-named funds across colleges /
	 * coalitions.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_designation_beneficiaries( WP_REST_Request $request ) {
		return $this->respond_designation_list(
			$request,
			Beneficiary::POST_TYPE,
			Beneficiary::META_CAMPAIGN_IDS,
			true
		);
	}

	/**
	 * Shared implementation for the Team / Beneficiary typeahead endpoints.
	 *
	 * The `_giving_*_campaigns` arrays are stored as serialized post_meta, so
	 * a `meta_query` LIKE match against the serialized payload would be
	 * fragile. Instead we pull a bounded candidate pool ordered by title
	 * (narrowed by `s=` when the donor is typing) and filter in PHP — fine
	 * for realistic Giving Day datasets (low hundreds of posts max).
	 *
	 * @param WP_REST_Request $request
	 * @param string          $post_type           Team::POST_TYPE or Beneficiary::POST_TYPE.
	 * @param string          $campaigns_meta_key  The post type's `_giving_*_campaigns` meta key.
	 * @param bool            $with_unit_label     Append the parent unit to the label (beneficiaries).
	 * @return WP_REST_Response
	 */
	private function respond_designation_list( WP_REST_Request $request, string $post_type, string $campaigns_meta_key, bool $with_unit_label ): WP_REST_Response {
		$by_id = (int) $request->get_param( 'id' );
		if ( $by_id > 0 ) {
			$post  = get_post( $by_id );
			$items = array();
			if ( $post instanceof WP_Post && $post_type === $post->post_type && 'publish' === $post->post_status ) {
				$items[] = $this->format_designation_item( $post, $with_unit_label );
			}
			return $this->respond_array( $items );
		}

		$campaign_id = (int) $request->get_param( 'campaign_id' );
		if ( $campaign_id <= 0 ) {
			$campaign_id = OrderAttribution::default_campaign_id();
		}

		$raw_query = $request->get_param( 'q' );
		$query_str = is_string( $raw_query ) ? trim( $raw_query ) : '';

		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 200,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);
		if ( '' !== $query_str ) {
			$args['s'] = $query_str;
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( $campaign_id > 0 ) {
				$campaigns = get_post_meta( $post->ID, $campaigns_meta_key, true );
				if ( is_array( $campaigns ) ) {
					$ids = array_values( array_filter( array_map( 'intval', $campaigns ) ) );
				} elseif ( is_scalar( $campaigns ) && (int) $campaigns > 0 ) {
					$ids = array( (int) $campaigns );
				} else {
					$ids = array();
				}
				if ( ! in_array( $campaign_id, $ids, true ) ) {
					continue;
				}
			}
			$items[] = $this->format_designation_item( $post, $with_unit_label );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $this->respond_array( $items );
	}

	/**
	 * Shapes a single post for the typeahead contract.
	 *
	 * @param WP_Post $post
	 * @param bool    $with_unit_label
	 * @return array{id:int,label:string}
	 */
	private function format_designation_item( WP_Post $post, bool $with_unit_label ): array {
		$title = self::decode_text( get_the_title( $post ) );

		if ( $with_unit_label ) {
			$unit = self::decode_text( Beneficiary::display_unit_label( (int) $post->ID ) );
			if ( '' !== $unit && $unit !== $title ) {
				/* translators: 1: beneficiary / fund title, 2: parent unit (college, coalition, etc). */
				$title = sprintf( _x( '%1$s — %2$s', 'beneficiary chip label', 'giving-day-blocks' ), $title, $unit );
			}
		}

		return array(
			'id'    => (int) $post->ID,
			'label' => $title,
		);
	}

	/**
	 * Wraps a flat array payload in a no-store response.
	 *
	 * Used by endpoints whose contract is a raw JSON array (e.g. the
	 * team51-donations typeahead contract `[{id,label},…]`). Distinct from
	 * {@see self::respond()} which wraps an associative payload.
	 *
	 * @param array<int, mixed> $data
	 * @return WP_REST_Response
	 */
	private function respond_array( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
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
	 * Decodes HTML entities so REST clients receive raw text.
	 *
	 * WP runs term names and post titles through filters that emit
	 * `&amp;` and `&#038;`. Those entities reach React via JSON and render
	 * as literal text (JSX does not decode HTML entities), so we strip
	 * them at the API boundary and let consumers re-encode for their
	 * target context.
	 *
	 * @param string $value Possibly-encoded display value.
	 */
	private static function decode_text( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
