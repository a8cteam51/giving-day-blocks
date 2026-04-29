<?php
/**
 * Main plugin class.
 *
 * @package Team51\GivingDay
 * @since   0.1.0
 */

namespace Team51\GivingDay;

use Team51\GivingDay\Admin\CampaignEditor;
use Team51\GivingDay\Admin\MatchEditor;
use Team51\GivingDay\Data\Context;
use Team51\GivingDay\Data\Leaderboard;
use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Challenge;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Setup\MockData;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamCategory;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together.
 *
 * Kept intentionally small in this first slice — additional components
 * (REST, Aggregator, Blocks, Admin) will be registered here as they land.
 */
final class Plugin {

	/**
	 * The Campaign custom post type component.
	 *
	 * @var Campaign|null
	 */
	public ?Campaign $campaign = null;

	/**
	 * The Team custom post type component.
	 *
	 * @var Team|null
	 */
	public ?Team $team = null;

	/**
	 * The GivingMatch custom post type component.
	 *
	 * @var GivingMatch|null
	 */
	public ?GivingMatch $match = null;

	/**
	 * The Challenge custom post type component.
	 *
	 * @var Challenge|null
	 */
	public ?Challenge $challenge = null;

	/**
	 * The Beneficiary custom post type component.
	 *
	 * @var Beneficiary|null
	 */
	public ?Beneficiary $beneficiary = null;

	/**
	 * The Cause taxonomy component.
	 *
	 * @var Cause|null
	 */
	public ?Cause $cause = null;

	/**
	 * The Team Category taxonomy component.
	 *
	 * @var TeamCategory|null
	 */
	public ?TeamCategory $team_category = null;

	/**
	 * First-activation sample data seeder.
	 *
	 * @var MockData|null
	 */
	public ?MockData $mock_data = null;

	/**
	 * REST API router.
	 *
	 * @var REST|null
	 */
	public ?REST $rest = null;

	/**
	 * Block registrar.
	 *
	 * @var Blocks|null
	 */
	public ?Blocks $blocks = null;

	/**
	 * Campaign edit screen UI (Gutenberg sidebar panel).
	 *
	 * @var CampaignEditor|null
	 */
	public ?CampaignEditor $campaign_editor = null;

	/**
	 * Match edit screen UI (Gutenberg sidebar panel).
	 *
	 * @var MatchEditor|null
	 */
	public ?MatchEditor $match_editor = null;

	/**
	 * Order attribution listener (tags WC orders with campaign context).
	 *
	 * @var OrderAttribution|null
	 */
	public ?OrderAttribution $order_attribution = null;

	/**
	 * Plugin constructor. Kept protected to enforce the singleton pattern.
	 */
	protected function __construct() {
		/* Empty on purpose. */
	}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @return void
	 */
	private function __clone() {
		/* Empty on purpose. */
	}

	/**
	 * Prevent unserialization of the singleton.
	 *
	 * @return void
	 */
	public function __wakeup() {
		/* Empty on purpose. */
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Boots the plugin's components.
	 *
	 * Safe to call multiple times; each component guards its own registration.
	 *
	 * @return void
	 */
	public function initialize(): void {
		if ( null !== $this->campaign ) {
			return;
		}

		$this->campaign = new Campaign();
		$this->campaign->register();

		$this->team = new Team();
		$this->team->register();

		$this->match = new GivingMatch();
		$this->match->register();

		$this->challenge = new Challenge();
		$this->challenge->register();

		$this->beneficiary = new Beneficiary();
		$this->beneficiary->register();

		$this->cause = new Cause();
		$this->cause->register();

		$this->team_category = new TeamCategory();
		$this->team_category->register();

		$this->mock_data = new MockData();
		$this->mock_data->register();

		$this->rest = new REST();
		$this->rest->register();

		$this->blocks = new Blocks();
		$this->blocks->register();

		$this->campaign_editor = new CampaignEditor();
		$this->campaign_editor->register();

		$this->match_editor = new MatchEditor();
		$this->match_editor->register();

		Context::register_hooks();

		$this->order_attribution = new OrderAttribution();
		$this->order_attribution->register();

		Leaderboard::register_hooks();
	}
}
