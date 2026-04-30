<?php
/**
 * Context copy on Giving Day list and taxonomy admin screens.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.1.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Challenge;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamGroup;

defined( 'ABSPATH' ) || exit;

/**
 * Injects a short orientation paragraph below the list table heading row.
 */
final class ScreenIntro {

	private const HR_NEEDLE = '<hr class="wp-header-end">';

	/**
	 * Buffered HTML fragment to inject after {@see self::HR_NEEDLE}.
	 *
	 * @var string|null
	 */
	private static $pending_fragment = null;

	public function register(): void {
		add_action( 'load-edit.php', array( self::class, 'on_load_edit' ) );
		add_action( 'load-edit-tags.php', array( self::class, 'on_load_edit_tags' ) );
		add_action( 'admin_head', array( $this, 'print_styles' ) );
	}

	/**
	 * @return void
	 */
	public static function on_load_edit(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		$text = self::intro_text_for_post_type_list( $screen->post_type );
		if ( null === $text ) {
			return;
		}

		self::$pending_fragment = self::build_fragment( $text );
		ob_start( array( self::class, 'inject_into_buffer' ) );
	}

	/**
	 * @return void
	 */
	public static function on_load_edit_tags(): void {
		global $pagenow;
		// `load-edit-tags.php` also runs for `term.php` (single-term editor); only buffer the list screen.
		if ( 'edit-tags.php' !== $pagenow ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-tags' !== $screen->base ) {
			return;
		}

		$text = self::intro_text_for_taxonomy_list( $screen->taxonomy, $screen->post_type );
		if ( null === $text ) {
			return;
		}

		self::$pending_fragment = self::build_fragment( $text );
		ob_start( array( self::class, 'inject_into_buffer' ) );
	}

	/**
	 * @return string
	 */
	public static function inject_into_buffer( string $buffer, int $phase = 0 ): string {
		unset( $phase );
		if ( null === self::$pending_fragment ) {
			return $buffer;
		}

		$fragment               = self::$pending_fragment;
		self::$pending_fragment = null;

		$pos = strpos( $buffer, self::HR_NEEDLE );
		if ( false === $pos ) {
			return $buffer;
		}

		$end = $pos + strlen( self::HR_NEEDLE );
		return substr( $buffer, 0, $end ) . $fragment . substr( $buffer, $end );
	}

	/**
	 * @return void
	 */
	public function print_styles(): void {
		if ( null === self::current_list_intro_text() ) {
			return;
		}

		echo '<style id="giving-day-screen-intro-css">'
			. '.giving-day-screen-intro{max-width:72ch;margin:0.35em 0 1em;font-size:14px;line-height:1.55;color:#50575e;}'
			. '</style>';
	}

	/**
	 * Resolves intro text for admin_head (no buffer state).
	 *
	 * @return string|null
	 */
	private static function current_list_intro_text(): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return null;
		}

		if ( 'edit' === $screen->base ) {
			return self::intro_text_for_post_type_list( $screen->post_type );
		}

		if ( 'edit-tags' === $screen->base ) {
			return self::intro_text_for_taxonomy_list( $screen->taxonomy, $screen->post_type );
		}

		return null;
	}

	private static function intro_text_for_post_type_list( string $post_type ): ?string {
		switch ( $post_type ) {
			case Beneficiary::POST_TYPE:
				return __(
					'Each Beneficiary (also called a Fund) is where donations are booked—a scholarship, program, unit, or partner nonprofit. Tag funds with Causes so donors can browse by topic.',
					'giving-day-blocks'
				);
			case Team::POST_TYPE:
				return __(
					'Teams are the fundraising groups—classes, departments, clubs, or other teams competing for gifts. They represent who is raising money, such as "Class of 2012", "Women\'s Soccer", or "Downtown Lions Club".',
					'giving-day-blocks'
				);
			case GivingMatch::POST_TYPE:
				return __(
					'Matches are sponsor offers that amplify donations during a campaign window—for example dollar-for-dollar matching up to a cap, or a lump sum that unlocks after enough unique donors give.',
					'giving-day-blocks'
				);
			case Challenge::POST_TYPE:
				return __(
					'Challenges are time-boxed goals inside a campaign—such as hitting a dollar total or donation count in a set window. They create spikes of activity and often pair with sponsor rewards.',
					'giving-day-blocks'
				);
			default:
				return null;
		}
	}

	private static function intro_text_for_taxonomy_list( string $taxonomy, string $post_type ): ?string {
		if ( Cause::TAXONOMY === $taxonomy && Beneficiary::POST_TYPE === $post_type ) {
			return __(
				'Causes are broad mission areas (for example Arts or Engineering) used to organize Beneficiaries / Funds so donors can browse by topic. A Cause categorizes what a Fund supports, making it easier for donors to find Funds that match their interests.',
				'giving-day-blocks'
			);
		}

		if ( TeamGroup::TAXONOMY === $taxonomy && Team::POST_TYPE === $post_type ) {
			return __(
				'Team Groups are dimensions for sorting or tabbing leaderboards—such as Class year or Sport—with child terms as the buckets. Example of Team Groups are "Class Year", "Athletic", or "Civic & Service Clubs".',
				'giving-day-blocks'
			);
		}

		return null;
	}

	private static function build_fragment( string $text ): string {
		return '<p class="giving-day-screen-intro">' . esc_html( $text ) . '</p>';
	}
}
