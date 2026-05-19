<?php
/**
 * Plugin-shipped FSE block templates for single Team and Beneficiary posts.
 *
 * Registers two templates via register_block_template() (WP 6.7+). Themes
 * may override by shipping their own single-team.html / single-beneficiary.html.
 *
 * @package Team51\GivingDay\Setup
 * @since   1.1.0
 */

namespace Team51\GivingDay\Setup;

defined( 'ABSPATH' ) || exit;

final class BlockTemplates {

	public function register(): void {
		add_action( 'init', array( $this, 'register_templates' ), 20 );
	}

	public function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		$dir = dirname( __DIR__, 2 ) . '/templates';

		register_block_template(
			'giving-day-blocks//single-team',
			array(
				'title'       => __( 'Single Team (Giving Day)', 'giving-day-blocks' ),
				'description' => __( 'Default template for Team posts: title, goal, donate button, content.', 'giving-day-blocks' ),
				'content'     => (string) file_get_contents( $dir . '/single-team.html' ),
				'post_types'  => array( \Team51\GivingDay\PostTypes\Team::POST_TYPE ),
			)
		);

		register_block_template(
			'giving-day-blocks//single-beneficiary',
			array(
				'title'       => __( 'Single Beneficiary (Giving Day)', 'giving-day-blocks' ),
				'description' => __( 'Default template for Beneficiary posts: title, goal, donate button, content.', 'giving-day-blocks' ),
				'content'     => (string) file_get_contents( $dir . '/single-beneficiary.html' ),
				'post_types'  => array( \Team51\GivingDay\PostTypes\Beneficiary::POST_TYPE ),
			)
		);
	}
}
