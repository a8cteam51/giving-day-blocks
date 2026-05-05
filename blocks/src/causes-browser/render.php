<?php
/**
 * Server render: giving-day/causes-browser.
 *
 * Emits the initial top-level Cause Areas grid for SEO / no-JS, plus a
 * heading and search input. view.js hydrates and replaces the content area
 * with an interactive drill-down + search experience that talks to
 * /giving-day/v1/cause-areas and /giving-day/v1/cause-areas/beneficiaries.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Rendered inner content (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\Taxonomies\Cause;

defined( 'ABSPATH' ) || exit;

$heading            = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
$columns_desktop    = isset( $attributes['columnsDesktop'] ) ? (int) $attributes['columnsDesktop'] : 4;
$columns_desktop    = max( 1, min( 6, $columns_desktop ) );
$show_counts        = ! empty( $attributes['showCounts'] );
$search_placeholder = isset( $attributes['searchPlaceholder'] ) && '' !== $attributes['searchPlaceholder']
	? (string) $attributes['searchPlaceholder']
	: __( 'Search beneficiaries', 'giving-day-blocks' );

// Per-instance DOM id so two browser blocks on one page don't collide on
// label/input pairing. Threaded through to the hydration config so the
// React app reuses the same id after mount, avoiding a flash of mismatched
// `for`/`id` attributes during SSR → hydration.
$search_input_id = wp_unique_id( 'giving-day-cause-areas-search-' );

$top_terms = get_terms(
	array(
		'taxonomy'   => Cause::TAXONOMY,
		'parent'     => 0,
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
	)
);

$labels = array(
	'rootBreadcrumb'       => __( 'Cause Areas', 'giving-day-blocks' ),
	'noResults'            => __( 'No results found.', 'giving-day-blocks' ),
	'loading'              => __( 'Loading…', 'giving-day-blocks' ),
	'beneficiariesLabel'   => __( 'beneficiaries', 'giving-day-blocks' ),
	'beneficiaryLabel'     => __( 'beneficiary', 'giving-day-blocks' ),
	'searchAllPlaceholder' => $search_placeholder,
	'errorLoading'         => __( 'Could not load results. Please try again.', 'giving-day-blocks' ),
);

$config = array(
	'restNamespace'         => 'giving-day/v1',
	'columnsDesktop'        => $columns_desktop,
	'showCounts'            => (bool) $show_counts,
	'beneficiariesPostType' => Beneficiary::POST_TYPE,
	'searchInputId'         => $search_input_id,
	'labels'                => $labels,
);

$wrapper_extra = array(
	'class'                 => 'giving-day-cause-areas',
	'data-cause-areas-root' => '1',
	'data-config'           => wp_json_encode( $config ),
	'style'                 => '--giving-day-cause-columns: ' . (int) $columns_desktop . ';',
);

$wrapper_attrs = get_block_wrapper_attributes( $wrapper_extra );
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via get_block_wrapper_attributes(). ?>>
	<?php if ( '' !== $heading ) : ?>
		<div class="giving-day-cause-areas__heading-row">
			<h2 class="giving-day-cause-areas__heading"><?php echo esc_html( $heading ); ?></h2>
		</div>
	<?php endif; ?>

	<div class="giving-day-cause-areas__search" data-pre-hydrate="1">
		<label for="<?php echo esc_attr( $search_input_id ); ?>" class="screen-reader-text">
			<?php echo esc_html( $search_placeholder ); ?>
		</label>
		<input
			type="search"
			id="<?php echo esc_attr( $search_input_id ); ?>"
			class="giving-day-cause-areas__search-input"
			name="search"
			placeholder="<?php echo esc_attr( $search_placeholder ); ?>"
			autocomplete="off"
			disabled
			aria-disabled="true"
		/>
	</div>

	<noscript>
		<p class="giving-day-cause-areas__noscript">
			<?php esc_html_e( 'Browsing Cause Areas requires JavaScript. Please enable it to filter and search.', 'giving-day-blocks' ); ?>
		</p>
	</noscript>

	<div class="giving-day-cause-areas__viewport" data-pre-hydrate="1" aria-busy="true">
		<div class="giving-day-cause-areas__grid" data-initial-view="causes">
			<?php
			if ( is_array( $top_terms ) && count( $top_terms ) > 0 ) :
				foreach ( $top_terms as $cause_term ) :
					if ( ! $cause_term instanceof WP_Term ) {
						continue;
					}
					$image_url = Cause::get_image_url( (int) $cause_term->term_id );
					$count     = $show_counts ? Cause::count_beneficiaries( (int) $cause_term->term_id, true ) : 0;
					?>
					<button
						type="button"
						class="giving-day-cause-areas__card"
						data-cause-id="<?php echo esc_attr( (string) $cause_term->term_id ); ?>"
						data-cause-name="<?php echo esc_attr( html_entity_decode( $cause_term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?>"
						disabled
						aria-disabled="true"
						tabindex="-1"
					>
						<span class="giving-day-cause-areas__card-image">
							<?php if ( '' !== $image_url ) : ?>
								<img src="<?php echo esc_url( $image_url ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="giving-day-cause-areas__card-image-fallback" aria-hidden="true"></span>
							<?php endif; ?>
						</span>
						<span class="giving-day-cause-areas__card-body">
							<span class="giving-day-cause-areas__card-name"><?php echo esc_html( html_entity_decode( $cause_term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?></span>
							<?php if ( $show_counts ) : ?>
								<span class="giving-day-cause-areas__card-count">
									<?php
									printf(
										/* translators: %s: formatted beneficiary count */
										esc_html( _n( '%s beneficiary', '%s beneficiaries', $count, 'giving-day-blocks' ) ),
										esc_html( number_format_i18n( $count ) )
									);
									?>
								</span>
							<?php endif; ?>
						</span>
					</button>
					<?php
				endforeach;
			else :
				?>
				<p class="giving-day-cause-areas__empty">
					<?php esc_html_e( 'No Cause Areas have been published yet.', 'giving-day-blocks' ); ?>
				</p>
				<?php
			endif;
			?>
		</div>
	</div>
</div>
