# Giving Day Blocks

An out-of-the-box, open-source **Giving Day** product for WooCommerce. A set of purpose-built Gutenberg blocks, a shared data layer, and an admin dashboard that let any organization launch a complete fundraiser event in minutes instead of months.

## What you get

Eight blocks designed to work together during a Giving Day event:

| Block | Purpose |
|-------|---------|
| `giving-day/goal-progress` | Real-time visual tracker toward a fundraising goal. Horizontal and vertical layouts. |
| `giving-day/countdown-pre` | Pre-event countdown that builds anticipation, then hands off at 00:00:00. |
| `giving-day/countdown-event` | Event-day countdown (24h or custom). Appears automatically when the pre-event countdown finishes. |
| `giving-day/totals-post` | Final totals shown once the event ends. Replaces the countdown in place. |
| `giving-day/leaderboard` | Top donors, teams, campaigns, or causes. Configurable dimension and size. |
| `giving-day/match-my-gift` | Sponsor matching banner that doubles donation urgency. |
| `giving-day/challenges` | Time-boxed mini-events (e.g. "50 donations in the next hour unlocks $10,000"). |
| `giving-day/war-room` | Back-office live dashboard for organizers. Block + standalone admin page. |

All blocks are:

- **Mobile-first and responsive** (SCSS with shared tokens and a `media()` breakpoint mixin).
- **Theme-aware** — styles pull from `theme.json` via CSS custom properties (`var(--wp--preset--color--primary)` etc.). No hardcoded colors or fonts.
- **Accessible** — visible focus, `aria-live` on live-updating regions, `role="progressbar"`, and `prefers-reduced-motion` support.
- **Server-rendered first** — blocks work without JavaScript; `view.js` upgrades them with live refresh when available.
- **Drift-corrected** — all countdowns are anchored to server time returned by the REST API, never the browser clock.

## Data model

Four custom post types, all `show_in_rest`:

- **`giving_campaign`** — the event itself: start/end, pre-event start, goal amount, currency, timezone, linked donation products.
- **`giving_team`** — belongs to a campaign; has a captain, slug, image, optional team goal.
- **`giving_match`** — sponsor match: multiplier, cap, active window.
- **`giving_challenge`** — time-boxed challenge: type (donations / amount / team), threshold, reward.

## REST API

Namespace: `giving-day/v1`. Read endpoints are public; write endpoints require `manage_options`.

| Endpoint | Returns |
|----------|---------|
| `GET /campaign/{id}/summary` | Goal, raised, percent, donor count, event window |
| `GET /campaign/{id}/countdown` | Pre-event start, start, end, and `server_time` (ISO 8601) |
| `GET /campaign/{id}/leaderboard?dimension=&limit=` | Top donors / teams / campaigns / causes |
| `GET /match/{id}/progress` | Matched so far, cap, remaining |
| `GET /challenge/{id}/progress` | Current value, threshold, window remaining |
| `GET /campaign/{id}/warroom` | Aggregated payload for the dashboard |

Every response includes a `server_time` field so client countdowns never drift.

## Directory layout

```
giving-day-blocks/
├── giving-day-blocks.php       # Plugin bootstrap, dependency checks
├── functions.php
├── composer.json               # PSR-4: Team51\GivingDay\
├── package.json                # wp-scripts, jest, jest-preset-default
├── jest.config.js
├── blocks/
│   ├── src/
│   │   ├── _shared/            # Shared hooks, tokens.scss, utilities
│   │   ├── goal-progress/
│   │   ├── countdown-pre/
│   │   ├── countdown-event/
│   │   ├── totals-post/
│   │   ├── leaderboard/
│   │   ├── match-my-gift/
│   │   ├── challenges/
│   │   └── war-room/
│   └── build/                  # Generated — do not edit
├── src/                        # PSR-4 PHP
│   ├── Plugin.php
│   ├── Blocks.php
│   ├── PostTypes/
│   ├── Data/Aggregator.php
│   ├── REST.php
│   ├── Admin/
│   └── Integrations/Donations.php
├── assets/                     # Admin React (War Room admin page)
├── tests/js/                   # Jest tests per block
└── languages/
```

## Development

### Setup

```bash
cd wp-content/plugins/giving-day-blocks
composer install
npm install
```

### Scripts

```bash
npm run start     # Watch mode for blocks (wp-scripts)
npm run build     # Production build into blocks/build/
npm test          # Run Jest suite
npm run test:watch
npm run lint      # ESLint + Stylelint + pkg-json
npm run format    # Auto-fix JS/SCSS
composer run lint:php
composer run format:php
```

### Conventions

- **Namespace:** `Team51\GivingDay\` (PSR-4 via Composer).
- **Text domain:** `giving-day-blocks`.
- **Block namespace:** `giving-day/*`.
- **Styles:** SCSS, mobile-first. Use tokens from `blocks/src/_shared/tokens.scss`; never hardcode colors or fonts — pull from `theme.json` via CSS custom properties.
- **Breakpoints:** `@include media(sm|md|lg)` mapped to 600 / 782 / 1080 px.
- **Server render first** (`render.php`); `view.js` is for live updates only.
- **Accessibility:** focus states, `aria-live="polite"` for live regions, `prefers-reduced-motion` respected.
- **i18n:** always use text domain `giving-day-blocks`. Run the internationalization script after adding strings.

### Testing

Tests use **Jest** with `@wordpress/jest-preset-default` in a `jsdom` environment. Every block has at least:

1. A placeholder-state test (no campaign selected).
2. An attribute-update test (campaign picker changes state).
3. A snapshot of save output or `view.js` DOM render.

Shared hooks (`useCountdown`, `useLiveRefresh`) have their own unit tests under `tests/js/_shared/`.

`@wordpress/api-fetch` and `@wordpress/data` are mocked via `tests/__mocks__/`.

## License

This plugin is licensed under the **GNU General Public License v2.0 or later** 