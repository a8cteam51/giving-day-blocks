<?php
/**
 * Post-Event Results admin page.
 *
 * Lives under Giving Days → Results. Surfaces the per-campaign breakdown
 * by Beneficiary and Team — plus per-entity drilldown views with donor
 * lists (display name only, no email) — for the audiences identified in
 * the Phase 11 scoping:
 *
 *   - Internal finance / organizers, for reconciliation and check-cutting.
 *   - Partner nonprofits / fund managers, who get a forwardable CSV or a
 *     copy-paste HTML block for their thank-you emails.
 *
 * Reads via {@see \Team51\GivingDay\Data\Aggregator::results_for_campaign()}
 * so the page renders whether or not a snapshot has been taken. When a
 * snapshot exists the "Snapshot locked at …" header and the adjustments
 * log appear; otherwise the page shows current live numbers and a "Lock
 * results now" button.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.4.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\Services\ResultsSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin page + the manual-snapshot action handlers.
 */
final class ResultsPage {

	public const PAGE_SLUG         = 'giving-day-results';
	public const REQUIRED_CAP      = 'manage_woocommerce';
	public const ACTION_SNAPSHOT   = 'giving_day_results_snapshot';
	public const ACTION_RESNAPSHOT = 'giving_day_results_resnapshot';
	public const ACTION_SAVE_GRACE = 'giving_day_results_save_grace';
	public const NONCE_SNAPSHOT    = 'giving_day_results_snapshot_nonce';
	public const NONCE_GRACE       = 'giving_day_results_grace_nonce';

	/**
	 * Wires the admin menu + action handlers. Idempotent.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION_SNAPSHOT, array( $this, 'handle_snapshot' ) );
		add_action( 'admin_post_' . self::ACTION_RESNAPSHOT, array( $this, 'handle_resnapshot' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_GRACE, array( $this, 'handle_save_grace' ) );
	}

	/**
	 * Adds the Giving Days → Results submenu.
	 */
	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Campaign::POST_TYPE,
			__( 'Results', 'giving-day-blocks' ),
			__( 'Results', 'giving-day-blocks' ),
			self::REQUIRED_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the page; dispatches to one of the views based on `?view`.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view results.', 'giving-day-blocks' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$campaign_id   = isset( $_GET['campaign'] ) ? absint( wp_unslash( $_GET['campaign'] ) ) : 0;
		$view          = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$has_entity_id = isset( $_GET['id'] );
		$entity_id     = $has_entity_id ? absint( wp_unslash( $_GET['id'] ) ) : -1;
		$tab           = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'beneficiaries';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap giving-day-results">';

		if ( $campaign_id <= 0 ) {
			$this->render_campaign_picker();
			echo '</div>';
			return;
		}

		$campaign = get_post( $campaign_id );
		if ( ! $campaign || Campaign::POST_TYPE !== $campaign->post_type ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Campaign not found.', 'giving-day-blocks' ) );
			echo '</div>';
			return;
		}

		$this->render_admin_notices();

		$results = Aggregator::results_for_campaign( $campaign_id );

		if ( 'beneficiary' === $view && $has_entity_id && $entity_id >= 0 ) {
			$this->render_entity_view( $campaign_id, $results, 'beneficiaries', $entity_id, __( 'Beneficiary / Fund', 'giving-day-blocks' ) );
		} elseif ( 'team' === $view && $has_entity_id && $entity_id >= 0 ) {
			$this->render_entity_view( $campaign_id, $results, 'teams', $entity_id, __( 'Team', 'giving-day-blocks' ) );
		} else {
			$this->render_overview( $campaign_id, $results, $tab );
		}

		echo '</div>';
	}

	/**
	 * Surfaces success / error flash messages from the snapshot actions.
	 */
	private function render_admin_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash flags.
		if ( isset( $_GET['gd_snapshot'] ) && 'locked' === $_GET['gd_snapshot'] ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Results locked.', 'giving-day-blocks' ) );
		}
		if ( isset( $_GET['gd_snapshot'] ) && 'resnapshotted' === $_GET['gd_snapshot'] ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Snapshot rebuilt from live data. Pre-existing adjustments are unchanged.', 'giving-day-blocks' ) );
		}
		if ( isset( $_GET['gd_snapshot_error'] ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['gd_snapshot_error'] ) );
			if ( '' !== $msg ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Initial landing: list every published Campaign with a "View results" link.
	 */
	private function render_campaign_picker(): void {
		echo '<h1>' . esc_html__( 'Campaign Results', 'giving-day-blocks' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pick a campaign to see its per-Beneficiary and per-Team breakdown, export CSVs, and (when the event has ended) lock in the final results.', 'giving-day-blocks' ) . '</p>';

		$this->render_grace_panel();

		$campaigns = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $campaigns ) ) {
			echo '<p>' . esc_html__( 'No published campaigns yet.', 'giving-day-blocks' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Campaign', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Ends', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Snapshot', 'giving-day-blocks' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $campaigns as $campaign ) {
			$cid       = (int) $campaign->ID;
			$end       = (string) get_post_meta( $cid, Campaign::META_END_DATETIME, true );
			$snapshot  = Aggregator::read_snapshot( $cid );
			$locked_at = is_array( $snapshot ) ? (string) ( $snapshot['snapshot_locked_at'] ?? '' ) : '';
			$url       = add_query_arg(
				array(
					'post_type' => Campaign::POST_TYPE,
					'page'      => self::PAGE_SLUG,
					'campaign'  => $cid,
				),
				admin_url( 'edit.php' )
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( get_the_title( $campaign ) ) . '</strong></td>';
			echo '<td>' . esc_html( $this->format_local_datetime( $end ) ) . '</td>';
			echo '<td>' . esc_html(
				'' !== $locked_at
					? sprintf(
						/* translators: %s: human-readable locked timestamp. */
						__( 'Locked %s', 'giving-day-blocks' ),
						$this->format_local_datetime( $locked_at )
					)
					: __( 'Live (not locked)', 'giving-day-blocks' )
			) . '</td>';
			echo '<td><a href="' . esc_url( $url ) . '" class="button">' . esc_html__( 'View results', 'giving-day-blocks' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Small sitewide-setting panel: the grace window between an event's
	 * `end_datetime` and the moment its snapshot auto-locks. 24h default.
	 * The `giving_day_snapshot_grace_hours` filter still overrides per
	 * campaign for code-driven exceptions.
	 */
	private function render_grace_panel(): void {
		$hours = (int) get_option( ResultsSnapshot::OPTION_GRACE_HOURS, ResultsSnapshot::DEFAULT_GRACE_HOURS );
		if ( $hours < 0 ) {
			$hours = ResultsSnapshot::DEFAULT_GRACE_HOURS;
		}

		echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0;max-width:560px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Auto-lock grace window', 'giving-day-blocks' ) . '</h2>';
		echo '<p>' . esc_html__( 'Number of hours to wait after an event ends before its results lock in automatically. You can always lock earlier with the "Lock results now" button on a campaign\'s results page.', 'giving-day-blocks' ) . '</p>';
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE_GRACE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_SAVE_GRACE ) );
		echo '<p>';
		printf(
			'<label for="giving-day-grace-hours">%s</label> ',
			esc_html__( 'Grace window (hours):', 'giving-day-blocks' )
		);
		printf(
			'<input type="number" min="0" max="720" id="giving-day-grace-hours" name="grace_hours" value="%d" class="small-text" /> ',
			(int) $hours
		);
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Save', 'giving-day-blocks' ) );
		echo '</p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the per-campaign overview: header strip + tabbed list table
	 * (Beneficiaries / Teams) + adjustments log.
	 *
	 * @param int                  $campaign_id Campaign post ID.
	 * @param array<string, mixed> $results     Aggregator::results_for_campaign() output.
	 * @param string               $tab         Active tab slug.
	 */
	private function render_overview( int $campaign_id, array $results, string $tab ): void {
		$active_tab = in_array( $tab, array( 'beneficiaries', 'teams' ), true ) ? $tab : 'beneficiaries';

		echo '<h1>';
		printf(
			/* translators: %s: campaign title. */
			esc_html__( 'Results: %s', 'giving-day-blocks' ),
			esc_html( get_the_title( $campaign_id ) )
		);
		echo ' <a href="' . esc_url( $this->page_url() ) . '" class="page-title-action">' . esc_html__( 'All campaigns', 'giving-day-blocks' ) . '</a>';
		echo '</h1>';

		$this->render_snapshot_strip( $campaign_id, $results );

		$this->render_tabs( $campaign_id, $active_tab );

		if ( 'teams' === $active_tab ) {
			$this->render_entity_overview_table( $campaign_id, $results, 'teams' );
		} else {
			$this->render_entity_overview_table( $campaign_id, $results, 'beneficiaries' );
		}

		$this->render_adjustments_log( $results );
	}

	/**
	 * Header card showing campaign-wide totals + snapshot lifecycle controls.
	 *
	 * @param int                  $campaign_id Campaign post ID.
	 * @param array<string, mixed> $results     Aggregator::results_for_campaign() output.
	 */
	private function render_snapshot_strip( int $campaign_id, array $results ): void {
		$locked_at = (string) ( $results['snapshot_locked_at'] ?? '' );
		$current   = is_array( $results['current'] ?? null ) ? $results['current']['campaign'] : $results['campaign'];
		$raised    = (float) ( $current['raised'] ?? 0 );
		$count     = (int) ( $current['count'] ?? 0 );
		$donors    = (int) ( $current['unique_donors'] ?? 0 );
		$currency  = (string) ( $current['currency'] ?? $results['currency'] ?? '' );
		$due       = ResultsSnapshot::due_timestamp( $campaign_id );

		echo '<div class="giving-day-results__strip" style="background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0;display:flex;gap:2em;align-items:center;flex-wrap:wrap">';

		echo '<div>';
		echo '<div style="font-size:0.85em;color:#646970">' . esc_html__( 'Total raised', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . wp_kses_post( wc_price( $raised, array( 'currency' => $currency ) ) ) . '</div>';
		echo '</div>';

		echo '<div>';
		echo '<div style="font-size:0.85em;color:#646970">' . esc_html__( 'Donations', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . esc_html( number_format_i18n( $count ) ) . '</div>';
		echo '</div>';

		echo '<div>';
		echo '<div style="font-size:0.85em;color:#646970">' . esc_html__( 'Unique donors', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . esc_html( number_format_i18n( $donors ) ) . '</div>';
		echo '</div>';

		echo '<div style="margin-left:auto">';
		if ( '' !== $locked_at ) {
			echo '<p style="margin:0 0 0.5em"><strong>' . esc_html__( 'Snapshot locked', 'giving-day-blocks' ) . '</strong><br />';
			echo esc_html( $this->format_local_datetime( $locked_at ) );
			echo '</p>';
			$this->render_snapshot_button( $campaign_id, self::ACTION_RESNAPSHOT, __( 'Re-snapshot from live data', 'giving-day-blocks' ), 'button' );
		} else {
			if ( null !== $due ) {
				$relative = $due > time()
					? sprintf(
						/* translators: %s: relative time string. */
						__( 'Auto-locks in %s.', 'giving-day-blocks' ),
						human_time_diff( time(), $due )
					)
					: __( 'Past the grace window — will lock on the next admin pageview.', 'giving-day-blocks' );
				echo '<p style="margin:0 0 0.5em;color:#646970">' . esc_html( $relative ) . '</p>';
			} else {
				echo '<p style="margin:0 0 0.5em;color:#646970">' . esc_html__( 'Set an event end date to enable auto-snapshot.', 'giving-day-blocks' ) . '</p>';
			}
			$this->render_snapshot_button( $campaign_id, self::ACTION_SNAPSHOT, __( 'Lock results now', 'giving-day-blocks' ), 'button button-primary' );
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renders one of the two snapshot action forms.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $action      `admin_post_*` action slug.
	 * @param string $label       Button label.
	 * @param string $css_class   Button CSS class.
	 */
	private function render_snapshot_button( int $campaign_id, string $action, string $label, string $css_class ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( self::NONCE_SNAPSHOT );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="campaign_id" value="%d" />', (int) $campaign_id );
		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $css_class ), esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * Tab nav for Beneficiaries / Teams.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $active_tab  Active tab slug.
	 */
	private function render_tabs( int $campaign_id, string $active_tab ): void {
		$tabs = array(
			'beneficiaries' => __( 'Beneficiaries / Funds', 'giving-day-blocks' ),
			'teams'         => __( 'Teams', 'giving-day-blocks' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg(
				array(
					'post_type' => Campaign::POST_TYPE,
					'page'      => self::PAGE_SLUG,
					'campaign'  => $campaign_id,
					'tab'       => $slug,
				),
				admin_url( 'edit.php' )
			);
			$class = 'nav-tab' . ( $active_tab === $slug ? ' nav-tab-active' : '' );
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	/**
	 * Per-tab breakdown table.
	 *
	 * @param int                  $campaign_id Campaign post ID.
	 * @param array<string, mixed> $results     Aggregator::results_for_campaign() output.
	 * @param string               $section     `beneficiaries` or `teams`.
	 */
	private function render_entity_overview_table( int $campaign_id, array $results, string $section ): void {
		$rows     = $this->preferred_rows( $results, $section );
		$currency = (string) ( $results['currency'] ?? '' );

		$is_beneficiary = 'beneficiaries' === $section;
		$results_csv    = ResultsExport::build_url(
			$is_beneficiary ? ResultsExport::ACTION_BENEFICIARY_RESULTS : ResultsExport::ACTION_TEAM_RESULTS,
			$campaign_id
		);
		$donors_csv     = ResultsExport::build_url(
			$is_beneficiary ? ResultsExport::ACTION_DONORS_PER_BENEFICIARY : ResultsExport::ACTION_DONORS_PER_TEAM,
			$campaign_id
		);

		echo '<p>';
		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( $results_csv ),
			esc_html(
				$is_beneficiary
					? __( 'Download beneficiary totals (CSV)', 'giving-day-blocks' )
					: __( 'Download team totals (CSV)', 'giving-day-blocks' )
			)
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( $donors_csv ),
			esc_html(
				$is_beneficiary
					? __( 'Download donors per beneficiary (CSV)', 'giving-day-blocks' )
					: __( 'Download donors per team (CSV)', 'giving-day-blocks' )
			)
		);
		echo '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No attributed donations yet.', 'giving-day-blocks' ) . '</p>';
			return;
		}

		$total_raised = 0.0;
		foreach ( $rows as $row ) {
			$total_raised += (float) ( $row['raised'] ?? 0 );
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html( $is_beneficiary ? __( 'Beneficiary / Fund', 'giving-day-blocks' ) : __( 'Team', 'giving-day-blocks' ) ) . '</th>';
		if ( $is_beneficiary ) {
			echo '<th>' . esc_html__( 'Parent unit', 'giving-day-blocks' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Raised', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Donations', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Unique donors', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Share', 'giving-day-blocks' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id            = (int) ( $row['id'] ?? 0 );
			$title         = (string) ( $row['title'] ?? '' );
			$raised        = (float) ( $row['raised'] ?? 0 );
			$share         = $total_raised > 0 ? ( $raised / $total_raised ) * 100 : 0;
			$is_unassigned = 0 === $id;

			$detail_url = add_query_arg(
				array(
					'post_type' => Campaign::POST_TYPE,
					'page'      => self::PAGE_SLUG,
					'campaign'  => $campaign_id,
					'view'      => $is_beneficiary ? 'beneficiary' : 'team',
					'id'        => $id,
				),
				admin_url( 'edit.php' )
			);

			$title_display = '' !== $title ? $title : sprintf( '#%d', $id );
			echo '<tr>';
			if ( $is_unassigned ) {
				echo '<td><strong><em>' . esc_html( $title_display ) . '</em></strong><br />'
					. '<span style="color:#646970;font-size:0.9em">'
					. esc_html(
						$is_beneficiary
							? __( 'Donations with no Beneficiary / Fund selected.', 'giving-day-blocks' )
							: __( 'Donations with no Team selected.', 'giving-day-blocks' )
					)
					. '</span></td>';
			} else {
				echo '<td><strong>' . esc_html( $title_display ) . '</strong></td>';
			}
			if ( $is_beneficiary ) {
				echo '<td>' . esc_html( $id > 0 ? Beneficiary::display_unit_label( $id ) : '' ) . '</td>';
			}
			echo '<td>' . wp_kses_post( wc_price( $raised, array( 'currency' => $currency ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $row['count'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $row['unique_donors'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( round( $share, 1 ), 1 ) . '%' ) . '</td>';
			echo '<td><a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View donors', 'giving-day-blocks' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Per-entity (beneficiary / team) detail view: header stats + donor
	 * list + per-entity CSV download + copy-paste HTML block for partner
	 * thank-you emails.
	 *
	 * @param int                  $campaign_id Campaign post ID.
	 * @param array<string, mixed> $results     Aggregator::results_for_campaign() output.
	 * @param string               $section     `beneficiaries` or `teams`.
	 * @param int                  $entity_id   Entity post ID.
	 * @param string               $type_label  Human label for the entity type.
	 */
	private function render_entity_view( int $campaign_id, array $results, string $section, int $entity_id, string $type_label ): void {
		$rows = $this->preferred_rows( $results, $section );
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) === $entity_id ) {
				$row = $candidate;
				break;
			}
		}

		$back_url = add_query_arg(
			array(
				'post_type' => Campaign::POST_TYPE,
				'page'      => self::PAGE_SLUG,
				'campaign'  => $campaign_id,
				'tab'       => $section,
			),
			admin_url( 'edit.php' )
		);

		$is_unassigned = 0 === $entity_id;
		if ( $row ) {
			$title = (string) ( $row['title'] ?? '' );
		} elseif ( $is_unassigned ) {
			$title = __( 'Unassigned', 'giving-day-blocks' );
		} else {
			$title = (string) get_the_title( $entity_id );
		}
		$currency = (string) ( $results['currency'] ?? '' );

		echo '<h1>';
		printf(
			/* translators: 1: entity type label, 2: entity title. */
			esc_html__( '%1$s: %2$s', 'giving-day-blocks' ),
			esc_html( $type_label ),
			esc_html( '' !== $title ? $title : sprintf( '#%d', $entity_id ) )
		);
		echo ' <a href="' . esc_url( $back_url ) . '" class="page-title-action">' . esc_html__( 'Back to overview', 'giving-day-blocks' ) . '</a>';
		echo '</h1>';

		if ( $is_unassigned ) {
			echo '<p style="color:#646970">' . esc_html(
				'beneficiaries' === $section
					? __( 'These donations counted toward the campaign total but were never tied to a specific Beneficiary / Fund. Re-attribute them from the order edit screen if a beneficiary should have been chosen.', 'giving-day-blocks' )
					: __( 'These donations counted toward the campaign total but were never tied to a specific Team.', 'giving-day-blocks' )
			) . '</p>';
		}

		if ( null === $row ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No donations recorded against this entity for this campaign.', 'giving-day-blocks' ) . '</p></div>';
			return;
		}

		$is_beneficiary = 'beneficiaries' === $section;
		$raised         = (float) ( $row['raised'] ?? 0 );
		$count          = (int) ( $row['count'] ?? 0 );
		$donors         = (int) ( $row['unique_donors'] ?? 0 );

		$campaign_block = is_array( $results['current'] ?? null ) ? $results['current']['campaign'] : $results['campaign'];
		$campaign_total = (float) ( $campaign_block['raised'] ?? 0 );
		$share          = $campaign_total > 0 ? ( $raised / $campaign_total ) * 100 : 0;

		if ( $is_beneficiary && ! $is_unassigned ) {
			$parent_unit = Beneficiary::display_unit_label( $entity_id );
			if ( '' !== $parent_unit ) {
				echo '<p style="margin:0 0 0.5em;color:#646970">' . esc_html(
					sprintf(
						/* translators: %s: parent unit name (e.g. a college or coalition). */
						__( 'Part of: %s', 'giving-day-blocks' ),
						$parent_unit
					)
				) . '</p>';
			}
		}

		echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0;display:flex;gap:2em;flex-wrap:wrap">';
		echo '<div><div style="font-size:0.85em;color:#646970">' . esc_html__( 'Raised', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . wp_kses_post( wc_price( $raised, array( 'currency' => $currency ) ) ) . '</div></div>';
		echo '<div><div style="font-size:0.85em;color:#646970">' . esc_html__( 'Donations', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . esc_html( number_format_i18n( $count ) ) . '</div></div>';
		echo '<div><div style="font-size:0.85em;color:#646970">' . esc_html__( 'Unique donors', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . esc_html( number_format_i18n( $donors ) ) . '</div></div>';
		echo '<div><div style="font-size:0.85em;color:#646970">' . esc_html__( 'Average gift', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . wp_kses_post( wc_price( (float) ( $row['avg'] ?? 0 ), array( 'currency' => $currency ) ) ) . '</div></div>';
		echo '<div><div style="font-size:0.85em;color:#646970">' . esc_html__( 'Share of campaign total', 'giving-day-blocks' ) . '</div>';
		echo '<div style="font-size:1.5em;font-weight:600">' . esc_html( number_format_i18n( round( $share, 1 ), 1 ) . '%' ) . '</div></div>';
		echo '</div>';

		$donor_csv = ResultsExport::build_url(
			$is_beneficiary ? ResultsExport::ACTION_DONORS_PER_BENEFICIARY : ResultsExport::ACTION_DONORS_PER_TEAM,
			$campaign_id,
			$entity_id
		);

		echo '<p><a class="button button-primary" href="' . esc_url( $donor_csv ) . '">' . esc_html__( 'Download donor list (CSV)', 'giving-day-blocks' ) . '</a></p>';

		$donors_rows = isset( $row['donors'] ) && is_array( $row['donors'] ) ? $row['donors'] : array();
		if ( empty( $donors_rows ) ) {
			echo '<p>' . esc_html__( 'No donor detail captured (the snapshot may have been taken before any donations were recorded).', 'giving-day-blocks' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Donor', 'giving-day-blocks' ) . '</th>';
			echo '<th>' . esc_html__( 'Amount', 'giving-day-blocks' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'giving-day-blocks' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $donors_rows as $donor ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $donor['display_name'] ?? __( 'Donor', 'giving-day-blocks' ) ) ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( (float) ( $donor['amount'] ?? 0 ), array( 'currency' => $currency ) ) ) . '</td>';
				echo '<td>' . esc_html( $this->format_local_datetime( (string) ( $donor['order_date'] ?? '' ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$this->render_thankyou_paste_block( $type_label, $title, $raised, $donors, $donors_rows, $currency );
		}
	}

	/**
	 * Renders a textarea pre-filled with HTML the organizer can paste into a
	 * thank-you email to the partner. Deliberately HTML so a copy-paste into
	 * Gmail / a CRM preserves formatting. The textarea is read-only with a
	 * one-click "Select all" hint.
	 *
	 * @param string                   $type_label   Human label for the entity type.
	 * @param string                   $entity_title Entity title (e.g. beneficiary or team name).
	 * @param float                    $raised       Amount raised by the entity.
	 * @param int                      $donor_count  Unique donor count.
	 * @param array<int, array<mixed>> $donors       Donor list rows (display_name, amount, etc.).
	 * @param string                   $currency     ISO currency code for `wc_price()`.
	 */
	private function render_thankyou_paste_block( string $type_label, string $entity_title, float $raised, int $donor_count, array $donors, string $currency ): void {
		$lines   = array();
		$lines[] = sprintf(
			'<p><strong>%s — %s</strong></p>',
			esc_html( $entity_title ),
			esc_html(
				sprintf(
					/* translators: 1: currency-formatted raised amount, 2: donor count. */
					_n( '%1$s raised from %2$d donor', '%1$s raised from %2$d donors', $donor_count, 'giving-day-blocks' ),
					wp_strip_all_tags( wc_price( $raised, array( 'currency' => $currency ) ) ),
					$donor_count
				)
			)
		);
		$lines[] = '<ul>';
		foreach ( $donors as $donor ) {
			$lines[] = sprintf(
				'<li>%s — %s</li>',
				esc_html( (string) ( $donor['display_name'] ?? '' ) ),
				esc_html( wp_strip_all_tags( wc_price( (float) ( $donor['amount'] ?? 0 ), array( 'currency' => $currency ) ) ) )
			);
		}
		$lines[] = '</ul>';

		$html = implode( "\n", $lines );

		echo '<h2 style="margin-top:2em">' . esc_html__( 'Copy-paste for partner thank-you email', 'giving-day-blocks' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: entity type label (Beneficiary / Fund or Team). */
					__( 'Copy this block into your %s thank-you email — it formats donor names and amounts without any email addresses.', 'giving-day-blocks' ),
					strtolower( $type_label )
				)
			)
		);
		echo '<textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">';
		echo esc_textarea( $html );
		echo '</textarea>';
	}

	/**
	 * Adjustments log table. No-op when empty.
	 *
	 * @param array<string, mixed> $results Aggregator::results_for_campaign() output.
	 */
	private function render_adjustments_log( array $results ): void {
		$log = isset( $results['adjustments'] ) && is_array( $results['adjustments'] ) ? $results['adjustments'] : array();
		if ( empty( $log ) ) {
			return;
		}
		$currency = (string) ( $results['currency'] ?? '' );

		echo '<h2 style="margin-top:2em">' . esc_html__( 'Adjustments since snapshot', 'giving-day-blocks' ) . '</h2>';
		echo '<p>' . esc_html__( 'Refunds, late offline donations, and manual corrections recorded after the snapshot was locked. These are added on top of the frozen total at read time — the snapshot itself is never rewritten.', 'giving-day-blocks' ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Beneficiary', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Team', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Delta amount', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Δ donations', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'giving-day-blocks' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'giving-day-blocks' ) . '</th>';
		echo '</tr></thead><tbody>';

		$labels = array(
			'refund'            => __( 'Refund', 'giving-day-blocks' ),
			'restore'           => __( 'Restore', 'giving-day-blocks' ),
			'offline_added'     => __( 'Offline donation', 'giving-day-blocks' ),
			'manual_correction' => __( 'Manual correction', 'giving-day-blocks' ),
		);

		foreach ( $log as $entry ) {
			$type       = (string) ( $entry['type'] ?? '' );
			$type_label = $labels[ $type ] ?? $type;
			$bene_id    = (int) ( $entry['beneficiary_id'] ?? 0 );
			$team_id    = (int) ( $entry['team_id'] ?? 0 );

			echo '<tr>';
			echo '<td>' . esc_html( $this->format_local_datetime( (string) ( $entry['at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $type_label ) . '</td>';
			echo '<td>' . esc_html( $bene_id > 0 ? get_the_title( $bene_id ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $team_id > 0 ? get_the_title( $team_id ) : '—' ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) ( $entry['delta_amount'] ?? 0 ), array( 'currency' => $currency ) ) ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%+d', (int) ( $entry['delta_count'] ?? 0 ) ) ) . '</td>';
			$order_id = (int) ( $entry['order_id'] ?? 0 );
			if ( $order_id > 0 ) {
				$edit = get_edit_post_link( $order_id );
				echo '<td>' . ( $edit
					? '<a href="' . esc_url( $edit ) . '">#' . esc_html( (string) $order_id ) . '</a>'
					: '#' . esc_html( (string) $order_id ) ) . '</td>';
			} else {
				echo '<td>—</td>';
			}
			echo '<td>' . esc_html( (string) ( $entry['reason'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Handles the "Lock results now" button.
	 */
	public function handle_snapshot(): void {
		$campaign_id = $this->guard_snapshot_request();
		$result      = Aggregator::snapshot_campaign( $campaign_id );
		$query       = null === $result
			? array( 'gd_snapshot_error' => __( 'Snapshot failed — campaign not found.', 'giving-day-blocks' ) )
			: array( 'gd_snapshot' => 'locked' );

		$this->redirect_after_snapshot( $campaign_id, $query );
	}

	/**
	 * Handles the "Re-snapshot from live data" button. Recomputes the
	 * snapshot from scratch, overwriting the prior one. Adjustments log is
	 * left untouched so historical context survives.
	 */
	public function handle_resnapshot(): void {
		$campaign_id = $this->guard_snapshot_request();
		$result      = Aggregator::snapshot_campaign( $campaign_id, true );
		$query       = null === $result
			? array( 'gd_snapshot_error' => __( 'Re-snapshot failed — campaign not found.', 'giving-day-blocks' ) )
			: array( 'gd_snapshot' => 'resnapshotted' );

		$this->redirect_after_snapshot( $campaign_id, $query );
	}

	/**
	 * Handles the "Save grace window" form. Persists the sitewide option
	 * read by {@see ResultsSnapshot::grace_seconds()}.
	 */
	public function handle_save_grace(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage results settings.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_GRACE );

		$hours = isset( $_POST['grace_hours'] ) ? (int) wp_unslash( $_POST['grace_hours'] ) : ResultsSnapshot::DEFAULT_GRACE_HOURS; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int.
		if ( $hours < 0 ) {
			$hours = 0;
		}
		if ( $hours > 720 ) {
			$hours = 720;
		}
		update_option( ResultsSnapshot::OPTION_GRACE_HOURS, $hours, false );

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Shared validation for both snapshot action handlers. Dies on failure.
	 */
	private function guard_snapshot_request(): int {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage results.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_SNAPSHOT );

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;
		if ( $campaign_id <= 0 || Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			wp_die( esc_html__( 'Campaign not found.', 'giving-day-blocks' ), '', array( 'response' => 404 ) );
		}
		return $campaign_id;
	}

	/**
	 * Redirects back to the breakdown for the given campaign with the
	 * supplied flash params merged in.
	 *
	 * @param int                  $campaign_id Campaign post ID.
	 * @param array<string,string> $extra       Flash params merged into the redirect URL.
	 */
	private function redirect_after_snapshot( int $campaign_id, array $extra ): void {
		$base = add_query_arg(
			array(
				'post_type' => Campaign::POST_TYPE,
				'page'      => self::PAGE_SLUG,
				'campaign'  => $campaign_id,
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( add_query_arg( $extra, $base ) );
		exit;
	}

	/**
	 * Returns the un-HTML-escaped admin URL for the Results page (no
	 * campaign selected). Mirrors `OfflineDonations::page_url()`.
	 */
	private function page_url(): string {
		return add_query_arg(
			array(
				'post_type' => Campaign::POST_TYPE,
				'page'      => self::PAGE_SLUG,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Prefers the snapshot+adjustments-merged rows when available so the
	 * page displays exactly what its CSV exports will produce.
	 *
	 * @param array<string,mixed> $results Aggregator::results_for_campaign() output.
	 * @param string              $section `beneficiaries` or `teams`.
	 * @return array<int, array<string,mixed>>
	 */
	private function preferred_rows( array $results, string $section ): array {
		if ( isset( $results['current'][ $section ] ) && is_array( $results['current'][ $section ] ) ) {
			return $results['current'][ $section ];
		}
		return isset( $results[ $section ] ) && is_array( $results[ $section ] ) ? $results[ $section ] : array();
	}

	/**
	 * Renders an ISO 8601 datetime in the site's timezone using the user's
	 * date+time format. Returns "—" for empty / unparseable input.
	 *
	 * @param string $iso ISO 8601 datetime string, or "" for the dash fallback.
	 */
	private function format_local_datetime( string $iso ): string {
		if ( '' === $iso ) {
			return '—';
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return '—';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}
}
