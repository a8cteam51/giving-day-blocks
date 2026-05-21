<?php
/**
 * Server render: giving-day/war-room.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

$campaign_id = isset( $attributes['campaignId'] ) ? (int) $attributes['campaignId'] : 0;
if ( $campaign_id <= 0 ) {
	return;
}

$campaign = get_post( $campaign_id );
if ( ! $campaign || Campaign::POST_TYPE !== $campaign->post_type ) {
	return;
}

// The war room surfaces donor display names + recent order rows; the front
// end gets nothing unless the viewer is also someone who could read this
// data directly from WooCommerce. Same cap the /warroom REST endpoint
// guards on, so the gate is consistent across surfaces.
if ( ! current_user_can( 'manage_woocommerce' ) ) {
	return;
}

$panels = isset( $attributes['panels'] ) && is_array( $attributes['panels'] )
	? array_values( array_filter( $attributes['panels'], 'is_string' ) )
	: array();

$refresh_ms = isset( $attributes['refreshInterval'] ) ? max( 5000, (int) $attributes['refreshInterval'] ) : 10000;

$initial = Aggregator::warroom_payload( $campaign_id );

$extra = array(
	'class'                   => 'giving-day-warroom',
	'data-giving-day-warroom' => '1',
	'data-campaign-id'        => (string) $campaign_id,
	'data-panels'             => esc_attr( wp_json_encode( $panels ) ),
	'data-refresh-ms'         => (string) $refresh_ms,
	'data-initial'            => esc_attr( wp_json_encode( $initial ) ),
);

$wrapper_attrs = get_block_wrapper_attributes( $extra );

?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<p class="giving-day-warroom__loading"><?php esc_html_e( 'Loading war room…', 'giving-day-blocks' ); ?></p>
</div>
