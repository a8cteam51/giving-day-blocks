<?php
/**
 * Giving Day settings page (admin).
 *
 * Adds a Settings submenu under the Giving Days menu, surfaces the state of
 * every prerequisite for accepting donations, and provides one-click actions
 * to provision a WooPayments sandbox (test-drive) account — including a
 * recovery path when the site already has a half-finished live account in
 * the way.
 *
 * Provisioning + reset both dispatch WooPayments' own REST endpoints via
 * `rest_do_request()` rather than touching internal services directly:
 *   POST /wc/v3/payments/onboarding/test_drive_account/init
 *   POST /wc/v3/payments/onboarding/reset
 *
 * Account state detection reads from the public
 * `WC_Payments_Account::get_account_status_data()` accessor (real account
 * fields), not from the local `wcpay_onboarding_test_mode` option, so the
 * page tells the truth even when a previous run flipped that flag without
 * actually replacing the connected account.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.2.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Campaign;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const PAGE_SLUG     = 'giving-day-settings';
	public const CAPABILITY    = 'manage_woocommerce';
	public const ACTION_SETUP  = 'giving_day_provision_test_drive';
	public const ACTION_RESET  = 'giving_day_reset_and_provision';
	private const NOTICE_KEY   = 'giving_day_settings_notice';
	private const WCPAY_PLUGIN = 'woocommerce-payments/woocommerce-payments.php';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_SETUP, array( $this, 'handle_provision' ) );
		add_action( 'admin_post_' . self::ACTION_RESET, array( $this, 'handle_reset_and_provision' ) );

		if ( defined( 'GIVING_DAY_BLOCKS_BASENAME' ) ) {
			add_filter( 'plugin_action_links_' . GIVING_DAY_BLOCKS_BASENAME, array( $this, 'plugin_action_links' ) );
		}
	}

	/**
	 * Adds a "Settings" link to the plugin row on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing action links.
	 * @return array<int|string, string>
	 */
	public function plugin_action_links( array $links ): array {
		$url  = admin_url( 'edit.php?post_type=' . Campaign::POST_TYPE . '&page=' . self::PAGE_SLUG );
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Settings', 'giving-day-blocks' )
		);
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Registers the Settings submenu under the Campaign CPT menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Campaign::POST_TYPE,
			__( 'Giving Day Settings', 'giving-day-blocks' ),
			__( 'Settings', 'giving-day-blocks' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the Settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'giving-day-blocks' ) );
		}

		$status = $this->collect_status();
		$notice = $this->consume_notice();

		?>
		<div class="wrap giving-day-settings">
			<h1><?php echo esc_html__( 'Giving Day Settings', 'giving-day-blocks' ); ?></h1>

			<?php if ( null !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description" style="max-width:72ch">
				<?php
				echo esc_html__(
					'This page lists the requirements for accepting donations and lets you provision a WooPayments sandbox account in one click. No real money is processed in sandbox mode — switch to live payments from Woo → Payments when you are ready.',
					'giving-day-blocks'
				);
				?>
			</p>

			<h2><?php echo esc_html__( 'Requirements', 'giving-day-blocks' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th style="width:60px"><?php echo esc_html__( 'Status', 'giving-day-blocks' ); ?></th>
						<th><?php echo esc_html__( 'Requirement', 'giving-day-blocks' ); ?></th>
						<th><?php echo esc_html__( 'Detail', 'giving-day-blocks' ); ?></th>
						<th><?php echo esc_html__( 'Action', 'giving-day-blocks' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['rows'] as $row ) : ?>
						<tr>
							<td><?php echo $row['ok'] ? '<span style="color:#1a7a3a">&#10003;</span>' : '<span style="color:#a00">&#10007;</span>'; ?></td>
							<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
							<td><?php echo esc_html( $row['detail'] ); ?></td>
							<td>
								<?php if ( ! empty( $row['action'] ) ) : ?>
									<a href="<?php echo esc_url( $row['action']['url'] ); ?>" class="button"<?php echo ! empty( $row['action']['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
										<?php echo esc_html( $row['action']['label'] ); ?>
									</a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php echo esc_html__( 'Sandbox payments', 'giving-day-blocks' ); ?></h2>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1em 1.5em;max-width:900px">
				<?php $this->render_account_panel( $status ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the per-state action panel inside the "Sandbox payments" card.
	 *
	 * @param array $status Output of {@see self::collect_status()}.
	 */
	private function render_account_panel( array $status ): void {
		$state = $status['account_state'];

		switch ( $state ) {
			case 'sandbox_ready':
				?>
				<p>
					<strong><?php echo esc_html__( 'Sandbox payments are ready.', 'giving-day-blocks' ); ?></strong><br>
					<?php
					echo esc_html__(
						'A WooPayments test-drive account is connected and accepting test payments. Place a donation using card 4242 4242 4242 4242 to confirm. When you are ready to take real money, head to Woo → Payments → Set up live payments.',
						'giving-day-blocks'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->wcpay_overview_url() ); ?>" class="button">
						<?php echo esc_html__( 'Open WooPayments dashboard', 'giving-day-blocks' ); ?>
					</a>
				</p>
				<?php
				break;

			case 'sandbox_pending':
				?>
				<p>
					<strong><?php echo esc_html__( 'Sandbox account provisioned, but not yet active.', 'giving-day-blocks' ); ?></strong><br>
					<?php
					echo esc_html__(
						'The WooPayments platform created a test-drive account but has not finished enabling it. Wait a moment and refresh this page, or reset and try again.',
						'giving-day-blocks'
					);
					?>
				</p>
				<?php $this->render_reset_form( __( 'Reset and retry sandbox', 'giving-day-blocks' ) ); ?>
				<?php
				break;

			case 'live_ready':
				?>
				<p>
					<strong><?php echo esc_html__( 'A live WooPayments account is connected.', 'giving-day-blocks' ); ?></strong><br>
					<?php
					echo esc_html__(
						'No sandbox setup is needed — your store can accept real donations through the existing WooPayments account.',
						'giving-day-blocks'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->wcpay_overview_url() ); ?>" class="button">
						<?php echo esc_html__( 'Open WooPayments dashboard', 'giving-day-blocks' ); ?>
					</a>
				</p>
				<?php
				break;

			case 'live_stuck':
				?>
				<p>
					<strong><?php echo esc_html__( 'A WooPayments live account is connected but not yet active.', 'giving-day-blocks' ); ?></strong><br>
					<?php
					echo esc_html__(
						'The connected account is missing required information (KYC, bank details, etc.) and cannot accept payments yet. You can either finish live onboarding, or reset the account and start fresh with a sandbox.',
						'giving-day-blocks'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->wcpay_overview_url() ); ?>" class="button button-primary">
						<?php echo esc_html__( 'Complete live setup', 'giving-day-blocks' ); ?>
					</a>
					&nbsp;
					<?php
					$this->render_reset_form(
						__( 'Reset account and create sandbox', 'giving-day-blocks' ),
						'button',
						__( 'This will permanently delete the connected WooPayments account and create a fresh sandbox account in its place. Continue?', 'giving-day-blocks' )
					);
					?>
				</p>
				<?php
				break;

			case 'unknown':
				?>
				<p>
					<strong><?php echo esc_html__( 'WooPayments is connected, but the account state could not be read.', 'giving-day-blocks' ); ?></strong><br>
					<?php
					echo esc_html__(
						'Open the WooPayments dashboard for more detail. If the account is partially set up, you can reset it and start over with a sandbox.',
						'giving-day-blocks'
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->wcpay_overview_url() ); ?>" class="button">
						<?php echo esc_html__( 'Open WooPayments dashboard', 'giving-day-blocks' ); ?>
					</a>
					&nbsp;
					<?php
					$this->render_reset_form(
						__( 'Reset and create sandbox', 'giving-day-blocks' ),
						'button',
						__( 'This will permanently delete the connected WooPayments account and create a fresh sandbox account. Continue?', 'giving-day-blocks' )
					);
					?>
				</p>
				<?php
				break;

			case 'none':
			default:
				?>
				<p>
					<?php
					echo esc_html__(
						'Click the button below to create a WooPayments sandbox account. This takes a few seconds and requires no personal or banking information.',
						'giving-day-blocks'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SETUP ); ?>">
					<?php wp_nonce_field( self::ACTION_SETUP ); ?>
					<button type="submit" class="button button-primary button-hero" <?php disabled( ! $status['can_provision'] ); ?>>
						<?php echo esc_html__( 'Set up sandbox payments', 'giving-day-blocks' ); ?>
					</button>
					<?php if ( ! $status['can_provision'] ) : ?>
						<p class="description">
							<?php echo esc_html__( 'Resolve the requirements above to enable this action.', 'giving-day-blocks' ); ?>
						</p>
					<?php endif; ?>
				</form>
				<?php
				break;
		}
	}

	/**
	 * Renders the destructive "reset and provision" form.
	 *
	 * @param string $label   Button label.
	 * @param string $variant 'button' or 'button-primary'.
	 * @param string $confirm Confirmation message used in the JS confirm() prompt.
	 */
	private function render_reset_form( string $label, string $variant = 'button-primary', string $confirm = '' ): void {
		if ( '' === $confirm ) {
			$confirm = __( 'This will permanently delete the connected WooPayments account and create a fresh sandbox account in its place. Continue?', 'giving-day-blocks' );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESET ); ?>">
			<?php wp_nonce_field( self::ACTION_RESET ); ?>
			<button type="submit" class="button <?php echo esc_attr( $variant ); ?>">
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handles the "Set up sandbox payments" form submission (no existing account).
	 */
	public function handle_provision(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'giving-day-blocks' ) );
		}
		check_admin_referer( self::ACTION_SETUP );

		$status = $this->collect_status();
		if ( ! $status['can_provision'] ) {
			$this->set_notice( 'error', __( 'Cannot set up sandbox payments — one or more requirements are not met.', 'giving-day-blocks' ) );
			$this->redirect_back();
		}

		$this->dispatch_test_drive_init();
		$this->redirect_back();
	}

	/**
	 * Handles the destructive "Reset account and create sandbox" form submission.
	 */
	public function handle_reset_and_provision(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'giving-day-blocks' ) );
		}
		check_admin_referer( self::ACTION_RESET );

		$status = $this->collect_status();
		if ( ! $status['platform_ready'] ) {
			$this->set_notice( 'error', __( 'Cannot reset — WooPayments and the WordPress.com connection must both be active.', 'giving-day-blocks' ) );
			$this->redirect_back();
		}

		// Only reset if there's something to reset; reset_onboarding throws otherwise.
		if ( ! empty( $status['account']['connected'] ) ) {
			$reset = new WP_REST_Request( 'POST', '/wc/v3/payments/onboarding/reset' );
			$reset->set_param( 'from', 'giving-day-blocks' );
			$reset->set_param( 'source', 'giving-day-settings' );
			$reset_response = rest_do_request( $reset );

			if ( $reset_response->is_error() ) {
				$err = $reset_response->as_error();
				$this->set_notice(
					'error',
					sprintf(
						/* translators: %s: error message returned by WooPayments. */
						__( 'Could not reset the existing WooPayments account: %s', 'giving-day-blocks' ),
						$err->get_error_message()
					)
				);
				$this->redirect_back();
			}
		}

		$this->dispatch_test_drive_init();
		$this->redirect_back();
	}

	/**
	 * Dispatches the WooPayments test-drive init endpoint and stores a notice.
	 */
	private function dispatch_test_drive_init(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/onboarding/test_drive_account/init' );
		$request->set_param( 'country', $this->store_country() );
		$request->set_param( 'capabilities', array( 'card_payments' => true ) );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			$this->set_notice(
				'error',
				sprintf(
					/* translators: %s: error message returned by WooPayments. */
					__( 'Sandbox setup failed: %s', 'giving-day-blocks' ),
					$error->get_error_message()
				)
			);
			return;
		}

		$data = $response->get_data();
		if ( ! empty( $data['success'] ) ) {
			$this->set_notice(
				'success',
				__( 'Sandbox payments are ready. Place a test donation with card 4242 4242 4242 4242 to confirm everything works end-to-end.', 'giving-day-blocks' )
			);
			return;
		}

		$this->set_notice(
			'error',
			__( 'Sandbox setup did not complete. Check the WooPayments logs (WooCommerce → Status → Logs) for details.', 'giving-day-blocks' )
		);
	}

	/**
	 * Returns the structured status used to render the page and gate actions.
	 *
	 * @return array{
	 *     rows: array<int, array{ok:bool,label:string,detail:string,action:?array{label:string,url:string,new_tab?:bool}}>,
	 *     can_provision: bool,
	 *     platform_ready: bool,
	 *     account: array<string,mixed>,
	 *     account_state: string
	 * }
	 */
	private function collect_status(): array {
		$rows           = array();
		$wc_ok          = $this->is_woocommerce_active();
		$wcpay_state    = $this->woopayments_state();
		$wcpay_active   = 'active' === $wcpay_state;
		$wpcom_ok       = $wcpay_active && $this->is_wpcom_connected();
		$country        = $this->store_country();
		$country_ok     = $wcpay_active && $this->is_country_supported( $country );
		$account        = $wcpay_active ? $this->account_state() : array( 'connected' => false );
		$account_state  = $this->classify_account( $account );
		$platform_ready = $wc_ok && $wcpay_active && $wpcom_ok;

		$rows[] = array(
			'ok'     => $wc_ok,
			'label'  => __( 'WooCommerce active', 'giving-day-blocks' ),
			'detail' => $wc_ok
				? __( 'Detected.', 'giving-day-blocks' )
				: __( 'WooCommerce 7.5+ is required.', 'giving-day-blocks' ),
			'action' => $wc_ok ? null : array(
				'label' => __( 'Install WooCommerce', 'giving-day-blocks' ),
				'url'   => admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ),
			),
		);

		$rows[] = array(
			'ok'     => $wcpay_active,
			'label'  => __( 'WooPayments active', 'giving-day-blocks' ),
			'detail' => $this->woopayments_detail( $wcpay_state ),
			'action' => $this->woopayments_action( $wcpay_state ),
		);

		$rows[] = array(
			'ok'     => $wpcom_ok,
			'label'  => __( 'WordPress.com connection', 'giving-day-blocks' ),
			'detail' => $wcpay_active
				? ( $wpcom_ok
					? __( 'Connected.', 'giving-day-blocks' )
					: __( 'Not connected. WooPayments requires a WordPress.com / Jetpack connection to provision an account.', 'giving-day-blocks' ) )
				: __( 'Cannot check until WooPayments is active.', 'giving-day-blocks' ),
			'action' => ( $wcpay_active && ! $wpcom_ok ) ? array(
				'label' => __( 'Connect Jetpack', 'giving-day-blocks' ),
				'url'   => admin_url( 'admin.php?page=jetpack' ),
			) : null,
		);

		$rows[] = array(
			'ok'     => $country_ok,
			'label'  => __( 'Store country supported', 'giving-day-blocks' ),
			'detail' => $wcpay_active
				? sprintf(
					/* translators: %s: ISO country code (e.g. "US"). */
					$country_ok
						? __( 'Store base country %s is supported.', 'giving-day-blocks' )
						: __( 'Store base country %s is not in the WooPayments supported list.', 'giving-day-blocks' ),
					$country !== '' ? $country : 'n/a'
				)
				: __( 'Cannot check until WooPayments is active.', 'giving-day-blocks' ),
			'action' => ( $wcpay_active && ! $country_ok ) ? array(
				'label'   => __( 'See supported countries', 'giving-day-blocks' ),
				'url'     => 'https://woocommerce.com/document/woopayments/compatibility/countries/',
				'new_tab' => true,
			) : null,
		);

		$account_row = $this->account_row( $account_state );
		if ( null !== $account_row ) {
			$rows[] = $account_row;
		}

		$can_provision = $platform_ready && $country_ok && 'none' === $account_state;

		return array(
			'rows'           => $rows,
			'can_provision'  => $can_provision,
			'platform_ready' => $platform_ready,
			'account'        => $account,
			'account_state'  => $account_state,
		);
	}

	/**
	 * Builds the optional "payments account" row appended to the requirements table.
	 *
	 * @return array{ok:bool,label:string,detail:string,action:?array{label:string,url:string,new_tab?:bool}}|null
	 */
	private function account_row( string $state ): ?array {
		switch ( $state ) {
			case 'sandbox_ready':
				return array(
					'ok'     => true,
					'label'  => __( 'Payments account', 'giving-day-blocks' ),
					'detail' => __( 'Sandbox (test-drive) account connected and active.', 'giving-day-blocks' ),
					'action' => null,
				);
			case 'sandbox_pending':
				return array(
					'ok'     => false,
					'label'  => __( 'Payments account', 'giving-day-blocks' ),
					'detail' => __( 'Sandbox account provisioned but not yet active.', 'giving-day-blocks' ),
					'action' => null,
				);
			case 'live_ready':
				return array(
					'ok'     => true,
					'label'  => __( 'Payments account', 'giving-day-blocks' ),
					'detail' => __( 'Live WooPayments account connected and accepting payments.', 'giving-day-blocks' ),
					'action' => null,
				);
			case 'live_stuck':
				return array(
					'ok'     => false,
					'label'  => __( 'Payments account', 'giving-day-blocks' ),
					'detail' => __( 'Live account connected but not yet active — KYC or bank details are missing.', 'giving-day-blocks' ),
					'action' => null,
				);
			case 'unknown':
				return array(
					'ok'     => false,
					'label'  => __( 'Payments account', 'giving-day-blocks' ),
					'detail' => __( 'Connected, but the account state could not be read.', 'giving-day-blocks' ),
					'action' => null,
				);
			case 'none':
			default:
				return null;
		}
	}

	/**
	 * Reads the connected account's real state via WC_Payments_Account::get_account_status_data().
	 *
	 * @return array{
	 *     connected: bool,
	 *     data_available?: bool,
	 *     is_test_drive?: bool,
	 *     is_live?: bool,
	 *     payments_enabled?: bool,
	 *     details_submitted?: bool,
	 *     past_due?: bool,
	 *     status?: string
	 * }
	 */
	private function account_state(): array {
		if ( ! class_exists( '\\WC_Payments' ) || ! method_exists( '\\WC_Payments', 'get_account_service' ) ) {
			return array( 'connected' => false );
		}
		$service = \WC_Payments::get_account_service();
		if ( ! $service || ! method_exists( $service, 'is_stripe_connected' ) || ! $service->is_stripe_connected() ) {
			return array( 'connected' => false );
		}

		if ( ! method_exists( $service, 'get_account_status_data' ) ) {
			return array(
				'connected'      => true,
				'data_available' => false,
			);
		}

		$data = $service->get_account_status_data();
		if ( ! is_array( $data ) || ! empty( $data['error'] ) ) {
			return array(
				'connected'      => true,
				'data_available' => false,
			);
		}

		return array(
			'connected'         => true,
			'data_available'    => true,
			'is_test_drive'     => (bool) ( $data['testDrive'] ?? false ),
			'is_live'           => (bool) ( $data['isLive'] ?? false ),
			'payments_enabled'  => (bool) ( $data['paymentsEnabled'] ?? false ),
			'details_submitted' => (bool) ( $data['detailsSubmitted'] ?? true ),
			'past_due'          => (bool) ( $data['pastDue'] ?? false ),
			'status'            => (string) ( $data['status'] ?? '' ),
		);
	}

	/**
	 * Maps an account_state() array to one of:
	 *   none | sandbox_ready | sandbox_pending | live_ready | live_stuck | unknown.
	 */
	private function classify_account( array $account ): string {
		if ( empty( $account['connected'] ) ) {
			return 'none';
		}
		if ( empty( $account['data_available'] ) ) {
			return 'unknown';
		}
		if ( ! empty( $account['is_test_drive'] ) ) {
			return ! empty( $account['payments_enabled'] ) ? 'sandbox_ready' : 'sandbox_pending';
		}
		$ready = ! empty( $account['payments_enabled'] )
			&& ! empty( $account['details_submitted'] )
			&& empty( $account['past_due'] );
		return $ready ? 'live_ready' : 'live_stuck';
	}

	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' );
	}

	/**
	 * Returns: 'active' | 'inactive' | 'missing'.
	 */
	private function woopayments_state(): string {
		if ( class_exists( '\\WC_Payments' ) ) {
			return 'active';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		return isset( $plugins[ self::WCPAY_PLUGIN ] ) ? 'inactive' : 'missing';
	}

	private function woopayments_detail( string $state ): string {
		switch ( $state ) {
			case 'active':
				$version = defined( 'WCPAY_VERSION_NUMBER' ) ? (string) WCPAY_VERSION_NUMBER : '';
				return '' !== $version
					? sprintf(
						/* translators: %s: WooPayments version (e.g. "10.7.1"). */
						__( 'Active (version %s).', 'giving-day-blocks' ),
						$version
					)
					: __( 'Active.', 'giving-day-blocks' );
			case 'inactive':
				return __( 'Installed but not activated.', 'giving-day-blocks' );
			default:
				return __( 'Not installed.', 'giving-day-blocks' );
		}
	}

	/**
	 * @return array{label:string,url:string,new_tab?:bool}|null
	 */
	private function woopayments_action( string $state ): ?array {
		if ( 'active' === $state ) {
			return null;
		}

		if ( 'inactive' === $state ) {
			return array(
				'label' => __( 'Activate WooPayments', 'giving-day-blocks' ),
				'url'   => wp_nonce_url(
					admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( self::WCPAY_PLUGIN ) ),
					'activate-plugin_' . self::WCPAY_PLUGIN
				),
			);
		}

		return array(
			'label' => __( 'Install WooPayments', 'giving-day-blocks' ),
			'url'   => admin_url( 'plugin-install.php?tab=plugin-information&plugin=woocommerce-payments' ),
		);
	}

	private function is_wpcom_connected(): bool {
		if ( class_exists( '\\Automattic\\Jetpack\\Connection\\Manager' ) ) {
			$manager = new \Automattic\Jetpack\Connection\Manager( 'woocommerce-payments' );
			if ( method_exists( $manager, 'is_connected' ) && $manager->is_connected() ) {
				return true;
			}
		}

		if ( class_exists( '\\Jetpack' ) && method_exists( '\\Jetpack', 'is_connection_ready' ) ) {
			return (bool) \Jetpack::is_connection_ready();
		}

		return false;
	}

	private function is_country_supported( string $country ): bool {
		if ( '' === $country ) {
			return false;
		}
		if ( ! class_exists( '\\WC_Payments_Utils' ) ) {
			return false;
		}
		return array_key_exists( $country, \WC_Payments_Utils::supported_countries() );
	}

	private function store_country(): string {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
			$base = (string) WC()->countries->get_base_country();
			if ( '' !== $base ) {
				return $base;
			}
		}
		return '';
	}

	private function wcpay_overview_url(): string {
		return admin_url( 'admin.php?page=wc-admin&path=%2Fpayments%2Foverview' );
	}

	private function set_notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_KEY . '_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * @return array{type:string,message:string}|null
	 */
	private function consume_notice(): ?array {
		$key    = self::NOTICE_KEY . '_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['type'] ) || empty( $notice['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => (string) $notice['type'],
			'message' => (string) $notice['message'],
		);
	}

	private function redirect_back(): void {
		wp_safe_redirect(
			admin_url( 'edit.php?post_type=' . Campaign::POST_TYPE . '&page=' . self::PAGE_SLUG )
		);
		exit;
	}
}
