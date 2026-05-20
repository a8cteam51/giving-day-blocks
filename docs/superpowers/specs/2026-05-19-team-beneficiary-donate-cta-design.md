# Team / Beneficiary Single-Page Goal + Donate CTA

**Status:** Design
**Date:** 2026-05-19
**Author:** Esteban Cairol (with Claude)

## Goal

Every single Team and single Beneficiary post page should, by default, render two new pieces of UI driven by giving-day-blocks:

1. **A Goal progress block** scoped to that Team or Beneficiary — *only when a goal is defined for that entity*. No goal = nothing rendered.
2. **A Donate button** linking to the site's donation page, pre-tagged with the appropriate URL param (`gd_team={ID}` or `gd_beneficiary={ID}`) so the donation form arrives with that designation already populated.

Order on the page is Goal → Donate (progress first, then call-to-action — standard conversion pattern).

Out of scope for this spec: archive/listing pages (the CPTs use `has_archive => false`); changes to the donation form itself in `team51-donations`.

## Architectural shape

Three units, each with one clear job:

```text
                       ┌──────────────────────────────────┐
   FSE template        │  templates/single-team.html      │
   (plugin-registered) │  templates/single-beneficiary.html│
                       └──────────────────────────────────┘
                                       │
                          composes ────┴────────┐
                                       │        │
                       ┌───────────────▼──┐  ┌──▼───────────────┐
   New / extended      │  goal-progress   │  │  donate-button   │
   blocks              │  (extended)      │  │  (new)           │
                       └──────┬───────────┘  └──────┬───────────┘
                              │                     │
                              ▼                     ▼
                       ┌────────────────┐  ┌────────────────────┐
   Helpers             │  GoalProgress  │  │  DonationUrl       │
                       │  (extended)    │  │  (new helper)      │
                       └────────────────┘  └────────────────────┘
```

Each unit is independently testable. The FSE templates depend on both blocks; the blocks depend on the helpers; the helpers depend only on WP/WC core.

## Components

### 1. `GoalProgress` extension — Team & Beneficiary targets

**File:** `src/Data/GoalProgress.php`

Today the resolver only handles Campaigns. The docblock at `GoalProgress.php:6-14` already anticipates this extension. Add:

- `public const TYPE_TEAM = 'team';`
- `public const TYPE_BENEFICIARY = 'beneficiary';`
- New `case` branches in `resolve()` that dispatch to `resolve_team()` / `resolve_beneficiary()`
- `resolve_team()`:
  - Validates post type is `Team::POST_TYPE`
  - Goal: `get_post_meta( $id, Team::META_GOAL_AMOUNT, true )`
  - Raised + donor count: new method on `Aggregator` — `totals_for_team( $team_id )` — that sums WC line items where `_giving_team_id === $team_id`
  - Currency: same WC default as campaign branch
- `resolve_beneficiary()`:
  - Validates post type is `Beneficiary::POST_TYPE`
  - Goal: `get_post_meta( $id, Beneficiary::META_GOAL_AMOUNT, true )` — **parent's own meta only**, no roll-up of children's goals (per design decision)
  - Raised + donor count: new method `Aggregator::totals_for_beneficiary( $beneficiary_id )`. **Open question deferred to implementation:** does this include donations tagged to descendant Beneficiaries? Default answer: yes (matches the "Parent posts roll up children at query time" intent from `Beneficiary.php:31`), but verify against existing Aggregator behavior. If it doesn't roll up today and we change it, that's a behavior change beyond this feature's scope — punt to a separate decision.

Return shape is unchanged — same array as the campaign branch.

**Tests:**
- Goal = 0 → returns payload with `goal: 0` (caller decides what to do)
- Unknown post type for the given ID → returns `null`
- Team with goal but no donations → `raised: 0`, `percent: 0`

### 2. `goal-progress` block — accept Team/Beneficiary targets

**Files:** `blocks/src/goal-progress/{block.json,edit.js,render.php,view.js}` + `blocks/src/_shared/utils/goalProgress.js`

**New attributes:**
- `targetType`: `'campaign' | 'team' | 'beneficiary' | 'auto'`, default `'auto'`
- `targetId`: number, default `0` (meaning auto-resolve from current post)

Keep the existing `campaignId` attribute for backwards compatibility — if `targetType` is `'campaign'` and `targetId` is empty but `campaignId` is set, use that. Deprecate but don't remove.

**Auto-detect behavior in `render.php`:**
When `targetType === 'auto'`, read `get_post()` at render time:
- `Team::POST_TYPE` → `TYPE_TEAM`, current post ID
- `Beneficiary::POST_TYPE` → `TYPE_BENEFICIARY`, current post ID
- `Campaign::POST_TYPE` → `TYPE_CAMPAIGN`, current post ID
- Anything else → return nothing rendered

This lets the same block markup work in any of the three template contexts without per-template configuration.

**The "no goal → render nothing" rule:**
In `render.php`, after calling `GoalProgress::resolve()`, check `$goal <= 0`. If so, `return;` before any markup. **This is a deliberate behavior change** for the Campaign case too: today the block renders a "no goal" track when goal is zero. New behavior is uniform across all three target types — no goal means no render. Verify with stakeholder that this is OK for Campaign pages, or gate the change on target type. Recommended: uniform behavior (cleaner mental model).

**REST endpoint for live-refresh:**
Today there's a `/campaign/{id}/summary` endpoint (per the docblock comment). Mirror with:
- `GET /giving-day/v1/team/{id}/summary`
- `GET /giving-day/v1/beneficiary/{id}/summary`

Same response shape as the campaign endpoint. Add routes in `src/REST.php`.

**JS branch in `goalProgress.js`:**
Read `data-target-type` and `data-target-id` from the wrapper (replacing or supplementing `data-campaign-id`). Build the right endpoint URL. Otherwise the hydration logic is identical.

**Editor (edit.js):**
Replace the campaign-only picker with a target-type selector + post-picker. Default to "auto-detect from current post" with a help text hint.

### 3. `donate-button` block — new

**Files (new):** `blocks/src/donate-button/{block.json,edit.js,render.php,style.scss,editor.scss,index.js}`

A small server-rendered block. Renders an `<a>` styled as a button.

**Attributes:**
- `label`: string, default `__( 'Donate', 'giving-day-blocks' )`
- `targetType`: `'auto' | 'team' | 'beneficiary' | 'none'`, default `'auto'`
- `targetId`: number, default `0`
- `align`, color, typography, spacing supports — same as core button

**Render logic:**
- Resolve target via the same auto-detect rules as `goal-progress`
- Build URL via new `DonationUrl::build( $target_type, $target_id )` helper (see component 4)
- Output a styled link with appropriate ARIA

**Special case:** `targetType === 'none'` → bare donate page link with no `gd_*` param. Useful for placing on Campaign pages where no specific designation makes sense.

**Editor:** an InspectorControls panel for target type + post picker. Live preview shows the resolved URL.

### 4. `DonationUrl` helper — new

**File (new):** `src/Data/DonationUrl.php`

Single static helper that any code (block, template, future widget) can use to build a donation URL.

```php
DonationUrl::build( string $type, int $id ): string
```

**Behavior:**
- Reads the donation page slug from a configurable source (see "Configuration" below)
- Appends `gd_team={id}` or `gd_beneficiary={id}` (using the param names from `DonationDesignationChips.php:102,121`)
- `type === 'none'` → just the donation page URL with no params

**Configuration:**
The donation page URL must be configurable because not every site uses `/donate` (some use `/give`, etc.). Resolution order:
1. Filter: `apply_filters( 'giving_day_donation_page_url', $default )` — themes/code can fully override
2. Option: `giving_day_donation_page_id` (post ID), resolved via `get_permalink()` — admin-editable
3. Default: `home_url( '/donate' )` — works for the recommended theme out of the box

The option lives under a new admin setting (or piggybacks on an existing settings screen — check `src/Admin` for an appropriate parent). Field is a Page picker that lists all published pages.

### 5. FSE block templates — plugin-shipped

**Files (new):**
- `templates/single-team.html`
- `templates/single-beneficiary.html`

Registered via `register_block_template()` in a new `src/Setup/BlockTemplates.php`.

```php
register_block_template(
    'giving-day-blocks//single-team',
    array(
        'title'       => __( 'Single Team (Giving Day)', 'giving-day-blocks' ),
        'description' => __( 'Default template for Team posts: title, goal, donate button, content.', 'giving-day-blocks' ),
        'content'     => file_get_contents( $path_to_html_file ),
        'post_types'  => array( Team::POST_TYPE ),
    )
);
```

**Template content (conceptual layout, exact markup TBD in implementation):**

```text
- Header (theme default)
- Post title
- giving-day/goal-progress  (targetType: auto)
- giving-day/donate-button   (targetType: auto)
- Post content
- Footer (theme default)
```

Same structure for Beneficiary.

**Why this approach:** registered templates appear in the Site Editor and can be customized by users without touching code. Themes can override by shipping their own `single-team.html` — standard FSE precedence applies.

**Constraint:** requires WP 6.7+ for `register_block_template()` and requires a block (FSE) theme to take effect. Bump the plugin's `Requires at least` header from 6.4 to 6.7. Document the block-theme requirement in the README.

## Configuration surface

| Setting | Mechanism | Default |
|---|---|---|
| Donation page URL | Filter `giving_day_donation_page_url`, falling back to option `giving_day_donation_page_id`, falling back to `home_url('/donate')` | `/donate` |
| Goal block target | Block attribute `targetType` / `targetId` | `'auto'` (resolves from current post) |
| Donate button target | Block attribute `targetType` / `targetId` | `'auto'` |
| Donate button label | Block attribute `label` | `"Donate"` |

No new admin screen required if we piggyback on an existing settings location for the donation page picker; otherwise one new field on a new "Giving Day → Settings" screen.

## Data-flow summary

1. Visitor lands on `/team/{slug}`
2. WP resolves template: `single-team` (registered by this plugin, or theme override if present)
3. Template includes `goal-progress` and `donate-button` blocks, both in `auto` mode
4. `goal-progress` render: `get_post()` → Team → `GoalProgress::resolve(TYPE_TEAM, $id)` → check goal > 0 → render or skip
5. `donate-button` render: `get_post()` → Team → `DonationUrl::build('team', $id)` → `https://site/donate?gd_team={id}`
6. On page load, `view.js` for `goal-progress` hydrates by calling `/wp-json/giving-day/v1/team/{id}/summary`

## Migration & rollout

- **No data migration needed.** Goal and donation-tagging meta already exist on Teams/Beneficiaries and on WC orders.
- **Existing posts:** new FSE templates apply immediately to all existing Team and Beneficiary posts since templates are post-type-scoped, not per-post.
- **Theme compatibility:** if the active theme is a classic (non-block) theme, the FSE templates don't apply. Document this in the README. The recommended companion theme is expected to be a block theme; verify.
- **Backwards compat for `goal-progress`:** existing block instances using `campaignId` keep working via the deprecation path described in component 2.

## Testing strategy

**Unit / integration (PHPUnit + WP test harness):**
- `GoalProgress::resolve(TYPE_TEAM, $id)` returns expected shape for: goal-set + donations, goal-set + no donations, no goal, invalid ID, wrong post type
- Same matrix for `TYPE_BENEFICIARY`
- `DonationUrl::build()`: filter override wins, option override wins over default, all three target types produce correct query strings
- New REST routes: 200 with payload for valid ID, 404 for invalid

**Block render tests:**
- `goal-progress` render returns empty string when goal = 0
- `goal-progress` auto-detect picks correct target from current post type
- `donate-button` renders correct `href` for each target type

**Manual / browser:**
- Single Team page shows progress bar when goal is set, hides when goal is 0
- Donate button links to `/donate?gd_team={id}` and the donation form lands with chip populated (regression test against existing `DonationDesignationChips` integration)
- Beneficiary with children: parent page shows parent's own goal (verify roll-up decision)
- Custom donation page slug via filter and via option both work
- Live-refresh: open a Team page, simulate a donation via WP-CLI, watch the bar update without reload

## Open questions resolved during brainstorm

1. ✅ Scope is single CPT pages, not archives (CPTs have `has_archive => false`)
2. ✅ Donation page URL is configurable (filter + option + default)
3. ✅ Beneficiary goal = parent's own meta only (not summed across children)
4. ✅ Page order: Goal → Donate (conversion-friendly)
5. ✅ FSE templates (not `the_content` filter, not editor template + backfill)

## Open questions deferred to implementation

1. Does `Aggregator::totals_for_beneficiary` roll up donations from descendant Beneficiaries, or sum only direct hits? Default to rolling up; verify existing Aggregator behavior first.
2. Should the "no goal → no render" rule apply uniformly (including to Campaign target) or only to Team/Beneficiary? Recommended: uniform, but confirm.
3. Where does the donation-page picker setting live in the admin UI? Check `src/Admin` for the right parent.
4. Exact HTML/CSS for the FSE templates — defer to implementation, follow existing block patterns in the plugin.

## Non-goals

- Cards / per-item Goal+Donate inside listing blocks (e.g., inside `causes-browser`). Out of scope.
- Changes to the donation form itself in the sibling `team51-donations` plugin.
- A new "general/campaign-level" donate button on Campaign pages (the `'none'` mode covers this if someone wants it later).
- Multi-currency handling beyond what `GoalProgress` already does.
