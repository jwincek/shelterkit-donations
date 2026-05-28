# Changelog

All notable changes to Starter Shelter Donations will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Reports campaign filter now also scopes the stats cards and donation-trend chart (previously only per-record tables filtered). Three additional abilities gained a `campaign_id` input property and accompanying SQL: `shelter-donations/get-stats`, `shelter-reports/dashboard-stats`, `shelter-reports/donation-trend`. Each adds an `INNER JOIN wp_term_relationships + wp_term_taxonomy` only when `campaign_id > 0`, then constrains `tt.term_id = %d`. Donor totals (lifetime / new-in-period) deliberately stay unfiltered — donors aren't directly campaign-tagged; we'd need a transitive join through donations/memberships which changes the metric's meaning. Notice copy under the filter now reflects the new scope.
- Reports tabs (Donations, Memberships, Memorials) now have a Campaign filter dropdown. Picking a campaign filters the per-record table to that campaign's conversions; stats cards remain period-wide for comparison and the page surfaces a notice clarifying the scope. The CSV Export carries the filter through (filename suffix `-campaign-{id}`). To support this, `shelter-memberships/list` and `shelter-memorials/list` gained a `campaign_id` input property and the callbacks now thread it into `Query::for(...)->whereInTaxonomy('sd_campaign', ...)`. `shelter-donations/list` already supported the param.
- `membership-form` editor inspector now exposes a Campaign picker, mirroring `donation-form`. Behaves identically: admin-set value wins over URL auto-tagging, no campaign locking when the picker is unset.
- Auto-tag donations and memberships to a campaign via `?campaign={id}` in the page URL. New helper `Helpers\resolve_campaign_id_from_request()` reads the query var, validates it against `sd_campaign`, and returns the term ID (or 0). `donation-form` and `membership-form` block renderers now use it as a fallback when no `campaignId` block attribute is set, so a click through campaign-card lands on the form page and the resulting donation/membership is attached to the originating campaign — the existing cart→order→ability `_sd_campaign_id` plumbing carries it through. `membership-form` gained a `campaignId` attribute (it had none), state/context wiring, "Supporting: {name}" badge, and `campaign_id` is now appended to its cart-submission formData.
- New `donation_page` and `membership_page` settings (Settings → General → Pages). `campaign-card` uses them to build the CTA target — donation drives link to the donation page, membership drives link to the membership page, both with `?campaign={id}` appended. Same settings replace the hardcoded `home_url('/donate/')` in `donor-dashboard/render.php` and `class-my-account.php::render_no_donor_message()`. When a page is unset the button is hidden rather than emitting a broken link, so admins notice the missing CTA and configure it. Two new helpers: `Helpers\get_donation_page_url()` and `Helpers\get_membership_page_url()`.
- `campaign-card` block now has an editor inspector: campaign picker (sourced from `window.starterShelterBlocks.campaigns`), display-option toggles (goal / raised-or-joined / donors / end date / donate button), and a progress-bar color swatch. Server-side render in the canvas previews the selected campaign.
- `campaign-card` button label is now type-aware: "Donate Now" on donation drives, "Join Now" on membership drives.
- `campaign-card` editor preview now loads the block's style.css (via new `editorStyle` declaration in block.json), so the progress bar and stats render the same in the editor as on the frontend.

### Changed
- `List_Columns` column configuration is now built lazily on first access instead of during plugin bootstrap, so `__()` calls no longer run before the `init` hook.
- `validate_item_meta()` return type relaxed from `true|WP_Error` to `bool|WP_Error`; the `true` literal type requires PHP 8.2+ and the plugin still declares an 8.1 floor.
- `campaign-card` block.json: removed `"ancestor": []` (empty array meant "no valid ancestors", hiding the block from the inserter even though it was registered). Added `"editorScript": "file:./edit.js"`.
- `campaign-card` donate button no longer carries `wp-element-button`, so themes stop overriding the configured `progressBarColor`. Added `box-sizing: border-box` to the card and descendants to prevent the full-width button overflowing the card (root cause: `width: 100%` + `padding: 0.875rem 1.5rem` under default `content-box`).

### Fixed
- `List_Columns` row layout no longer scrambles after Quick Edit. `register_columns()` and `register_sortable()` called `get_current_screen()` to disambiguate post type, but that global is null during `wp_ajax_inline_save()` — the filter then fell through to WP's default columns (`cb`, `title`, `date`), and the AJAX-returned row HTML didn't match the 8-cell table on the page. Filters now capture `$post_type` in a closure at registration time, removing the screen lookup entirely.
- Resolved "Translation loading was triggered too early" notice from `List_Columns::init()` running on `plugins_loaded` (same root cause as the 1.1.1 `Checkout_Fields` fix).
- WC Blocks mini-cart now refreshes after a form submit without requiring a page reload. The plugin was firing only `wc-blocks_added_to_cart` (the success event), but WC's mini-cart frontend uses a two-phase lazy-load — only `wc-blocks_adding_to_cart` is wired up initially, and the success listener isn't registered until the bundle finishes loading. Now firing both events (`adding_to_cart` before the fetch, `added_to_cart` on success with explicit `detail.preserveCartData: false` so the mini-cart refetches via the WC Store API).
- CI: `php -l` matrix step ran `find ... | grep -v 'No syntax errors'`, which exits non-zero on success — the job was reporting a clean codebase as a failure. Switched to `xargs -n1 php -l` so the step actually validates 8.1–8.3 compatibility.
- CI: added `phpcs.xml` (PHPCompatibilityWP ruleset, `testVersion=8.1-`); the `PHPCS Lint` job had been exiting 2 (usage error) every run because no config file was present.

## [1.1.1] - 2026-04-07

### Added
- Interactive "light a candle" feature on the memorial wall: per-user candle state, optimistic UI toggle, REST-backed persistence, server confirmation with rollback on failure.
- `LICENSE` file (GPL-2.0+).

### Changed
- Memorial wall candle interactivity moved into the `starter-shelter/memorials` Interactivity store so per-item `data-wp-each` context is available to the toggle action and derived state. The `starter-shelter/candles` namespace is now state-only (lit-list), seeded server-side via `wp_interactivity_state()`.
- Checkout field definitions in `Checkout_Fields` are now built lazily on first access instead of during plugin bootstrap, so `__()` calls no longer run before the `init` hook.
- Memorial wall block now omits empty filter keys from `shelter-memorials/list` ability input to satisfy schema validation, instead of passing `null` for `year`/`search`.

### Fixed
- Resolved "Translation loading was triggered too early" notice from `Checkout_Fields::init()` running on `plugins_loaded`.
- Resolved "Missing callback for ability" warnings for `shelter-{donations,memberships,memorials}/list` by adding explicit `callback` overrides in `config/abilities.json` (the auto-derived function name `list` collided with a PHP reserved keyword).
- Memorial wall block returning zero items when querying via the `shelter-memorials/list` ability (schema validation rejected `null` filter values).
- Removed the temporary `error_log` debug call in `register-bindings.php` left over from binding development.

## [1.0.0] - 2026-XX-XX

### Added
- Config-driven architecture with JSON definitions for entities, abilities, products, emails, and post types.
- Core infrastructure: Config loader with `$ref` resolution, Entity Hydrator, Query builder, CPT Registry.
- WordPress 6.9+ Abilities API integration with 16 registered abilities across donations, memberships, memorials, donors, and reports.
- WooCommerce integration: Product Mapper, Order Handler, Checkout Fields, Cart Handler, My Account endpoints.
- Four variable WooCommerce products: General Donations, In Memoriam Donations, Individual Memberships, Business Memberships.
- Custom post types: `sd_donation`, `sd_membership`, `sd_memorial`, `sd_donor`.
- Nine custom blocks: donation-form, membership-form, memorial-form, memorial-wall, memorial-archive, campaign-card, campaign-progress, donor-dashboard, donor-stats.
- Block Bindings sources for shelter post data, post meta, term data, and pattern overrides.
- Interactivity API stores for memorials, donations, memberships, campaigns, and donor dashboard.
- Config-driven WooCommerce email integration with HTML and plain-text templates.
- Admin pages: Settings, Reports, Dashboard Widget, Logo Moderation, Import/Export, Legacy Order Sync, Data Integrity, Activity Log.
- Auto-generated meta boxes from entity config.
- Custom list table columns for all CPTs.
- CSV import/export with validation and legacy memorial parsing.
- Legacy order sync tooling for migrating historical WooCommerce orders.
- Membership renewal reminder cron job.
- REST API controller for ability-backed endpoints.
- Block editor enhancements: custom block category, editor-only assets.
- Single memorial template (block-based and classic fallback).
