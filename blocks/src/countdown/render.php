<?php
/**
 * Server render: giving-day/countdown.
 *
 * Renders the initial state markup for SEO / no-JS, then view.js hydrates
 * and keeps the block live.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Rendered inner content (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\Colors;
use Team51\GivingDay\Data\Context;
use Team51\GivingDay\Data\Status;
use Team51\GivingDay\PostTypes\Campaign;

defined('ABSPATH') || exit;

$campaign_id = isset($attributes['campaignId']) ? (int) $attributes['campaignId'] : 0;
if ($campaign_id <= 0 ) {
    if (is_admin() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
        echo '<div class="giving-day-countdown giving-day-countdown--empty">'
        . esc_html__('Giving Day: Countdown — pick a campaign in the sidebar.', 'giving-day-blocks')
        . '</div>';
    }
    return;
}

$campaign = get_post($campaign_id);
if (! $campaign || Campaign::POST_TYPE !== $campaign->post_type ) {
    return;
}

Context::set( $campaign_id );

$status          = Status::resolve($campaign_id);
$hide_post_event = ! empty($attributes['hidePostEvent']);
if (Status::ENDED === $status && $hide_post_event ) {
    return;
}
if (Status::IDLE === $status ) {
    return;
}

$pre_headline   = isset($attributes['preHeadline']) ? (string) $attributes['preHeadline'] : '';
$live_headline  = isset($attributes['liveHeadline']) ? (string) $attributes['liveHeadline'] : '';
$ended_headline = isset($attributes['endedHeadline']) ? (string) $attributes['endedHeadline'] : '';
$show_donors    = ! empty($attributes['showDonorCount']);

$goal          = (float) get_post_meta($campaign_id, Campaign::META_GOAL_AMOUNT, true);
$raised        = (float) get_post_meta($campaign_id, Campaign::META_RAISED_OVERRIDE, true);
$donors        = (int) get_post_meta($campaign_id, Campaign::META_DONOR_COUNT_OVERRIDE, true);
$currency_meta = get_post_meta($campaign_id, Campaign::META_CURRENCY, true);
$currency      = (string) ( '' !== $currency_meta ? $currency_meta : get_option('woocommerce_currency', 'USD') );

$start = get_post_meta($campaign_id, Campaign::META_START_DATETIME, true);
$end   = get_post_meta($campaign_id, Campaign::META_END_DATETIME, true);

// Status::resolve() can land on SCHEDULED/LIVE without the matching boundary
// datetime (preview override, _giving_status_override meta, or a pre-event
// window with no explicit start). Resolve and validate the countdown target
// here so we never ship markup with an empty data-target.
$target = '';
if (Status::SCHEDULED === $status ) {
    $target = (string) $start;
} elseif (Status::LIVE === $status ) {
    $target = (string) $end;
}
$has_target = '' !== $target && false !== strtotime($target);

$headline = '';
switch ( $status ) {
case Status::SCHEDULED:
    $headline = $pre_headline;
    break;
case Status::LIVE:
    $headline = $live_headline;
    break;
case Status::ENDED:
    $headline = $ended_headline;
    break;
}

$labels = array(
    'days'               => __('days', 'giving-day-blocks'),
    'hours'              => __('hours', 'giving-day-blocks'),
    'minutes'            => __('minutes', 'giving-day-blocks'),
    'seconds'            => __('seconds', 'giving-day-blocks'),
    'of'                 => __('of', 'giving-day-blocks'),
    'donors'             => __('donors', 'giving-day-blocks'),
    'raisedTowardGoalOf' => __('raised toward a goal of', 'giving-day-blocks'),
);

$wrapper_extra = array(
    'class'                 => sprintf('giving-day-countdown giving-day-countdown--%s', esc_attr($status)),
    'data-status'           => esc_attr($status),
    'data-campaign-id'      => (string) $campaign_id,
    'data-hide-post-event'  => $hide_post_event ? '1' : '0',
    'data-pre-headline'     => esc_attr($pre_headline),
    'data-live-headline'    => esc_attr($live_headline),
    'data-ended-headline'   => esc_attr($ended_headline),
    'data-show-donor-count' => $show_donors ? '1' : '0',
    'data-start'            => esc_attr((string) $start),
    'data-end'              => esc_attr((string) $end),
    'data-labels'           => esc_attr(wp_json_encode($labels)),
);

$color_style = Colors::inline_style($campaign_id);
if ('' !== $color_style) {
    $wrapper_extra['style'] = $color_style;
}

$wrapper_attrs = get_block_wrapper_attributes($wrapper_extra);

$formatter = static function ( $amount ) use ( $currency ) {
    if (function_exists('wc_price') ) {
        return wp_strip_all_tags(wc_price($amount, array( 'currency' => $currency )));
    }
    if (class_exists('NumberFormatter') ) {
        $fmt = new NumberFormatter(get_locale(), NumberFormatter::CURRENCY);
        return $fmt->formatCurrency((float) $amount, $currency);
    }
    return sprintf('%s %s', $currency, number_format_i18n((float) $amount));
};

?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes internally. ?>>
    <div class="giving-day-countdown__body">
        <?php if ($headline ) : ?>
            <p class="giving-day-countdown__headline"><?php echo esc_html($headline); ?></p>
        <?php endif; ?>

        <?php if ($has_target && Status::SCHEDULED === $status ) : ?>
            <div class="giving-day-countdown__countdown" data-target="<?php echo esc_attr($target); ?>">
                <noscript><?php echo esc_html(sprintf(/* translators: %s: ISO datetime */ __('Starts %s', 'giving-day-blocks'), $target)); ?></noscript>
            </div>
        <?php elseif ($has_target && Status::LIVE === $status ) : ?>
            <div class="giving-day-countdown__countdown" data-target="<?php echo esc_attr($target); ?>">
                <noscript><?php echo esc_html(sprintf(/* translators: %s: ISO datetime */ __('Ends %s', 'giving-day-blocks'), $target)); ?></noscript>
            </div>
        <?php else : ?>
            <p class="giving-day-countdown__final"><?php echo esc_html($formatter($raised)); ?></p>
            <p class="giving-day-countdown__final-meta">
            <?php echo esc_html__('raised toward a goal of', 'giving-day-blocks'); ?>
            <?php echo esc_html($formatter($goal)); ?>
            </p>
            <?php if ($show_donors && $donors > 0 ) : ?>
                <p class="giving-day-countdown__donors"><?php echo esc_html(number_format_i18n($donors) . ' ' . __('donors', 'giving-day-blocks')); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
