<?php
/**
 * Adds the "Donation page" setting under Settings → Reading.
 *
 * @package Team51\GivingDay\Admin
 * @since   1.1.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Data\DonationUrl;

defined( 'ABSPATH' ) || exit;

final class DonationPageSetting {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	public function register_setting(): void {
		register_setting(
			'reading',
			DonationUrl::OPTION_PAGE_ID,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
				'show_in_rest'      => false,
			)
		);

		add_settings_field(
			DonationUrl::OPTION_PAGE_ID,
			__( 'Donation page', 'giving-day-blocks' ),
			array( $this, 'render_field' ),
			'reading',
			'default'
		);
	}

	public function render_field(): void {
		$current = (int) get_option( DonationUrl::OPTION_PAGE_ID, 0 );

		wp_dropdown_pages(
			array(
				'name'              => DonationUrl::OPTION_PAGE_ID,
				'selected'          => $current,
				'show_option_none'  => __( '— Use /donate (theme default) —', 'giving-day-blocks' ),
				'option_none_value' => 0,
			)
		);

		echo '<p class="description">' . esc_html__(
			'Donate buttons in giving-day blocks link to this page. Leave unset to use /donate.',
			'giving-day-blocks'
		) . '</p>';
	}
}
