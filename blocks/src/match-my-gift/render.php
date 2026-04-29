<?php
/**
 * Server render: giving-day/match-my-gift.
 *
 * Emits the resolved match (or nothing) for SEO / no-JS visitors. view.js
 * then hydrates in place and live-refreshes the numbers and countdown.
 *
 * Resolution order:
 *   matchSelection = 'specific' → resolve by matchId.
 *   matchSelection = 'auto'     → ask MatchProgress::active_for_campaign()
 *                                 for the most-urgent active match.
 *
 * Display preferences (variant, sponsor visibility, hideWhenComplete,
 * showOutsideWindow) live on the block; the *mechanic* (dollar-for-dollar
 * vs. donor-unlock, multiplier, threshold, window) lives on the
 * giving_match post and is rendered branch-wise here so editors don't
 * have to author copy themselves.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Rendered inner content (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\Colors;
use Team51\GivingDay\Data\MatchProgress;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\GivingMatch;

defined( 'ABSPATH' ) || exit;

$campaign_id     = isset( $attributes['campaignId'] ) ? (int) $attributes['campaignId'] : 0;
$match_selection = isset( $attributes['matchSelection'] ) && 'specific' === $attributes['matchSelection'] ? 'specific' : 'auto';
$forced_match_id = isset( $attributes['matchId'] ) ? (int) $attributes['matchId'] : 0;
$variant         = isset( $attributes['variant'] ) && 'banner' === $attributes['variant'] ? 'banner' : 'card';
$show_logo       = ! empty( $attributes['showSponsorLogo'] );
$show_name       = ! empty( $attributes['showSponsorName'] );
$show_countdown  = ! empty( $attributes['showCountdown'] );
$hide_complete   = isset( $attributes['hideWhenComplete'] ) ? (string) $attributes['hideWhenComplete'] : 'hide';
$show_outside    = isset( $attributes['showOutsideWindow'] ) ? (string) $attributes['showOutsideWindow'] : 'hide';

if ( $campaign_id <= 0 && $forced_match_id <= 0 ) {
	return;
}

$progress = null;
if ( 'specific' === $match_selection && $forced_match_id > 0 ) {
	$progress = MatchProgress::resolve( $forced_match_id );
} elseif ( $campaign_id > 0 ) {
	$active = MatchProgress::active_for_campaign( $campaign_id );
	if ( ! empty( $active ) ) {
		$progress = $active[0];
	} elseif ( 'show-scheduled' === $show_outside ) {
		$progress = MatchProgress::next_scheduled_for_campaign( $campaign_id );
	} elseif ( 'show-ended' === $show_outside ) {
		$progress = MatchProgress::last_completed_for_campaign( $campaign_id );
	}
}

if ( null === $progress ) {
	return;
}

$state = (string) ( $progress['state'] ?? MatchProgress::STATE_ACTIVE );
if ( ! MatchProgress::should_render( $state, $hide_complete, $show_outside ) ) {
	return;
}

$match_type    = (string) ( $progress['type'] ?? GivingMatch::TYPE_DOLLAR_FOR_DOLLAR );
$sponsor_name  = (string) ( $progress['sponsor_name'] ?? '' );
$sponsor_logo  = (string) ( $progress['sponsor_logo_url'] ?? '' );
$pct           = (int) ( $progress['pct'] ?? 0 );
$campaign_meta = $campaign_id > 0 ? $campaign_id : (int) ( $progress['campaign_id'] ?? 0 );
$currency_meta = $campaign_meta > 0 ? get_post_meta( $campaign_meta, Campaign::META_CURRENCY, true ) : '';
$currency      = (string) ( '' !== $currency_meta ? $currency_meta : get_option( 'woocommerce_currency', 'USD' ) );

$copy = MatchProgress::front_end_copy( $progress, $currency );

$wrapper_extra = array(
	'class'                    => trim(
		sprintf(
			'giving-day-match giving-day-match--%s giving-day-match--state-%s giving-day-match--type-%s',
			esc_attr( $variant ),
			esc_attr( $state ),
			esc_attr( $match_type )
		)
	),
	'data-campaign-id'         => (string) $campaign_meta,
	'data-match-id'            => (string) ( $progress['id'] ?? 0 ),
	'data-variant'             => $variant,
	'data-match-selection'     => $match_selection,
	'data-show-countdown'      => $show_countdown ? '1' : '0',
	'data-hide-when-complete'  => $hide_complete,
	'data-show-outside-window' => $show_outside,
	'data-currency'            => $currency,
	'data-initial'             => esc_attr( wp_json_encode( $progress ) ),
);

$color_style = $campaign_meta > 0 ? Colors::inline_style( $campaign_meta ) : '';
if ( '' !== $color_style ) {
	$wrapper_extra['style'] = $color_style;
}

$wrapper_attrs = get_block_wrapper_attributes( $wrapper_extra );

?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes internally. ?>>
	<div class="giving-day-match__inner" data-role="inner">
		<header class="giving-day-match__header">
			<p class="giving-day-match__type" data-role="type">
				<?php echo esc_html( $copy['type_label'] ); ?>
			</p>
			<?php if ( ! empty( $progress['title'] ) ) : ?>
				<h3 class="giving-day-match__title" data-role="title">
					<?php echo esc_html( $progress['title'] ); ?>
				</h3>
			<?php endif; ?>
			<?php if ( ( '' !== $sponsor_name || '' !== $sponsor_logo ) && ( $show_name || $show_logo ) ) : ?>
				<p class="giving-day-match__sponsor" data-role="sponsor">
					<?php if ( $show_logo && '' !== $sponsor_logo ) : ?>
						<img
							class="giving-day-match__logo"
							src="<?php echo esc_url( $sponsor_logo ); ?>"
							alt=""
						/>
					<?php endif; ?>
					<?php if ( $show_name ) : ?>
						<span>
							<?php echo esc_html__( 'Sponsored by', 'giving-day-blocks' ); ?>
							<strong><?php echo esc_html( $sponsor_name ); ?></strong>
						</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</header>

		<p class="giving-day-match__headline" data-role="headline" aria-live="polite">
			<?php echo esc_html( $copy['headline'] ); ?>
		</p>

		<div
			class="giving-day-match__track"
			role="progressbar"
			aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>"
			aria-valuemin="0"
			aria-valuemax="100"
			aria-valuetext="<?php echo esc_attr( $copy['meta'] ); ?>"
			data-role="track"
		>
			<span
				class="giving-day-match__fill"
				style="width: <?php echo esc_attr( (string) $pct ); ?>%;"
				data-role="fill"
				aria-hidden="true"
			></span>
		</div>

		<p class="giving-day-match__meta" data-role="meta">
			<?php echo esc_html( $copy['meta'] ); ?>
		</p>

		<?php if ( $show_countdown && MatchProgress::STATE_ACTIVE === $state && ! empty( $progress['window_end'] ) ) : ?>
			<p
				class="giving-day-match__countdown"
				data-role="countdown"
				data-target="<?php echo esc_attr( (string) $progress['window_end'] ); ?>"
				data-server-time="<?php echo esc_attr( (string) ( $progress['server_time'] ?? gmdate( 'c' ) ) ); ?>"
			>
				<?php echo esc_html__( 'Match window ends soon.', 'giving-day-blocks' ); ?>
			</p>
		<?php elseif ( $show_countdown && MatchProgress::STATE_SCHEDULED === $state && ! empty( $progress['window_start'] ) ) : ?>
			<p
				class="giving-day-match__countdown giving-day-match__countdown--scheduled"
				data-role="countdown"
				data-target="<?php echo esc_attr( (string) $progress['window_start'] ); ?>"
				data-server-time="<?php echo esc_attr( (string) ( $progress['server_time'] ?? gmdate( 'c' ) ) ); ?>"
			>
				<?php echo esc_html__( 'Match starts soon.', 'giving-day-blocks' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
