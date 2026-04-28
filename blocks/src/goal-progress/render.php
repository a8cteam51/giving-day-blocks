<?php
/**
 * Server render: giving-day/goal-progress.
 *
 * Emits an a11y-correct progressbar markup for SEO / no-JS visitors;
 * view.js then hydrates the bar in place and keeps it live.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Rendered inner content (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\Colors;
use Team51\GivingDay\Data\GoalProgress;

defined( 'ABSPATH' ) || exit;

$campaign_id = isset( $attributes['campaignId'] ) ? (int) $attributes['campaignId'] : 0;
if ( $campaign_id <= 0 ) {
	return;
}

$progress = GoalProgress::resolve( GoalProgress::TYPE_CAMPAIGN, $campaign_id );
if ( null === $progress ) {
	return;
}

$orientation     = isset( $attributes['orientation'] ) && 'vertical' === $attributes['orientation'] ? 'vertical' : 'horizontal';
$show_percent    = ! empty( $attributes['showPercent'] );
$show_raised     = ! empty( $attributes['showRaised'] );
$show_goal       = ! empty( $attributes['showGoal'] );
$show_donors     = ! empty( $attributes['showDonorCount'] );
$animate_bar     = ! empty( $attributes['animateBar'] );
$animate_numbers = ! empty( $attributes['animateNumbers'] );

$goal      = (float) $progress['goal'];
$raised    = (float) $progress['raised'];
$donors    = (int) $progress['donor_count'];
$currency  = (string) $progress['currency'];
$percent   = (float) $progress['percent'];
$has_goal  = $goal > 0;

$labels = array(
	'of'     => __( 'of', 'giving-day-blocks' ),
	'donors' => __( 'donors', 'giving-day-blocks' ),
);

// Aria-valuetext renders the bar's meaning to screen readers as a single
// human sentence; the visual labels below complement (not duplicate) it.
// Only meaningful when a goal exists — otherwise the bar drops its
// progressbar role entirely (see track markup below) and the raised total
// alone carries the announcement.
$aria_value_text = $has_goal
	? sprintf(
		/* translators: 1: raised amount, 2: goal amount, 3: percent. */
		__( '%1$s raised of %2$s, %3$s%%.', 'giving-day-blocks' ),
		GoalProgress::format_currency( $raised, $currency ),
		GoalProgress::format_currency( $goal, $currency ),
		(int) round( $percent )
	)
	: '';

// Seed view.js so it can hydrate without a flash, and so a failed first
// fetch still renders the SSR numbers. Mirrors /campaign/{id}/summary.
$initial = array(
	'raised'      => $raised,
	'goal'        => $goal,
	'currency'    => $currency,
	'donor_count' => $donors,
	'percent'     => $percent,
);

// `is-bar-animated` / `is-numbers-animated` are exposed as theme hooks; the
// actual animation runs client-side in view.js (rAF + ease-out via
// useCountUp) and already honors `prefers-reduced-motion`, so themes don't
// need to layer CSS transitions on top.
$wrapper_extra = array(
	'class'                 => trim(
		sprintf(
			'giving-day-goal-progress giving-day-goal-progress--%s%s%s',
			esc_attr( $orientation ),
			$animate_bar ? ' is-bar-animated' : '',
			$animate_numbers ? ' is-numbers-animated' : ''
		)
	),
	'data-campaign-id'      => (string) $campaign_id,
	'data-orientation'      => $orientation,
	'data-animate-bar'      => $animate_bar ? '1' : '0',
	'data-animate-numbers'  => $animate_numbers ? '1' : '0',
	'data-show-percent'     => $show_percent ? '1' : '0',
	'data-show-raised'      => $show_raised ? '1' : '0',
	'data-show-goal'        => $show_goal ? '1' : '0',
	'data-show-donor-count' => $show_donors ? '1' : '0',
	'data-labels'           => esc_attr( wp_json_encode( $labels ) ),
	'data-initial'          => esc_attr( wp_json_encode( $initial ) ),
);

$color_style = Colors::inline_style( $campaign_id );
if ( '' !== $color_style ) {
	$wrapper_extra['style'] = $color_style;
}

$wrapper_attrs = get_block_wrapper_attributes( $wrapper_extra );

$fill_inline_style = $orientation === 'vertical'
	? sprintf( 'height: %s%%;', $percent )
	: sprintf( 'width: %s%%;', $percent );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes internally. ?>>
	<div class="giving-day-goal-progress__inner">
		<?php if ( $show_raised ) : ?>
			<p class="giving-day-goal-progress__raised" data-role="raised">
				<?php echo esc_html( GoalProgress::format_currency( $raised, $currency ) ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $has_goal ) : ?>
			<div
				class="giving-day-goal-progress__track"
				role="progressbar"
				aria-valuenow="<?php echo esc_attr( (int) round( $percent ) ); ?>"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuetext="<?php echo esc_attr( $aria_value_text ); ?>"
			>
				<span
					class="giving-day-goal-progress__fill"
					style="<?php echo esc_attr( $fill_inline_style ); ?>"
					data-role="fill"
					aria-hidden="true"
				></span>
			</div>
		<?php else : ?>
			<div
				class="giving-day-goal-progress__track"
				data-role="track-no-goal"
				aria-hidden="true"
			>
				<span
					class="giving-day-goal-progress__fill"
					style="<?php echo esc_attr( $fill_inline_style ); ?>"
					data-role="fill"
				></span>
			</div>
		<?php endif; ?>

		<div class="giving-day-goal-progress__meta">
			<?php if ( $has_goal && $show_goal ) : ?>
				<span class="giving-day-goal-progress__goal" data-role="goal">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: formatted goal amount */
							__( 'of %s', 'giving-day-blocks' ),
							GoalProgress::format_currency( $goal, $currency )
						)
					);
					?>
				</span>
			<?php endif; ?>

			<?php if ( $has_goal && $show_percent ) : ?>
				<span class="giving-day-goal-progress__percent" data-role="percent">
					<?php echo esc_html( (int) round( $percent ) ); ?>%
				</span>
			<?php endif; ?>

			<?php if ( $show_donors && $donors > 0 ) : ?>
				<span class="giving-day-goal-progress__donors" data-role="donors">
					<?php
					echo esc_html(
						sprintf(
							'%s %s',
							number_format_i18n( $donors ),
							__( 'donors', 'giving-day-blocks' )
						)
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	</div>
</div>
