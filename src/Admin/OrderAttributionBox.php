<?php
/**
 * Edit Order meta box: Giving Day attribution.
 *
 * Renders three dropdowns (Campaign / Team / Beneficiary) on the
 * WooCommerce order edit screen — both the legacy `shop_order` post
 * editor and the HPOS `woocommerce_page_wc-orders` screen — so a
 * shop manager can backfill or correct the campaign attribution on
 * any donation order.
 *
 * Save path:
 *   - Validates each pick by post type and `publish` status.
 *   - Drops a Team / Beneficiary that is not tied to the picked
 *     Campaign (same rule {@see \Team51\GivingDay\Data\Context} uses
 *     when normalizing query attribution).
 *   - When the campaign is removed (set to 0), team and beneficiary
 *     are removed too — those IDs only make sense in the context of
 *     a campaign.
 *   - Bumps the {@see \Team51\GivingDay\Data\Aggregator} cache version
 *     for the old and new campaign IDs so totals + leaderboards
 *     recompute on the next read.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.2.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Team;
use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class OrderAttributionBox {

	private const META_BOX_ID    = 'giving-day-order-attribution';
	private const NONCE_ACTION   = 'giving_day_order_attribution_save';
	private const NONCE_FIELD    = 'giving_day_order_attribution_nonce';
	private const FIELD_CAMPAIGN = 'giving_day_campaign_id';
	private const FIELD_TEAM     = 'giving_day_team_id';
	private const FIELD_BENE     = 'giving_day_beneficiary_id';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 50, 2 );
	}

	/**
	 * Registers the meta box on both legacy and HPOS order edit screens.
	 */
	public function register_meta_box(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'Giving Day attribution', 'giving-day-blocks' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the meta box.
	 *
	 * @param WP_Post|WC_Order $object On the legacy screen this is a WP_Post; on
	 *                                 HPOS it is the WC_Order itself.
	 */
	public function render( $object ): void {
		$order_id = 0;
		if ( $object instanceof WC_Order ) {
			$order_id = $object->get_id();
		} elseif ( $object instanceof WP_Post ) {
			$order_id = (int) $object->ID;
		}
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$current = array(
			'campaign'    => (int) $order->get_meta( OrderAttribution::META_CAMPAIGN_ID ),
			'team'        => (int) $order->get_meta( OrderAttribution::META_TEAM_ID ),
			'beneficiary' => (int) $order->get_meta( OrderAttribution::META_BENEFICIARY_ID ),
		);

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$this->render_select(
			self::FIELD_CAMPAIGN,
			__( 'Campaign', 'giving-day-blocks' ),
			$this->get_published_posts( Campaign::POST_TYPE ),
			$current['campaign']
		);
		$this->render_select(
			self::FIELD_TEAM,
			__( 'Team', 'giving-day-blocks' ),
			$this->get_published_posts( Team::POST_TYPE ),
			$current['team']
		);
		$this->render_select(
			self::FIELD_BENE,
			__( 'Beneficiary / Fund', 'giving-day-blocks' ),
			$this->get_published_posts( Beneficiary::POST_TYPE ),
			$current['beneficiary']
		);
		?>
		<p class="description" style="margin-top:0.75em">
			<?php
			echo esc_html__(
				'Saving recomputes campaign totals and leaderboards on the next read. Team and Beneficiary are dropped if they don\'t belong to the picked Campaign.',
				'giving-day-blocks'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Persists changes from the meta box. Hooked on
	 * `woocommerce_process_shop_order_meta`, which fires for both legacy and
	 * HPOS save paths.
	 *
	 * @param int            $order_id Order post / record ID.
	 * @param WP_Post|WC_Order|null $maybe_order Provided by WC; legacy passes WP_Post, HPOS passes WC_Order, older callers may pass null.
	 */
	public function save( int $order_id, $maybe_order = null ): void {
		unset( $maybe_order );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$old_campaign = (int) $order->get_meta( OrderAttribution::META_CAMPAIGN_ID );

		$campaign_id    = $this->validate_post_id( $this->posted_int( self::FIELD_CAMPAIGN ), Campaign::POST_TYPE );
		$team_id        = $this->validate_post_id( $this->posted_int( self::FIELD_TEAM ), Team::POST_TYPE );
		$beneficiary_id = $this->validate_post_id( $this->posted_int( self::FIELD_BENE ), Beneficiary::POST_TYPE );

		// Team / Beneficiary only make sense within a campaign — and only when
		// they are explicitly tied to the chosen campaign. The campaign-id
		// meta is stored as an array of int IDs (see PostTypes\Team and
		// PostTypes\Beneficiary META_CAMPAIGN_IDS), so we membership-check.
		if ( 0 === $campaign_id ) {
			$team_id        = 0;
			$beneficiary_id = 0;
		} else {
			if ( $team_id > 0 && ! self::campaigns_meta_contains( $team_id, Team::META_CAMPAIGN_IDS, $campaign_id ) ) {
				$team_id = 0;
			}
			if ( $beneficiary_id > 0 && ! self::campaigns_meta_contains( $beneficiary_id, Beneficiary::META_CAMPAIGN_IDS, $campaign_id ) ) {
				$beneficiary_id = 0;
			}
		}

		$this->set_or_delete_meta( $order, OrderAttribution::META_CAMPAIGN_ID, $campaign_id );
		$this->set_or_delete_meta( $order, OrderAttribution::META_TEAM_ID, $team_id );
		$this->set_or_delete_meta( $order, OrderAttribution::META_BENEFICIARY_ID, $beneficiary_id );

		$order->save();

		if ( $old_campaign > 0 && $old_campaign !== $campaign_id ) {
			Aggregator::invalidate( $old_campaign );
		}
		if ( $campaign_id > 0 ) {
			Aggregator::invalidate( $campaign_id );
		}
	}

	/**
	 * @param array<int, WP_Post> $posts
	 */
	private function render_select( string $name, string $label, array $posts, int $current ): void {
		?>
		<p style="margin:0.75em 0">
			<label for="<?php echo esc_attr( $name ); ?>" style="display:block;font-weight:600;margin-bottom:0.25em">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" style="width:100%">
				<option value="0">— <?php echo esc_html__( 'None', 'giving-day-blocks' ); ?> —</option>
				<?php foreach ( $posts as $p ) : ?>
					<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( $current, $p->ID ); ?>>
						<?php
						/* translators: 1: post title, 2: post ID. */
						echo esc_html( sprintf( '%1$s (#%2$d)', $p->post_title, $p->ID ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * @return array<int, WP_Post>
	 */
	private function get_published_posts( string $post_type ): array {
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return is_array( $posts ) ? $posts : array();
	}

	private function posted_int( string $field ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in caller.
		return isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0;
	}

	private function validate_post_id( int $id, string $expected_type ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}
		if ( $post->post_type !== $expected_type ) {
			return 0;
		}
		if ( 'publish' !== $post->post_status ) {
			return 0;
		}
		return $id;
	}

	private function set_or_delete_meta( WC_Order $order, string $key, int $value ): void {
		if ( $value > 0 ) {
			$order->update_meta_data( $key, $value );
			return;
		}
		$order->delete_meta_data( $key );
	}

	/**
	 * Whether a Team / Beneficiary post's campaigns meta lists the given
	 * campaign ID. The meta is stored as an array of ints, but legacy or
	 * partially-migrated rows may carry a scalar — handle both.
	 */
	private static function campaigns_meta_contains( int $post_id, string $meta_key, int $campaign_id ): bool {
		$value = get_post_meta( $post_id, $meta_key, true );

		if ( is_array( $value ) ) {
			$ids = array_map( 'intval', $value );
			return in_array( $campaign_id, $ids, true );
		}

		return is_scalar( $value ) && (int) $value === $campaign_id;
	}
}
