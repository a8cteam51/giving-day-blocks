# Team / Beneficiary Single-Page Goal + Donate CTA — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-05-19-team-beneficiary-donate-cta-design.md` — read first.

**Goal:** On every single Team and single Beneficiary post page, render a Goal block (skipping when no goal is set) and a Donate button that pre-tags the donation form with the right entity, via plugin-shipped FSE templates.

**Architecture:** Extend `GoalProgress` + `Aggregator` to resolve Team / Beneficiary targets. Extend the existing `goal-progress` block with an `auto` target-type that resolves from `get_post()` at render. Add a new `donate-button` block that builds URLs through a new `DonationUrl` helper backed by a configurable donation-page setting. Ship `single-team.html` and `single-beneficiary.html` FSE templates via `register_block_template()` (WP 6.7+).

**Tech Stack:** WordPress 6.7+, PHP 8.1+, WooCommerce, `@wordpress/scripts`, Jest for JS tests.

## Deviations from the writing-plans skill defaults (read before starting)

- **No PHPUnit infrastructure exists in this plugin.** Adding one would dwarf this feature. Substitute strategy for PHP:
  - Smoke-verify each PHP component via WP-CLI (`wp eval`) snippets included in the task.
  - End-to-end verify in the browser at the rollout task.
- **Jest tests retained** for any JS-side logic added (matches existing `tests/js/` pattern).
- **Task granularity is larger than 2-5 minutes** — most tasks are 30-90 minutes — because this codebase has enough surrounding context that finer slicing produces noise. Each task still ends in a single self-contained commit.

## File Map

**Create:**
- `src/Data/DonationUrl.php`
- `src/Admin/DonationPageSetting.php`
- `src/Setup/BlockTemplates.php`
- `blocks/src/donate-button/block.json`
- `blocks/src/donate-button/index.js`
- `blocks/src/donate-button/edit.js`
- `blocks/src/donate-button/render.php`
- `blocks/src/donate-button/style.scss`
- `blocks/src/donate-button/editor.scss`
- `templates/single-team.html`
- `templates/single-beneficiary.html`
- `tests/js/donationPageUrl.test.js` (only if a JS helper for URL building is added; otherwise skip)

**Modify:**
- `giving-day-blocks.php` (header `Requires at least: 6.7`, version bump)
- `src/Data/GoalProgress.php` (TYPE_TEAM, TYPE_BENEFICIARY, resolve branches)
- `src/Data/Aggregator.php` (add `totals_for_team`, `totals_for_beneficiary`)
- `src/REST.php` (two new routes)
- `src/Plugin.php` (boot `BlockTemplates`, `DonationPageSetting`)
- `blocks/src/goal-progress/block.json` (add `targetType`, `targetId`)
- `blocks/src/goal-progress/render.php` (auto-detect; no-goal skip)
- `blocks/src/goal-progress/edit.js` (target-type controls)
- `blocks/src/_shared/utils/goalProgress.js` (endpoint URL by type)
- `blocks/src/goal-progress/view.js` (read new data attrs)
- `README.md` (block-theme requirement, WP 6.7+ note)

---

### Task 1: Bump plugin min WP version and bootstrap setup file

**Files:**
- Modify: `giving-day-blocks.php` header and version constant
- Create: `src/Setup/BlockTemplates.php` (skeleton — populated in Task 12)

This is a foundation commit. No behavior change, just version metadata and a placeholder class so later tasks have something to register.

- [ ] **Step 1: Update the plugin header**

Edit `giving-day-blocks.php`, lines around the file header docblock:

```php
 * Requires at least:       6.7
 * Requires PHP:            8.1
```

And bump the version:

```php
define( 'GIVING_DAY_BLOCKS_VERSION', '1.1.0-dev' );
```

- [ ] **Step 2: Create the BlockTemplates skeleton**

Create `src/Setup/BlockTemplates.php`:

```php
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
```

- [ ] **Step 3: Smoke-verify with WP-CLI**

```bash
wp eval 'echo GIVING_DAY_BLOCKS_VERSION;'
```

Expected output: `1.1.0-dev`

- [ ] **Step 4: Commit**

```bash
git add giving-day-blocks.php src/Setup/BlockTemplates.php
git commit -m "chore: bump min WP to 6.7 and scaffold BlockTemplates setup"
```

---

### Task 2: Add `Aggregator` totals methods for Team and Beneficiary

**Files:**
- Modify: `src/Data/Aggregator.php` — append two new public methods near `totals_for_campaign()` (around line 205)

These methods iterate WC orders attributed to a specific Team or Beneficiary and return the same payload shape as `compute_campaign_totals_live()`.

- [ ] **Step 1: Add `totals_for_team`**

Append after `totals_for_campaign()` in `src/Data/Aggregator.php`:

```php
	/**
	 * Live totals for a Team. Iterates WC orders where _giving_team_id
	 * matches. No transient caching at this stage — add later if measured
	 * load justifies it.
	 *
	 * @param int $team_id
	 * @return array<string,mixed> Same shape as compute_campaign_totals_live().
	 */
	public static function totals_for_team( int $team_id ): array {
		return self::compute_entity_totals_live( OrderAttribution::META_TEAM_ID, $team_id );
	}

	/**
	 * Live totals for a Beneficiary (parent's own meta + own attributed
	 * orders only — no descendant roll-up at this stage).
	 *
	 * @param int $beneficiary_id
	 * @return array<string,mixed>
	 */
	public static function totals_for_beneficiary( int $beneficiary_id ): array {
		return self::compute_entity_totals_live( OrderAttribution::META_BENEFICIARY_ID, $beneficiary_id );
	}

	private static function compute_entity_totals_live( string $meta_key, int $entity_id ): array {
		$raised     = 0.0;
		$count      = 0;
		$donor_keys = array();

		$query = new \WC_Order_Query(
			array(
				'limit'      => -1,
				'status'     => array( 'wc-completed', 'wc-processing' ),
				'return'     => 'objects',
				'meta_query' => array(
					array(
						'key'     => $meta_key,
						'value'   => $entity_id,
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $query->get_orders() as $order ) {
			$donation_total = self::donation_total_for_order( $order );
			if ( $donation_total <= 0 ) {
				continue;
			}
			$raised += $donation_total;
			++$count;

			$donor_key = self::donor_key_for_order( $order );
			if ( null !== $donor_key ) {
				$donor_keys[ $donor_key ] = true;
			}
		}

		$currency = (string) get_option( 'woocommerce_currency', 'USD' );

		return array(
			'entity_id'     => $entity_id,
			'raised'        => round( $raised, 2 ),
			'count'         => $count,
			'avg'           => $count > 0 ? round( $raised / $count, 2 ) : 0.0,
			'unique_donors' => count( $donor_keys ),
			'currency'      => $currency,
			'server_time'   => gmdate( 'c' ),
		);
	}
```

Note on order statuses: `wc-completed` and `wc-processing` match the buckets used elsewhere in this file. If the existing campaign aggregator uses a different status set, harmonize — grep `wc-completed` in `Aggregator.php` to confirm.

- [ ] **Step 2: Smoke-verify**

Pick an existing Team ID that has at least one completed donation order in the local DB. Run:

```bash
wp eval '
use Team51\GivingDay\Data\Aggregator;
print_r( Aggregator::totals_for_team( 701 ) );
'
```

Expected: array with non-zero `raised` if the Team has tagged completed donations; zero if not. Either is OK — confirms wiring.

Repeat with a Beneficiary ID for `totals_for_beneficiary`.

- [ ] **Step 3: Commit**

```bash
git add src/Data/Aggregator.php
git commit -m "feat(aggregator): add live totals_for_team and totals_for_beneficiary"
```

---

### Task 3: Extend `GoalProgress` with Team and Beneficiary target types

**Files:**
- Modify: `src/Data/GoalProgress.php`

The docblock at lines 6-14 already anticipates this. The change is mechanical.

- [ ] **Step 1: Add constants and resolve branches**

Edit `src/Data/GoalProgress.php`:

After `public const TYPE_CAMPAIGN = 'campaign';` add:

```php
	public const TYPE_TEAM         = 'team';
	public const TYPE_BENEFICIARY  = 'beneficiary';
```

Add a `use` statement at the top:

```php
use Team51\GivingDay\PostTypes\Team;
use Team51\GivingDay\PostTypes\Beneficiary;
```

In the `switch` inside `resolve()`, before `default:`, add:

```php
			case self::TYPE_TEAM:
				return self::resolve_team( $id );
			case self::TYPE_BENEFICIARY:
				return self::resolve_beneficiary( $id );
```

Append the two new branch methods after `resolve_campaign()`:

```php
	private static function resolve_team( int $team_id ): ?array {
		$post = get_post( $team_id );
		if ( ! $post || Team::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$totals   = Aggregator::totals_for_team( $team_id );
		$goal     = (float) get_post_meta( $team_id, Team::META_GOAL_AMOUNT, true );
		$raised   = isset( $totals['raised'] ) ? (float) $totals['raised'] : 0.0;
		$donors   = isset( $totals['unique_donors'] ) ? (int) $totals['unique_donors'] : 0;
		$currency = isset( $totals['currency'] ) ? (string) $totals['currency'] : (string) get_option( 'woocommerce_currency', 'USD' );

		$percent_raw = $goal > 0 ? ( $raised / $goal ) * 100 : 0.0;
		$percent     = max( 0.0, min( 100.0, $percent_raw ) );

		return array(
			'goal'        => $goal,
			'raised'      => $raised,
			'donor_count' => $donors,
			'currency'    => $currency,
			'percent'     => round( $percent, 2 ),
			'percent_raw' => round( $percent_raw, 2 ),
		);
	}

	private static function resolve_beneficiary( int $beneficiary_id ): ?array {
		$post = get_post( $beneficiary_id );
		if ( ! $post || Beneficiary::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$totals   = Aggregator::totals_for_beneficiary( $beneficiary_id );
		$goal     = (float) get_post_meta( $beneficiary_id, Beneficiary::META_GOAL_AMOUNT, true );
		$raised   = isset( $totals['raised'] ) ? (float) $totals['raised'] : 0.0;
		$donors   = isset( $totals['unique_donors'] ) ? (int) $totals['unique_donors'] : 0;
		$currency = isset( $totals['currency'] ) ? (string) $totals['currency'] : (string) get_option( 'woocommerce_currency', 'USD' );

		$percent_raw = $goal > 0 ? ( $raised / $goal ) * 100 : 0.0;
		$percent     = max( 0.0, min( 100.0, $percent_raw ) );

		return array(
			'goal'        => $goal,
			'raised'      => $raised,
			'donor_count' => $donors,
			'currency'    => $currency,
			'percent'     => round( $percent, 2 ),
			'percent_raw' => round( $percent_raw, 2 ),
		);
	}
```

- [ ] **Step 2: Smoke-verify**

```bash
wp eval '
use Team51\GivingDay\Data\GoalProgress;
print_r( GoalProgress::resolve( GoalProgress::TYPE_TEAM, 701 ) );
print_r( GoalProgress::resolve( GoalProgress::TYPE_BENEFICIARY, 612 ) );
'
```

Expected: arrays with `goal`, `raised`, `donor_count`, `currency`, `percent`, `percent_raw`. `goal` reflects whatever meta is on the post.

Edge: invalid ID returns `null`:

```bash
wp eval '
use Team51\GivingDay\Data\GoalProgress;
var_dump( GoalProgress::resolve( GoalProgress::TYPE_TEAM, 99999 ) );
'
```

Expected: `NULL`.

- [ ] **Step 3: Commit**

```bash
git add src/Data/GoalProgress.php
git commit -m "feat(goal-progress): resolve Team and Beneficiary targets"
```

---

### Task 4: Add REST endpoints for team/beneficiary summary

**Files:**
- Modify: `src/REST.php`

Mirror the existing `/campaign/{id}/summary` route. Read `register_routes()` first to copy the exact pattern (auth callback, args schema).

- [ ] **Step 1: Read the campaign summary route**

Open `src/REST.php`, locate `register_routes()`, find the `/campaign/(?P<id>[0-9]+)/summary` registration. Note: the callback name, permission_callback, and args structure.

- [ ] **Step 2: Register the new routes**

Inside `register_routes()`, after the campaign summary registration, add:

```php
		register_rest_route(
			self::NAMESPACE,
			'/team/(?P<id>[0-9]+)/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'team_summary' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/beneficiary/(?P<id>[0-9]+)/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'beneficiary_summary' ),
				'args'                => $args,
			)
		);
```

- [ ] **Step 3: Add the callback methods**

In the same class, add two new methods. Use the campaign summary method as a template — it likely calls `GoalProgress::resolve(TYPE_CAMPAIGN, $id)` and shapes the response. Mirror that for the new types.

```php
	public function team_summary( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$payload = \Team51\GivingDay\Data\GoalProgress::resolve(
			\Team51\GivingDay\Data\GoalProgress::TYPE_TEAM,
			$id
		);
		if ( null === $payload ) {
			return new WP_Error( 'not_found', __( 'Team not found.', 'giving-day-blocks' ), array( 'status' => 404 ) );
		}
		$payload['server_time'] = gmdate( 'c' );
		return new WP_REST_Response( $payload );
	}

	public function beneficiary_summary( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$payload = \Team51\GivingDay\Data\GoalProgress::resolve(
			\Team51\GivingDay\Data\GoalProgress::TYPE_BENEFICIARY,
			$id
		);
		if ( null === $payload ) {
			return new WP_Error( 'not_found', __( 'Beneficiary not found.', 'giving-day-blocks' ), array( 'status' => 404 ) );
		}
		$payload['server_time'] = gmdate( 'c' );
		return new WP_REST_Response( $payload );
	}
```

If the existing campaign summary callback shapes the response differently (e.g., wraps in an envelope, adds preview override handling), match that shape exactly. Consistency wins.

- [ ] **Step 4: Smoke-verify**

```bash
curl -s "http://giving-day.local/wp-json/giving-day/v1/team/701/summary" | jq
curl -s "http://giving-day.local/wp-json/giving-day/v1/beneficiary/612/summary" | jq
curl -s -o /dev/null -w "%{http_code}\n" "http://giving-day.local/wp-json/giving-day/v1/team/99999/summary"
```

Expected: first two return JSON payloads with `goal`/`raised`/`percent`/`server_time`. Third returns `404`.

- [ ] **Step 5: Commit**

```bash
git add src/REST.php
git commit -m "feat(rest): expose team and beneficiary summary endpoints"
```

---

### Task 5: Create `DonationUrl` helper + donation-page setting

**Files:**
- Create: `src/Data/DonationUrl.php`
- Create: `src/Admin/DonationPageSetting.php`
- Modify: `src/Plugin.php` (register the setting)

Resolution order: filter > option > default. The admin setting is a single Page picker.

- [ ] **Step 1: Create `DonationUrl`**

Create `src/Data/DonationUrl.php`:

```php
<?php
/**
 * Builds donation URLs with the appropriate gd_* designation param.
 *
 * Donation page resolution:
 *   1. apply_filters( 'giving_day_donation_page_url', $default )
 *   2. option giving_day_donation_page_id (resolved via get_permalink)
 *   3. home_url( '/donate' )
 *
 * @package Team51\GivingDay\Data
 * @since   1.1.0
 */

namespace Team51\GivingDay\Data;

defined( 'ABSPATH' ) || exit;

final class DonationUrl {

	public const OPTION_PAGE_ID = 'giving_day_donation_page_id';
	public const FILTER_URL     = 'giving_day_donation_page_url';

	public const TYPE_TEAM        = 'team';
	public const TYPE_BENEFICIARY = 'beneficiary';
	public const TYPE_NONE        = 'none';

	public static function build( string $type, int $id = 0 ): string {
		$base = self::resolve_base_url();

		if ( self::TYPE_TEAM === $type && $id > 0 ) {
			return add_query_arg( array( 'gd_team' => $id ), $base );
		}
		if ( self::TYPE_BENEFICIARY === $type && $id > 0 ) {
			return add_query_arg( array( 'gd_beneficiary' => $id ), $base );
		}
		return $base;
	}

	private static function resolve_base_url(): string {
		$default = home_url( '/donate' );

		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $page_id > 0 ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				$default = $permalink;
			}
		}

		return (string) apply_filters( self::FILTER_URL, $default );
	}
}
```

- [ ] **Step 2: Create the admin setting**

Create `src/Admin/DonationPageSetting.php`:

```php
<?php
/**
 * Adds the "Donation page" setting under Settings → Reading.
 *
 * Single page picker, stored in option giving_day_donation_page_id.
 * Lives on the Reading screen because it has a similar "this is the
 * page for X" semantic as the existing "Posts page" setting.
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
```

- [ ] **Step 3: Boot both in `Plugin.php`**

Open `src/Plugin.php`, find where other services are instantiated (search for `->register()`). Add:

```php
		( new \Team51\GivingDay\Admin\DonationPageSetting() )->register();
```

Place near other admin or setup service registrations. No need to register `DonationUrl` — it's a static helper.

- [ ] **Step 4: Smoke-verify**

```bash
wp eval '
use Team51\GivingDay\Data\DonationUrl;
echo DonationUrl::build( DonationUrl::TYPE_TEAM, 701 ) . PHP_EOL;
echo DonationUrl::build( DonationUrl::TYPE_BENEFICIARY, 612 ) . PHP_EOL;
echo DonationUrl::build( DonationUrl::TYPE_NONE ) . PHP_EOL;
'
```

Expected (with default settings):

```text
http://giving-day.local/donate?gd_team=701
http://giving-day.local/donate?gd_beneficiary=612
http://giving-day.local/donate
```

Then set a custom donation page in admin (Settings → Reading) or via:

```bash
DONATE_PAGE_ID=$(wp post create --post_type=page --post_title="Give" --post_status=publish --porcelain)
wp option update giving_day_donation_page_id $DONATE_PAGE_ID
wp eval 'echo \Team51\GivingDay\Data\DonationUrl::build( "team", 701 ) . PHP_EOL;'
```

Expected: URL points to `/give/?gd_team=701`.

- [ ] **Step 5: Commit**

```bash
git add src/Data/DonationUrl.php src/Admin/DonationPageSetting.php src/Plugin.php
git commit -m "feat: add DonationUrl helper and donation-page admin setting"
```

---

### Task 6: Extend `goal-progress` block.json with target attributes

**Files:**
- Modify: `blocks/src/goal-progress/block.json`

- [ ] **Step 1: Add new attributes**

In `blocks/src/goal-progress/block.json`, locate the `"attributes"` object. Add two new attributes alongside the existing `campaignId`:

```json
		"targetType": {
			"type": "string",
			"default": "auto",
			"enum": [ "auto", "campaign", "team", "beneficiary" ]
		},
		"targetId": {
			"type": "number",
			"default": 0
		}
```

Keep `campaignId` as-is for backwards compat.

- [ ] **Step 2: Build and verify schema**

```bash
npm run build:blocks
```

Expected: no build error. The block.json gets copied to `blocks/build/goal-progress/block.json`.

- [ ] **Step 3: Commit**

```bash
git add blocks/src/goal-progress/block.json
git commit -m "feat(goal-progress): add targetType and targetId attributes"
```

---

### Task 7: Update `goal-progress` render.php — auto-detect + no-goal skip

**Files:**
- Modify: `blocks/src/goal-progress/render.php`

Two behavior changes:
1. New auto-detect path: when `targetType === 'auto'`, pick type from current post type. Falls back to existing `campaignId` for backwards compat.
2. When the resolved target has no goal (`$goal <= 0`), return without rendering — uniform across all target types.

- [ ] **Step 1: Replace the resolution block**

Open `blocks/src/goal-progress/render.php`. Replace lines 21-31 (the current `$campaign_id` resolution and `GoalProgress::resolve(...)` call) with:

```php
$target_type = isset( $attributes['targetType'] ) ? (string) $attributes['targetType'] : 'auto';
$target_id   = isset( $attributes['targetId'] ) ? (int) $attributes['targetId'] : 0;

if ( 'auto' === $target_type ) {
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	switch ( $post->post_type ) {
		case \Team51\GivingDay\PostTypes\Team::POST_TYPE:
			$target_type = GoalProgress::TYPE_TEAM;
			$target_id   = (int) $post->ID;
			break;
		case \Team51\GivingDay\PostTypes\Beneficiary::POST_TYPE:
			$target_type = GoalProgress::TYPE_BENEFICIARY;
			$target_id   = (int) $post->ID;
			break;
		case \Team51\GivingDay\PostTypes\Campaign::POST_TYPE:
			$target_type = GoalProgress::TYPE_CAMPAIGN;
			$target_id   = (int) $post->ID;
			break;
		default:
			return;
	}
}

// Backwards compat: legacy block instances stored campaignId only.
if ( $target_id <= 0 && ! empty( $attributes['campaignId'] ) ) {
	$target_type = GoalProgress::TYPE_CAMPAIGN;
	$target_id   = (int) $attributes['campaignId'];
}

if ( $target_id <= 0 ) {
	return;
}

// Context::set is campaign-scoped today; only set it when we have a campaign id
// to avoid drift. Team/Beneficiary contexts can be wired later if needed.
if ( GoalProgress::TYPE_CAMPAIGN === $target_type ) {
	Context::set( $target_id );
}

$progress = GoalProgress::resolve( $target_type, $target_id );
if ( null === $progress ) {
	return;
}

// "No goal → render nothing" rule, applied uniformly across target types.
if ( (float) $progress['goal'] <= 0 ) {
	return;
}
```

- [ ] **Step 2: Update `$campaign_id` references downstream**

The rest of `render.php` (after line ~31) still references `$campaign_id` in `data-campaign-id`, `Colors::inline_style( $campaign_id )`, and aria text. Update:

Replace `$campaign_id` with `$target_id` throughout the rest of the file, except keep the `data-campaign-id` attribute only when the target type is campaign (for backwards compat with anything in the wild). Add new data attributes:

```php
$wrapper_extra['data-target-type'] = $target_type;
$wrapper_extra['data-target-id']   = (string) $target_id;
```

And modify the existing `'data-campaign-id'` line:

```php
'data-campaign-id'      => GoalProgress::TYPE_CAMPAIGN === $target_type ? (string) $target_id : '',
```

For `Colors::inline_style( $target_id )` — verify this helper works for non-campaign IDs. If it only knows campaign colors, gate it:

```php
$color_style = GoalProgress::TYPE_CAMPAIGN === $target_type
	? Colors::inline_style( $target_id )
	: '';
```

- [ ] **Step 3: Remove the existing "no goal" rendered fallback**

The current render.php renders a track without progressbar role when `$has_goal` is false (lines ~138-150 in the file as written). With the new early-return, that branch is dead — but leaving it doesn't hurt. Optional cleanup: remove the `else` branch after the `if ( $has_goal )` and remove the `<?php endif; ?>` so the file is shorter. If unsure, skip cleanup — the dead branch is harmless.

- [ ] **Step 4: Build and smoke-verify**

```bash
npm run build:blocks
```

Open a Team post in the browser (without an FSE template yet — just preview the goal block as inserted into post content). Verify:
- Team with goal set: bar renders.
- Team with goal = 0: nothing renders.
- Existing Campaign block instances using `campaignId` still work.

- [ ] **Step 5: Commit**

```bash
git add blocks/src/goal-progress/render.php
git commit -m "feat(goal-progress): auto-detect target from post type, skip when no goal"
```

---

### Task 8: Update `goal-progress` edit.js — target-type controls

**Files:**
- Modify: `blocks/src/goal-progress/edit.js`

Add a `SelectControl` for `targetType` and a `__experimentalPostPicker` (or fallback to a `NumberControl` for `targetId`) in the InspectorControls panel. When `targetType === 'auto'`, hide the `targetId` field.

- [ ] **Step 1: Read the current edit.js**

Open `blocks/src/goal-progress/edit.js`. Locate the `InspectorControls` section.

- [ ] **Step 2: Add target-type and target-id controls**

Inside the existing `PanelBody` (or in a new `PanelBody` titled "Target"), add at the top:

```jsx
<SelectControl
  label={ __( 'Target type', 'giving-day-blocks' ) }
  value={ attributes.targetType || 'auto' }
  options={ [
    { label: __( 'Auto (use current post)', 'giving-day-blocks' ), value: 'auto' },
    { label: __( 'Campaign', 'giving-day-blocks' ), value: 'campaign' },
    { label: __( 'Team', 'giving-day-blocks' ), value: 'team' },
    { label: __( 'Beneficiary', 'giving-day-blocks' ), value: 'beneficiary' },
  ] }
  onChange={ ( value ) => setAttributes( { targetType: value, targetId: 0 } ) }
  help={ __( '"Auto" picks the goal of the current Team, Beneficiary, or Campaign page at render.', 'giving-day-blocks' ) }
/>

{ attributes.targetType && attributes.targetType !== 'auto' && (
  <NumberControl
    label={ __( 'Target post ID', 'giving-day-blocks' ) }
    value={ attributes.targetId || 0 }
    onChange={ ( value ) => setAttributes( { targetId: parseInt( value, 10 ) || 0 } ) }
    min={ 0 }
  />
) }
```

If `NumberControl` isn't already imported, add it to the `@wordpress/components` import block.

- [ ] **Step 3: Build and verify in editor**

```bash
npm run build:blocks
```

Open the block editor on any post, insert a Goal+Progress block. Verify:
- Target type dropdown appears.
- Switching to "Team" shows the post ID field.
- Switching back to "Auto" hides it.

- [ ] **Step 4: Commit**

```bash
git add blocks/src/goal-progress/edit.js
git commit -m "feat(goal-progress): editor controls for target type and id"
```

---

### Task 9: Update `goalProgress.js` shared utility for new endpoints

**Files:**
- Modify: `blocks/src/_shared/utils/goalProgress.js`
- Modify: `tests/js/goalProgress.test.js`
- Modify: `blocks/src/goal-progress/view.js` (read new data attrs)

The shared utility currently builds `/campaign/{id}/summary` URLs. Add a target-type branch.

- [ ] **Step 1: Write failing tests**

Open `tests/js/goalProgress.test.js`. Add (or extend the URL-building test):

```js
describe( 'goalProgressEndpoint', () => {
	it( 'builds the campaign endpoint by default', () => {
		expect( goalProgressEndpoint( 'campaign', 12 ) ).toBe( '/wp-json/giving-day/v1/campaign/12/summary' );
	} );

	it( 'builds the team endpoint', () => {
		expect( goalProgressEndpoint( 'team', 701 ) ).toBe( '/wp-json/giving-day/v1/team/701/summary' );
	} );

	it( 'builds the beneficiary endpoint', () => {
		expect( goalProgressEndpoint( 'beneficiary', 612 ) ).toBe( '/wp-json/giving-day/v1/beneficiary/612/summary' );
	} );

	it( 'returns null for unknown type', () => {
		expect( goalProgressEndpoint( 'unknown', 1 ) ).toBeNull();
	} );
} );
```

Adjust the import at top of test file to include `goalProgressEndpoint` from the shared util.

- [ ] **Step 2: Run tests — expect failure**

```bash
npx jest tests/js/goalProgress.test.js
```

Expected: failure (`goalProgressEndpoint` not exported).

- [ ] **Step 3: Implement**

Open `blocks/src/_shared/utils/goalProgress.js`. Add (or replace any existing URL builder with):

```js
export function goalProgressEndpoint( type, id ) {
	const base = '/wp-json/giving-day/v1';
	switch ( type ) {
		case 'campaign':
			return `${ base }/campaign/${ id }/summary`;
		case 'team':
			return `${ base }/team/${ id }/summary`;
		case 'beneficiary':
			return `${ base }/beneficiary/${ id }/summary`;
		default:
			return null;
	}
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
npx jest tests/js/goalProgress.test.js
```

Expected: all green.

- [ ] **Step 5: Update view.js to use the new endpoint builder**

In `blocks/src/goal-progress/view.js`, read the new data attributes set in Task 7:

```js
const targetType = el.dataset.targetType || 'campaign';
const targetId   = parseInt( el.dataset.targetId || el.dataset.campaignId || '0', 10 );
const endpoint   = goalProgressEndpoint( targetType, targetId );
if ( ! endpoint || ! targetId ) {
	return;
}
// then fetch( endpoint ) ...
```

Replace any existing hardcoded campaign URL construction.

- [ ] **Step 6: Build and verify in browser**

```bash
npm run build:blocks
```

Open a Team page with a goal in the browser. DevTools → Network tab. Confirm the block hydrates by calling `/wp-json/giving-day/v1/team/{id}/summary` and the bar updates.

- [ ] **Step 7: Commit**

```bash
git add blocks/src/_shared/utils/goalProgress.js blocks/src/goal-progress/view.js tests/js/goalProgress.test.js
git commit -m "feat(goal-progress): hydrate via team/beneficiary REST endpoints"
```

---

### Task 10: New `donate-button` block

**Files (all create):**
- `blocks/src/donate-button/block.json`
- `blocks/src/donate-button/index.js`
- `blocks/src/donate-button/edit.js`
- `blocks/src/donate-button/render.php`
- `blocks/src/donate-button/style.scss`
- `blocks/src/donate-button/editor.scss`

Server-rendered. Auto-detects target from current post type. Editor controls allow overriding.

- [ ] **Step 1: Create `block.json`**

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "giving-day/donate-button",
	"version": "0.1.0",
	"title": "Giving Day: Donate Button",
	"category": "widgets",
	"icon": "money-alt",
	"description": "A donation call-to-action button. Auto-tags the donation form with the current Team or Beneficiary via gd_team / gd_beneficiary URL params.",
	"keywords": [ "giving day", "donate", "button", "cta" ],
	"textdomain": "giving-day-blocks",
	"supports": {
		"html": false,
		"color": {
			"background": true,
			"text": true,
			"link": true
		},
		"spacing": {
			"margin": true,
			"padding": true
		},
		"typography": {
			"fontSize": true,
			"fontWeight": true
		},
		"align": [ "wide", "full", "left", "center", "right" ]
	},
	"attributes": {
		"label": {
			"type": "string",
			"default": "Donate"
		},
		"targetType": {
			"type": "string",
			"default": "auto",
			"enum": [ "auto", "team", "beneficiary", "none" ]
		},
		"targetId": {
			"type": "number",
			"default": 0
		}
	},
	"editorScript": "file:./index.js",
	"style": "file:./style-index.css",
	"editorStyle": "file:./index.css",
	"render": "file:./render.php"
}
```

- [ ] **Step 2: Create `index.js`**

```js
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, { edit } );
```

- [ ] **Step 3: Create `edit.js`**

```js
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, SelectControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const { label, targetType, targetId } = attributes;
	const blockProps = useBlockProps( { className: 'giving-day-donate-button' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Target', 'giving-day-blocks' ) } initialOpen>
					<SelectControl
						label={ __( 'Target type', 'giving-day-blocks' ) }
						value={ targetType || 'auto' }
						options={ [
							{ label: __( 'Auto (use current post)', 'giving-day-blocks' ), value: 'auto' },
							{ label: __( 'Team', 'giving-day-blocks' ), value: 'team' },
							{ label: __( 'Beneficiary', 'giving-day-blocks' ), value: 'beneficiary' },
							{ label: __( 'None (no designation)', 'giving-day-blocks' ), value: 'none' },
						] }
						onChange={ ( value ) => setAttributes( { targetType: value, targetId: 0 } ) }
						help={ __( '"Auto" picks the current Team or Beneficiary at render.', 'giving-day-blocks' ) }
					/>
					{ ( targetType === 'team' || targetType === 'beneficiary' ) && (
						<NumberControl
							label={ __( 'Target post ID', 'giving-day-blocks' ) }
							value={ targetId || 0 }
							onChange={ ( value ) => setAttributes( { targetId: parseInt( value, 10 ) || 0 } ) }
							min={ 0 }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<RichText
					tagName="span"
					className="giving-day-donate-button__label"
					value={ label }
					onChange={ ( value ) => setAttributes( { label: value } ) }
					placeholder={ __( 'Donate', 'giving-day-blocks' ) }
					allowedFormats={ [] }
				/>
			</div>
		</>
	);
}
```

- [ ] **Step 4: Create `render.php`**

```php
<?php
/**
 * Server render: giving-day/donate-button.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner content (unused).
 * @var WP_Block $block      Block instance.
 *
 * @package Team51\GivingDay\Blocks
 */

use Team51\GivingDay\Data\DonationUrl;
use Team51\GivingDay\PostTypes\Beneficiary;
use Team51\GivingDay\PostTypes\Team;

defined( 'ABSPATH' ) || exit;

$label       = isset( $attributes['label'] ) && '' !== $attributes['label']
	? (string) $attributes['label']
	: __( 'Donate', 'giving-day-blocks' );
$target_type = isset( $attributes['targetType'] ) ? (string) $attributes['targetType'] : 'auto';
$target_id   = isset( $attributes['targetId'] ) ? (int) $attributes['targetId'] : 0;

if ( 'auto' === $target_type ) {
	$post = get_post();
	if ( $post ) {
		switch ( $post->post_type ) {
			case Team::POST_TYPE:
				$target_type = DonationUrl::TYPE_TEAM;
				$target_id   = (int) $post->ID;
				break;
			case Beneficiary::POST_TYPE:
				$target_type = DonationUrl::TYPE_BENEFICIARY;
				$target_id   = (int) $post->ID;
				break;
			default:
				$target_type = DonationUrl::TYPE_NONE;
				$target_id   = 0;
		}
	} else {
		$target_type = DonationUrl::TYPE_NONE;
		$target_id   = 0;
	}
}

$href = DonationUrl::build( $target_type, $target_id );

$wrapper_attrs = get_block_wrapper_attributes(
	array(
		'class' => 'giving-day-donate-button',
	)
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<a class="giving-day-donate-button__link wp-block-button__link wp-element-button" href="<?php echo esc_url( $href ); ?>">
		<?php echo wp_kses_post( $label ); ?>
	</a>
</div>
```

- [ ] **Step 5: Create `style.scss` (frontend) and `editor.scss` (editor-only)**

`style.scss`:

```scss
.giving-day-donate-button {
	display: inline-block;

	&__link {
		display: inline-block;
		text-decoration: none;
	}
}
```

`editor.scss`:

```scss
.giving-day-donate-button {
	&__label {
		display: inline-block;
		min-width: 4em;
	}
}
```

Intentionally minimal styles — block supports (color, typography, spacing) drive most of the visual. Matches core button conventions via `wp-block-button__link wp-element-button` classes so themes that style core buttons style this one too.

- [ ] **Step 6: Build**

```bash
npm run build:blocks
```

Expected: no build errors. New `blocks/build/donate-button/` directory exists with built files.

- [ ] **Step 7: Smoke-verify in editor**

In WP admin, edit any page → insert "Giving Day: Donate Button". Verify the editor controls appear and the label is editable.

Insert it on a draft Team post, save, view the rendered output. Confirm the `href` looks like `/donate?gd_team={id}`.

- [ ] **Step 8: Commit**

```bash
git add blocks/src/donate-button/
git commit -m "feat: new donate-button block with auto-detect targets"
```

---

### Task 11: Plugin block registration for `donate-button`

**Files:**
- Modify: `src/Blocks.php` (wherever the plugin's blocks are registered server-side)

The other blocks have a `register_block_type()` call somewhere in PHP that points to the built directory. The new block needs the same.

- [ ] **Step 1: Locate the existing registrations**

```bash
grep -n "register_block_type\|register_block_type_from_metadata" src/Blocks.php
```

Read how other blocks (e.g., `goal-progress`) get registered.

- [ ] **Step 2: Add the new block**

Mirror the existing pattern. If it's a list of block slugs iterated and registered, just add `'donate-button'` to the list. If each is registered explicitly, add an explicit registration that matches.

Example (adjust to match the file):

```php
register_block_type_from_metadata( $blocks_build_dir . '/donate-button' );
```

- [ ] **Step 3: Smoke-verify**

```bash
wp eval '$reg = WP_Block_Type_Registry::get_instance(); var_dump( $reg->is_registered( "giving-day/donate-button" ) );'
```

Expected: `bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add src/Blocks.php
git commit -m "chore(blocks): register donate-button block"
```

---

### Task 12: FSE templates for single Team and single Beneficiary

**Files:**
- Create: `templates/single-team.html`
- Create: `templates/single-beneficiary.html`
- Modify: `src/Setup/BlockTemplates.php` (populate the `register_templates()` method)

- [ ] **Step 1: Write `templates/single-team.html`**

```html
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

	<!-- wp:post-title {"level":1,"align":"wide"} /-->

	<!-- wp:giving-day/goal-progress {"targetType":"auto","align":"wide"} /-->

	<!-- wp:giving-day/donate-button {"targetType":"auto","label":"Donate to this team","align":"center"} /-->

	<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"}} /-->

</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
```

- [ ] **Step 2: Write `templates/single-beneficiary.html`**

Identical structure, only the donate-button label differs:

```html
<!-- wp:template-part {"slug":"header","tagName":"header"} /-->

<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group">

	<!-- wp:post-title {"level":1,"align":"wide"} /-->

	<!-- wp:giving-day/goal-progress {"targetType":"auto","align":"wide"} /-->

	<!-- wp:giving-day/donate-button {"targetType":"auto","label":"Donate","align":"center"} /-->

	<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"}} /-->

</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
```

- [ ] **Step 3: Populate `BlockTemplates::register_templates()`**

Open `src/Setup/BlockTemplates.php` and replace the empty `register_templates()` body with:

```php
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
```

The `function_exists()` guard makes the file harmless on sites that downgrade below WP 6.7 — it bails silently instead of fatal-ing.

- [ ] **Step 4: Boot the setup class in `Plugin.php`**

Open `src/Plugin.php`, alongside the `DonationPageSetting` registration added in Task 5, add:

```php
		( new \Team51\GivingDay\Setup\BlockTemplates() )->register();
```

- [ ] **Step 5: Smoke-verify in browser**

Active theme must be a block (FSE) theme — verify:

```bash
wp theme list --status=active
```

If the active theme is classic, install/activate a block theme temporarily for verification (e.g., Twenty Twenty-Five): `wp theme activate twentytwentyfive`.

Then visit `http://giving-day.local/team/{some-slug}/` in the browser. Expected:
- Page renders with title at top, Goal block under it (if goal is set), Donate button below, then post content.
- View source: confirm the Goal block emits its progressbar markup with `data-target-type="team"`.
- Donate button href: `…/donate?gd_team={id}` (or whatever the configured donation page is).
- A Team post with `_giving_team_goal_amount` = 0 or unset: the Goal block does not render, but the Donate button still does.

Repeat for a single Beneficiary page.

- [ ] **Step 6: Commit**

```bash
git add templates/ src/Setup/BlockTemplates.php src/Plugin.php
git commit -m "feat: ship FSE templates for single Team and Beneficiary"
```

---

### Task 13: README and docs update

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add notes near the requirements section**

Append or insert into the requirements section of `README.md`:

```markdown
## Single Team / Beneficiary pages

This plugin ships FSE templates that automatically render a goal progress bar (when a goal is set) and a Donate button on every single Team and single Beneficiary post page.

**Requirements:**
- WordPress 6.7+ (for `register_block_template()`)
- A block (FSE) theme. The plugin's FSE templates do not apply on classic themes.

**Donate page configuration:** Settings → Reading → "Donation page". Defaults to `/donate`. Can also be overridden in code via the `giving_day_donation_page_url` filter.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: document Team/Beneficiary FSE templates and donate page setting"
```

---

### Task 14: End-to-end verification + version bump

**Files:**
- Modify: `giving-day-blocks.php` (drop `-dev` suffix)

Do a full E2E pass before declaring the feature done.

- [ ] **Step 1: Manual E2E checklist**

In the browser, with a block theme active:

- [ ] Single Team page (goal set, donations present): goal bar shows correct raised/percent.
- [ ] Single Team page (goal = 0): goal block hidden, donate button still shown.
- [ ] Single Team page (no donations yet): goal bar at 0%, no JS errors.
- [ ] Donate button click on Team page lands on donation page with `gd_team` URL param. Donation form chip populated (regression test for existing `DonationDesignationChips` integration).
- [ ] Same matrix for Beneficiary page.
- [ ] Set a custom donation page in Settings → Reading; Donate button updates accordingly.
- [ ] Pre-existing Campaign goal-progress blocks (using `campaignId`) still render correctly.
- [ ] Theme override works: copy `single-team.html` into the active block theme's `templates/` directory; that version takes precedence in Site Editor and on the frontend.

- [ ] **Step 2: PHPCS pass**

```bash
phpcs --standard=WordPress src/ blocks/src/ templates/
```

Expected: no errors. Fix any that surface.

- [ ] **Step 3: Run full Jest suite**

```bash
npm test
```

Expected: all green.

- [ ] **Step 4: Bump version**

In `giving-day-blocks.php`:

```php
define( 'GIVING_DAY_BLOCKS_VERSION', '1.1.0' );
```

And update the header `Version:` line if it exists there too.

- [ ] **Step 5: Final commit**

```bash
git add giving-day-blocks.php
git commit -m "chore: release 1.1.0 — Team/Beneficiary single-page goal + donate"
```

---

## Self-review

**Spec coverage:**
- ✅ Goal block extension to Team/Beneficiary → Task 3
- ✅ "No goal → no render" rule → Task 7
- ✅ Donate button block → Task 10, 11
- ✅ Configurable donation URL → Task 5
- ✅ FSE templates → Task 12
- ✅ Goal → Donate order → Task 12 (template order)
- ✅ `Requires at least` bump → Task 1
- ✅ REST endpoints → Task 4
- ✅ Aggregator helpers → Task 2
- ✅ goalProgress.js JS branch → Task 9
- ✅ Editor UI for new target types → Task 8

**Placeholder scan:** No "TBD" / "TODO" / "implement later" in steps. The "open question deferred to implementation" note in Task 2 about order statuses is a verification prompt, not a placeholder — it tells the implementer what to grep for.

**Type consistency:** `targetType` / `targetId` used consistently across block.json, render.php, edit.js, view.js, goalProgress.js. Constants `TYPE_TEAM` / `TYPE_BENEFICIARY` / `TYPE_NONE` used consistently in `GoalProgress` and `DonationUrl` (the latter mirrors the former's names for symmetry).

**Risks called out:**
- Aggregator order-status set may need harmonizing (Task 2, Step 1).
- `Colors::inline_style()` is campaign-scoped; gated in Task 7.
- `Context::set()` is campaign-scoped; only called for campaign target in Task 7.
- Block theme required for FSE templates; documented in Task 13 and verified in Task 14.
