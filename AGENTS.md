# AGENTS.md

## What this plugin is

`giving-day-blocks` is a **generic, reusable WordPress plugin** intended to be installed by **any university, college, nonprofit, or community organization** that runs a Giving Day campaign. It is **not** built for a single event, a single school, or a single year.

Every change must be designed with that audience in mind: unknown sites, unknown themes, unknown data, unknown admins, unknown campaign dates.

## How to think about every task

When the user reports a bug or asks for a fix, do not stop at "make this site work today." Work through these layers in order:

1. **Root cause** — why did this actually break? Reproduce the failure path in code, not just on this machine.
2. **Generalization** — would this same fix hold up on a different site, with different content, different roles, different theme, different campaign dates, different locale/timezone, different post counts? If not, the fix is wrong.
3. **UX upstream** — was the user (admin or donor) ever in a position to do the wrong thing? If a misconfiguration, missing field, ambiguous label, or unguarded input led to the bug, fix that too. The best bug fix is the one that prevents the bug from being reachable.

## What "fixing it" does **not** mean

- Editing the database directly (options, postmeta, transients) to patch one site's state.
- Hardcoding IDs, slugs, dates, URLs, org names, or campaign-specific copy.
- One-off scripts or "run this once" snippets.
- Conditionals keyed on a specific environment, host, or installation.
- Workarounds that depend on the current dataset rather than the data model.

If a one-time data repair is genuinely required, surface it explicitly and separately — it is not the fix.

## Questions to ask before proposing a change

- Does this assume anything about *this* site that wouldn't hold on a fresh install?
- What happens on a multisite, a non-English locale, a different timezone, a site with thousands of donations, or a site with zero donations yet?
- Is the setting/option/field discoverable by an admin who has never seen this plugin before?
- If two organizations install this plugin in the same year, do their campaigns stay independent?
- Are we leaning on a value that only exists because of how this specific install was seeded?

If any answer is unclear, ask the user before writing code.

## Confidence and honesty

- State assumptions explicitly. If you don't know how a setting is populated on a generic install, say so and check the code path rather than guessing from this machine's state.
- Prefer reading the plugin's public APIs, registration code, and block definitions over inspecting local options/transients to infer behavior.

---

## Technical specs

### Runtime requirements
- **PHP:** `>=8.1` (composer `platform.php = 8.1`).
- **WordPress:** `>=6.4`.
- **WooCommerce:** `>=7.5`. Declares HPOS (`custom_order_tables`) compatibility.
- **Sibling plugin:** `team51-donations` is a required runtime dependency (donation form + custom-fields registry). Lives at `../team51-donations/` in this repo layout. See `PLAN.md` in that plugin for the field-registry contract — `giving-day-blocks` is the *consumer*, not the owner.

### PHP / Composer
- **Namespace:** `Team51\GivingDay\` → PSR-4 to `src/`. Autoloader at `vendor/autoload.php` is loaded from the bootstrap on `plugins_loaded`; missing autoloader surfaces an admin notice rather than fatals.
- **Entry point:** `giving-day-blocks.php` → `Team51\GivingDay\Plugin::get_instance()->initialize()` (singleton, wires every component).
- **Coding standard:** `wp-coding-standards/wpcs ^3.0` + `phpcompatibility/phpcompatibility-wp`. There is **no** `phpcs.xml` checked in — `composer run lint:php` runs `phpcs --basepath=. . -v` against the installed standards. When linting a single file, use `phpcs --standard=WordPress <file>`.
- **Lint:** `composer run lint:php`
- **Auto-fix:** `composer run format:php`
- **i18n:** `composer run internationalize` (wraps `makepot` / `updatepo` / `makejson`). Text domain: `giving-day-blocks`.

### JS / build (wp-scripts)
- **Node:** `>=20.10`, **npm:** `>=10.2`.
- Three independent webpack builds — all must be rebuilt after touching their sources:
  - `npm run build:blocks` — `blocks/src/**` → `blocks/build/**` (also copies `render.php`).
  - `npm run build:admin` — `assets/admin-campaign/**` → `assets/build/admin-campaign/**`.
  - `npm run build:match` — `assets/admin-match/**` → `assets/build/admin-match/**`.
- `npm run build` runs all three sequentially. Dev watch: `npm run start` / `start:admin` / `start:match`.
- **Lint:** `npm run lint` (scripts + styles + pkg-json + readme).
- **Format:** `npm run format`.
- **Tests:** `npm test` (Jest via `wp-scripts test-unit-js`, config at `jest.config.js`; tests in `tests/js/`).

### Block registration
- Blocks are registered from the compiled manifest at `blocks/build/blocks-manifest.php` (WP 6.7+ `wp_register_block_types_from_metadata_collection`) with a per-folder `register_block_type_from_metadata` fallback. **You cannot register a new block by editing source alone — you must `npm run build` so the manifest and metadata land in `blocks/build/`.**
- Block sources live under `blocks/src/<name>/` with the standard `block.json` / `edit.js` / `view.js` / `render.php` / `*.scss` shape. Shared SCSS tokens and JS hooks are under `blocks/src/_shared/`.
- Current blocks: `causes-browser`, `countdown`, `goal-progress`, `leaderboard`, `leaderboard-tabs`, `match-my-gift`, `totals`. (Some block names in `README.md` describe planned blocks not yet in `blocks/src/` — trust the filesystem.)

### Data model — slugs you will reference often
- **CPTs** (all `show_in_rest`, non-public, grouped under the "Giving Day" admin menu):
  - `giving_campaign` — the event itself (one post per yearly run).
  - `giving_team` — long-lived; M2M to campaigns via `_giving_team_campaigns`.
  - `giving_beneficiary` — hierarchical; M2M to campaigns via `_giving_beneficiary_campaigns`. UI label "Beneficiary / Fund".
  - `giving_match` — per-campaign sponsor match.
  - `giving_challenge` — reusable time-boxed mini-event with a *relative* window resolved per campaign.
- **Taxonomies** (hierarchical, `show_in_rest`):
  - `giving_cause` (REST base `causes`) — attached to `giving_beneficiary`.
  - `giving_team_group` (REST base `team-groups`) — attached to `giving_team`.

### REST API
- **Namespace:** `giving-day/v1` (defined as `REST::NAMESPACE`). All routes live in `src/REST.php`.
- Read routes are `permission_callback = __return_true`; writes require `manage_options`.
- Every response includes a `server_time` field — **client countdowns must anchor to it, never `Date.now()`**.
- The `?givingday=pre|live|post` query arg is a status preview override honored **only for logged-in users** (`Data\Status`).

### Aggregator + caching
- `Team51\GivingDay\Data\Aggregator` is the authoritative source of campaign totals (raised, donor count, etc.). Cache is invalidated via `Aggregator::invalidate( $campaign_id )`. **Never** reintroduce raw-override meta like `_giving_raised_override` — `Plugin::maybe_purge_legacy_overrides()` actively scrubs it.
- Rewrite rules are flushed once per deploy when `Plugin::REWRITE_RULES_VERSION` increases. Bump that constant if you change CPT/taxonomy `rewrite` args.

### Code layout (`src/`)
- `Plugin.php` — singleton bootstrap.
- `PostTypes/`, `Taxonomies/` — each extends an `Abstract*` base; slugs are class constants (`POST_TYPE`, `TAXONOMY`).
- `Admin/` — settings page, menu, screen intro, CPT-specific editor panels, offline-donations admin, cause term meta, order attribution meta box.
- `Data/` — `Aggregator`, `Context`, `Status`, `Leaderboard`, `GoalProgress`, `MatchProgress`, `Colors`.
- `Frontend/SingleTemplates.php` — single-template overrides.
- `Integrations/` — `OrderAttribution` (tags WC orders with campaign context), `OfflineGateway`.
- `Services/` — `CampaignSetup`, `OfflineDonations`.
- `Setup/MockData.php` — first-activation sample data seeder. Activation sets the `giving_day_blocks_pending_seed` option; actual seeding runs on the next `admin_init`.
- `REST.php`, `Blocks.php` — REST router and block registrar.

### Definition of done for a PHP change
1. `composer run lint:php` passes (or `composer run format:php` to autofix first).
2. If you touched JS/SCSS/`block.json`, run the matching `npm run build:*` so `blocks/build/` and `assets/build/` are in sync.
3. If you touched JS logic with a corresponding test in `tests/js/`, run `npm test`.
4. Do not edit anything under `blocks/build/`, `assets/build/`, `vendor/`, or `node_modules/` directly — they are build/output and will be overwritten.
