<?php
/**
 * Match progress resolver.
 *
 * Single source of truth for the discriminated-union shape consumed by
 * /match/{id}/progress, /campaign/{id}/active-matches, the war room, and
 * the block render.php (PLAN.md § 4.3, § 5.6).
 *
 * The two real-world mechanics are modeled as a discriminated record
 * rather than a flat shape because their progress is denominated in
 * different units (dollars vs. donor count) and their copy reads
 * differently to donors. Branching once here keeps every consumer's
 * read shape obvious.
 *
 * Until the WC order Aggregator (PLAN.md § 4.3) lands, the
 * "matched so far" / "donors so far" numbers come from dev-override meta
 * on the Match post, mirroring the dev overrides on Campaign. When the
 * Aggregator ships, this class will prefer real data and fall back to
 * the overrides only when no orders exist yet.
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\GivingMatch;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class MatchProgress {

	public const STATE_SCHEDULED = 'scheduled';
	public const STATE_ACTIVE    = 'active';
	public const STATE_EXHAUSTED = 'exhausted';
	public const STATE_UNLOCKED  = 'unlocked';
	public const STATE_COMPLETED = 'completed';

	/**
	 * Resolves a match post to its full progress payload.
	 *
	 * Returns null when the post doesn't exist, isn't a giving_match, or
	 * has been trashed. Callers can early-bail without manual validation.
	 *
	 * Common fields on every shape:
	 *   id, type, state, sponsor_name, sponsor_logo_url,
	 *   window_start, window_end, multiplier_label, server_time, pct
	 *
	 * type === 'dollar_for_dollar' adds:
	 *   multiplier, matched_so_far, cap, remaining
	 *
	 * type === 'donor_unlock' adds:
	 *   donors_so_far, donor_threshold, donors_remaining, unlock_amount
	 *
	 * @param int $match_id Match post ID.
	 * @return array<string,mixed>|null
	 */
	public static function resolve( int $match_id ): ?array {
		if ( $match_id <= 0 ) {
			return null;
		}

		$post = get_post( $match_id );
		if ( ! $post instanceof WP_Post || GivingMatch::POST_TYPE !== $post->post_type ) {
			return null;
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $match_id ) ) {
			return null;
		}

		$campaign_id = (int) get_post_meta( $match_id, GivingMatch::META_CAMPAIGN_ID, true );
		$type_meta   = (string) get_post_meta( $match_id, GivingMatch::META_TYPE, true );
		$type        = in_array( $type_meta, GivingMatch::TYPES, true ) ? $type_meta : GivingMatch::TYPE_DOLLAR_FOR_DOLLAR;

		$window = self::resolve_window( $match_id, $campaign_id );

		$now    = time();
		$active = (bool) get_post_meta( $match_id, GivingMatch::META_ACTIVE, true );

		$payload = array(
			'id'               => $match_id,
			'campaign_id'      => $campaign_id,
			'type'             => $type,
			'title'            => get_the_title( $match_id ),
			'sponsor_name'     => (string) get_post_meta( $match_id, GivingMatch::META_SPONSOR_NAME, true ),
			'sponsor_logo_url' => self::sponsor_logo_url( $match_id ),
			'window_start'     => $window['start_iso'],
			'window_end'       => $window['end_iso'],
			'window_start_ts'  => $window['start_ts'],
			'window_end_ts'    => $window['end_ts'],
			'active_flag'      => $active,
			'server_time'      => gmdate( 'c' ),
		);

		if ( GivingMatch::TYPE_DOLLAR_FOR_DOLLAR === $type ) {
			$payload = array_merge( $payload, self::resolve_dollar_for_dollar( $match_id ) );
		} else {
			$payload = array_merge( $payload, self::resolve_donor_unlock( $match_id ) );
		}

		$payload['state'] = self::compute_state( $type, $payload, $window, $active, $now );
		$payload['pct']   = self::compute_pct( $type, $payload );

		return $payload;
	}

	/**
	 * Returns true when a match in the given state should be rendered to
	 * a visitor given the block's display preferences. Mirrored client-side
	 * in matchProgress.js so SSR and hydration agree.
	 *
	 * @param string $state         One of self::STATE_*.
	 * @param string $hide_complete 'hide' | 'show-goal-reached'.
	 * @param string $show_outside  'hide' | 'show-scheduled' | 'show-ended'.
	 */
	public static function should_render( string $state, string $hide_complete, string $show_outside ): bool {
		if ( self::STATE_SCHEDULED === $state ) {
			return 'show-scheduled' === $show_outside;
		}
		if ( self::STATE_COMPLETED === $state ) {
			return 'show-ended' === $show_outside;
		}
		if ( self::STATE_EXHAUSTED === $state || self::STATE_UNLOCKED === $state ) {
			return 'show-goal-reached' === $hide_complete;
		}
		return true;
	}

	/**
	 * Returns the next match scheduled to run on a campaign, or null.
	 *
	 * Used by the SSR path when a block opts into
	 * `showOutsideWindow=show-scheduled`. Uses the same per-id resolve
	 * loop as active_for_campaign() so window inheritance and state
	 * computation stay in one place.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string,mixed>|null
	 */
	public static function next_scheduled_for_campaign( int $campaign_id ): ?array {
		return self::pick_extreme( $campaign_id, self::STATE_SCHEDULED, 'window_start_ts', 'min' );
	}

	/**
	 * Returns the most-recent completed match on a campaign, or null.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string,mixed>|null
	 */
	public static function last_completed_for_campaign( int $campaign_id ): ?array {
		return self::pick_extreme( $campaign_id, self::STATE_COMPLETED, 'window_end_ts', 'max' );
	}

	/**
	 * Returns 0..N matches whose window contains $now, sorted by
	 * end-soonest first (tiebreak: post_date), filtered to those whose
	 * `active` flag is true.
	 *
	 * Used by the block's "auto-select" mode and the war room so the
	 * "which match is showing right now?" rule lives in one place.
	 *
	 * @param int      $campaign_id Campaign post ID.
	 * @param int|null $now         Unix timestamp; defaults to time().
	 * @return array<int, array<string,mixed>> List of progress payloads.
	 */
	public static function active_for_campaign( int $campaign_id, ?int $now = null ): array {
		if ( $campaign_id <= 0 ) {
			return array();
		}
		$now = $now ?? time();

		$ids = get_posts(
			array(
				'post_type'              => GivingMatch::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 50,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => GivingMatch::META_CAMPAIGN_ID,
						'value'   => $campaign_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$rows = array();
		foreach ( (array) $ids as $id ) {
			$payload = self::resolve( (int) $id );
			if ( null === $payload ) {
				continue;
			}
			if ( self::STATE_ACTIVE !== $payload['state'] ) {
				continue;
			}
			$rows[] = $payload;
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$ae = $a['window_end_ts'] ?? PHP_INT_MAX;
				$be = $b['window_end_ts'] ?? PHP_INT_MAX;
				if ( $ae === $be ) {
					return ( $a['id'] ?? 0 ) <=> ( $b['id'] ?? 0 );
				}
				return $ae <=> $be;
			}
		);

		return $rows;
	}

	/**
	 * Walks every match attached to a campaign and returns the one whose
	 * $key field is the smallest (mode='min') or largest (mode='max')
	 * among rows in $state. Returns null when none qualify.
	 *
	 * @param int    $campaign_id
	 * @param string $state
	 * @param string $key   Field on the resolved payload to compare.
	 * @param string $mode  'min' or 'max'.
	 * @return array<string,mixed>|null
	 */
	private static function pick_extreme( int $campaign_id, string $state, string $key, string $mode ): ?array {
		if ( $campaign_id <= 0 ) {
			return null;
		}
		$ids    = get_posts(
			array(
				'post_type'              => GivingMatch::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 50,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => GivingMatch::META_CAMPAIGN_ID,
						'value'   => $campaign_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$best   = null;
		$best_v = null;
		foreach ( (array) $ids as $id ) {
			$payload = self::resolve( (int) $id );
			if ( null === $payload || ( $payload['state'] ?? '' ) !== $state ) {
				continue;
			}
			$v = $payload[ $key ] ?? null;
			if ( null === $v ) {
				continue;
			}
			if ( null === $best ) {
				$best   = $payload;
				$best_v = $v;
				continue;
			}
			if ( 'min' === $mode && $v < $best_v ) {
				$best   = $payload;
				$best_v = $v;
			} elseif ( 'max' === $mode && $v > $best_v ) {
				$best   = $payload;
				$best_v = $v;
			}
		}
		return $best;
	}

	/**
	 * Builds the type-aware front-end copy for a resolved match payload.
	 * Returns the same string set view.js produces in JS so SSR and
	 * hydration look identical to the visitor.
	 *
	 * @param array<string,mixed> $payload  Resolved progress payload.
	 * @param string              $currency 3-letter ISO code.
	 * @return array{type_label: string, headline: string, meta: string}
	 */
	public static function front_end_copy( array $payload, string $currency ): array {
		$payload_type = (string) ( $payload['type'] ?? GivingMatch::TYPE_DOLLAR_FOR_DOLLAR );
		$state        = (string) ( $payload['state'] ?? self::STATE_ACTIVE );

		if ( GivingMatch::TYPE_DONOR_UNLOCK === $payload_type ) {
			return self::copy_donor_unlock( $payload, $currency, $state );
		}
		return self::copy_dollar_for_dollar( $payload, $currency, $state );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function copy_donor_unlock( array $payload, string $currency, string $state ): array {
		$threshold = (int) ( $payload['donor_threshold'] ?? 0 );
		$donors    = (int) ( $payload['donors_so_far'] ?? 0 );
		$remaining = (int) ( $payload['donors_remaining'] ?? 0 );
		$unlock    = (float) ( $payload['unlock_amount'] ?? 0 );

		$type_label = __( 'Donor unlock', 'giving-day-blocks' );
		switch ( $state ) {
			case self::STATE_SCHEDULED:
				$headline = __( 'Match starts soon — help unlock the bonus.', 'giving-day-blocks' );
				break;
			case self::STATE_UNLOCKED:
				$headline = sprintf(
					/* translators: %s: amount (formatted). */
					__( 'Goal reached — %s unlocked!', 'giving-day-blocks' ),
					self::format_currency( $unlock, $currency )
				);
				break;
			case self::STATE_COMPLETED:
				$headline = __( 'Match ended.', 'giving-day-blocks' );
				break;
			case self::STATE_ACTIVE:
			default:
				$headline = sprintf(
					/* translators: 1: donors needed, 2: amount (formatted). */
					__( '%1$s more donors unlock %2$s.', 'giving-day-blocks' ),
					number_format_i18n( $remaining ),
					self::format_currency( $unlock, $currency )
				);
				break;
		}

		$meta = sprintf(
			/* translators: 1: donors so far, 2: donor threshold. */
			__( '%1$s of %2$s donors', 'giving-day-blocks' ),
			number_format_i18n( $donors ),
			number_format_i18n( $threshold )
		);

		return array(
			'type_label' => $type_label,
			'headline'   => $headline,
			'meta'       => $meta,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function copy_dollar_for_dollar( array $payload, string $currency, string $state ): array {
		$multiplier_label = (string) ( $payload['multiplier_label'] ?? '2×' );
		$cap              = (float) ( $payload['cap'] ?? 0 );
		$matched          = (float) ( $payload['matched_so_far'] ?? 0 );
		$remaining        = (float) ( $payload['remaining'] ?? 0 );

		$type_label = __( 'Dollar-for-dollar', 'giving-day-blocks' );
		switch ( $state ) {
			case self::STATE_SCHEDULED:
				$headline = __( 'Match starts soon — your gift will go further.', 'giving-day-blocks' );
				break;
			case self::STATE_EXHAUSTED:
				$headline = sprintf(
					/* translators: %s: cap (formatted). */
					__( '%s fully matched — thank you!', 'giving-day-blocks' ),
					self::format_currency( $cap, $currency )
				);
				break;
			case self::STATE_COMPLETED:
				$headline = __( 'Match ended.', 'giving-day-blocks' );
				break;
			case self::STATE_ACTIVE:
			default:
				$headline = sprintf(
					/* translators: %s: multiplier label, e.g. "2×". */
					__( 'Your gift goes %s further.', 'giving-day-blocks' ),
					$multiplier_label
				);
				break;
		}

		$meta = $cap > 0
			? sprintf(
				/* translators: 1: matched amount, 2: cap, 3: remaining. */
				__( '%1$s of %2$s matched — %3$s still available.', 'giving-day-blocks' ),
				self::format_currency( $matched, $currency ),
				self::format_currency( $cap, $currency ),
				self::format_currency( $remaining, $currency )
			)
			: sprintf(
				/* translators: %s: matched amount. */
				__( '%s matched so far.', 'giving-day-blocks' ),
				self::format_currency( $matched, $currency )
			);

		return array(
			'type_label' => $type_label,
			'headline'   => $headline,
			'meta'       => $meta,
		);
	}

	/**
	 * SSR currency formatter. Mirrors GoalProgress::format_currency but
	 * inlined here so this module has no read-time dependency on the
	 * goal-progress one.
	 *
	 * @param float  $amount
	 * @param string $currency 3-letter ISO code.
	 */
	public static function format_currency( float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) );
		}
		if ( class_exists( 'NumberFormatter' ) ) {
			$fmt = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			return (string) $fmt->formatCurrency( $amount, $currency );
		}
		return sprintf( '%s %s', $currency, number_format_i18n( $amount ) );
	}

	/**
	 * Resolves the absolute window for a match.
	 *
	 * If the match's own start/end meta is set, use it. Otherwise inherit
	 * the parent Campaign's start/end so a "runs the whole event" match
	 * doesn't need its own datetimes filled in.
	 *
	 * @param int $match_id
	 * @param int $campaign_id
	 * @return array{start_iso: string, end_iso: string, start_ts: int|null, end_ts: int|null}
	 */
	private static function resolve_window( int $match_id, int $campaign_id ): array {
		$start_iso = (string) get_post_meta( $match_id, GivingMatch::META_START_DATETIME, true );
		$end_iso   = (string) get_post_meta( $match_id, GivingMatch::META_END_DATETIME, true );

		if ( '' === $start_iso && $campaign_id > 0 ) {
			$start_iso = (string) get_post_meta( $campaign_id, Campaign::META_START_DATETIME, true );
		}
		if ( '' === $end_iso && $campaign_id > 0 ) {
			$end_iso = (string) get_post_meta( $campaign_id, Campaign::META_END_DATETIME, true );
		}

		$start_ts = '' !== $start_iso ? strtotime( $start_iso ) : false;
		$end_ts   = '' !== $end_iso ? strtotime( $end_iso ) : false;

		return array(
			'start_iso' => $start_iso,
			'end_iso'   => $end_iso,
			'start_ts'  => false === $start_ts ? null : $start_ts,
			'end_ts'    => false === $end_ts ? null : $end_ts,
		);
	}

	/**
	 * Builds the dollar-for-dollar specific subset of fields.
	 *
	 * @param int $match_id
	 * @return array<string,mixed>
	 */
	private static function resolve_dollar_for_dollar( int $match_id ): array {
		$multiplier = (float) get_post_meta( $match_id, GivingMatch::META_MULTIPLIER, true );
		if ( $multiplier <= 0 ) {
			$multiplier = 2.0;
		}
		$cap     = (float) get_post_meta( $match_id, GivingMatch::META_CAP_AMOUNT, true );
		$matched = (float) get_post_meta( $match_id, GivingMatch::META_MATCHED_OVERRIDE, true );
		if ( $matched < 0 ) {
			$matched = 0.0;
		}
		if ( $cap > 0 && $matched > $cap ) {
			$matched = $cap;
		}

		$remaining = $cap > 0 ? max( 0.0, $cap - $matched ) : 0.0;

		return array(
			'multiplier'       => round( $multiplier, 4 ),
			'multiplier_label' => self::multiplier_label( $multiplier ),
			'cap'              => round( $cap, 2 ),
			'matched_so_far'   => round( $matched, 2 ),
			'remaining'        => round( $remaining, 2 ),
		);
	}

	/**
	 * Builds the donor-unlock specific subset of fields.
	 *
	 * @param int $match_id
	 * @return array<string,mixed>
	 */
	private static function resolve_donor_unlock( int $match_id ): array {
		$threshold = (int) get_post_meta( $match_id, GivingMatch::META_DONOR_THRESHOLD, true );
		$threshold = max( 0, $threshold );
		$unlock    = (float) get_post_meta( $match_id, GivingMatch::META_UNLOCK_AMOUNT, true );
		$donors    = (int) get_post_meta( $match_id, GivingMatch::META_DONORS_OVERRIDE, true );
		$donors    = max( 0, $donors );

		$remaining = $threshold > 0 ? max( 0, $threshold - $donors ) : 0;

		return array(
			'donor_threshold'  => $threshold,
			'donors_so_far'    => $donors,
			'donors_remaining' => $remaining,
			'unlock_amount'    => round( $unlock, 2 ),
		);
	}

	/**
	 * Computes the match state.
	 *
	 * Precedence (top wins):
	 *   - active flag is false           → completed (admin-paused)
	 *   - now < window_start             → scheduled
	 *   - now >= window_end              → completed
	 *   - exhausted (D4D cap reached)    → exhausted
	 *   - unlocked (donor threshold hit) → unlocked
	 *   - otherwise                      → active
	 *
	 * Both `exhausted` and `unlocked` rank above raw window math because
	 * a match that hit its cap mid-window should advertise that fact even
	 * though the clock hasn't run out yet.
	 *
	 * @param string                                                 $type
	 * @param array<string,mixed>                                    $payload
	 * @param array{start_ts: int|null, end_ts: int|null,
	 *             start_iso: string, end_iso: string} $window
	 * @param bool                                                   $active
	 * @param int                                                    $now
	 * @return string
	 */
	private static function compute_state( string $type, array $payload, array $window, bool $active, int $now ): string {
		if ( ! $active ) {
			return self::STATE_COMPLETED;
		}

		$start = $window['start_ts'];
		$end   = $window['end_ts'];

		if ( null !== $end && $now >= $end ) {
			return self::STATE_COMPLETED;
		}
		if ( null !== $start && $now < $start ) {
			return self::STATE_SCHEDULED;
		}

		if ( GivingMatch::TYPE_DOLLAR_FOR_DOLLAR === $type ) {
			$cap     = (float) ( $payload['cap'] ?? 0 );
			$matched = (float) ( $payload['matched_so_far'] ?? 0 );
			if ( $cap > 0 && $matched >= $cap ) {
				return self::STATE_EXHAUSTED;
			}
		} else {
			$threshold = (int) ( $payload['donor_threshold'] ?? 0 );
			$donors    = (int) ( $payload['donors_so_far'] ?? 0 );
			if ( $threshold > 0 && $donors >= $threshold ) {
				return self::STATE_UNLOCKED;
			}
		}

		return self::STATE_ACTIVE;
	}

	/**
	 * Computes the percent (0..100) for the progress bar fill.
	 *
	 * @param string              $type
	 * @param array<string,mixed> $payload
	 * @return int
	 */
	private static function compute_pct( string $type, array $payload ): int {
		if ( GivingMatch::TYPE_DOLLAR_FOR_DOLLAR === $type ) {
			$cap     = (float) ( $payload['cap'] ?? 0 );
			$matched = (float) ( $payload['matched_so_far'] ?? 0 );
			if ( $cap <= 0 ) {
				return 0;
			}
			return (int) round( max( 0.0, min( 100.0, ( $matched / $cap ) * 100 ) ) );
		}
		$threshold = (int) ( $payload['donor_threshold'] ?? 0 );
		$donors    = (int) ( $payload['donors_so_far'] ?? 0 );
		if ( $threshold <= 0 ) {
			return 0;
		}
		return (int) round( max( 0.0, min( 100.0, ( $donors / $threshold ) * 100 ) ) );
	}

	/**
	 * Returns a short, human-readable label for a multiplier.
	 *
	 * 2 → "2×", 2.5 → "2.5×". Used by SSR to keep the same string the
	 * editor preview shows and to drop the responsibility of rounding off
	 * the front-end view layer.
	 *
	 * @param float $multiplier
	 * @return string
	 */
	private static function multiplier_label( float $multiplier ): string {
		if ( $multiplier <= 0 ) {
			return '';
		}
		$rounded = round( $multiplier, 2 );
		if ( floor( $rounded ) === $rounded ) {
			return sprintf( '%d×', (int) $rounded );
		}
		return sprintf( '%s×', rtrim( rtrim( (string) $rounded, '0' ), '.' ) );
	}

	/**
	 * Resolves the URL of the sponsor logo attachment, or empty string.
	 *
	 * @param int $match_id
	 * @return string
	 */
	private static function sponsor_logo_url( int $match_id ): string {
		$attachment_id = (int) get_post_meta( $match_id, GivingMatch::META_SPONSOR_LOGO, true );
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $attachment_id, 'medium' );
		return is_string( $src ) ? $src : '';
	}
}
