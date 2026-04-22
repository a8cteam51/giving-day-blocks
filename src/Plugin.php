<?php
/**
 * Main plugin class.
 *
 * @package Team51\GivingDay
 * @since   0.1.0
 */

namespace Team51\GivingDay;

use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Challenge;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;

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
		$this->campaign = new Campaign();
		$this->campaign->register();

		$this->team = new Team();
		$this->team->register();

		$this->match = new GivingMatch();
		$this->match->register();

		$this->challenge = new Challenge();
		$this->challenge->register();
	}
}
