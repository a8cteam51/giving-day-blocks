<?php
/**
 * Server render: giving-day/leaderboard.
 *
 * @var array         $attributes
 * @var string        $content
 * @var WP_Block       $block
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\Context;
use Team51\GivingDay\Data\Leaderboard;
use Team51\GivingDay\PostTypes\Campaign;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'giving_day_blocks_render_leaderboard_row' ) ) {
	/**
	 * Renders one leaderboard row (SSR).
	 *
	 * @param array<string,mixed> $row Row payload.
	 * @param bool                $show_amount Show amount column.
	 * @param bool                $show_avatar Show avatar column.
	 * @param callable            $price_fmt Currency formatter.
	 */
	function giving_day_blocks_render_leaderboard_row( array $row, bool $show_amount, bool $show_avatar, callable $price_fmt ): void {
	$rank   = isset( $row['rank'] ) ? (int) $row['rank'] : 0;
	$label  = isset( $row['label'] ) ? (string) $row['label'] : '';
	$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
	$avatar = isset( $row['avatar_url'] ) ? (string) $row['avatar_url'] : '';
	echo '<li class="giving-day-leaderboard__row">';
	echo '<span class="giving-day-leaderboard__rank" aria-hidden="true">' . esc_html( (string) $rank ) . '</span>';
	if ( $show_avatar ) {
		echo '<span class="giving-day-leaderboard__avatar-wrap">';
		if ( $avatar !== '' ) {
			echo '<img src="' . esc_url( $avatar ) . '" alt="" class="giving-day-leaderboard__avatar" width="40" height="40" loading="lazy" decoding="async" />';
		} else {
			echo '<span class="giving-day-leaderboard__avatar giving-day-leaderboard__avatar--placeholder" aria-hidden="true"></span>';
		}
		echo '</span>';
	}
	echo '<span class="giving-day-leaderboard__label">' . esc_html( $label ) . '</span>';
	if ( $show_amount ) {
		echo '<span class="giving-day-leaderboard__amount">' . esc_html( $price_fmt( $amount ) ) . '</span>';
	}
	echo '</li>';
	}
}

$campaign_id = isset( $attributes['campaignId'] ) ? (int) $attributes['campaignId'] : 0;
$ctx_parent  = isset( $block->context['giving-day/parentCampaignId'] )
	? (int) $block->context['giving-day/parentCampaignId']
	: 0;
if ( $campaign_id <= 0 && $ctx_parent > 0 ) {
	$campaign_id = $ctx_parent;
}

if ( $campaign_id <= 0 ) {
	return;
}

$campaign = get_post( $campaign_id );
if (
	! $campaign
	|| Campaign::POST_TYPE !== $campaign->post_type
	|| ( 'publish' !== $campaign->post_status && ! current_user_can( 'read_post', $campaign->ID ) )
) {
	return;
}

Context::set( $campaign_id );

$dimension = isset( $attributes['dimension'] ) ? (string) $attributes['dimension'] : Leaderboard::DIMENSION_TEAMS;
if ( ! in_array( $dimension, array( Leaderboard::DIMENSION_DONORS, Leaderboard::DIMENSION_TEAMS, Leaderboard::DIMENSION_BENEFICIARIES, Leaderboard::DIMENSION_CAUSES ), true ) ) {
	$dimension = Leaderboard::DIMENSION_TEAMS;
}

$limit                 = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 10;
$filter_term_id        = isset( $attributes['filterTermId'] ) ? (int) $attributes['filterTermId'] : 0;
$group_by_parent       = isset( $attributes['groupByParentTermId'] ) ? (int) $attributes['groupByParentTermId'] : 0;
$show_amount           = ! empty( $attributes['showAmount'] );
$show_avatar           = ! empty( $attributes['showAvatar'] );
$anonymize             = ! empty( $attributes['anonymize'] );
$refresh_interval      = isset( $attributes['refreshInterval'] ) ? (int) $attributes['refreshInterval'] : 15000;
$tab_label             = isset( $attributes['tabLabel'] ) ? (string) $attributes['tabLabel'] : '';

$args = array(
	'filter_term_id'            => $filter_term_id,
	'group_by_parent_term_id'   => $group_by_parent,
);

$data = Leaderboard::fetch( $campaign_id, $dimension, $limit, $args );

if ( isset( $data['error'] ) && 'invalid_group_parent' === $data['error'] ) {
	$data['groups'] = array();
}

if ( Leaderboard::DIMENSION_DONORS === $dimension && $anonymize ) {
	if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
		$data['rows'] = Leaderboard::apply_anonymize( $data['rows'], true );
	}
	if ( isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
		foreach ( $data['groups'] as &$grp ) {
			if ( isset( $grp['rows'] ) && is_array( $grp['rows'] ) ) {
				$grp['rows'] = Leaderboard::apply_anonymize( $grp['rows'], true );
			}
		}
		unset( $grp );
	}
}

$currency = isset( $data['currency'] ) ? (string) $data['currency'] : get_option( 'woocommerce_currency', 'USD' );

$price_fmt = static function ( $amount ) use ( $currency ) {
	if ( function_exists( 'wc_price' ) ) {
		return wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $currency ) ) );
	}
	return sprintf( '%s %s', esc_html( $currency ), esc_html( number_format_i18n( (float) $amount, 2 ) ) );
};

$query_payload = array(
	'dimension'             => $dimension,
	'limit'                 => $limit,
	'filterTermId'          => $filter_term_id,
	'groupByParentTermId'   => $group_by_parent,
	'anonymize'             => $anonymize,
);
$list_class = 'giving-day-leaderboard__list' . ( $show_avatar ? '' : ' giving-day-leaderboard__list--no-avatar' );

$wrapper_attrs = get_block_wrapper_attributes(
	array(
		'class'                     => 'giving-day-leaderboard',
		'data-campaign-id'         => (string) $campaign_id,
		'data-dimension'           => esc_attr( $dimension ),
		'data-limit'               => (string) $limit,
		'data-filter-term-id'      => (string) $filter_term_id,
		'data-group-by-parent'     => (string) $group_by_parent,
		'data-anonymize'           => $anonymize ? '1' : '0',
		'data-show-amount'         => $show_amount ? '1' : '0',
		'data-show-avatar'         => $show_avatar ? '1' : '0',
		'data-refresh-ms'          => (string) max( 5000, $refresh_interval ),
		'data-tab-label'           => esc_attr( $tab_label ),
		'data-tab-slug'            => esc_attr( sanitize_title( '' !== $tab_label ? $tab_label : 'tab-' . (string) $campaign_id ) ),
		'data-initial'             => esc_attr( wp_json_encode( $data ) ),
		'data-query'               => esc_attr( wp_json_encode( $query_payload ) ),
	)
);

?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="giving-day-leaderboard__inner">
		<?php
		if ( ! empty( $data['groups'] ) && is_array( $data['groups'] ) ) :
			foreach ( $data['groups'] as $group ) :
				$term_name = isset( $group['term']['name'] ) ? (string) $group['term']['name'] : '';
				$g_rows    = isset( $group['rows'] ) && is_array( $group['rows'] ) ? $group['rows'] : array();
				?>
				<section class="giving-day-leaderboard__group">
					<?php if ( $term_name !== '' ) : ?>
						<h3 class="giving-day-leaderboard__group-title"><?php echo esc_html( $term_name ); ?></h3>
					<?php endif; ?>
					<?php if ( array() === $g_rows ) : ?>
						<p class="giving-day-leaderboard__empty"><?php esc_html_e( 'No entries yet.', 'giving-day-blocks' ); ?></p>
					<?php else : ?>
						<ol class="<?php echo esc_attr( $list_class ); ?>">
							<?php
							foreach ( $g_rows as $row ) {
								giving_day_blocks_render_leaderboard_row( $row, $show_amount, $show_avatar, $price_fmt );
							}
							?>
						</ol>
					<?php endif; ?>
				</section>
				<?php
			endforeach;
		elseif ( ! empty( $data['rows'] ) && is_array( $data['rows'] ) ) :
			?>
			<ol class="<?php echo esc_attr( $list_class ); ?>">
				<?php
				foreach ( $data['rows'] as $row ) {
					giving_day_blocks_render_leaderboard_row( $row, $show_amount, $show_avatar, $price_fmt );
				}
				?>
			</ol>
			<?php
		else :
			?>
			<p class="giving-day-leaderboard__empty"><?php esc_html_e( 'No leaderboard data yet.', 'giving-day-blocks' ); ?></p>
		<?php endif; ?>
	</div>
</div>
