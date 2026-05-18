<?php
/**
 * CSV exports for post-event campaign results.
 *
 * Streams four admin-only CSVs:
 *
 *   - `beneficiary-results.csv`   — one row per Beneficiary / Fund.
 *   - `donors-per-beneficiary.csv` — one row per (Beneficiary, donor).
 *   - `team-results.csv`           — one row per Team.
 *   - `donors-per-team.csv`        — one row per (Team, donor).
 *
 * All files are UTF-8 with a BOM (so Excel on Windows opens them in the
 * correct encoding without manual cell formatting). Donor rows never
 * include the donor's email address — the display name follows the
 * `{First} {L}.` shape, or "Anonymous" when the order carries
 * `_wpcomsp_donation_anonymous = 'yes'` (handled inside
 * {@see \Team51\GivingDay\Data\Aggregator::build_donor_list_entry()}).
 *
 * @package Team51\GivingDay\Admin
 * @since   0.4.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\Taxonomies\Cause;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the export action handlers.
 */
final class ResultsExport {

	public const REQUIRED_CAP = 'manage_woocommerce';
	public const NONCE_ACTION = 'giving_day_export_results';

	public const ACTION_BENEFICIARY_RESULTS    = 'giving_day_export_beneficiary_results';
	public const ACTION_DONORS_PER_BENEFICIARY = 'giving_day_export_donors_per_beneficiary';
	public const ACTION_TEAM_RESULTS           = 'giving_day_export_team_results';
	public const ACTION_DONORS_PER_TEAM        = 'giving_day_export_donors_per_team';

	/**
	 * Wires the admin-post handlers. Idempotent.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_BENEFICIARY_RESULTS, array( $this, 'export_beneficiary_results' ) );
		add_action( 'admin_post_' . self::ACTION_DONORS_PER_BENEFICIARY, array( $this, 'export_donors_per_beneficiary' ) );
		add_action( 'admin_post_' . self::ACTION_TEAM_RESULTS, array( $this, 'export_team_results' ) );
		add_action( 'admin_post_' . self::ACTION_DONORS_PER_TEAM, array( $this, 'export_donors_per_team' ) );
	}

	/**
	 * Returns an `admin-post.php` URL for one of the export actions, with a
	 * fresh nonce and the campaign ID. Optional third arg scopes the
	 * donor-list export to a single beneficiary or team — pass `null`
	 * (default) for the unfiltered export, or any non-negative int (including
	 * 0, the "Unassigned" bucket) to filter.
	 *
	 * @param string   $action      One of the ACTION_* constants.
	 * @param int      $campaign_id Campaign post ID.
	 * @param int|null $entity_id   Optional entity ID for per-entity donor exports; 0 is the "Unassigned" bucket.
	 */
	public static function build_url( string $action, int $campaign_id, ?int $entity_id = null ): string {
		$args = array(
			'action'      => $action,
			'campaign_id' => $campaign_id,
			'_wpnonce'    => wp_create_nonce( self::NONCE_ACTION ),
		);
		if ( null !== $entity_id && $entity_id >= 0 ) {
			$args['entity_id'] = $entity_id;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Handler: beneficiary-results.csv
	 */
	public function export_beneficiary_results(): void {
		$campaign_id = $this->guard_request();

		$results = Aggregator::results_for_campaign( $campaign_id );
		$rows    = $this->preferred_rows( $results, 'beneficiaries' );

		$this->stream_csv(
			$this->filename( $campaign_id, 'beneficiary-results' ),
			array(
				__( 'Beneficiary ID', 'giving-day-blocks' ),
				__( 'Slug', 'giving-day-blocks' ),
				__( 'Title', 'giving-day-blocks' ),
				__( 'Parent unit', 'giving-day-blocks' ),
				__( 'Causes', 'giving-day-blocks' ),
				__( 'Raised', 'giving-day-blocks' ),
				__( 'Donations', 'giving-day-blocks' ),
				__( 'Unique donors', 'giving-day-blocks' ),
				__( 'Average gift', 'giving-day-blocks' ),
				__( 'Snapshot locked at', 'giving-day-blocks' ),
			),
			array_map(
				function ( array $row ) use ( $results ) {
					$id = (int) ( $row['id'] ?? 0 );
					return array(
						$id,
						$id > 0 ? get_post_field( 'post_name', $id ) : '',
						(string) ( $row['title'] ?? '' ),
						$id > 0 ? Beneficiary::display_unit_label( $id ) : '',
						$id > 0 ? $this->cause_terms_for( $id ) : '',
						(float) ( $row['raised'] ?? 0 ),
						(int) ( $row['count'] ?? 0 ),
						(int) ( $row['unique_donors'] ?? 0 ),
						(float) ( $row['avg'] ?? 0 ),
						(string) ( $results['snapshot_locked_at'] ?? '' ),
					);
				},
				$rows
			)
		);
	}

	/**
	 * Handler: donors-per-beneficiary.csv
	 *
	 * Honors `entity_id` to scope the output to a single beneficiary; this
	 * is what the per-beneficiary report page's "Download donor list (this
	 * beneficiary only)" button uses.
	 */
	public function export_donors_per_beneficiary(): void {
		$campaign_id = $this->guard_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in guard_request().
		$has_filter = isset( $_GET['entity_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in guard_request().
		$entity_id     = $has_filter ? absint( wp_unslash( $_GET['entity_id'] ) ) : 0;
		$is_unassigned = $has_filter && 0 === $entity_id;

		$results = Aggregator::results_for_campaign( $campaign_id );
		$rows    = $this->preferred_rows( $results, 'beneficiaries' );
		if ( $has_filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => (int) ( $row['id'] ?? 0 ) === $entity_id
				)
			);
		}

		$out = array();
		foreach ( $rows as $bene ) {
			$bene_title = (string) ( $bene['title'] ?? '' );
			$bene_id    = (int) ( $bene['id'] ?? 0 );
			if ( '' === $bene_title && 0 === $bene_id ) {
				$bene_title = __( 'Unassigned', 'giving-day-blocks' );
			}
			$donors = isset( $bene['donors'] ) && is_array( $bene['donors'] ) ? $bene['donors'] : array();
			foreach ( $donors as $donor ) {
				$out[] = array(
					$bene_id,
					$bene_title,
					(string) ( $donor['display_name'] ?? __( 'Donor', 'giving-day-blocks' ) ),
					(float) ( $donor['amount'] ?? 0 ),
					(string) ( $donor['order_date'] ?? '' ),
				);
			}
		}

		if ( $is_unassigned ) {
			$basename = 'donors-beneficiary-unassigned';
		} elseif ( $has_filter ) {
			$basename = sprintf( 'donors-beneficiary-%d', $entity_id );
		} else {
			$basename = 'donors-per-beneficiary';
		}

		$this->stream_csv(
			$this->filename( $campaign_id, $basename ),
			array(
				__( 'Beneficiary ID', 'giving-day-blocks' ),
				__( 'Beneficiary title', 'giving-day-blocks' ),
				__( 'Donor', 'giving-day-blocks' ),
				__( 'Amount', 'giving-day-blocks' ),
				__( 'Order date', 'giving-day-blocks' ),
			),
			$out
		);
	}

	/**
	 * Handler: team-results.csv
	 */
	public function export_team_results(): void {
		$campaign_id = $this->guard_request();
		$results     = Aggregator::results_for_campaign( $campaign_id );
		$rows        = $this->preferred_rows( $results, 'teams' );

		$this->stream_csv(
			$this->filename( $campaign_id, 'team-results' ),
			array(
				__( 'Team ID', 'giving-day-blocks' ),
				__( 'Slug', 'giving-day-blocks' ),
				__( 'Title', 'giving-day-blocks' ),
				__( 'Raised', 'giving-day-blocks' ),
				__( 'Donations', 'giving-day-blocks' ),
				__( 'Unique donors', 'giving-day-blocks' ),
				__( 'Average gift', 'giving-day-blocks' ),
				__( 'Snapshot locked at', 'giving-day-blocks' ),
			),
			array_map(
				function ( array $row ) use ( $results ) {
					$id = (int) ( $row['id'] ?? 0 );
					return array(
						$id,
						$id > 0 ? get_post_field( 'post_name', $id ) : '',
						(string) ( $row['title'] ?? '' ),
						(float) ( $row['raised'] ?? 0 ),
						(int) ( $row['count'] ?? 0 ),
						(int) ( $row['unique_donors'] ?? 0 ),
						(float) ( $row['avg'] ?? 0 ),
						(string) ( $results['snapshot_locked_at'] ?? '' ),
					);
				},
				$rows
			)
		);
	}

	/**
	 * Handler: donors-per-team.csv
	 */
	public function export_donors_per_team(): void {
		$campaign_id = $this->guard_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in guard_request().
		$has_filter = isset( $_GET['entity_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in guard_request().
		$entity_id     = $has_filter ? absint( wp_unslash( $_GET['entity_id'] ) ) : 0;
		$is_unassigned = $has_filter && 0 === $entity_id;

		$results = Aggregator::results_for_campaign( $campaign_id );
		$rows    = $this->preferred_rows( $results, 'teams' );
		if ( $has_filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => (int) ( $row['id'] ?? 0 ) === $entity_id
				)
			);
		}

		$out = array();
		foreach ( $rows as $team ) {
			$team_title = (string) ( $team['title'] ?? '' );
			$team_id    = (int) ( $team['id'] ?? 0 );
			if ( '' === $team_title && 0 === $team_id ) {
				$team_title = __( 'Unassigned', 'giving-day-blocks' );
			}
			$donors = isset( $team['donors'] ) && is_array( $team['donors'] ) ? $team['donors'] : array();
			foreach ( $donors as $donor ) {
				$out[] = array(
					$team_id,
					$team_title,
					(string) ( $donor['display_name'] ?? __( 'Donor', 'giving-day-blocks' ) ),
					(float) ( $donor['amount'] ?? 0 ),
					(string) ( $donor['order_date'] ?? '' ),
				);
			}
		}

		if ( $is_unassigned ) {
			$basename = 'donors-team-unassigned';
		} elseif ( $has_filter ) {
			$basename = sprintf( 'donors-team-%d', $entity_id );
		} else {
			$basename = 'donors-per-team';
		}

		$this->stream_csv(
			$this->filename( $campaign_id, $basename ),
			array(
				__( 'Team ID', 'giving-day-blocks' ),
				__( 'Team title', 'giving-day-blocks' ),
				__( 'Donor', 'giving-day-blocks' ),
				__( 'Amount', 'giving-day-blocks' ),
				__( 'Order date', 'giving-day-blocks' ),
			),
			$out
		);
	}

	/**
	 * Validates cap + nonce + campaign param. Dies on any failure (the
	 * caller is an admin-post.php handler — no fallback path to surface a
	 * notice from). Returns the validated campaign ID.
	 */
	private function guard_request(): int {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export results.', 'giving-day-blocks' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		if ( $campaign_id <= 0 || Campaign::POST_TYPE !== get_post_type( $campaign_id ) ) {
			wp_die( esc_html__( 'Campaign not found.', 'giving-day-blocks' ), '', array( 'response' => 404 ) );
		}
		return $campaign_id;
	}

	/**
	 * Prefers the snapshot+adjustments-merged rows when available so the
	 * CSV reflects whatever a viewer sees on the admin breakdown screen.
	 *
	 * @param array<string,mixed> $results Output of Aggregator::results_for_campaign().
	 * @param string              $section `beneficiaries` or `teams`.
	 * @return list<array<string,mixed>>
	 */
	private function preferred_rows( array $results, string $section ): array {
		if ( isset( $results['current'][ $section ] ) && is_array( $results['current'][ $section ] ) ) {
			return $results['current'][ $section ];
		}
		return isset( $results[ $section ] ) && is_array( $results[ $section ] ) ? $results[ $section ] : array();
	}

	/**
	 * Returns the comma-joined list of Cause Area term names assigned to a
	 * beneficiary (parent path included as " > " for hierarchical terms),
	 * suitable for a CSV cell.
	 *
	 * @param int $beneficiary_id Beneficiary post ID.
	 */
	private function cause_terms_for( int $beneficiary_id ): string {
		$terms = get_the_terms( $beneficiary_id, Cause::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		$labels = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$labels[] = $term->name;
		}
		return implode( ', ', $labels );
	}

	/**
	 * Builds a stable, readable filename for the export.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $basename    Short slug describing the file (e.g. `beneficiary-results`).
	 */
	private function filename( int $campaign_id, string $basename ): string {
		$slug = get_post_field( 'post_name', $campaign_id );
		if ( '' === $slug ) {
			$slug = 'campaign-' . $campaign_id;
		}
		return sprintf( '%s_%s_%s.csv', sanitize_file_name( $slug ), $basename, gmdate( 'Ymd' ) );
	}

	/**
	 * Streams a CSV with UTF-8 BOM, a header row, and the provided body rows.
	 * Calls exit() — no return.
	 *
	 * @param string                   $filename Suggested filename.
	 * @param array<int, string>       $headers  Header row.
	 * @param array<int, array<mixed>> $rows    Body rows.
	 */
	private function stream_csv( string $filename, array $headers, array $rows ): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// UTF-8 BOM so Excel on Windows opens the file in UTF-8 by default.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}
}
