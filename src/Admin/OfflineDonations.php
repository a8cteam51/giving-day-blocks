<?php
/**
 * Offline Donations admin screen.
 *
 * Lists past offline donations and provides a form to record new ones.
 * Each save creates a real WC order via {@see Services\OfflineDonations}.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.3.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Integrations\OfflineGateway;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Services\CampaignSetup;
use Team51\GivingDay\Services\OfflineDonations as OfflineService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Giving Days → Offline Donations submenu and handles its form.
 */
final class OfflineDonations {

	public const PAGE_SLUG           = 'giving-day-offline-donations';
	public const NONCE_ACTION        = 'giving_day_offline_donation_save';
	public const NONCE_ACTION_CREATE = 'giving_day_offline_create_product';
	public const REQUIRED_CAP        = 'edit_shop_orders';
	public const REQUIRED_CAP_CREATE = 'edit_products';
	private const DRAFT_TRANSIENT_PREFIX = 'giving_day_offline_draft_';
	private const DRAFT_TTL_SECONDS  = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hooks the submenu registration and form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_giving_day_offline_donation_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_giving_day_offline_create_product', array( $this, 'handle_create_product' ) );
	}

	/**
	 * Adds the submenu under Giving Days.
	 */
	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Campaign::POST_TYPE,
			__( 'Offline Donations', 'giving-day-blocks' ),
			__( 'Offline Donations', 'giving-day-blocks' ),
			self::REQUIRED_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the page; switches between list and form based on `?action`.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'giving-day-blocks' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.

		echo '<div class="wrap giving-day-offline-donations">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Offline Donations', 'giving-day-blocks' ) . '</h1>';

		if ( 'new' !== $action ) {
			$new_url = add_query_arg( array( 'action' => 'new' ), self::page_url() );
			echo ' <a href="' . esc_url( $new_url ) . '" class="page-title-action">' . esc_html__( 'Add Donation', 'giving-day-blocks' ) . '</a>';
		}

		echo '<hr class="wp-header-end" />';

		$this->render_admin_notices();

		if ( 'new' === $action ) {
			$this->render_form();
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Surfaces success/error flash messages set by `handle_save()` redirects.
	 */
	private function render_admin_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash flags from a redirect we just controlled.
		if ( isset( $_GET['giving_day_created'] ) ) {
			$order_id = absint( $_GET['giving_day_created'] );
			$edit_url = $order_id > 0 ? get_edit_post_link( $order_id ) : '';
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s%s</p></div>',
				esc_html__( 'Offline donation recorded.', 'giving-day-blocks' ),
				$edit_url
					? ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'View order', 'giving-day-blocks' ) . '</a>'
					: ''
			);
		}
		if ( isset( $_GET['giving_day_product_created'] ) ) {
			$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
			$title       = $campaign_id > 0 ? get_the_title( $campaign_id ) : '';
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				$title
					? esc_html(
						sprintf(
							/* translators: %s: campaign title. */
							__( 'Donation product created and linked to "%s". You can now record the donation.', 'giving-day-blocks' ),
							$title
						)
					)
					: esc_html__( 'Donation product created and linked to the campaign.', 'giving-day-blocks' )
			);
		}
		if ( isset( $_GET['giving_day_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['giving_day_error'] ) );
			if ( '' !== $message ) {
				$campaign_id = isset( $_GET['giving_day_no_product'] ) ? absint( $_GET['giving_day_no_product'] ) : 0;
				printf(
					'<div class="notice notice-error"><p>%s%s</p></div>',
					esc_html( $message ),
					$campaign_id > 0 && current_user_can( self::REQUIRED_CAP_CREATE )
						? $this->render_create_product_button( $campaign_id ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output.
						: ''
				);
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Renders a small inline form with a "Create donation product" button
	 * for the given campaign. Returned (not echoed) so `printf()` can
	 * concatenate it into a notice. All output is pre-escaped here.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	private function render_create_product_button( int $campaign_id ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:.75em">
			<?php wp_nonce_field( self::NONCE_ACTION_CREATE ); ?>
			<input type="hidden" name="action" value="giving_day_offline_create_product" />
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>" />
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Create donation product', 'giving-day-blocks' ); ?>
			</button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the table of past offline donations.
	 */
	private function render_list(): void {
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		$orders = wc_get_orders(
			array(
				'limit'      => $per_page,
				'page'       => $paged,
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				// Admin-only paginated screen, the meta filter is acceptable here.
				'meta_key'   => OfflineService::META_OFFLINE_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $orders->orders ) ) {
			echo '<p>' . esc_html__( 'No offline donations recorded yet.', 'giving-day-blocks' ) . '</p>';
			return;
		}

		$tender_labels = OfflineService::tender_choices();

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Donor', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Campaign', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Tender', 'giving-day-blocks' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $orders->orders as $order ) {
			$campaign_id    = (int) $order->get_meta( '_giving_campaign_id' );
			$campaign_title = $campaign_id > 0 ? get_the_title( $campaign_id ) : '';
			$tender         = (string) $order->get_meta( OfflineService::META_TENDER );
			$reference      = (string) $order->get_meta( OfflineService::META_REFERENCE );
			$tender_label   = $tender_labels[ $tender ] ?? $tender;
			if ( '' !== $reference ) {
				$tender_label .= ' — ' . $reference;
			}
			$edit_url = get_edit_post_link( $order->get_id() );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( (string) $order->get_id() ) . '</a></td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0 ) ) . '</td>';
			echo '<td>' . esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
			$email = $order->get_billing_email();
			if ( $email ) {
				echo '<br /><small>' . esc_html( $email ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $campaign_title ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</td>';
			echo '<td>' . esc_html( $tender_label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $orders->max_num_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', self::page_url() ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $orders->max_num_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Renders the "Add donation" form.
	 */
	private function render_form(): void {
		$campaigns     = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$teams         = get_posts(
			array(
				'post_type'              => Team::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$beneficiaries = get_posts(
			array(
				'post_type'              => Beneficiary::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $campaigns ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Publish at least one campaign before recording offline donations.', 'giving-day-blocks' ) . '</p></div>';
			return;
		}

		$now_local    = wp_date( 'Y-m-d\TH:i' );
		$tender_label = OfflineService::tender_choices();
		$action_url   = admin_url( 'admin-post.php' );
		$countries    = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		$base_country = function_exists( 'wc_get_base_location' ) ? (string) ( wc_get_base_location()['country'] ?? '' ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only form prefill.
		$preselect_campaign = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;

		// Restore any draft saved by `handle_save()` after a validation error.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by our own redirect.
		$draft = isset( $_GET['giving_day_draft'] ) ? self::consume_draft() : array();
		$draft = is_array( $draft ) ? $draft : array();
		$d     = static function ( string $key, $fallback = '' ) use ( $draft ) {
			return array_key_exists( $key, $draft ) ? (string) $draft[ $key ] : (string) $fallback;
		};
		$d_int = static function ( string $key ) use ( $draft ): int {
			return isset( $draft[ $key ] ) ? (int) $draft[ $key ] : 0;
		};
		if ( ! empty( $draft['campaign_id'] ) ) {
			$preselect_campaign = (int) $draft['campaign_id'];
		}
		$amount_value      = $d( 'amount' );
		$donation_date     = '' !== $d( 'donation_date' ) ? $d( 'donation_date' ) : $now_local;
		$tender_value      = $d( 'tender' );
		$reference_value   = $d( 'reference' );
		$donor_name_value  = $d( 'donor_name' );
		$donor_email_value = $d( 'donor_email' );
		$preselect_team    = $d_int( 'team_id' );
		$preselect_ben     = $d_int( 'beneficiary_id' );
		$phone_value       = $d( 'donor_phone' );
		$addr1_value       = $d( 'billing_address_1' );
		$addr2_value       = $d( 'billing_address_2' );
		$city_value        = $d( 'billing_city' );
		$state_value       = $d( 'billing_state' );
		$postcode_value    = $d( 'billing_postcode' );
		$country_value     = '' !== $d( 'billing_country' ) ? $d( 'billing_country' ) : $base_country;
		$notes_value       = $d( 'admin_notes' );

		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="giving-day-offline-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="giving_day_offline_donation_save" />

			<h2><?php esc_html_e( 'Donation', 'giving-day-blocks' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>

				<tr>
					<th scope="row"><label for="giving_day_campaign_id"><?php esc_html_e( 'Campaign', 'giving-day-blocks' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="giving_day_campaign_id" name="campaign_id" required>
							<option value=""><?php esc_html_e( '— Select campaign —', 'giving-day-blocks' ); ?></option>
							<?php foreach ( $campaigns as $campaign ) : ?>
								<option value="<?php echo esc_attr( (string) $campaign->ID ); ?>" <?php selected( (int) $campaign->ID, $preselect_campaign ); ?>><?php echo esc_html( get_the_title( $campaign ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_amount"><?php esc_html_e( 'Amount', 'giving-day-blocks' ); ?> <span class="required">*</span></label></th>
					<td>
						<input type="number" step="0.01" min="0.01" id="giving_day_amount" name="amount" required class="regular-text" value="<?php echo esc_attr( $amount_value ); ?>" />
						<p class="description"><?php esc_html_e( 'Amount actually collected. Currency follows the campaign.', 'giving-day-blocks' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_donation_date"><?php esc_html_e( 'Donation date', 'giving-day-blocks' ); ?></label></th>
					<td>
						<input type="datetime-local" id="giving_day_donation_date" name="donation_date" value="<?php echo esc_attr( $donation_date ); ?>" />
						<p class="description"><?php esc_html_e( 'Defaults to now. Backdate when entering donations after the event.', 'giving-day-blocks' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_tender"><?php esc_html_e( 'Tender', 'giving-day-blocks' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="giving_day_tender" name="tender" required>
							<?php foreach ( $tender_label as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $tender_value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_reference"><?php esc_html_e( 'Reference', 'giving-day-blocks' ); ?></label></th>
					<td>
						<input type="text" id="giving_day_reference" name="reference" class="regular-text" value="<?php echo esc_attr( $reference_value ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. Check number, receipt number, etc.', 'giving-day-blocks' ); ?></p>
					</td>
				</tr>

			</tbody></table>

			<h2><?php esc_html_e( 'Donor', 'giving-day-blocks' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>

				<tr>
					<th scope="row"><label for="giving_day_donor_name"><?php esc_html_e( 'Name', 'giving-day-blocks' ); ?> <span class="required">*</span></label></th>
					<td><input type="text" id="giving_day_donor_name" name="donor_name" required class="regular-text" autocomplete="name" value="<?php echo esc_attr( $donor_name_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_donor_email"><?php esc_html_e( 'Email', 'giving-day-blocks' ); ?> <span class="required">*</span></label></th>
					<td><input type="email" id="giving_day_donor_email" name="donor_email" required class="regular-text" autocomplete="email" value="<?php echo esc_attr( $donor_email_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_team_id"><?php esc_html_e( 'Team', 'giving-day-blocks' ); ?></label></th>
					<td>
						<select id="giving_day_team_id" name="team_id">
							<option value="0"><?php esc_html_e( '— None —', 'giving-day-blocks' ); ?></option>
							<?php foreach ( $teams as $team ) : ?>
								<option value="<?php echo esc_attr( (string) $team->ID ); ?>" <?php selected( (int) $team->ID, $preselect_team ); ?>><?php echo esc_html( get_the_title( $team ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Optional. Must belong to the selected campaign.', 'giving-day-blocks' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_beneficiary_id"><?php esc_html_e( 'Beneficiary', 'giving-day-blocks' ); ?></label></th>
					<td>
						<select id="giving_day_beneficiary_id" name="beneficiary_id">
							<option value="0"><?php esc_html_e( '— None —', 'giving-day-blocks' ); ?></option>
							<?php foreach ( $beneficiaries as $beneficiary ) : ?>
								<option value="<?php echo esc_attr( (string) $beneficiary->ID ); ?>" <?php selected( (int) $beneficiary->ID, $preselect_ben ); ?>><?php echo esc_html( get_the_title( $beneficiary ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Optional. Must belong to the selected campaign.', 'giving-day-blocks' ); ?></p>
					</td>
				</tr>

			</tbody></table>

			<h2><?php esc_html_e( 'Billing address', 'giving-day-blocks' ); ?> <span class="giving-day-offline-form__optional"><?php esc_html_e( '(optional)', 'giving-day-blocks' ); ?></span></h2>
			<table class="form-table" role="presentation"><tbody>

				<tr>
					<th scope="row"><label for="giving_day_phone"><?php esc_html_e( 'Phone', 'giving-day-blocks' ); ?></label></th>
					<td><input type="text" id="giving_day_phone" name="donor_phone" class="regular-text" autocomplete="tel" value="<?php echo esc_attr( $phone_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_address_1"><?php esc_html_e( 'Address line 1', 'giving-day-blocks' ); ?></label></th>
					<td><input type="text" id="giving_day_address_1" name="billing_address_1" class="regular-text" autocomplete="address-line1" value="<?php echo esc_attr( $addr1_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_address_2"><?php esc_html_e( 'Address line 2', 'giving-day-blocks' ); ?></label></th>
					<td><input type="text" id="giving_day_address_2" name="billing_address_2" class="regular-text" autocomplete="address-line2" value="<?php echo esc_attr( $addr2_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_city"><?php esc_html_e( 'City', 'giving-day-blocks' ); ?></label></th>
					<td><input type="text" id="giving_day_city" name="billing_city" class="regular-text" autocomplete="address-level2" value="<?php echo esc_attr( $city_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_state"><?php esc_html_e( 'State / Region', 'giving-day-blocks' ); ?></label></th>
					<td><input type="text" id="giving_day_state" name="billing_state" class="regular-text" autocomplete="address-level1" value="<?php echo esc_attr( $state_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_postcode"><?php esc_html_e( 'Postcode', 'giving-day-blocks' ); ?></label></th>
					<td><input type="text" id="giving_day_postcode" name="billing_postcode" class="regular-text" autocomplete="postal-code" value="<?php echo esc_attr( $postcode_value ); ?>" /></td>
				</tr>

				<tr>
					<th scope="row"><label for="giving_day_country"><?php esc_html_e( 'Country', 'giving-day-blocks' ); ?></label></th>
					<td>
						<?php if ( ! empty( $countries ) ) : ?>
							<select id="giving_day_country" name="billing_country" autocomplete="country">
								<option value=""><?php esc_html_e( '— Select country —', 'giving-day-blocks' ); ?></option>
								<?php foreach ( $countries as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $country_value ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" id="giving_day_country" name="billing_country" maxlength="2" placeholder="US" value="<?php echo esc_attr( $country_value ); ?>" />
						<?php endif; ?>
					</td>
				</tr>

			</tbody></table>

			<h2><?php esc_html_e( 'Internal notes', 'giving-day-blocks' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>

				<tr>
					<th scope="row"><label for="giving_day_admin_notes"><?php esc_html_e( 'Notes', 'giving-day-blocks' ); ?></label></th>
					<td>
						<textarea id="giving_day_admin_notes" name="admin_notes" class="large-text" rows="3"><?php echo esc_textarea( $notes_value ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Stored as a private order note. Not shown to the donor.', 'giving-day-blocks' ); ?></p>
					</td>
				</tr>

			</tbody></table>

			<?php submit_button( __( 'Record donation', 'giving-day-blocks' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Returns the un-HTML-escaped admin URL for this page. Built directly
	 * because `menu_page_url()` returns an esc_url'd string, which would
	 * double-encode (`&` → `&#038;`) once we pass it through `esc_url()`
	 * again on output and break the link.
	 */
	private static function page_url(): string {
		return add_query_arg(
			array(
				'post_type' => Campaign::POST_TYPE,
				'page'      => self::PAGE_SLUG,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Handles the form POST: validates nonce/cap, dispatches to the service,
	 * and redirects with a flash flag for success or error.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to record offline donations.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$amount_raw = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$args       = array(
			'campaign_id'       => isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0,
			'amount'            => '' === $amount_raw ? 0.0 : (float) $amount_raw,
			'donor_name'        => isset( $_POST['donor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_name'] ) ) : '',
			'donor_email'       => isset( $_POST['donor_email'] ) ? sanitize_email( wp_unslash( $_POST['donor_email'] ) ) : '',
			'team_id'           => isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0,
			'beneficiary_id'    => isset( $_POST['beneficiary_id'] ) ? absint( $_POST['beneficiary_id'] ) : 0,
			'tender'            => isset( $_POST['tender'] ) ? sanitize_key( wp_unslash( $_POST['tender'] ) ) : '',
			'reference'         => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
			'donor_phone'       => isset( $_POST['donor_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_phone'] ) ) : '',
			'billing_address_1' => isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
			'billing_address_2' => isset( $_POST['billing_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) : '',
			'billing_city'      => isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
			'billing_state'     => isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
			'billing_postcode'  => isset( $_POST['billing_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '',
			'billing_country'   => isset( $_POST['billing_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) ) : '',
			'donation_date'     => isset( $_POST['donation_date'] ) ? sanitize_text_field( wp_unslash( $_POST['donation_date'] ) ) : '',
			'admin_notes'       => isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '',
		);

		$result = OfflineService::create_order( $args );

		$base = self::page_url();
		if ( is_wp_error( $result ) ) {
			// Stash the typed values so the next form render can prefill them.
			self::store_draft( $args );

			$query = array(
				'action'           => 'new',
				'giving_day_error' => rawurlencode( $result->get_error_message() ),
				'giving_day_draft' => 1,
			);
			// Pass the campaign through so the form prefills the select and
			// the inline "Create donation product" button knows which
			// campaign to act on.
			if ( $args['campaign_id'] > 0 ) {
				$query['campaign_id'] = $args['campaign_id'];
			}
			if ( 'giving_day_offline_no_product' === $result->get_error_code() && $args['campaign_id'] > 0 ) {
				$query['giving_day_no_product'] = $args['campaign_id'];
			}
			$redirect = add_query_arg( $query, $base );
		} else {
			self::clear_draft();
			$redirect = add_query_arg(
				array( 'giving_day_created' => (int) $result ),
				$base
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Persists a one-shot draft of the form values for the current user, so
	 * they survive the post/redirect/get bounce on validation failures.
	 *
	 * @param array<string, mixed> $args Sanitized form data from `handle_save()`.
	 */
	private static function store_draft( array $args ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient( self::DRAFT_TRANSIENT_PREFIX . $user_id, $args, self::DRAFT_TTL_SECONDS );
	}

	/**
	 * Reads and deletes the current user's saved draft. One-shot so a stale
	 * draft can't keep prefilling on unrelated visits.
	 *
	 * @return array<string, mixed>
	 */
	private static function consume_draft(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$key   = self::DRAFT_TRANSIENT_PREFIX . $user_id;
		$draft = get_transient( $key );
		delete_transient( $key );
		return is_array( $draft ) ? $draft : array();
	}

	/**
	 * Clears the current user's draft without reading it.
	 */
	private static function clear_draft(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		delete_transient( self::DRAFT_TRANSIENT_PREFIX . $user_id );
	}

	/**
	 * Handles the "Create donation product" button. Creates a virtual
	 * donation product, attaches it to the campaign, and bounces back to
	 * the form with a success notice and the campaign preselected.
	 */
	public function handle_create_product(): void {
		if ( ! current_user_can( self::REQUIRED_CAP_CREATE ) ) {
			wp_die( esc_html__( 'You do not have permission to create donation products.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION_CREATE );

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		if ( $campaign_id <= 0 || ! current_user_can( 'edit_post', $campaign_id ) ) {
			wp_die( esc_html__( 'You cannot edit that campaign.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}

		$result = CampaignSetup::create_donation_product( $campaign_id );
		$base   = self::page_url();
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'action'           => 'new',
					'campaign_id'      => $campaign_id,
					'giving_day_error' => rawurlencode( $result->get_error_message() ),
				),
				$base
			);
		} else {
			$redirect = add_query_arg(
				array(
					'action'                     => 'new',
					'campaign_id'                => $campaign_id,
					'giving_day_product_created' => (int) $result,
				),
				$base
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
