# Admin / Dashboard / Reporting Audit

Plugin: `vcpahumane-wc-donations` (internal name: Starter Shelter Donations, v1.1.1)
Scope: `includes/admin/{class-menu, class-dashboard-widget, class-reports, class-activity-log}.php` (~2,600 LOC)
Reference layers cross-checked: `includes/abilities/`, `includes/core/`, `config/abilities.json`, `includes/core/helpers.php`
Dimensions: architecture alignment, code quality, security, correctness

---

## Executive summary

The dashboard and reports UIs are functionally broken in several places, in the same way and for the same reason: **the admin renderers expect a data shape that the abilities layer does not produce.** Every consumer reads fields like `memberships.active`, `memberships.expiring_soon`, `donors.total`, `by_allocation`, `by_tier`, `progress.total_raised`, but the registered abilities return `memberships.active_count`, `totals.new_donors`, `stats.progress`, etc. The renderers all guard with `?? 0`, so the failures are silent — every membership card, every donor card, every "by tier" / "by allocation" table, and the campaign CSV export quietly show zeros.

This is the single biggest finding and dwarfs everything else. The four files are otherwise reasonable, with a handful of architecture violations (duplicated raw SQL across admin files, ad-hoc queries that bypass `Query::for(...)`) and minor security/correctness issues (spoofable IP capture in the audit log, timezone confusion in "X ago" timestamps, hidden superglobal reads in `get_date_range_for_period`).

Recommended order of work:
1. **Fix the ability ↔ admin contract** — either widen `dashboard_stats` to produce what the UI needs, or change every admin renderer to consume what the ability actually returns. The first is right; the UI's expected shape is the better domain model.
2. **De-duplicate** the expiring-membership and pending-family-notification raw SQL (it lives in two places: [class-menu.php:247-268](includes/admin/class-menu.php#L247-L268) and [class-dashboard-widget.php:397-426](includes/admin/class-dashboard-widget.php#L397-L426)) by promoting to an ability or to `Query::for(...)`.
3. **Audit-log IP capture** — stop trusting forwarded headers unless behind a known proxy.
4. **Timezone** — `human_time_diff(strtotime(...))` calls are subtly wrong.

---

## 1. Critical correctness bugs — ability ↔ admin shape mismatches

The `shelter-reports/dashboard-stats` ability ([includes/abilities/reports.php:134-242](includes/abilities/reports.php#L134-L242)) returns:

```
donations   => { count, total, total_formatted, unique_donors, average }
memberships => { new_count, active_count, total, total_formatted }
memorials   => { count, total, total_formatted }
totals      => { grand_total, total_formatted, new_donors }
period, date_range, generated_at
```

The `shelter-donations/get-stats` ability ([includes/abilities/donations.php:139-180](includes/abilities/donations.php#L139-L180)) returns:

```
total_amount, total_formatted, donation_count, donor_count,
average_amount, period, date_range
```

The `shelter-reports/campaign-report` ability ([includes/abilities/reports.php:286-307](includes/abilities/reports.php#L286-L307)) returns:

```
campaign   => { id, name, description, goal, goal_formatted }
donations  => [...]
stats      => { total, total_formatted, count, donor_count, average, progress, remaining }
```

Here is what every admin consumer reads versus what is actually returned.

### 1.1 Main "Shelter Donations" dashboard page ([class-menu.php:78-189](includes/admin/class-menu.php#L78-L189))

| Reads | Ability returns | Effect |
|---|---|---|
| [L103](includes/admin/class-menu.php#L103) `donations.total` | ✓ | Works |
| [L110](includes/admin/class-menu.php#L110) `donations.count` | ✓ | Works |
| [L121](includes/admin/class-menu.php#L121) `memberships.active` | `active_count` | **Always 0** |
| [L128](includes/admin/class-menu.php#L128) `memberships.new` | `new_count` | **Always 0** |
| [L134, L139](includes/admin/class-menu.php#L134) `memberships.expiring_soon` | (not produced) | **Always 0**, border color logic broken |
| [L151](includes/admin/class-menu.php#L151) `donors.total` | (no `donors` key — only `totals.new_donors`) | **Always 0** |
| [L158](includes/admin/class-menu.php#L158) `donors.new` | (no `donors` key) | **Always 0** |

Three of four cards on the main admin landing page show zero regardless of database state.

### 1.2 WP dashboard widget ([class-dashboard-widget.php:83-268](includes/admin/class-dashboard-widget.php#L83-L268) and AJAX twin at L295-370)

| Reads | Returns | Effect |
|---|---|---|
| [L143, L334](includes/admin/class-dashboard-widget.php#L143) `donations.total` | ✓ | Works |
| [L153, L341](includes/admin/class-dashboard-widget.php#L153) `donations.count` | ✓ | Works |
| [L163, L348](includes/admin/class-dashboard-widget.php#L163) `donations.unique_donors` | ✓ | Works |
| [L173, L355](includes/admin/class-dashboard-widget.php#L173) `memberships.active` | `active_count` | **Always 0** |
| [L183, L362](includes/admin/class-dashboard-widget.php#L183) `memorials.count` | ✓ | Works |

### 1.3 Reports → Donations tab ([class-reports.php:206-269](includes/admin/class-reports.php#L206-L269))

Calls `shelter-donations/get-stats`. Reads:

| Reads | Returns | Effect |
|---|---|---|
| [L225](includes/admin/class-reports.php#L225) `total_formatted` | ✓ | Works |
| [L229](includes/admin/class-reports.php#L229) `donation_count` | ✓ | Works |
| [L233](includes/admin/class-reports.php#L233) `donor_count` | ✓ | Works |
| [L237](includes/admin/class-reports.php#L237) `average_amount` | ✓ | Works |
| [L247-267](includes/admin/class-reports.php#L247-L267) `by_allocation[]` with `.count`, `.total` | **not produced** | **"By Allocation" table never renders.** Only `annual_summary` produces `by_allocation`, and with different shape. |

### 1.4 Reports → Memberships tab ([class-reports.php:278-343](includes/admin/class-reports.php#L278-L343))

Calls `shelter-reports/dashboard-stats`. Reads `$stats.memberships`:

| Reads | Returns | Effect |
|---|---|---|
| [L299](includes/admin/class-reports.php#L299) `active` | `active_count` | **Always 0** |
| [L303](includes/admin/class-reports.php#L303) `new` | `new_count` | **Always 0** |
| [L307](includes/admin/class-reports.php#L307) `expiring_soon` | not produced | **Always 0** |
| [L311](includes/admin/class-reports.php#L311) `revenue` | `total` | **Always 0** ($0.00) |
| [L321-341](includes/admin/class-reports.php#L321-L341) `by_tier[]` with `.count`, `.revenue` | not produced | **"By Tier" table never renders** |

Entire memberships tab is broken.

### 1.5 Reports → Memorials tab ([class-reports.php:352-385](includes/admin/class-reports.php#L352-L385))

Calls `shelter-reports/dashboard-stats`. Reads `$stats.memorials`:

| Reads | Returns | Effect |
|---|---|---|
| [L372](includes/admin/class-reports.php#L372) `total` (labeled "Total Memorials" as a count) | `total` is a **dollar amount**, the count is `count` | **Wrong number displayed** — shows total dollars but labeled as memorial count |
| [L376](includes/admin/class-reports.php#L376) `new` | not produced | **Always 0** |
| [L380](includes/admin/class-reports.php#L380) `revenue` | not produced | **Always 0** ($0.00) |

### 1.6 Campaign CSV export ([class-reports.php:809-851](includes/admin/class-reports.php#L809-L851))

Calls `shelter-reports/campaign-report`. Reads:

| Reads | Returns | Effect |
|---|---|---|
| [L833](includes/admin/class-reports.php#L833) `progress.total_raised` | `stats.total` | **Always 0** in CSV |
| [L834](includes/admin/class-reports.php#L834) `progress.percent_of_goal` | `stats.progress` | **Always 0%** in CSV |
| [L847](includes/admin/class-reports.php#L847) `donor_name` on each donation | Entity_Hydrator computes a `donor_name` field per [class-entity-hydrator.php:199](includes/core/class-entity-hydrator.php#L199) comment | Likely works, verify |

### 1.7 Recommendation

Treat the dashboard-page expected shape as the canonical contract and extend the ability to match. Suggested new return from `shelter-reports/dashboard-stats`:

```php
[
  'donations' => [ 'total', 'count', 'unique_donors', 'average' ],
  'memberships' => [
    'active' => /* end_date >= today */,
    'new'    => /* start_date in period */,
    'expiring_soon' => /* end_date in next 30d */,
    'revenue' => /* sum amount in period */,
    'by_tier' => [ tier => [ count, revenue ] ],
  ],
  'memorials' => [ 'total', 'count', 'new', 'revenue' ],
  'donors' => [ 'total', 'new' ],
  // existing 'totals' can stay for back-compat or be dropped
]
```

And add `by_allocation` to `shelter-donations/get-stats`. Once the ability is correct, the admin pages need only field-name tweaks.

---

## 2. Architecture alignment

The README promises four layers: **Config → Infrastructure → Abilities → Consumer.** Reality in admin:

### 2.1 Wins

- [class-dashboard-widget.php:91-105](includes/admin/class-dashboard-widget.php#L91-L105), [class-menu.php:80-88](includes/admin/class-menu.php#L80-L88), [class-reports.php:208-215, 280-287, 353-360, 724-734, 774-782, 816-824](includes/admin/class-reports.php#L208-L215) — admin code calls `wp_get_ability(...)->execute()` for stats and lists. Correct delegation. Bugs aside, the layering is honored here.
- [class-activity-log.php:50-66](includes/admin/class-activity-log.php#L50-L66) — listens to domain actions (`starter_shelter_donation_created`, etc.) instead of polling. Clean consumer.

### 2.2 Architecture violations

**A) Raw SQL duplicated across admin classes.** The "expiring memberships in N days" and "pending family notifications" queries are written in raw SQL twice, with slightly different windows (7 vs 30 days):

- [class-menu.php:247-254](includes/admin/class-menu.php#L247-L254) and [L257-268](includes/admin/class-menu.php#L257-L268) — 7-day expiring + pending notifications for the menu badge.
- [class-dashboard-widget.php:397-404](includes/admin/class-dashboard-widget.php#L397-L404) and [L415-426](includes/admin/class-dashboard-widget.php#L415-L426) — same logic for the widget action items.

Both should be a single ability (e.g., `shelter-reports/action-items` or `shelter-memberships/expiring`) or at minimum use `Query::for('sd_membership')->whereDateBetween('end_date', ...)->count()`. The query builder already supports this exact pattern.

**B) Recent-activity UNION query bypasses every layer.** [class-dashboard-widget.php:450-510](includes/admin/class-dashboard-widget.php#L450-L510) emits a 60-line raw `UNION ALL` across three CPTs with hand-joined meta tables. This is exactly what `Entity_Hydrator` and `Query` exist to abstract. Should be an ability returning a unified `recent_activity` feed, with `Query` calls per type and a merge in PHP, or a single ability that owns the union.

**C) Retention metric SQL belongs in an ability.** [class-reports.php:565-622](includes/admin/class-reports.php#L565-L622) implements a multi-join self-correlated subquery in the renderer. This is real reporting logic — it belongs in `abilities/reports.php` as `membership_retention`.

**D) Donation trend chart SQL belongs in an ability.** [class-reports.php:464-555](includes/admin/class-reports.php#L464-L555) — same problem, `GROUP BY DATE_FORMAT(...)` in the view layer.

**E) Campaign "raised" query reimplements `Query::sum`.** [class-reports.php:633-663](includes/admin/class-reports.php#L633-L663) — `Query::for('sd_donation')->whereInTaxonomy('sd_campaign', $id)->whereDateBetween(...)->sum('amount')` would replace 30 lines of raw SQL, and `Query::sum()` already exists at [class-query.php:581-638](includes/core/class-query.php#L581-L638).

**F) Even the abilities layer cheats.** Not strictly an admin issue but worth flagging: `dashboard_stats` ([includes/abilities/reports.php:134-242](includes/abilities/reports.php#L134-L242)) uses raw `$wpdb` rather than the `Query` builder it documents. Three near-identical aggregate queries that could be `Query::for(...)->whereDateBetween(...)->count()` + `->sum('amount')`. The justification is probably aggregation, but `Query` has `sum()` and `count()`.

**G) Activity log uses a custom table outside the config-driven framework.** [class-activity-log.php:79-106](includes/admin/class-activity-log.php#L79-L106). Defensible (high-volume append-only logging), but the architecture doc claims "config-driven" without acknowledging this exception. Worth documenting.

### 2.3 Inline CSS

[class-dashboard-widget.php:579-693](includes/admin/class-dashboard-widget.php#L579-L693) and [class-menu.php:96-186](includes/admin/class-menu.php#L96-L186) (inline `style=` attrs on many elements) — should move to `assets/css/admin-*.css` like reports already does at [class-reports.php:72-77](includes/admin/class-reports.php#L72-L77). The activity log gets it half-right with `wp_add_inline_style` at [class-activity-log.php:130](includes/admin/class-activity-log.php#L130) but the CSS is still a heredoc in PHP.

---

## 3. Security findings

### 3.1 Spoofable client IP in audit log — HIGH

[class-activity-log.php:622-636](includes/admin/class-activity-log.php#L622-L636) reads `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP` **before** `REMOTE_ADDR`. Any client can send those headers; unless the server is configured to strip them at the edge (or you've confirmed it's behind Cloudflare/etc.), an attacker can write **arbitrary IPs into the audit log**, defeating its forensic purpose. For local Flywheel/wp-env dev this is harmless; for production it's a real audit-trail integrity issue.

Fix: only honor forwarded headers if you have a known-proxy whitelist, or default to `REMOTE_ADDR` and only use forwarded headers when explicitly enabled via filter.

### 3.2 Hidden `$_GET`/`$_POST` reads in helper — MEDIUM

[helpers.php:449-452](includes/core/helpers.php#L449-L452) — `get_date_range_for_period('custom')` directly reads `$_GET['date_from']`, `$_POST['date_to']`. This is a request-context dependency inside a pure-looking helper. Consequences:

- Any code path that calls `get_date_range_for_period('custom')` outside the reports page is influenced by request globals it can't see in the call site.
- Only `sanitize_text_field` is applied — there is no format validation that the string is actually a date. It eventually feeds `meta_value BETWEEN %s AND %s` queries, which are safe from injection but can return garbage on malformed input.

Fix: pass `date_from`/`date_to` explicitly via the function signature; let callers (admin page, AJAX handler) extract from request and validate as `Y-m-d`.

### 3.3 Unescaped `paginate_links()` output — LOW

[class-activity-log.php:582](includes/admin/class-activity-log.php#L582) — `echo $page_links;`. WP's `paginate_links` returns trusted HTML, so this is fine in practice, but a static analyzer will flag it. Wrap in `wp_kses_post()` or add a `phpcs:ignore` comment.

### 3.4 Settings change log can leak large objects — LOW

[class-activity-log.php:338-362](includes/admin/class-activity-log.php#L338-L362) — `log_settings_changed` records changed field *names* (good) but the underlying `update_option_starter_shelter_options` hook receives full old/new values. If any future code path widens this to log values, it could capture API keys, etc. Currently safe; flag for future maintenance.

### 3.5 PII / GDPR retention — LOW

[class-activity-log.php:155-169](includes/admin/class-activity-log.php#L155-L169) stores `ip_address` and (in `meta` JSON) recipient emails from `log_email_sent`. The 90-day cleanup ([L641-651](includes/admin/class-activity-log.php#L641-L651)) is appropriate, but document this for GDPR purposes and consider hashing IPs or making capture opt-in.

### 3.6 Capability and nonce coverage — OK

- All admin pages call `current_user_can('manage_options')` before rendering ([class-reports.php:99](includes/admin/class-reports.php#L99), [class-activity-log.php:409](includes/admin/class-activity-log.php#L409), dashboard widget registers only for admins at [class-dashboard-widget.php:60](includes/admin/class-dashboard-widget.php#L60)).
- Nonces are checked on AJAX (`sd_dashboard_refresh` at [class-dashboard-widget.php:296](includes/admin/class-dashboard-widget.php#L296)) and CSV export ([class-reports.php:671](includes/admin/class-reports.php#L671)).
- Filename in CSV export is safely composed from `sanitize_key()` values at [class-reports.php:680-683](includes/admin/class-reports.php#L680-L683); no header-injection vector.

---

## 4. Code quality / simplification

### 4.1 Near-identical render functions in dashboard widget

[class-dashboard-widget.php:render_widget](includes/admin/class-dashboard-widget.php#L83-L268) and [::ajax_refresh](includes/admin/class-dashboard-widget.php#L295-L370) duplicate the entire 5-card grid markup. The AJAX handler returns just the inner grid, but the markup is copy-pasted. Extract a `render_stats_grid($stats): string` and have both paths call it.

### 4.2 Repetitive `log_X` methods

[class-activity-log.php:175-403](includes/admin/class-activity-log.php#L175-L403) — eight nearly identical `log_donation_created`, `log_membership_created`, ... methods. Each is "look up donor name → call `self::log(...)` with hard-coded category and a sprintf'd English message." Could be data-driven:

```php
private const EVENT_CONFIG = [
  'donation_created' => [
    'category' => 'donation',
    'message'  => '{donor} donated {amount}',
    'object_type' => 'sd_donation',
  ],
  // ...
];
```

Even without that, a single helper `log_with_donor($event_type, $category, $donor_id, $object_id, $template, $extra_args)` would halve the file.

### 4.3 Three-tab rendering with duplicate "ability missing" boilerplate

[class-reports.php:206-220, 278-292, 352-365](includes/admin/class-reports.php#L206) — same "get ability, error if null, error if WP_Error" prelude three times in a row. Extract a `fetch_stats($ability_name, $args)` helper that returns `array|null` (echoing the error inline).

### 4.4 Hard-coded English strings in dashboard widget activity feed

[class-dashboard-widget.php:545, 555, 565](includes/admin/class-dashboard-widget.php#L545) — `'donated'`, `'joined as'`, `'Memorial for'` are not wrapped in `__()`. Inconsistent with the rest of the file, which is properly i18n'd.

### 4.5 The activity log page is a partial WP_List_Table reimplementation

[class-activity-log.php:408-589](includes/admin/class-activity-log.php#L408-L589) does its own filter form, pagination, search, count query, etc. WordPress core's `WP_List_Table` exists exactly for this. The current code is ~180 lines doing what `WP_List_Table` would do in ~80. Not a bug, but worth flagging.

### 4.6 Magic numbers and hard-coded periods

- `expiring_soon` "30 days" comes from `class-menu.php` saying 7-day badge vs. dashboard widget saying 7-day expiring vs. UI labels saying "within 30 days" ([class-menu.php:142](includes/admin/class-menu.php#L142)). Different code paths use different windows. Centralize.
- "Recent activity" hard-coded to 5 items ([class-dashboard-widget.php:214](includes/admin/class-dashboard-widget.php#L214)); reasonable but worth a constant.
- 90-day audit log retention is filterable (good — [class-activity-log.php:645](includes/admin/class-activity-log.php#L645)).

### 4.7 Caching subtleties

- [class-dashboard-widget.php:88-106](includes/admin/class-dashboard-widget.php#L88-L106) caches `dashboard_stats` for 15 min per period, invalidating on any `save_post_sd_*`. Good.
- [class-menu.php:233, 271](includes/admin/class-menu.php#L233) caches the menu badge for 10 min, invalidating on `save_post_sd_membership`, `save_post_sd_memorial`, and three custom actions. Good.
- **Gap:** action items in [class-dashboard-widget.php:436](includes/admin/class-dashboard-widget.php#L436) cache `sd_dashboard_action_items` but the invalidation list at [L38-41](includes/admin/class-dashboard-widget.php#L38-L41) only listens for `save_post_*`, not for the logo-approval and family-notification actions. The menu cache *does* listen for these ([class-menu.php:41-43](includes/admin/class-menu.php#L41-L43)). Dashboard widget action items can show stale counts for up to 15 min after a logo is approved.
- **Gap:** recent activity has no transient cache at all — the UNION query runs every dashboard load. Likely fine on small sites; worth caching for ~1 min.

---

## 5. Correctness — non-shape issues

### 5.1 Timezone confusion in "X ago" timestamps — MEDIUM

[class-dashboard-widget.php:547, 557, 567](includes/admin/class-dashboard-widget.php#L547) — `human_time_diff(strtotime($row->post_date), time())`. `$row->post_date` is the MySQL `post_date` column (site local time as stored by WP, but interpreted as the server's PHP timezone, **not UTC**). `time()` is UTC. The two are not directly comparable; you'll see incorrect "X ago" deltas equal to the site's UTC offset. Same issue at [class-activity-log.php:535-536](includes/admin/class-activity-log.php#L535-L536) — uses `wp_date('M j', strtotime(...))` which applies the offset twice (once when `strtotime` interprets the string in the server TZ, once via `wp_date`).

Fix: use `get_post_time('U', true, $id)` (GMT epoch) for `human_time_diff`, or `mysql2date('U', $row->post_date, false)` for the activity log.

### 5.2 The dashboard ability ignores its `period` for memberships' active count

[includes/abilities/reports.php:162-177](includes/abilities/reports.php#L162-L177) — `active_count` is computed as "memberships whose `_sd_end_date >= today` **AND** whose `_sd_start_date BETWEEN period_start AND period_end`." That's "memberships that started in this period and are still active," not "memberships currently active." For most admin uses, "active" should be `end_date >= today` regardless of start window. The current logic gives 0 for any non-current period, and a small number for "month/year."

### 5.3 New donors uses `_sd_created_date` not post_date

[includes/abilities/reports.php:194-204](includes/abilities/reports.php#L194-L204) — if a donor is created without `_sd_created_date` postmeta (which is possible from any code path that creates a donor outside the standard hydrator), they won't be counted. Worth checking that every donor-creation path writes this meta key.

### 5.4 `wp_date('Y-m-d', strtotime('+7 days'))`

[class-menu.php:254](includes/admin/class-menu.php#L254), [class-dashboard-widget.php:404](includes/admin/class-dashboard-widget.php#L404), [class-reports.php:569](includes/admin/class-reports.php#L569) — `strtotime('+7 days')` returns a Unix timestamp from the **system** time, then `wp_date` formats in the WP timezone. On servers running UTC, this is fine; on servers with a non-UTC PHP `date.timezone`, "today" and "+7 days" disagree about which day boundary you mean. Minor edge case.

### 5.5 Activity-log filter dropdown query runs every page load

[class-activity-log.php:471](includes/admin/class-activity-log.php#L471) — `SELECT DISTINCT event_category FROM ...` with no index hint; for a large log (tens of thousands of rows) this scans. There's a `KEY event_category` index ([L98](includes/admin/class-activity-log.php#L98)), so it's a loose index scan, but on big tables this should be a constant list of known categories.

### 5.6 `log_settings_changed` only iterates new_value keys

[class-activity-log.php:343-347](includes/admin/class-activity-log.php#L343-L347) — `foreach ($new_value as $key => $value)` misses keys that exist in old but not new (i.e., setting fields that were *removed*). Probably won't happen with options-API settings, but worth noting.

### 5.7 Strict-typed callbacks vs. action argument coercion

Per saved memory `wp-action-callback-empty-string-arg`: a `do_action('starter_shelter_X')` with no args passes `''` to a `?array` param and throws `TypeError`. Several log_X methods are typed as `array $data` ([L175, L196, L219, L241, L260, L280, L302](includes/admin/class-activity-log.php#L175)). If any caller dispatches without all three args, the log silently fatals (in `WP_DEBUG`) or generates a 500. Document the action signatures and consider `mixed $data` + `is_array` normalization in the log methods.

### 5.8 `render_widget_config` writes user preference without nonce

[class-dashboard-widget.php:274-276](includes/admin/class-dashboard-widget.php#L274-L276) — accepts `$_POST['sd_dashboard_period']` and calls `update_user_option`. This is fine **only because** WP core's dashboard widget config form has its own nonce verification before invoking the callback. Verify this assumption holds across WP versions — if WP ever loosens that, this becomes a CSRF vector for changing a user's own preference. Low impact regardless.

---

## 6. Per-file quick-reference

### class-menu.php (298 LOC)

- ✓ Caps and caching done well.
- ✗ Three of four dashboard cards always show zero (§1.1).
- ✗ Duplicates raw SQL with dashboard-widget (§2.2 A).
- Minor: timezone (§5.4).

### class-dashboard-widget.php (694 LOC)

- ✓ Cache strategy is sound.
- ✗ "Active members" card always zero (§1.2).
- ✗ Recent-activity UNION SQL bypasses every layer (§2.2 B).
- ✗ Action items duplicate the menu SQL (§2.2 A).
- ✗ Timezone bug in "X ago" (§5.1).
- ✗ Action-items cache invalidation gap (§4.7).
- ✗ render_widget and ajax_refresh duplicate markup (§4.1).
- ✗ Inline CSS heredoc (§2.3).
- Minor: hard-coded English strings (§4.4), `render_widget_config` CSRF assumption (§5.8).

### class-reports.php (852 LOC)

- ✗ Donations tab: "By Allocation" table never renders (§1.3).
- ✗ Memberships tab: every stat and the "By Tier" table broken (§1.4).
- ✗ Memorials tab: total mislabeled, new/revenue zero (§1.5).
- ✗ Campaign CSV: progress fields are zero (§1.6).
- ✗ Retention metric SQL belongs in abilities (§2.2 C).
- ✗ Trend chart SQL belongs in abilities (§2.2 D).
- ✗ Campaign raised sum reimplements `Query::sum` (§2.2 E).
- ✗ Three-tab boilerplate (§4.3).

### class-activity-log.php (737 LOC)

- ✓ Hook-based listener pattern is clean.
- ✓ Cleanup cron + retention filter (§3.5).
- ✗ Spoofable IP capture (§3.1).
- ✗ Repetitive log_X methods (§4.2).
- ✗ Partial WP_List_Table reimplementation (§4.5).
- ✗ Timezone bug in display (§5.1).
- Minor: paginate_links escape (§3.3), strict-typed callbacks (§5.7), filter dropdown query (§5.5).

---

## 7. Suggested action plan (ranked by impact / effort)

| Priority | Item | Files | Effort |
|---|---|---|---|
| P0 | Fix `dashboard_stats` ability to return `memberships.{active,new,expiring_soon,revenue,by_tier}`, `donors.{total,new}`, `memorials.{new,revenue}` | `abilities/reports.php` | ~1 day |
| P0 | Fix `donations/get-stats` to return `by_allocation` | `abilities/donations.php` | ~1h |
| P0 | Fix `campaign-report` shape OR fix CSV reader keys | `abilities/reports.php` or `admin/class-reports.php` | ~1h |
| P0 | Audit log IP capture: default to `REMOTE_ADDR`, opt-in for proxies | `admin/class-activity-log.php` | ~30m |
| P1 | Promote expiring-membership + pending-notification queries to one ability, replace both copies | `admin/class-menu.php`, `admin/class-dashboard-widget.php`, new `abilities/...` | ~2h |
| P1 | Fix `human_time_diff` timezone (use GMT epoch) | `admin/class-dashboard-widget.php`, `admin/class-activity-log.php` | ~30m |
| P1 | Remove `$_GET`/`$_POST` reads from `get_date_range_for_period('custom')`; pass dates explicitly | `core/helpers.php`, callers | ~1h |
| P2 | Move retention/trend SQL into abilities | `admin/class-reports.php`, `abilities/reports.php` | ~3h |
| P2 | DRY the `log_X` methods | `admin/class-activity-log.php` | ~2h |
| P3 | Migrate activity log table to `WP_List_Table` | `admin/class-activity-log.php` | ~3h |
| P3 | Move inline CSS to enqueued stylesheets | dashboard widget, menu, activity log | ~2h |
| P3 | Cache invalidation for action items on logo/notification actions | `admin/class-dashboard-widget.php` | ~15m |

Total P0 work to make the admin actually display correct numbers: roughly half a day.
