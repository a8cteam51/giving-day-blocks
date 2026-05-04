<?php
/**
 * Goal progress resolution.
 *
 * Single helper used by `goal-progress` block render.php (and, later, any
 * REST endpoint we add for Team/Beneficiary targets) to answer the
 * question: "what is the goal, how much has been raised, and how many
 * donors does this target have?"
 *
 * Today the only supported target is a Campaign. The CPTs Team and
 * Beneficiary already carry their own `_giving_*_goal_amount` meta and
 * are tagged on WC orders via `_giving_team_id` / `_giving_beneficiary_id`,
 * so adding them later is a single new `case` in {@see resolve()} plus a
 * matching JS branch in blocks/src/_shared/utils/goalProgress.js.
 *
 * `raised` and `donor_count` come from {@see Aggregator::totals_for_campaign()},
 * which sums donation line items off real WC orders.
 *
 * @package Team51\GivingDay\Data
 * @since   0.1.0
 */

namespace Team51\GivingDay\Data;

use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

final class GoalProgress {

	public const TYPE_CAMPAIGN = 'campaign';

	/**
	 * Resolves a target descriptor to a normalized progress payload.
	 *
	 * Shape:
	 *   array{
	 *     goal:        float,
	 *     raised:      float,
	 *     donor_count: int,
	 *     currency:    string,
	 *     percent:     float,   // 0..100, clamped for the bar fill
	 *     percent_raw: float,   // unclamped, can exceed 100
	 *   }
	 *
	 * Returns null when the target is unsupported or doesn't exist, so
	 * callers can early-bail without manually validating each branch.
	 *
	 * @param string $type Target type, e.g. self::TYPE_CAMPAIGN.
	 * @param int    $id   Target post ID.
	 * @return array<string,mixed>|null
	 */
	public static function resolve( string $type, int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		switch ( $type ) {
			case self::TYPE_CAMPAIGN:
				return self::resolve_campaign( $id );
			default:
				return null;
		}
	}

	/**
	 * Campaign branch.
	 *
	 * @param int $campaign_id
	 * @return array<string,mixed>|null
	 */
	private static function resolve_campaign( int $campaign_id ): ?array {
		$post = get_post( $campaign_id );
		if ( ! $post || Campaign::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$totals = Aggregator::totals_for_campaign( $campaign_id );

		$goal     = (float) get_post_meta( $campaign_id, Campaign::META_GOAL_AMOUNT, true );
		$raised   = isset( $totals['raised'] ) ? (float) $totals['raised'] : 0.0;
		$donors   = isset( $totals['unique_donors'] ) ? (int) $totals['unique_donors'] : 0;
		$currency = isset( $totals['currency'] ) ? (string) $totals['currency'] : (string) get_option( 'woocommerce_currency', 'USD' );

		$percent_raw = $goal > 0 ? ( $raised / $goal ) * 100 : 0.0;
		$percent     = max( 0.0, min( 100.0, $percent_raw ) );

		return array(
			'goal'        => $goal,
			'raised'      => $raised,
			'donor_count' => $donors,
			'currency'    => $currency,
			'percent'     => round( $percent, 2 ),
			'percent_raw' => round( $percent_raw, 2 ),
		);
	}

	/**
	 * Currency-format an amount for SSR. Prefers `wc_price()` because it
	 * honors the WooCommerce store's number / decimal settings; falls
	 * back to `NumberFormatter` when WooCommerce is missing (e.g. CLI),
	 * and finally a plain "USD 12,345" rendering.
	 *
	 * @param float  $amount
	 * @param string $currency 3-letter ISO code.
	 * @return string
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
}
