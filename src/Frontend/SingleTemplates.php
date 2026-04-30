<?php
/**
 * Front-end single templates for public Giving Day CPTs.
 *
 * @package Team51\GivingDay\Frontend
 * @since   0.1.0
 */

namespace Team51\GivingDay\Frontend;

use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the same template hierarchy entry as Pages (`page.php` / `page` block
 * template) before the generic `single.php` / `single` fallback, so Team and
 * Beneficiary singles match normal page layout. Theme-specific
 * `single-{post_type}.php` / `single-{post_type}` remain higher priority.
 */
final class SingleTemplates {

	/**
	 * @var list<string>
	 */
	private const POST_TYPES = array(
		Beneficiary::POST_TYPE,
		Team::POST_TYPE,
	);

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'single_template_hierarchy', array( self::class, 'filter_single_template_hierarchy' ) );
	}

	/**
	 * @param string[] $templates Hierarchy filenames passed to {@see locate_template()}.
	 * @return string[]
	 */
	public static function filter_single_template_hierarchy( array $templates ): array {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return $templates;
		}

		$pos = array_search( 'single.php', $templates, true );
		if ( false !== $pos ) {
			array_splice( $templates, $pos, 0, array( 'page.php' ) );
			return $templates;
		}

		$templates[] = 'page.php';
		return $templates;
	}
}
