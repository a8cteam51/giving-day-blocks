<?php
/**
 * Giving Days → Import admin page.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.5.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\Services\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for bulk-importing Beneficiaries, Teams, and Donations from CSV.
 */
final class ImportPage {

	public const PAGE_SLUG            = 'giving-day-import';
	public const REQUIRED_CAP         = 'manage_woocommerce';
	public const ACTION_IMPORT        = 'giving_day_import_run';
	public const ACTION_TEMPLATE      = 'giving_day_import_template';
	public const ACTION_ERRORS_CSV    = 'giving_day_import_errors_csv';
	public const ACTION_BATCH         = 'giving_day_import_batch';
	public const NONCE_IMPORT         = 'giving_day_import_nonce';
	public const NONCE_BATCH          = 'giving_day_import_batch_nonce';
	public const ERRORS_TRANSIENT_TTL = 30 * MINUTE_IN_SECONDS;
	public const JOB_TRANSIENT_TTL    = HOUR_IN_SECONDS;

	/**
	 * Wires admin menu + admin-post handlers. Idempotent.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::ACTION_TEMPLATE, array( $this, 'handle_template_download' ) );
		add_action( 'admin_post_' . self::ACTION_ERRORS_CSV, array( $this, 'handle_errors_download' ) );
		add_action( 'wp_ajax_' . self::ACTION_BATCH, array( $this, 'handle_batch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the admin URL for the Import page, optionally jumping to a tab.
	 *
	 * @param string $type Optional Importer::TYPE_* slug to preselect.
	 */
	public static function url_for_type( string $type = '' ): string {
		$args = array(
			'post_type' => Campaign::POST_TYPE,
			'page'      => self::PAGE_SLUG,
		);
		if ( '' !== $type && in_array( $type, Importer::types(), true ) ) {
			$args['type'] = $type;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Adds the Giving Days → Import submenu.
	 */
	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Campaign::POST_TYPE,
			__( 'Import', 'giving-day-blocks' ),
			__( 'Import', 'giving-day-blocks' ),
			self::REQUIRED_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the Import admin page (tabbed by type).
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to import data.', 'giving-day-blocks' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$active_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : Importer::TYPE_BENEFICIARIES;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active_type, Importer::types(), true ) ) {
			$active_type = Importer::TYPE_BENEFICIARIES;
		}

		echo '<div class="wrap giving-day-import">';
		echo '<h1>' . esc_html__( 'Import data', 'giving-day-blocks' ) . '</h1>';
		echo '<p>' . esc_html__( 'Bulk-create Cause Areas, Beneficiaries / Funds, Teams, or historical Donations from a CSV. Slugs are used to reference Campaigns, Beneficiaries, Teams, and Causes — so the same file works across staging and production. Run imports in this order so cross-references resolve: Causes → Beneficiaries → Teams → Donations.', 'giving-day-blocks' ) . '</p>';

		$this->render_admin_notices();
		$this->render_tabs( $active_type );

		if ( $this->is_ready_state() ) {
			$this->render_progress_state( $active_type );
		} else {
			$this->render_active_form( $active_type );
		}

		echo '</div>';
	}

	/**
	 * True when the URL is post-upload, ready to run the chunked job
	 * (donations only). Read-only flag, nonce-checked when batches run.
	 */
	private function is_ready_state(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flag from our own redirect.
		if ( ! isset( $_GET['gd_import'] ) ) {
			return false;
		}
		return 'ready' === sanitize_key( wp_unslash( $_GET['gd_import'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Renders the tab bar across the three import types.
	 *
	 * @param string $active Currently-active type slug.
	 */
	private function render_tabs( string $active ): void {
		$tabs = array(
			Importer::TYPE_CAUSES        => __( 'Cause Areas', 'giving-day-blocks' ),
			Importer::TYPE_BENEFICIARIES => __( 'Beneficiaries / Funds', 'giving-day-blocks' ),
			Importer::TYPE_TEAMS         => __( 'Teams', 'giving-day-blocks' ),
			Importer::TYPE_DONATIONS     => __( 'Donations', 'giving-day-blocks' ),
		);

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg(
				array(
					'post_type' => Campaign::POST_TYPE,
					'page'      => self::PAGE_SLUG,
					'type'      => $slug,
				),
				admin_url( 'edit.php' )
			);
			$class = 'nav-tab' . ( $active === $slug ? ' nav-tab-active' : '' );
			printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
		}
		echo '</h2>';
	}

	/**
	 * Renders the upload form + template-download button for one import type.
	 *
	 * @param string $type One of Importer::TYPE_*.
	 */
	private function render_active_form( string $type ): void {
		$cap     = Importer::row_cap( $type );
		$columns = Importer::template_columns( $type );

		$template_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_TEMPLATE,
					'type'   => $type,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_IMPORT
		);

		echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0;max-width:780px">';

		echo '<h2 style="margin-top:0">' . esc_html( $this->type_heading( $type ) ) . '</h2>';
		echo '<p>' . wp_kses_post( $this->type_intro( $type, $cap ) ) . '</p>';

		echo '<p><a class="button" href="' . esc_url( $template_url ) . '">' . esc_html__( 'Download CSV template', 'giving-day-blocks' ) . '</a></p>';

		echo '<p>' . esc_html__( 'Expected columns:', 'giving-day-blocks' ) . ' <code>' . esc_html( implode( ', ', $columns ) ) . '</code></p>';

		printf(
			'<form method="post" enctype="multipart/form-data" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( self::NONCE_IMPORT );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_IMPORT ) );
		printf( '<input type="hidden" name="type" value="%s" />', esc_attr( $type ) );
		echo '<p><input type="file" name="import_file" accept=".csv,text/csv" required /></p>';
		printf( '<p><button type="submit" class="button button-primary">%s</button></p>', esc_html__( 'Upload and import', 'giving-day-blocks' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Returns the H2 label for the active import type.
	 *
	 * @param string $type One of Importer::TYPE_*.
	 */
	private function type_heading( string $type ): string {
		switch ( $type ) {
			case Importer::TYPE_CAUSES:
				return __( 'Import Cause Areas', 'giving-day-blocks' );
			case Importer::TYPE_TEAMS:
				return __( 'Import Teams', 'giving-day-blocks' );
			case Importer::TYPE_DONATIONS:
				return __( 'Import Donations', 'giving-day-blocks' );
		}
		return __( 'Import Beneficiaries / Funds', 'giving-day-blocks' );
	}

	/**
	 * Returns the intro paragraph copy for the active import type.
	 *
	 * @param string $type One of Importer::TYPE_*.
	 * @param int    $cap  Row cap for this import type.
	 */
	private function type_intro( string $type, int $cap ): string {
		switch ( $type ) {
			case Importer::TYPE_CAUSES:
				return sprintf(
					/* translators: %d: row cap. */
					esc_html__( 'Bulk-create Cause Area taxonomy terms. Up to %d rows per upload. Existing slugs are skipped. Use parent_slug to nest a term under another Cause; the parent must already exist (either pre-imported or earlier in the same file). Cause images can be added later from the term edit screen.', 'giving-day-blocks' ),
					(int) $cap
				);
			case Importer::TYPE_TEAMS:
				return sprintf(
					/* translators: %d: row cap. */
					esc_html__( 'Bulk-create Team posts. Up to %d rows per upload. Existing slugs are skipped — never overwritten. Use team_groups (comma-separated term slugs) to tag a Team into one or more groups for the Leaderboard Tabs block.', 'giving-day-blocks' ),
					(int) $cap
				);
			case Importer::TYPE_DONATIONS:
				return sprintf(
					/* translators: %d: row cap. */
					esc_html__( 'Bulk-record offline / historical donations as WooCommerce orders. Up to %d rows per upload. The external_id column dedupes re-uploads — rows already imported are skipped. If the target campaign has already been snapshotted, imported donations land in the snapshot adjustments log; re-snapshot from the Results page to fold them into the frozen totals.', 'giving-day-blocks' ),
					(int) $cap
				);
		}
		return sprintf(
			/* translators: %d: row cap. */
			esc_html__( 'Bulk-create Beneficiary / Fund posts. Up to %d rows per upload. Existing slugs are skipped — never overwritten. Use parent_slug to nest a Beneficiary under another Beneficiary for hierarchy roll-ups.', 'giving-day-blocks' ),
			(int) $cap
		);
	}

	/**
	 * Surfaces flash notices set by the import handler redirect, and renders
	 * the post-import summary + error report download link when present.
	 */
	private function render_admin_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash flags.
		if ( ! isset( $_GET['gd_import'] ) ) {
			return;
		}
		$flash = sanitize_key( wp_unslash( $_GET['gd_import'] ) );

		if ( 'error' === $flash ) {
			$msg = isset( $_GET['gd_message'] ) ? sanitize_text_field( wp_unslash( $_GET['gd_message'] ) ) : __( 'Import failed.', 'giving-day-blocks' );
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
			return;
		}

		if ( 'done' !== $flash ) {
			return;
		}

		$created    = isset( $_GET['gd_created'] ) ? (int) $_GET['gd_created'] : 0;
		$skipped    = isset( $_GET['gd_skipped'] ) ? (int) $_GET['gd_skipped'] : 0;
		$errors     = isset( $_GET['gd_errors'] ) ? (int) $_GET['gd_errors'] : 0;
		$errors_key = isset( $_GET['gd_errors_key'] ) ? sanitize_key( wp_unslash( $_GET['gd_errors_key'] ) ) : '';
		$type       = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: created count, 2: skipped count, 3: error count. */
					__( 'Import complete: %1$d created, %2$d skipped, %3$d errors.', 'giving-day-blocks' ),
					$created,
					$skipped,
					$errors
				)
			)
		);

		if ( $errors > 0 && '' !== $errors_key ) {
			$download_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::ACTION_ERRORS_CSV,
						'key'    => $errors_key,
						'type'   => $type,
					),
					admin_url( 'admin-post.php' )
				),
				self::NONCE_IMPORT
			);
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $download_url ),
				esc_html__( 'Download error report (CSV)', 'giving-day-blocks' )
			);
		}
	}

	/**
	 * Handles the upload form POST: parses + imports + redirects with flash args.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to import data.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_IMPORT );

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! in_array( $type, Importer::types(), true ) ) {
			$this->redirect_to_page(
				$type,
				array(
					'gd_import'  => 'error',
					'gd_message' => __( 'Unknown import type.', 'giving-day-blocks' ),
				)
			);
		}

		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			$this->redirect_to_page(
				$type,
				array(
					'gd_import'  => 'error',
					'gd_message' => __( 'No file uploaded.', 'giving-day-blocks' ),
				)
			);
		}

		$file_error = isset( $_FILES['import_file']['error'] ) ? (int) $_FILES['import_file']['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_OK !== $file_error ) {
			$this->redirect_to_page(
				$type,
				array(
					'gd_import'  => 'error',
					'gd_message' => sprintf(
						/* translators: %d: PHP UPLOAD_ERR_* code. */
						__( 'Upload failed (PHP error code %d).', 'giving-day-blocks' ),
						$file_error
					),
				)
			);
		}

		$tmp_path = sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) );
		if ( ! is_uploaded_file( $tmp_path ) ) {
			$this->redirect_to_page(
				$type,
				array(
					'gd_import'  => 'error',
					'gd_message' => __( 'Upload could not be validated.', 'giving-day-blocks' ),
				)
			);
		}

		$rows = Importer::parse_csv( $tmp_path, $type );
		if ( is_wp_error( $rows ) ) {
			$this->redirect_to_page(
				$type,
				array(
					'gd_import'  => 'error',
					'gd_message' => $rows->get_error_message(),
				)
			);
		}

		if ( Importer::TYPE_DONATIONS === $type ) {
			$key = $this->stash_job( $type, (array) $rows );
			$this->redirect_to_page(
				$type,
				array(
					'gd_import' => 'ready',
					'gd_key'    => $key,
					'gd_total'  => count( $rows ),
				)
			);
		}

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = Importer::import( $type, $rows );

		$errors_key = '';
		if ( ! empty( $result['errors'] ) ) {
			$errors_key = $this->stash_errors( (array) $result['errors'] );
		}

		$this->redirect_to_page(
			$type,
			array(
				'gd_import'     => 'done',
				'gd_created'    => (int) $result['created'],
				'gd_skipped'    => (int) $result['skipped'],
				'gd_errors'     => count( $result['errors'] ),
				'gd_errors_key' => $errors_key,
			)
		);
	}

	/**
	 * Streams the per-type CSV template as a file download.
	 */
	public function handle_template_download(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to download templates.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_IMPORT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( ! in_array( $type, Importer::types(), true ) ) {
			wp_die( esc_html__( 'Unknown import type.', 'giving-day-blocks' ), '', array( 'response' => 400 ) );
		}

		$csv      = Importer::template_csv( $type );
		$filename = sprintf( 'giving-day-import-template-%s.csv', $type );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV stream.
		exit;
	}

	/**
	 * Streams the per-row errors CSV stashed by the most recent import.
	 */
	public function handle_errors_download(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to download error reports.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_IMPORT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';

		if ( '' === $key ) {
			wp_die( esc_html__( 'Missing error report key.', 'giving-day-blocks' ), '', array( 'response' => 400 ) );
		}

		$errors = get_transient( $this->errors_transient_key( $key ) );
		if ( ! is_array( $errors ) ) {
			wp_die( esc_html__( 'Error report has expired or could not be found. Re-run the import to regenerate it.', 'giving-day-blocks' ), '', array( 'response' => 404 ) );
		}

		$csv      = Importer::errors_csv( $errors );
		$filename = sprintf( 'giving-day-import-errors-%s.csv', '' !== $type ? $type : 'rows' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV stream.
		exit;
	}

	/**
	 * AJAX handler that processes one batch of an in-flight donation
	 * import. Reads the stashed job, runs `Importer::import()` over the
	 * next slice, persists updated counts + errors back to the job
	 * transient, and returns JSON for the JS progress loop.
	 *
	 * When the job's offset reaches its total, the accumulated error log
	 * is copied to the standard errors transient (so the existing
	 * download endpoint works unchanged) and the job transient is
	 * deleted.
	 */
	public function handle_batch(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'giving-day-blocks' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_BATCH, 'nonce' );

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing job key.', 'giving-day-blocks' ) ), 400 );
		}

		$job_key = $this->job_transient_key( $key );
		$job     = get_transient( $job_key );
		if ( ! is_array( $job ) || empty( $job['rows'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Import job expired or not found. Re-upload the file.', 'giving-day-blocks' ) ),
				404
			);
		}

		$type   = (string) ( $job['type'] ?? '' );
		$offset = (int) ( $job['offset'] ?? 0 );
		$total  = (int) ( $job['total'] ?? count( $job['rows'] ) );
		$slice  = array_slice( $job['rows'], $offset, Importer::IMPORT_BATCH_SIZE );

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$result = Importer::import( $type, $slice, $offset );

		$job['offset']  = $offset + count( $slice );
		$job['created'] = (int) ( $job['created'] ?? 0 ) + (int) $result['created'];
		$job['skipped'] = (int) ( $job['skipped'] ?? 0 ) + (int) $result['skipped'];
		$prior_errors   = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();
		$job['errors']  = array_merge( $prior_errors, (array) $result['errors'] );

		$done = $job['offset'] >= $total;

		if ( $done ) {
			$errors_key = '';
			if ( ! empty( $job['errors'] ) ) {
				$errors_key = $this->stash_errors( $job['errors'] );
			}
			delete_transient( $job_key );

			$redirect_url = add_query_arg(
				array(
					'gd_import'     => 'done',
					'gd_created'    => (int) $job['created'],
					'gd_skipped'    => (int) $job['skipped'],
					'gd_errors'     => count( $job['errors'] ),
					'gd_errors_key' => $errors_key,
				),
				self::url_for_type( $type )
			);

			wp_send_json_success(
				array(
					'done'         => true,
					'offset'       => $total,
					'total'        => $total,
					'created'      => (int) $job['created'],
					'skipped'      => (int) $job['skipped'],
					'errors'       => count( $job['errors'] ),
					'redirect_url' => $redirect_url,
				)
			);
		}

		set_transient( $job_key, $job, self::JOB_TRANSIENT_TTL );

		wp_send_json_success(
			array(
				'done'    => false,
				'offset'  => (int) $job['offset'],
				'total'   => $total,
				'created' => (int) $job['created'],
				'skipped' => (int) $job['skipped'],
				'errors'  => count( $job['errors'] ),
			)
		);
	}

	/**
	 * Enqueues the chunked-import progress script on the Import page,
	 * scoped to the ready-state view (so the JS doesn't load on the
	 * upload form).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'giving_campaign_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( ! $this->is_ready_state() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flag from our own redirect.
		$key   = isset( $_GET['gd_key'] ) ? sanitize_key( wp_unslash( $_GET['gd_key'] ) ) : '';
		$total = isset( $_GET['gd_total'] ) ? (int) $_GET['gd_total'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $key || $total <= 0 ) {
			return;
		}

		$handle = 'giving-day-import-progress';
		wp_register_script(
			$handle,
			GIVING_DAY_BLOCKS_URL . 'assets/admin-import/import.js',
			array(),
			GIVING_DAY_BLOCKS_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'givingDayImport',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::ACTION_BATCH,
				'nonce'     => wp_create_nonce( self::NONCE_BATCH ),
				'key'       => $key,
				'total'     => $total,
				'batchSize' => Importer::IMPORT_BATCH_SIZE,
				'i18n'      => array(
					'starting'  => __( 'Starting…', 'giving-day-blocks' ),
					/* translators: 1: rows processed, 2: total rows, 3: created count, 4: skipped count, 5: error count. */
					'progress'  => __( '%1$s of %2$s processed (%3$s created, %4$s skipped, %5$s errors)', 'giving-day-blocks' ),
					/* translators: %s: error message from the failed batch. */
					'failed'    => __( 'Batch failed: %s', 'giving-day-blocks' ),
					'completed' => __( 'Finalizing…', 'giving-day-blocks' ),
				),
			)
		);
		wp_enqueue_script( $handle );
	}

	/**
	 * Renders the ready-state UI: row count + Start button + progress
	 * bar that the enqueued JS drives. Only reached for donation imports
	 * (the only chunked type today).
	 *
	 * @param string $type Active type slug, used to keep the tab highlighted.
	 */
	private function render_progress_state( string $type ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flag from our own redirect.
		$total = isset( $_GET['gd_total'] ) ? (int) $_GET['gd_total'] : 0;
		$key   = isset( $_GET['gd_key'] ) ? sanitize_key( wp_unslash( $_GET['gd_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0;max-width:780px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Ready to import', 'giving-day-blocks' ) . '</h2>';

		if ( '' === $key || $total <= 0 ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'The upload session was lost. Re-upload the file to try again.', 'giving-day-blocks' )
			);
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( self::url_for_type( $type ) ),
				esc_html__( 'Back to upload', 'giving-day-blocks' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: row count, 2: batch size. */
					__( 'Parsed %1$d rows. The import will run in batches of %2$d via AJAX so it does not hit the request timeout. You can close this tab and re-upload the same file later — already-imported rows (matched by external_id) will be skipped.', 'giving-day-blocks' ),
					$total,
					Importer::IMPORT_BATCH_SIZE
				)
			)
		);

		echo '<p><button type="button" class="button button-primary" id="giving-day-import-start">' . esc_html__( 'Start import', 'giving-day-blocks' ) . '</button>';
		echo ' <a class="button" href="' . esc_url( self::url_for_type( $type ) ) . '">' . esc_html__( 'Cancel', 'giving-day-blocks' ) . '</a></p>';

		echo '<div id="giving-day-import-progress" style="display:none;margin-top:1em">';
		echo '<div style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;height:18px;overflow:hidden">';
		echo '<div id="giving-day-import-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width 150ms linear"></div>';
		echo '</div>';
		echo '<p id="giving-day-import-progress-label" style="margin-top:8px;font-family:monospace">' . esc_html__( 'Starting…', 'giving-day-blocks' ) . '</p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Stashes a parsed CSV as a pending chunked import job. Returns the
	 * random key used to resume it.
	 *
	 * @param string                            $type Importer type slug.
	 * @param array<int, array<string, string>> $rows Parsed CSV rows.
	 */
	private function stash_job( string $type, array $rows ): string {
		$key     = wp_generate_password( 16, false, false );
		$payload = array(
			'type'    => $type,
			'rows'    => $rows,
			'total'   => count( $rows ),
			'offset'  => 0,
			'created' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		set_transient( $this->job_transient_key( $key ), $payload, self::JOB_TRANSIENT_TTL );
		return $key;
	}

	/**
	 * Builds the transient key for an in-flight import job.
	 *
	 * @param string $key Per-job random suffix.
	 */
	private function job_transient_key( string $key ): string {
		return 'giving_day_import_job_' . $key;
	}

	/**
	 * Saves the per-row error list in a short-lived transient keyed by an
	 * unguessable string, so the download URL is self-contained but temporary.
	 *
	 * @param array<int, array<string,mixed>> $errors Error rows.
	 */
	private function stash_errors( array $errors ): string {
		$key = wp_generate_password( 16, false, false );
		set_transient( $this->errors_transient_key( $key ), $errors, self::ERRORS_TRANSIENT_TTL );
		return $key;
	}

	/**
	 * Builds the transient key holding a stashed per-row error report.
	 *
	 * @param string $key Per-report random suffix.
	 */
	private function errors_transient_key( string $key ): string {
		return 'giving_day_import_errors_' . $key;
	}

	/**
	 * Redirects back to the Import page with flash query args.
	 *
	 * @param string                $type One of Importer::TYPE_*, used to land the user on the right tab.
	 * @param array<string, scalar> $args Flash query args.
	 */
	private function redirect_to_page( string $type, array $args ): void {
		$base = add_query_arg(
			array(
				'post_type' => Campaign::POST_TYPE,
				'page'      => self::PAGE_SLUG,
				'type'      => in_array( $type, Importer::types(), true ) ? $type : Importer::TYPE_BENEFICIARIES,
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}
}
