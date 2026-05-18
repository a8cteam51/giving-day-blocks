<?php
/**
 * CSV importer for Beneficiaries, Teams, and Donations.
 *
 * @package Team51\GivingDay\Services
 * @since   0.5.0
 */

namespace Team51\GivingDay\Services;

use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Services\OfflineDonations as OfflineService;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamGroup;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * CSV import service backing the Giving Days → Import admin page.
 */
final class Importer {

	public const TYPE_CAUSES        = 'causes';
	public const TYPE_BENEFICIARIES = 'beneficiaries';
	public const TYPE_TEAMS         = 'teams';
	public const TYPE_DONATIONS     = 'donations';

	public const ROW_CAP_POSTS     = 5000;
	public const ROW_CAP_DONATIONS = 5000;

	/**
	 * Default batch size for chunked donation imports. The donation
	 * pipeline runs each row through `OfflineDonations::create_order()`
	 * (full WC order + hooks + adjustment listener) at ~100–200ms per
	 * row, so a single PHP request can comfortably handle ~200 before
	 * approaching a typical 30–60s timeout.
	 */
	public const IMPORT_BATCH_SIZE = 200;

	public const META_EXTERNAL_ID = '_giving_import_external_id';
	public const META_ANONYMOUS   = '_wpcomsp_donation_anonymous';

	/**
	 * Returns the row cap for a given import type.
	 *
	 * @param string $type One of the TYPE_* constants.
	 */
	public static function row_cap( string $type ): int {
		return self::TYPE_DONATIONS === $type ? self::ROW_CAP_DONATIONS : self::ROW_CAP_POSTS;
	}

	/**
	 * Returns the list of TYPE_* slugs this service supports.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array( self::TYPE_CAUSES, self::TYPE_BENEFICIARIES, self::TYPE_TEAMS, self::TYPE_DONATIONS );
	}

	/**
	 * Parses a UTF-8 (optionally BOM-prefixed) CSV file into an array of
	 * associative rows keyed by the header row. Returns a WP_Error when the
	 * file is unreadable, the header is empty, or the row cap is exceeded.
	 *
	 * @param string $path Server-side path to the uploaded file.
	 * @param string $type One of the TYPE_* constants.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public static function parse_csv( string $path, string $type ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'giving_day_import_unreadable', __( 'Could not read the uploaded file.', 'giving-day-blocks' ) );
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'giving_day_import_open_failed', __( 'Could not open the uploaded file.', 'giving-day-blocks' ) );
		}

		$header = fgetcsv( $handle );
		if ( false === $header || array() === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'giving_day_import_empty', __( 'The uploaded file is empty.', 'giving-day-blocks' ) );
		}

		$header = self::strip_bom_from_first( $header );
		$header = array_map( static fn( $h ): string => is_string( $h ) ? trim( $h ) : '', $header );

		$rows = array();
		$cap  = self::row_cap( $type );

		while ( true ) {
			$cells = fgetcsv( $handle );
			if ( false === $cells ) {
				break;
			}
			if ( array( null ) === $cells || ( 1 === count( $cells ) && ( null === $cells[0] || '' === $cells[0] ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $header as $idx => $col ) {
				if ( '' === $col ) {
					continue;
				}
				$value       = $cells[ $idx ] ?? '';
				$row[ $col ] = is_string( $value ) ? trim( $value ) : '';
			}
			$rows[] = $row;

			if ( count( $rows ) > $cap ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error(
					'giving_day_import_too_many_rows',
					sprintf(
						/* translators: 1: row cap, 2: import type. */
						__( 'This file has more than %1$d rows (the %2$s import cap). Split it into smaller files.', 'giving-day-blocks' ),
						$cap,
						$type
					)
				);
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Runs one of the three import types and returns a normalized result.
	 *
	 * Shape:
	 *   [
	 *     'created' => int,
	 *     'skipped' => int,
	 *     'errors'  => list<array{ row: int, message: string, data: array }>,
	 *   ]
	 *
	 * @param string                            $type       One of TYPE_*.
	 * @param array<int, array<string, string>> $rows       Parsed CSV rows (may be a slice for chunked imports).
	 * @param int                               $row_offset Logical row number offset; row 0 of `$rows` corresponds
	 *                                                       to header-relative row $row_offset+2 in error messages.
	 * @return array<string, mixed>
	 */
	public static function import( string $type, array $rows, int $row_offset = 0 ): array {
		switch ( $type ) {
			case self::TYPE_CAUSES:
				return self::import_causes( $rows, $row_offset );
			case self::TYPE_BENEFICIARIES:
				return self::import_beneficiaries( $rows, $row_offset );
			case self::TYPE_TEAMS:
				return self::import_teams( $rows, $row_offset );
			case self::TYPE_DONATIONS:
				return self::import_donations( $rows, $row_offset );
			default:
				return self::empty_result();
		}
	}

	/**
	 * Creates Cause Area taxonomy terms from parsed CSV rows.
	 *
	 * Parents must appear in the file before any child that references
	 * them (or already exist) — within-file forward references aren't
	 * resolved.
	 *
	 * @param array<int, array<string, string>> $rows       Parsed CSV rows.
	 * @param int                               $row_offset Row-number offset for error reporting in chunked imports.
	 * @return array<string, mixed>
	 */
	private static function import_causes( array $rows, int $row_offset = 0 ): array {
		$result = self::empty_result();

		foreach ( $rows as $index => $row ) {
			$row_num = $row_offset + $index + 2;

			$name = isset( $row['name'] ) ? trim( $row['name'] ) : '';
			if ( '' === $name ) {
				$result['errors'][] = self::error_row( $row_num, __( 'Name is required.', 'giving-day-blocks' ), $row );
				continue;
			}

			$slug = isset( $row['slug'] ) && '' !== $row['slug']
				? sanitize_title( $row['slug'] )
				: sanitize_title( $name );

			if ( get_term_by( 'slug', $slug, Cause::TAXONOMY ) ) {
				++$result['skipped'];
				continue;
			}

			$parent_id   = 0;
			$parent_slug = isset( $row['parent_slug'] ) ? trim( $row['parent_slug'] ) : '';
			if ( '' !== $parent_slug ) {
				$parent = get_term_by( 'slug', $parent_slug, Cause::TAXONOMY );
				if ( ! $parent instanceof \WP_Term ) {
					$result['errors'][] = self::error_row(
						$row_num,
						sprintf(
							/* translators: %s: parent slug. */
							__( 'Parent Cause "%s" not found.', 'giving-day-blocks' ),
							$parent_slug
						),
						$row
					);
					continue;
				}
				$parent_id = (int) $parent->term_id;
			}

			$term = wp_insert_term(
				$name,
				Cause::TAXONOMY,
				array(
					'slug'        => $slug,
					'description' => isset( $row['description'] ) ? sanitize_textarea_field( $row['description'] ) : '',
					'parent'      => $parent_id,
				)
			);
			if ( is_wp_error( $term ) ) {
				$result['errors'][] = self::error_row( $row_num, $term->get_error_message(), $row );
				continue;
			}

			++$result['created'];
		}

		return $result;
	}

	/**
	 * Creates Beneficiary posts from parsed CSV rows.
	 *
	 * @param array<int, array<string, string>> $rows       Parsed CSV rows.
	 * @param int                               $row_offset Row-number offset for error reporting in chunked imports.
	 * @return array<string, mixed>
	 */
	private static function import_beneficiaries( array $rows, int $row_offset = 0 ): array {
		$result = self::empty_result();

		foreach ( $rows as $index => $row ) {
			$row_num = $row_offset + $index + 2;

			$title = isset( $row['title'] ) ? trim( $row['title'] ) : '';
			if ( '' === $title ) {
				$result['errors'][] = self::error_row( $row_num, __( 'Title is required.', 'giving-day-blocks' ), $row );
				continue;
			}

			$slug = isset( $row['slug'] ) && '' !== $row['slug'] ? sanitize_title( $row['slug'] ) : sanitize_title( $title );
			if ( self::post_with_slug_exists( $slug, Beneficiary::POST_TYPE ) ) {
				++$result['skipped'];
				continue;
			}

			$parent_id   = 0;
			$parent_slug = isset( $row['parent_slug'] ) ? trim( $row['parent_slug'] ) : '';
			if ( '' !== $parent_slug ) {
				$parent_post = self::find_post_by_slug( $parent_slug, Beneficiary::POST_TYPE );
				if ( ! $parent_post ) {
					$result['errors'][] = self::error_row(
						$row_num,
						sprintf(
							/* translators: %s: parent slug. */
							__( 'Parent Beneficiary "%s" not found.', 'giving-day-blocks' ),
							$parent_slug
						),
						$row
					);
					continue;
				}
				$parent_id = (int) $parent_post->ID;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => Beneficiary::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_parent'  => $parent_id,
					'post_excerpt' => isset( $row['excerpt'] ) ? sanitize_textarea_field( $row['excerpt'] ) : '',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$result['errors'][] = self::error_row( $row_num, $post_id->get_error_message(), $row );
				continue;
			}

			if ( isset( $row['goal_amount'] ) && '' !== $row['goal_amount'] && is_numeric( $row['goal_amount'] ) ) {
				update_post_meta( $post_id, Beneficiary::META_GOAL_AMOUNT, (float) $row['goal_amount'] );
			}
			if ( isset( $row['parent_org'] ) && '' !== $row['parent_org'] ) {
				update_post_meta( $post_id, Beneficiary::META_PARENT_ORG, sanitize_text_field( $row['parent_org'] ) );
			}

			$campaign_ids = self::resolve_campaign_slugs( $row['campaigns'] ?? '' );
			if ( ! empty( $campaign_ids ) ) {
				update_post_meta( $post_id, Beneficiary::META_CAMPAIGN_IDS, $campaign_ids );
			}

			$cause_term_ids = self::resolve_term_slugs( $row['causes'] ?? '', Cause::TAXONOMY );
			if ( ! empty( $cause_term_ids ) ) {
				wp_set_object_terms( $post_id, $cause_term_ids, Cause::TAXONOMY );
			}

			++$result['created'];
		}

		return $result;
	}

	/**
	 * Creates Team posts from parsed CSV rows.
	 *
	 * @param array<int, array<string, string>> $rows       Parsed CSV rows.
	 * @param int                               $row_offset Row-number offset for error reporting in chunked imports.
	 * @return array<string, mixed>
	 */
	private static function import_teams( array $rows, int $row_offset = 0 ): array {
		$result = self::empty_result();

		foreach ( $rows as $index => $row ) {
			$row_num = $row_offset + $index + 2;

			$title = isset( $row['title'] ) ? trim( $row['title'] ) : '';
			if ( '' === $title ) {
				$result['errors'][] = self::error_row( $row_num, __( 'Title is required.', 'giving-day-blocks' ), $row );
				continue;
			}

			$slug = isset( $row['slug'] ) && '' !== $row['slug'] ? sanitize_title( $row['slug'] ) : sanitize_title( $title );
			if ( self::post_with_slug_exists( $slug, Team::POST_TYPE ) ) {
				++$result['skipped'];
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => Team::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_name'   => $slug,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$result['errors'][] = self::error_row( $row_num, $post_id->get_error_message(), $row );
				continue;
			}

			if ( isset( $row['goal_amount'] ) && '' !== $row['goal_amount'] && is_numeric( $row['goal_amount'] ) ) {
				update_post_meta( $post_id, Team::META_GOAL_AMOUNT, (float) $row['goal_amount'] );
			}

			$campaign_ids = self::resolve_campaign_slugs( $row['campaigns'] ?? '' );
			if ( ! empty( $campaign_ids ) ) {
				update_post_meta( $post_id, Team::META_CAMPAIGN_IDS, $campaign_ids );
			}

			$team_group_term_ids = self::resolve_term_slugs( $row['team_groups'] ?? '', TeamGroup::TAXONOMY );
			if ( ! empty( $team_group_term_ids ) ) {
				wp_set_object_terms( $post_id, $team_group_term_ids, TeamGroup::TAXONOMY );
			}

			++$result['created'];
		}

		return $result;
	}

	/**
	 * Records donations as WC orders via the OfflineDonations service.
	 *
	 * @param array<int, array<string, string>> $rows       Parsed CSV rows.
	 * @param int                               $row_offset Row-number offset for error reporting in chunked imports.
	 * @return array<string, mixed>
	 */
	private static function import_donations( array $rows, int $row_offset = 0 ): array {
		$result = self::empty_result();

		$seen_external_ids = self::existing_external_ids();

		foreach ( $rows as $index => $row ) {
			$row_num = $row_offset + $index + 2;

			$external_id = isset( $row['external_id'] ) ? trim( $row['external_id'] ) : '';
			if ( '' === $external_id ) {
				$result['errors'][] = self::error_row( $row_num, __( 'external_id is required for donation rows.', 'giving-day-blocks' ), $row );
				continue;
			}
			if ( isset( $seen_external_ids[ $external_id ] ) ) {
				++$result['skipped'];
				continue;
			}

			$campaign_slug = isset( $row['campaign_slug'] ) ? trim( $row['campaign_slug'] ) : '';
			if ( '' === $campaign_slug ) {
				$result['errors'][] = self::error_row( $row_num, __( 'campaign_slug is required.', 'giving-day-blocks' ), $row );
				continue;
			}
			$campaign = self::find_post_by_slug( $campaign_slug, Campaign::POST_TYPE );
			if ( ! $campaign ) {
				$result['errors'][] = self::error_row(
					$row_num,
					sprintf(
						/* translators: %s: campaign slug. */
						__( 'Campaign "%s" not found.', 'giving-day-blocks' ),
						$campaign_slug
					),
					$row
				);
				continue;
			}

			$beneficiary_id = self::resolve_optional_slug( $row['beneficiary_slug'] ?? '', Beneficiary::POST_TYPE );
			$team_id        = self::resolve_optional_slug( $row['team_slug'] ?? '', Team::POST_TYPE );

			$args = array(
				'campaign_id'    => (int) $campaign->ID,
				'amount'         => isset( $row['amount'] ) ? (float) $row['amount'] : 0.0,
				'donor_name'     => isset( $row['donor_name'] ) ? sanitize_text_field( $row['donor_name'] ) : '',
				'donor_email'    => isset( $row['donor_email'] ) ? sanitize_email( $row['donor_email'] ) : '',
				'tender'         => isset( $row['tender'] ) ? sanitize_key( $row['tender'] ) : OfflineService::TENDER_OTHER,
				'reference'      => isset( $row['reference'] ) ? sanitize_text_field( $row['reference'] ) : '',
				'donation_date'  => isset( $row['donation_date'] ) ? sanitize_text_field( $row['donation_date'] ) : '',
				'admin_notes'    => isset( $row['notes'] ) ? sanitize_textarea_field( $row['notes'] ) : '',
				'team_id'        => $team_id,
				'beneficiary_id' => $beneficiary_id,
			);

			$order_id = OfflineService::create_order( $args );
			if ( is_wp_error( $order_id ) ) {
				$result['errors'][] = self::error_row( $row_num, $order_id->get_error_message(), $row );
				continue;
			}

			update_post_meta( $order_id, self::META_EXTERNAL_ID, $external_id );
			if ( self::yes_no( $row['anonymous'] ?? '' ) ) {
				update_post_meta( $order_id, self::META_ANONYMOUS, 'yes' );
			}

			$seen_external_ids[ $external_id ] = true;
			++$result['created'];
		}

		return $result;
	}

	/**
	 * Returns the CSV text for a downloadable template, including a UTF-8
	 * BOM (so Excel on Windows opens it correctly) and a couple of example
	 * rows so users see what every column should look like.
	 *
	 * @param string $type One of the TYPE_* constants.
	 */
	public static function template_csv( string $type ): string {
		$bom = "\xEF\xBB\xBF";

		switch ( $type ) {
			case self::TYPE_CAUSES:
				$header  = array( 'name', 'slug', 'parent_slug', 'description' );
				$samples = array(
					array( 'Science', 'science', '', 'Research, labs, and STEM scholarships.' ),
					array( 'Arts', 'arts', '', 'Music, drama, visual arts, and humanities.' ),
					array( 'Marine Biology', 'marine-biology', 'science', 'Coastal ecosystem and species research — nested under Science.' ),
				);
				break;
			case self::TYPE_BENEFICIARIES:
				$header  = array( 'title', 'slug', 'parent_slug', 'goal_amount', 'parent_org', 'excerpt', 'causes', 'campaigns' );
				$samples = array(
					array( 'Library Fund', 'library-fund', '', '5000', '', 'Books, materials, and equipment for the library.', 'education,arts', 'sample-giving-day-2026' ),
					array( 'Robotics Lab', 'robotics-lab', 'college-of-engineering', '2500', '', 'Funds new robotics equipment.', 'education,technology', 'sample-giving-day-2026' ),
					array( 'Local Food Bank', 'local-food-bank', '', '', 'Member of: Health Coalition of Travis County', 'Direct food relief in Travis County.', 'community', 'sample-giving-day-2026' ),
				);
				break;
			case self::TYPE_TEAMS:
				$header  = array( 'title', 'slug', 'goal_amount', 'team_groups', 'campaigns' );
				$samples = array(
					array( 'Class of 2014', 'class-of-2014', '1000', 'class-year,class-year-2014', 'sample-giving-day-2026' ),
					array( 'Football', 'football', '2500', 'athletic,athletic-football', 'sample-giving-day-2026' ),
				);
				break;
			case self::TYPE_DONATIONS:
				$header  = array( 'external_id', 'campaign_slug', 'beneficiary_slug', 'team_slug', 'amount', 'donor_name', 'donor_email', 'donation_date', 'tender', 'reference', 'anonymous', 'notes' );
				$samples = array(
					array( 'legacy-001', 'sample-giving-day-2026', 'library-fund', '', '50.00', 'Jane Doe', 'jane@example.com', '2026-03-15T10:30', 'check', '1042', 'no', '' ),
					array( 'legacy-002', 'sample-giving-day-2026', 'robotics-lab', 'class-of-2014', '100.00', 'John Smith', 'john@example.com', '2026-03-15T11:00', 'cash', '', 'no', 'Brought to gala' ),
					array( 'legacy-003', 'sample-giving-day-2026', '', '', '25.00', 'Anonymous Donor', 'donor@example.com', '2026-03-15T11:30', 'other', 'wire transfer', 'yes', '' ),
				);
				break;
			default:
				return $bom;
		}

		$out = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			return $bom . implode( ',', $header ) . "\n";
		}
		fputcsv( $out, $header );
		foreach ( $samples as $sample ) {
			fputcsv( $out, $sample );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $bom . ( is_string( $csv ) ? $csv : '' );
	}

	/**
	 * Returns the column names a template ships with. Useful for the
	 * upload form's "expected columns" hint.
	 *
	 * @param string $type One of TYPE_*.
	 * @return array<int, string>
	 */
	public static function template_columns( string $type ): array {
		switch ( $type ) {
			case self::TYPE_CAUSES:
				return array( 'name', 'slug', 'parent_slug', 'description' );
			case self::TYPE_BENEFICIARIES:
				return array( 'title', 'slug', 'parent_slug', 'goal_amount', 'parent_org', 'excerpt', 'causes', 'campaigns' );
			case self::TYPE_TEAMS:
				return array( 'title', 'slug', 'goal_amount', 'team_groups', 'campaigns' );
			case self::TYPE_DONATIONS:
				return array( 'external_id', 'campaign_slug', 'beneficiary_slug', 'team_slug', 'amount', 'donor_name', 'donor_email', 'donation_date', 'tender', 'reference', 'anonymous', 'notes' );
		}
		return array();
	}

	/**
	 * Builds a downloadable CSV of the per-row errors from a result, so
	 * organizers can fix and re-upload only the failing rows.
	 *
	 * @param array<int, array<string, mixed>> $errors Errors from `import()`.
	 */
	public static function errors_csv( array $errors ): string {
		$bom = "\xEF\xBB\xBF";
		$out = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			return $bom;
		}

		$column_set = array();
		foreach ( $errors as $err ) {
			if ( isset( $err['data'] ) && is_array( $err['data'] ) ) {
				foreach ( array_keys( $err['data'] ) as $col ) {
					$column_set[ (string) $col ] = true;
				}
			}
		}
		$data_columns = array_keys( $column_set );

		$header = array_merge( array( 'row', 'error' ), $data_columns );
		fputcsv( $out, $header );

		foreach ( $errors as $err ) {
			$row_data = isset( $err['data'] ) && is_array( $err['data'] ) ? $err['data'] : array();
			$cells    = array( (int) ( $err['row'] ?? 0 ), (string) ( $err['message'] ?? '' ) );
			foreach ( $data_columns as $col ) {
				$cells[] = (string) ( $row_data[ $col ] ?? '' );
			}
			fputcsv( $out, $cells );
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $bom . ( is_string( $csv ) ? $csv : '' );
	}

	/**
	 * Returns the zero-state result struct used by every importer.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_result(): array {
		return array(
			'created' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
	}

	/**
	 * Builds one entry for the per-row error list returned by import().
	 *
	 * @param int                  $row_num 1-based row number including the header line.
	 * @param string               $message Human-readable reason.
	 * @param array<string,string> $data    Original row contents.
	 * @return array<string, mixed>
	 */
	private static function error_row( int $row_num, string $message, array $data ): array {
		return array(
			'row'     => $row_num,
			'message' => $message,
			'data'    => $data,
		);
	}

	/**
	 * True when a post with this slug already exists in the given CPT.
	 *
	 * @param string $slug      Slug to test.
	 * @param string $post_type CPT slug.
	 */
	private static function post_with_slug_exists( string $slug, string $post_type ): bool {
		return null !== self::find_post_by_slug( $slug, $post_type );
	}

	/**
	 * Resolves a slug to a WP_Post in a specific CPT, or null when not found.
	 *
	 * @param string $slug      Slug to look up.
	 * @param string $post_type CPT slug.
	 */
	private static function find_post_by_slug( string $slug, string $post_type ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}
		$post = get_page_by_path( $slug, OBJECT, $post_type );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Returns the ID for a slug column that may be blank, or 0 when unset / unresolved.
	 *
	 * @param string $slug      Slug from the CSV cell.
	 * @param string $post_type CPT slug.
	 */
	private static function resolve_optional_slug( string $slug, string $post_type ): int {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$post = self::find_post_by_slug( $slug, $post_type );
		return $post ? (int) $post->ID : 0;
	}

	/**
	 * Resolves a comma-separated list of campaign slugs into an array of IDs.
	 *
	 * @param string $value Raw cell value (e.g. "spring-2026,fall-2026").
	 * @return array<int, int>
	 */
	private static function resolve_campaign_slugs( string $value ): array {
		$slugs = array_filter( array_map( 'trim', explode( ',', $value ) ) );
		$ids   = array();
		foreach ( $slugs as $slug ) {
			$post = self::find_post_by_slug( $slug, Campaign::POST_TYPE );
			if ( $post ) {
				$ids[] = (int) $post->ID;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolves a comma-separated list of term slugs into term IDs.
	 *
	 * Auto-creates missing terms at the taxonomy root so the import does
	 * not silently drop unknown slugs (which previously left teams
	 * untagged and leaderboard groupings empty).
	 *
	 * @param string $value    Raw cell value.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, int>
	 */
	private static function resolve_term_slugs( string $value, string $taxonomy ): array {
		$slugs = array_filter( array_map( 'trim', explode( ',', $value ) ) );
		$ids   = array();
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$name   = self::humanize_slug( $slug );
			$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			if ( is_array( $result ) && isset( $result['term_id'] ) ) {
				$ids[] = (int) $result['term_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Title-cases a slug for use as a default term name (e.g. "class-year-2014" → "Class Year 2014").
	 *
	 * @param string $slug Sanitized slug.
	 */
	private static function humanize_slug( string $slug ): string {
		$name = trim( str_replace( array( '-', '_' ), ' ', $slug ) );
		if ( '' === $name ) {
			return $slug;
		}
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $name );
	}

	/**
	 * Returns a `[external_id => true]` map of every WC order that already
	 * carries an import external ID, so we can short-circuit duplicates
	 * without one wc_get_orders per row.
	 *
	 * @return array<string, bool>
	 */
	private static function existing_external_ids(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$order_ids = wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'ids',
				'meta_key'     => self::META_EXTERNAL_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);
		if ( ! is_array( $order_ids ) ) {
			return array();
		}
		$map = array();
		foreach ( $order_ids as $oid ) {
			$value = get_post_meta( (int) $oid, self::META_EXTERNAL_ID, true );
			if ( is_string( $value ) && '' !== $value ) {
				$map[ $value ] = true;
			}
		}
		return $map;
	}

	/**
	 * Interprets common truthy strings ("yes", "y", "true", "1") as boolean true.
	 *
	 * @param string $value Raw cell value.
	 */
	private static function yes_no( string $value ): bool {
		$value = strtolower( trim( $value ) );
		return in_array( $value, array( 'yes', 'y', 'true', '1' ), true );
	}

	/**
	 * Strips a leading UTF-8 BOM from the first cell of a header row.
	 *
	 * @param array<int, mixed> $header Raw header row as returned by fgetcsv().
	 * @return array<int, mixed>
	 */
	private static function strip_bom_from_first( array $header ): array {
		if ( ! empty( $header ) && is_string( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		}
		return $header;
	}
}
