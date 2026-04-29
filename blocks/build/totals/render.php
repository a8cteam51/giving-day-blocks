<?php
/**
 * Server render: giving-day/totals.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
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
    return;
}

Context::set( $campaign_id );

$campaign = get_post($campaign_id);
if (! $campaign || Campaign::POST_TYPE !== $campaign->post_type ) {
    return;
}

$mode   = isset($attributes['mode']) && 'final-only' === $attributes['mode'] ? 'final-only' : 'auto';
$status = Status::resolve($campaign_id);

if ('final-only' === $mode && Status::ENDED !== $status ) {
    // Emit a hidden shell so view.js can reveal the block if the state flips
    // (e.g. `?givingday=post` preview, or the event ends while the tab stays open).
    $hidden_shell = true;
} else {
    $hidden_shell = false;
}

$headline      = isset($attributes['headline']) ? (string) $attributes['headline'] : '';
$subhead       = isset($attributes['subhead']) ? (string) $attributes['subhead'] : '';
$show_goal     = ! empty($attributes['showGoal']);
$show_donors   = ! empty($attributes['showDonorCount']);

$goal     = (float) get_post_meta($campaign_id, Campaign::META_GOAL_AMOUNT, true);
$raised   = (float) get_post_meta($campaign_id, Campaign::META_RAISED_OVERRIDE, true);
$donors   = (int) get_post_meta($campaign_id, Campaign::META_DONOR_COUNT_OVERRIDE, true);
$currency_meta = get_post_meta($campaign_id, Campaign::META_CURRENCY, true);
$currency      = (string) ( $currency_meta !== '' ? $currency_meta : get_option('woocommerce_currency', 'USD') );

$labels = array(
    'of'     => __('of', 'giving-day-blocks'),
    'donors' => __('donors', 'giving-day-blocks'),
);

// Seeds useCampaignSummary() so the React mount matches the SSR markup
// and the last-known values survive a failed first fetch. Shape mirrors
// the /campaign/{id}/summary REST payload fields consumed by view.js.
$initial = array(
    'raised'      => $raised,
    'goal'        => $goal,
    'currency'    => $currency,
    'donor_count' => $donors,
);

$extra = array(
    'class'                 => 'giving-day-totals',
    'data-campaign-id'      => (string) $campaign_id,
    'data-mode'             => $mode,
    'data-show-goal'        => $show_goal ? '1' : '0',
    'data-show-donor-count' => $show_donors ? '1' : '0',
    'data-headline'         => esc_attr($headline),
    'data-subhead'          => esc_attr($subhead),
    'data-labels'           => esc_attr(wp_json_encode($labels)),
    'data-initial'          => esc_attr(wp_json_encode($initial)),
);
if ($hidden_shell ) {
    $extra['hidden'] = 'hidden';
}

$color_style = Colors::inline_style($campaign_id);
if ('' !== $color_style) {
    $extra['style'] = $color_style;
}

$wrapper_attrs = get_block_wrapper_attributes($extra);

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
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <div class="giving-day-totals__body">
        <?php if (! $hidden_shell ) : ?>
            <?php if ($headline ) : ?>
                <p class="giving-day-totals__headline"><?php echo esc_html($headline); ?></p>
            <?php endif; ?>
            <p class="giving-day-totals__amount"><?php echo esc_html($formatter($raised)); ?></p>
            <?php if ($show_goal && $goal > 0 ) : ?>
                <p class="giving-day-totals__goal">
                <?php echo esc_html__('of', 'giving-day-blocks'); ?>
                <?php echo esc_html($formatter($goal)); ?>
                </p>
            <?php endif; ?>
            <?php if ($show_donors && $donors > 0 ) : ?>
                <p class="giving-day-totals__donors">
                <?php echo esc_html(number_format_i18n($donors) . ' ' . __('donors', 'giving-day-blocks')); ?>
                </p>
            <?php endif; ?>
            <?php if ($subhead ) : ?>
                <p class="giving-day-totals__subhead"><?php echo esc_html($subhead); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
