<?php
/**
 * Team Group taxonomy.
 *
 * @package Team51\GivingDay\Taxonomies
 * @since   0.1.0
 */

namespace Team51\GivingDay\Taxonomies;

use Team51\GivingDay\PostTypes\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `giving_team_group` hierarchical taxonomy on Teams.
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
 *
 * Renamed from the original `giving_team_category` slug to "Team Group"
 * for two reasons: "Category" is generic and collides with WordPress's
 * built-in `category` taxonomy in conversation, and "Group" matches how
 * organizers actually talk about these buckets ("our class-year groups",
 * "athletic groups").
 */
final class TeamGroup extends AbstractTaxonomy {

	public const TAXONOMY  = 'giving_team_group';
	public const REST_BASE = 'team-groups';

	public function get_taxonomy(): string {
		return self::TAXONOMY;
	}

	public function get_object_types(): array {
		return array( Team::POST_TYPE );
	}

	protected function get_taxonomy_args(): array {
		return array(
			'labels'            => array(
				'name'                     => _x( 'Team Groups', 'taxonomy general name', 'giving-day-blocks' ),
				'singular_name'            => _x( 'Team Group', 'taxonomy singular name', 'giving-day-blocks' ),
				'menu_name'                => __( 'Team Groups', 'giving-day-blocks' ),
				'all_items'                => __( 'All Team Groups', 'giving-day-blocks' ),
				'parent_item'              => __( 'Parent Team Group', 'giving-day-blocks' ),
				'edit_item'                => __( 'Edit Team Group', 'giving-day-blocks' ),
				'add_new_item'             => __( 'Add New Team Group', 'giving-day-blocks' ),
				'search_items'             => __( 'Search Team Groups', 'giving-day-blocks' ),
				'not_found'                => __( 'No team groups found.', 'giving-day-blocks' ),
				'parent_field_description' => __( 'Assign a parent Team Group to build a dimension. Top-level terms act as leaderboard tabs (e.g. "Class Year"); child terms are the buckets within each tab (e.g. "2014", "2015").', 'giving-day-blocks' ),
				'name_field_description'   => __( 'The name is how this Team Group appears on your site when filtering Teams.', 'giving-day-blocks' ),
				'slug_field_description'   => __( 'The URL-friendly version of the Team Group name.', 'giving-day-blocks' ),
				'desc_field_description'   => __( 'Optional description for this Team Group. Not shown by default in all themes.', 'giving-day-blocks' ),
			),
			'description'       => __( 'Hierarchical groups for Teams. Top-level terms act as leaderboard tabs (e.g. "Class Year", "Athletic"); child terms are the buckets within each tab.', 'giving-day-blocks' ),
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
