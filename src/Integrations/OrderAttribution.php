<?php
/**
 * Order attribution listener.
 *
 * Hooked on woocommerce_checkout_create_order — reads the session
 * context set by Data\Context and writes _giving_campaign_id,
 * _giving_team_id, and _giving_beneficiary_id as order meta.
 *
 * A `giving_day_attribute_order` filter fires before persistence so
 * external code (CLI importers, off-site payment callbacks, etc.) can
 * override or enrich the attribution.
 *
 * @package Team51\GivingDay\Integrations
 * @since   0.1.0
 */

namespace Team51\GivingDay\Integrations;

use Team51\GivingDay\Data\Context;
use Team51\GivingDay\Data\Status;
use Team51\GivingDay\PostTypes\Campaign;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderAttribution {

	public const META_CAMPAIGN_ID    = '_giving_campaign_id';
	public const META_TEAM_ID        = '_giving_team_id';
	public const META_BENEFICIARY_ID = '_giving_beneficiary_id';

	/**
	 * Registers the checkout hook.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order' ), 20, 1 );
		add_action( 'admin_notices', array( $this, 'maybe_render_overlap_notice' ) );
	}

	/**
	 * Writes attribution meta onto a newly created WC order.
	 *
	 * Runs at priority 20 on woocommerce_checkout_create_order. The donation
	 * form designation chips (see DonationDesignationChips) write at priority
	 * 10, so any meta they wrote already exists on the order by the time we
	 * arrive — this method is additive and never overwrites an existing
	 * value, so the donor's explicit chip choice wins over Context-derived
	 * attribution.
	 *
	 * @param WC_Order $order The order being created.
	 */
	public function tag_order( WC_Order $order ): void {
		$attribution = Context::get();

		// Fall back to a single live campaign when the visitor never set context
		// (e.g. donated straight from a product page without visiting a Giving
		// Day surface). Filter runs after, so external code can still override.
		if ( empty( $attribution['campaign_id'] ) ) {
			$default = self::default_campaign_id();
			if ( $default > 0 ) {
				$attribution['campaign_id'] = $default;
			}
		}

		/**
		 * Filters the attribution data before it is written to the order.
		 *
		 * Return an array with keys campaign_id, team_id, beneficiary_id.
		 * Set campaign_id to 0 to leave the order unattributed.
		 *
		 * @param array{campaign_id: int, team_id: int, beneficiary_id: int} $attribution
		 * @param WC_Order $order
		 */
		$attribution = apply_filters( 'giving_day_attribute_order', $attribution, $order );

		if ( ! is_array( $attribution ) ) {
			return;
		}

		$campaign_id    = isset( $attribution['campaign_id'] ) ? absint( $attribution['campaign_id'] ) : 0;
		$team_id        = isset( $attribution['team_id'] ) ? absint( $attribution['team_id'] ) : 0;
		$beneficiary_id = isset( $attribution['beneficiary_id'] ) ? absint( $attribution['beneficiary_id'] ) : 0;

		// Prefer values already on the order — typically written by the chip
		// listener at priority 10. Donor-explicit choices beat session inference.
		$existing_campaign    = (int) $order->get_meta( self::META_CAMPAIGN_ID, true );
		$existing_team        = (int) $order->get_meta( self::META_TEAM_ID, true );
		$existing_beneficiary = (int) $order->get_meta( self::META_BENEFICIARY_ID, true );

		if ( $existing_campaign > 0 ) {
			$campaign_id = $existing_campaign;
		}
		if ( $existing_team > 0 ) {
			$team_id = $existing_team;
		}
		if ( $existing_beneficiary > 0 ) {
			$beneficiary_id = $existing_beneficiary;
		}

		if ( 0 === $campaign_id ) {
			return;
		}

		$order->update_meta_data( self::META_CAMPAIGN_ID, $campaign_id );

		if ( $team_id > 0 ) {
			$order->update_meta_data( self::META_TEAM_ID, $team_id );
		}
		if ( $beneficiary_id > 0 ) {
			$order->update_meta_data( self::META_BENEFICIARY_ID, $beneficiary_id );
		}
	}

	/**
	 * Returns the campaign ID that orphan donations should default to.
	 *
	 * Picks among published Campaign posts that {@see Status::resolve()}
	 * reports as live. If exactly one is live, that one wins. If multiple
	 * are live, the one with the most recent `META_START_DATETIME` wins.
	 * Returns 0 when no campaign is currently live (we never guess across
	 * pre/ended campaigns — those orders stay unattributed and can be
	 * fixed via the Edit Order screen).
	 */
	public static function default_campaign_id(): int {
		$live = self::live_campaigns_sorted();
		if ( empty( $live ) ) {
			return 0;
		}

		return $live[0]['id'];
	}

	/**
	 * Renders an admin notice when more than one campaign is live, so
	 * admins notice accidental overlap (orphan donations get attributed
	 * to the most recently started one).
	 */
	public function maybe_render_overlap_notice(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$live = self::live_campaigns_sorted();
		if ( count( $live ) < 2 ) {
			return;
		}

		$items = array();
		foreach ( $live as $entry ) {
			$title    = get_the_title( $entry['id'] );
			$edit_url = get_edit_post_link( $entry['id'] );
			$label    = '' !== $title ? $title : sprintf( '#%d', $entry['id'] );
			$items[]  = $edit_url
				? sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $label ) )
				: esc_html( $label );
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			esc_html__( 'Giving Day:', 'giving-day-blocks' ),
			sprintf(
				/* translators: %s: comma-separated list of live campaign links. */
				esc_html__( 'More than one campaign is currently live: %s.', 'giving-day-blocks' ),
				implode( ', ', $items ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item is escaped above.
			),
			esc_html__( 'Donations without explicit attribution will default to the campaign with the most recent start. If this overlap is unintentional, adjust the schedule on one of the campaigns.', 'giving-day-blocks' )
		);
	}

	/**
	 * Returns currently-live campaigns sorted by most recent start first.
	 *
	 * @return array<int, array{id: int, start: string}>
	 */
	private static function live_campaigns_sorted(): array {
		$candidates = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( empty( $candidates ) ) {
			return array();
		}

		$live = array();
		foreach ( $candidates as $cid ) {
			$cid = (int) $cid;
			if ( Status::LIVE !== Status::resolve( $cid ) ) {
				continue;
			}
			$live[] = array(
				'id'    => $cid,
				'start' => (string) get_post_meta( $cid, Campaign::META_START_DATETIME, true ),
			);
		}

		if ( count( $live ) > 1 ) {
			usort(
				$live,
				static function ( array $a, array $b ): int {
					return strcmp( $b['start'], $a['start'] );
				}
			);
		}

		return $live;
	}
}
