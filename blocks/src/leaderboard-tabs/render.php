<?php
/**
 * Server render: giving-day/leaderboard-tabs.
 *
 * @var array         $attributes
 * @var string        $content Inner blocks HTML.
 * @var WP_Block       $block
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\Context;
use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

$campaign_id = isset( $attributes['campaignId'] ) ? (int) $attributes['campaignId'] : 0;

if ( $campaign_id <= 0 ) {
	$wrapper = get_block_wrapper_attributes(
		array(
			'class' => 'giving-day-leaderboard-tabs giving-day-leaderboard-tabs--no-campaign',
		)
	);
	printf(
		'<div %1$s>%2$s</div>',
		$wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
	return;
}

$campaign = get_post( $campaign_id );
if ( ! $campaign || Campaign::POST_TYPE !== $campaign->post_type ) {
	$wrapper = get_block_wrapper_attributes(
		array(
			'class' => 'giving-day-leaderboard-tabs giving-day-leaderboard-tabs--no-campaign',
		)
	);
	printf(
		'<div %1$s>%2$s</div>',
		$wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
	return;
}

Context::set( $campaign_id );

$default_tab = isset( $attributes['defaultTabIndex'] ) ? (int) $attributes['defaultTabIndex'] : 0;
if ( $default_tab < 0 ) {
	$default_tab = 0;
}

$wrapper = get_block_wrapper_attributes(
	array(
		'class'               => 'giving-day-leaderboard-tabs',
		'data-campaign-id'    => (string) $campaign_id,
		'data-default-tab'    => (string) $default_tab,
	)
);

printf(
	'<div %1$s>%2$s</div>',
	$wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
);
