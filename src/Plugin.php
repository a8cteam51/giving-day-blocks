<?php
/**
 * Main plugin class.
 *
 * @package Team51\GivingDay
 * @since   0.1.0
 */

namespace Team51\GivingDay;

use Team51\GivingDay\Admin\CampaignEditor;
use Team51\GivingDay\Admin\DonationPageSetting;
use Team51\GivingDay\Admin\CauseTermMeta;
use Team51\GivingDay\Admin\Menu as AdminMenu;
use Team51\GivingDay\Admin\MatchEditor;
use Team51\GivingDay\Admin\BeneficiaryEditor;
use Team51\GivingDay\Admin\ImportPage;
use Team51\GivingDay\Admin\ImportShortcuts;
use Team51\GivingDay\Admin\OfflineDonations as OfflineDonationsAdmin;
use Team51\GivingDay\Admin\OrderAttributionBox;
use Team51\GivingDay\Admin\ResultsExport;
use Team51\GivingDay\Admin\ResultsPage;
use Team51\GivingDay\Admin\ScreenIntro;
use Team51\GivingDay\Admin\SettingsPage;
use Team51\GivingDay\Admin\TeamEditor;
use Team51\GivingDay\Data\Aggregator;
use Team51\GivingDay\Data\Context;
use Team51\GivingDay\Frontend\SingleTemplates;
use Team51\GivingDay\Integrations\DonationDesignationChips;
use Team51\GivingDay\Integrations\OfflineGateway;
use Team51\GivingDay\Integrations\OrderAttribution;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Campaign;
use Team51\GivingDay\PostTypes\Challenge;
use Team51\GivingDay\PostTypes\GivingMatch;
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\Services\ResultsSnapshot;
use Team51\GivingDay\Setup\MockData;
use Team51\GivingDay\Taxonomies\Cause;
use Team51\GivingDay\Taxonomies\TeamGroup;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together.
 *
 * Kept intentionally small in this first slice — additional components
 * (REST, Aggregator, Blocks, Admin) will be registered here as they land.
 */
final class Plugin {

	/**
	 * Bump when CPT/tax rewrite args change so permalinks are regenerated.
	 */
	private const REWRITE_RULES_VERSION = 2;

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
	 * The Team Group taxonomy component.
	 *
	 * @var TeamGroup|null
	 */
	public ?TeamGroup $team_group = null;

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
	 * Donation form designation chips (consumer of team51-donations Custom Fields API).
	 *
	 * @var DonationDesignationChips|null
	 */
	public ?DonationDesignationChips $designation_chips = null;

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

		SingleTemplates::register();

		$this->cause = new Cause();
		$this->cause->register();

		$this->team_group = new TeamGroup();
		$this->team_group->register();

		$this->mock_data = new MockData();
		$this->mock_data->register();

		$this->rest = new REST();
		$this->rest->register();

		$this->blocks = new Blocks();
		$this->blocks->register();

		$this->campaign_editor = new CampaignEditor();
		$this->campaign_editor->register();

		( new SettingsPage() )->register();

		( new OrderAttributionBox() )->register();

		( new AdminMenu() )->register();

		( new ScreenIntro() )->register();

		$this->match_editor = new MatchEditor();
		$this->match_editor->register();

		( new TeamEditor() )->register();
		( new BeneficiaryEditor() )->register();

		Context::register_hooks();

		$this->order_attribution = new OrderAttribution();
		$this->order_attribution->register();

		$this->designation_chips = new DonationDesignationChips();
		$this->designation_chips->register();

		OfflineGateway::register();

		( new OfflineDonationsAdmin() )->register();

		( new ResultsPage() )->register();
		( new ResultsExport() )->register();

		( new ImportPage() )->register();
		( new ImportShortcuts() )->register();

		( new CauseTermMeta() )->register();

		( new DonationPageSetting() )->register();

		Aggregator::register_hooks();
		ResultsSnapshot::register_hooks();

		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 1000 );
		add_action( 'init', array( $this, 'maybe_purge_legacy_overrides' ), 1001 );
	}

	/**
	 * Flushes rewrite rules once after deploy when {@see self::REWRITE_RULES_VERSION} increases.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		$stored = (int) get_option( 'giving_day_blocks_rewrite_version', 0 );
		if ( $stored >= self::REWRITE_RULES_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'giving_day_blocks_rewrite_version', self::REWRITE_RULES_VERSION, true );
	}

	/**
	 * One-shot cleanup: deletes the legacy `_giving_raised_override` and
	 * `_giving_donor_count_override` campaign meta (which used to replace
	 * the Aggregator's computed totals — see commit removing them) and
	 * bumps each campaign's cache version so transients keyed under the
	 * old version can't return stale override-substituted payloads.
	 *
	 * Idempotent via the `giving_day_blocks_overrides_purged_v1` option.
	 */
	public function maybe_purge_legacy_overrides(): void {
		if ( get_option( 'giving_day_blocks_overrides_purged_v1' ) ) {
			return;
		}

		delete_post_meta_by_key( '_giving_raised_override' );
		delete_post_meta_by_key( '_giving_donor_count_override' );

		$campaign_ids = get_posts(
			array(
				'post_type'              => Campaign::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( is_array( $campaign_ids ) ) {
			foreach ( $campaign_ids as $cid ) {
				Aggregator::invalidate( (int) $cid );
			}
		}

		update_option( 'giving_day_blocks_overrides_purged_v1', 1, false );
	}
}
