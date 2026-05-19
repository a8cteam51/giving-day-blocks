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
		// Populated in Task 12.
	}
}
