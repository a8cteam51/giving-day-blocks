<?php
/**
 * First-activation sample data seeder.
 *
 * Think "Hello Dolly" / "Sample Page": a tiny, generic, opinion-free data set
 * that lets a freshly-activated plugin show something meaningful in the admin
 * and in the block editor on the very first page load.
 *
 * @package Team51\GivingDay\Setup
 * @since   0.1.0
 */

namespace Team51\GivingDay\Setup;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Challenge;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamCategory;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds a minimal, generic sample data set on first activation.
 *
 * Why the two-step dance (activation hook → admin_init):
 * WordPress fires `register_activation_hook` callbacks inside the admin
 * request that triggered the activation, AFTER `init` has already run for
 * that request — which means our CPTs and taxonomies are not yet registered
 * at activation time. So activation only raises a flag option, and the
 * actual seeding happens on the first `admin_init` of the following request,
 * once every CPT/taxonomy is live.
 *
 * Seeding is guarded by a "does any campaign already exist?" check so this
 * never clobbers a real site: if the admin has created anything, we bail.
 */
final class MockData {

	/**
	 * Option name used to flag that seeding is pending.
	 *
	 * Set by the activation hook, consumed (and deleted) on the next
	 * `admin_init` by `maybe_seed()`.
	 */
	public const PENDING_OPTION = 'giving_day_blocks_pending_seed';

	/**
	 * Option name where we record the IDs of the seeded posts/terms, so the
	 * data can be identified later (e.g. to surface "this is sample data"
	 * notices, or to clean up on uninstall).
	 */
	public const SEEDED_OPTION = 'giving_day_blocks_seeded_ids';

	/**
	 * Hooks the seeder up. Idempotent: safe to call on every request.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_seed' ), 20 );
	}

	/**
	 * Activation hook callback. Raises the "seed me on next request" flag.
	 *
	 * Kept static so it can be wired from the plugin bootstrap without
	 * needing a live instance (the bootstrap runs before autoload guarantees
	 * a Plugin instance is available on activation).
	 */
	public static function flag_on_activation(): void {
		// Only flag if the option doesn't already exist — if the admin
		// already seeded once and then deactivated/reactivated, we don't
		// want to re-seed and create duplicate "Sample" content.
		if ( false === get_option( self::SEEDED_OPTION, false ) ) {
			update_option( self::PENDING_OPTION, 1, false );
		}
	}

	/**
	 * Runs at the start of the first post-activation admin request.
	 *
	 * Bails (silently) if the flag is missing, or if there's already any
	 * Campaign in the DB — this is a first-run helper, not a reset tool.
	 */
	public function maybe_seed(): void {
		if ( ! get_option( self::PENDING_OPTION ) ) {
			return;
		}

		// Clear the flag first so a fatal error mid-seed doesn't loop.
		delete_option( self::PENDING_OPTION );

		if ( $this->has_existing_data() ) {
			return;
		}

		$seeded = $this->seed();

		if ( ! empty( $seeded ) ) {
			update_option( self::SEEDED_OPTION, $seeded, false );
		}
	}

	/**
	 * Returns true if any Giving Day Campaign already exists — in that case
	 * we assume the site has real data and stay out of the way.
	 */
	private function has_existing_data(): bool {
		$existing = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'any',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Creates the sample data set.
	 *
	 * @return array<string, mixed> Map of what was created, keyed by role
	 *                              (campaign, teams[], match, challenge, etc.),
	 *                              or an empty array if nothing was created.
	 */
	private function seed(): array {
		$now        = new \DateTimeImmutable( 'now', wp_timezone() );
		$year_label = (int) $now->format( 'Y' );

		// Pick an event date ~30 days from today so the pre-event countdown
		// is meaningful out of the box. Use the year of that date for the
		// campaign label (handles the "activated on Dec 30" edge case).
		$event_start = $now->modify( '+30 days' )->setTime( 0, 0 );
		$event_end   = $event_start->modify( '+24 hours' );
		$pre_event   = $event_start->modify( '-14 days' );
		$title_year  = (int) $event_start->format( 'Y' );

		$terms = $this->seed_terms();

		$campaign_id = $this->create_campaign( $title_year, $pre_event, $event_start, $event_end );
		if ( ! $campaign_id ) {
			return array();
		}

		$beneficiary_id = $this->create_beneficiary( $campaign_id, $terms['cause_child'] );

		$team_ids = array(
			$this->create_team( 'Team Alpha', $campaign_id, $terms['team_cat_child'] ),
			$this->create_team( 'Team Beta', $campaign_id, $terms['team_cat_child'] ),
		);
		$team_ids = array_values( array_filter( $team_ids ) );

		$match_id        = $this->create_match( $campaign_id, $event_start );
		$donor_match_id  = $this->create_donor_unlock_match( $campaign_id, $event_start );
		$challenge_id    = $this->create_challenge( $campaign_id );

		return array(
			'campaign'     => $campaign_id,
			'beneficiary'  => $beneficiary_id,
			'teams'        => $team_ids,
			'match'        => $match_id,
			'donor_match'  => $donor_match_id,
			'challenge'    => $challenge_id,
			'terms'        => $terms,
		);
	}

	/**
	 * Creates a tiny two-level tree under each hierarchical taxonomy so the
	 * "browse by cause" and "tabbed leaderboards" UIs have something to show.
	 *
	 * @return array<string, int> Term IDs keyed by role.
	 */
	private function seed_terms(): array {
		$cause_parent = $this->ensure_term( __( 'Education', 'giving-day-blocks' ), Cause::TAXONOMY, 0 );
		$cause_child  = $this->ensure_term( __( 'Scholarships', 'giving-day-blocks' ), Cause::TAXONOMY, $cause_parent );

		$team_cat_parent = $this->ensure_term( __( 'Class Year', 'giving-day-blocks' ), TeamCategory::TAXONOMY, 0 );
		$team_cat_child  = $this->ensure_term( __( 'Alumni', 'giving-day-blocks' ), TeamCategory::TAXONOMY, $team_cat_parent );

		return array(
			'cause_parent'    => $cause_parent,
			'cause_child'     => $cause_child,
			'team_cat_parent' => $team_cat_parent,
			'team_cat_child'  => $team_cat_child,
		);
	}

	/**
	 * Inserts a term if it doesn't already exist under the given parent,
	 * and returns its ID either way.
	 */
	private function ensure_term( string $name, string $taxonomy, int $parent_id ): int {
		$existing = term_exists( $name, $taxonomy, $parent_id > 0 ? $parent_id : null );
		if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
			return (int) $existing['term_id'];
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => $parent_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Creates the sample Campaign.
	 */
	private function create_campaign(
		int $title_year,
		\DateTimeImmutable $pre_event,
		\DateTimeImmutable $start,
		\DateTimeImmutable $end
	): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => Campaign::POST_TYPE,
				'post_status'  => 'publish',
				/* translators: %d: four-digit year, e.g. 2026. */
				'post_title'   => sprintf( __( 'Sample Giving Day %d', 'giving-day-blocks' ), $title_year ),
				'post_content' => __( 'This is sample data created when Giving Day Blocks was first activated. Edit or delete it freely — it is just a starting point so the plugin has something to render out of the box.', 'giving-day-blocks' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, Campaign::META_PRE_EVENT_START, $pre_event->format( 'c' ) );
		update_post_meta( $post_id, Campaign::META_START_DATETIME, $start->format( 'c' ) );
		update_post_meta( $post_id, Campaign::META_END_DATETIME, $end->format( 'c' ) );
		update_post_meta( $post_id, Campaign::META_TIMEZONE, wp_timezone_string() );
		update_post_meta( $post_id, Campaign::META_GOAL_AMOUNT, 50000 );
		update_post_meta( $post_id, Campaign::META_CURRENCY, get_option( 'woocommerce_currency', 'USD' ) );

		return (int) $post_id;
	}

	/**
	 * Creates a Team post attached to the Campaign and tagged with the
	 * sample team-category term.
	 */
	private function create_team( string $title, int $campaign_id, int $team_cat_term_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Team::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, Team::META_CAMPAIGN_IDS, array( $campaign_id ) );
		update_post_meta( $post_id, Team::META_GOAL_AMOUNT, 5000 );

		if ( $team_cat_term_id > 0 ) {
			wp_set_object_terms( $post_id, array( $team_cat_term_id ), TeamCategory::TAXONOMY );
		}

		return (int) $post_id;
	}

	/**
	 * Creates a Beneficiary post tied to the Campaign and tagged with the
	 * sample cause term.
	 */
	private function create_beneficiary( int $campaign_id, int $cause_term_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => Beneficiary::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Sample Beneficiary Fund', 'giving-day-blocks' ),
				'post_excerpt' => __( 'A generic sample destination for donations.', 'giving-day-blocks' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, Beneficiary::META_CAMPAIGN_IDS, array( $campaign_id ) );
		update_post_meta( $post_id, Beneficiary::META_GOAL_AMOUNT, 10000 );
		update_post_meta( $post_id, Beneficiary::META_PARENT_ORG, __( 'Sample Organization', 'giving-day-blocks' ) );

		if ( $cause_term_id > 0 ) {
			wp_set_object_terms( $post_id, array( $cause_term_id ), Cause::TAXONOMY );
		}

		return (int) $post_id;
	}

	/**
	 * Creates a sponsor Match tied to the Campaign, active during the first
	 * hour of the event.
	 */
	private function create_match( int $campaign_id, \DateTimeImmutable $event_start ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => GivingMatch::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Sample Sponsor Match', 'giving-day-blocks' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		$match_end = $event_start->modify( '+1 hour' );

		update_post_meta( $post_id, GivingMatch::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $post_id, GivingMatch::META_TYPE, GivingMatch::TYPE_DOLLAR_FOR_DOLLAR );
		update_post_meta( $post_id, GivingMatch::META_SPONSOR_NAME, __( 'Sample Sponsor', 'giving-day-blocks' ) );
		update_post_meta( $post_id, GivingMatch::META_MULTIPLIER, 2 );
		update_post_meta( $post_id, GivingMatch::META_CAP_AMOUNT, 10000 );
		update_post_meta( $post_id, GivingMatch::META_START_DATETIME, $event_start->format( 'c' ) );
		update_post_meta( $post_id, GivingMatch::META_END_DATETIME, $match_end->format( 'c' ) );
		update_post_meta( $post_id, GivingMatch::META_ACTIVE, true );
		update_post_meta( $post_id, GivingMatch::META_MATCHED_OVERRIDE, 3500 );

		return (int) $post_id;
	}

	/**
	 * Creates a second sample Match in the donor-unlock shape so editors
	 * can preview both mechanics out of the box. Runs in the second hour
	 * of the event so it doesn't overlap the dollar-for-dollar one.
	 */
	private function create_donor_unlock_match( int $campaign_id, \DateTimeImmutable $event_start ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => GivingMatch::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Sample Donor-Unlock Match', 'giving-day-blocks' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		$start = $event_start->modify( '+1 hour' );
		$end   = $event_start->modify( '+2 hours' );

		update_post_meta( $post_id, GivingMatch::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $post_id, GivingMatch::META_TYPE, GivingMatch::TYPE_DONOR_UNLOCK );
		update_post_meta( $post_id, GivingMatch::META_SPONSOR_NAME, __( 'Sample Sponsor', 'giving-day-blocks' ) );
		update_post_meta( $post_id, GivingMatch::META_DONOR_THRESHOLD, 100 );
		update_post_meta( $post_id, GivingMatch::META_UNLOCK_AMOUNT, 5000 );
		update_post_meta( $post_id, GivingMatch::META_START_DATETIME, $start->format( 'c' ) );
		update_post_meta( $post_id, GivingMatch::META_END_DATETIME, $end->format( 'c' ) );
		update_post_meta( $post_id, GivingMatch::META_ACTIVE, true );
		update_post_meta( $post_id, GivingMatch::META_DONORS_OVERRIDE, 73 );

		return (int) $post_id;
	}

	/**
	 * Creates a sample Challenge attached to the Campaign: "get 50 donations
	 * in the first hour of the event to unlock a bonus."
	 */
	private function create_challenge( int $campaign_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Challenge::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Power Hour', 'giving-day-blocks' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, Challenge::META_CAMPAIGN_IDS, array( $campaign_id ) );
		update_post_meta( $post_id, Challenge::META_TYPE, 'donation_count' );
		update_post_meta( $post_id, Challenge::META_THRESHOLD, 50 );
		update_post_meta( $post_id, Challenge::META_REWARD_LABEL, __( 'Unlock a bonus gift from our sample sponsor', 'giving-day-blocks' ) );
		update_post_meta( $post_id, Challenge::META_REWARD_AMOUNT, 5000 );
		update_post_meta( $post_id, Challenge::META_WINDOW_OFFSET_START, 'PT0H' );
		update_post_meta( $post_id, Challenge::META_WINDOW_OFFSET_END, 'PT1H' );

		return (int) $post_id;
	}
}
