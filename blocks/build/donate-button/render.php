<?php
/**
 * Server render: giving-day/donate-button.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\DonationUrl;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Team;

defined( 'ABSPATH' ) || exit;

$label       = isset( $attributes['label'] ) && '' !== $attributes['label']
	? (string) $attributes['label']
	: __( 'Donate', 'giving-day-blocks' );
$target_type = isset( $attributes['targetType'] ) ? (string) $attributes['targetType'] : 'auto';
$target_id   = isset( $attributes['targetId'] ) ? (int) $attributes['targetId'] : 0;

if ( 'auto' === $target_type ) {
	$post = get_post();
	if ( $post ) {
		switch ( $post->post_type ) {
			case Team::POST_TYPE:
				$target_type = DonationUrl::TYPE_TEAM;
				$target_id   = (int) $post->ID;
				break;
			case Beneficiary::POST_TYPE:
				$target_type = DonationUrl::TYPE_BENEFICIARY;
				$target_id   = (int) $post->ID;
				break;
			default:
				$target_type = DonationUrl::TYPE_NONE;
				$target_id   = 0;
		}
	} else {
		$target_type = DonationUrl::TYPE_NONE;
		$target_id   = 0;
	}
}

$href = DonationUrl::build( $target_type, $target_id );

$wrapper_attrs = get_block_wrapper_attributes(
	array(
		'class' => 'wp-block-button giving-day-donate-button',
	)
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes escapes internally. ?>>
	<a class="giving-day-donate-button__link wp-block-button__link wp-element-button" href="<?php echo esc_url( $href ); ?>">
		<?php echo wp_kses_post( $label ); ?>
	</a>
</div>
