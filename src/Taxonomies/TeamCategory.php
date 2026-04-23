<?php
/**
 * Team Category taxonomy.
 *
 * @package Team51\GivingDay\Taxonomies
 * @since   0.1.0
 */

namespace Team51\GivingDay\Taxonomies;

use Team51\GivingDay\PostTypes\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_team_category` hierarchical taxonomy on Teams.
 *
 * Powers tabbed/faceted leaderboards. Site admins build a tree whose
 * top-level terms are "grouping dimensions" and child terms are the
 * actual buckets, e.g.:
 *
 *  - Class Year
 *    - 2014
 *    - 2015
 *  - Athletic
 *    - Football
 *    - Basketball
 *  - Department
 *    - Engineering
 *    - Arts
 *
 * A Team can be tagged with multiple terms (e.g. "2014" + "Football"). The
 * Leaderboard block accepts `filterTermId` and `groupByParentTermId` attrs
 * so that a tab like "Class Year" renders one sub-leaderboard per child of
 * the "Class Year" parent term.
 */
final class TeamCategory extends AbstractTaxonomy {

	public const TAXONOMY  = 'giving_team_category';
	public const REST_BASE = 'team-categories';

	public function get_taxonomy(): string {
		return self::TAXONOMY;
	}

	public function get_object_types(): array {
		return array( Team::POST_TYPE );
	}

	protected function get_taxonomy_args(): array {
		return array(
			'labels'            => array(
				'name'                     => _x( 'Team Categories', 'taxonomy general name', 'giving-day-blocks' ),
				'singular_name'            => _x( 'Team Category', 'taxonomy singular name', 'giving-day-blocks' ),
				'menu_name'                => __( 'Categories', 'giving-day-blocks' ),
				'all_items'                => __( 'All Team Categories', 'giving-day-blocks' ),
				'parent_item'              => __( 'Parent Team Category', 'giving-day-blocks' ),
				'edit_item'                => __( 'Edit Team Category', 'giving-day-blocks' ),
				'add_new_item'             => __( 'Add New Team Category', 'giving-day-blocks' ),
				'search_items'             => __( 'Search Team Categories', 'giving-day-blocks' ),
				'not_found'                => __( 'No team categories found.', 'giving-day-blocks' ),
				'parent_field_description' => __( 'Assign a parent Team Category to build a dimension. Top-level terms act as leaderboard tabs (e.g. "Class Year"); child terms are the buckets within each tab (e.g. "2014", "2015").', 'giving-day-blocks' ),
				'name_field_description'   => __( 'The name is how this Team Category appears on your site when filtering Teams.', 'giving-day-blocks' ),
				'slug_field_description'   => __( 'The URL-friendly version of the Team Category name.', 'giving-day-blocks' ),
				'desc_field_description'   => __( 'Optional description for this Team Category. Not shown by default in all themes.', 'giving-day-blocks' ),
			),
			'description'       => __( 'Hierarchical categories for Teams. Top-level terms act as leaderboard tabs (e.g. "Class Year", "Athletic"); child terms are the buckets within each tab.', 'giving-day-blocks' ),
			'hierarchical'      => true,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => self::REST_BASE,
			'rest_namespace'    => self::REST_NAMESPACE,
			'rewrite'           => false,
		);
	}
}
