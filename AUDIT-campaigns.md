# Campaigns — End-to-End Audit + Membership Drive Design

Plugin: `vcpahumane-wc-donations` (Starter Shelter Donations v1.1.1)
Scope: every file that touches `sd_campaign` — config, abilities, REST, blocks (editor + frontend + interactivity store), WooCommerce integration, admin reports.
Companion to [AUDIT-admin-dashboard-reports.md](AUDIT-admin-dashboard-reports.md) — this one drills the Campaigns feature specifically.

---

## TL;DR

The Campaigns feature is **plumbing without a control panel and with several broken wires**. The data path from "donor picks a campaign at checkout" through "donation is tagged with the campaign term" works. Everything *downstream of that* — goal-setting, end-date-setting, the campaign-card block, the campaign auto-refresh, the campaign-report ability's goal lookup, the campaign-card "Donate Now" deep link — has at least one bug or missing piece.

Five separate places implement the "raised" SQL, two of which use the wrong join column (`term_taxonomy_id` where `term_id` is intended). The single canonical reader (`abilities/reports.php`) uses a different meta key (`'goal'`) than every other reader (`'_sd_goal'`), so even if you backfilled goals via SQL with `_sd_goal`, the official ability still returns `null`.

The block-side editor experience is asymmetric: `campaign-progress` has an `edit.js` with a campaign picker; `campaign-card` has none and therefore no way to set its `campaignId` attribute from the editor. Its fallback (first campaign with `_sd_end_date >= today`) returns nothing in default state because no writer ever sets `_sd_end_date`.

The Membership Drive design fits cleanly with the existing taxonomy if we extend `sd_campaign` to attach to `sd_membership` and add a `_sd_campaign_type` term meta to switch the meaning of "goal" between dollars and member count. Section 4 below details this.

---

## 1. Architecture and data flow

### 1.1 Schema

| Layer | Storage | Field | Set by | Read by |
|---|---|---|---|---|
| Taxonomy | `sd_campaign` term | name, description, slug | WP taxonomy UI | everywhere |
| Term meta | `_sd_goal` | dollar amount | **nobody** | 5 readers |
| Term meta | `_sd_end_date` | Y-m-d | **nobody** | 4 readers |
| Term meta | `goal` (no prefix!) | dollar amount | nobody | **1 reader** (the ability) |
| Order meta | `_sd_campaign_id` | term_id | cart-handler at checkout | order-handler |
| Cart-item meta | `sd_campaign_id` | term_id | cart-handler from `$_REQUEST` | display, order-saving |
| Donation tax. | `wp_term_relationships` | (term_taxonomy_id → object) | `wp_set_object_terms` in `shelter-donations/create` | every "raised" reader |

### 1.2 Donation-to-campaign data path (this part actually works)

```
   user picks campaign in checkout select
            │
            │  field name: "campaign_id"
            ▼
   class-checkout-fields.php:112-120
   (renders a <select> with options from get_campaign_options)
            │
            ▼
   class-cart-handler.php:197-199 (during checkout submit)
   reads $post_data['campaign_id'] → $data['sd_campaign_id']
            │
            ▼
   class-cart-handler.php:589-590 (order create)
   $order->update_meta_data('_sd_campaign_id', $values['sd_campaign_id'])
            │
            ▼
   on order paid → class-order-handler.php::process_item
            │
            ▼
   Product_Mapper::build_input  reads products.json input_mapping
            │  { campaign_id: { source: order_meta, key: _sd_campaign_id } }
            ▼
   wp_get_ability('shelter-donations/create')->execute([... campaign_id => N ...])
            │
            ▼
   abilities/donations.php:71-72
   wp_set_object_terms($donation_id, [(int)$campaign_id], 'sd_campaign')
```

There is also a parallel path for items added to the cart directly (not via checkout): the cart-handler reads `$_REQUEST['sd_campaign']` at [class-cart-handler.php:424](includes/woocommerce/class-cart-handler.php#L424) when adding to cart, persists into cart-item meta, and on order completion the same `_sd_campaign_id` order meta gets written ([L589](includes/woocommerce/class-cart-handler.php#L589)).

### 1.3 The "five readers, three implementations" problem

Five places compute "raised for a campaign":

| File | Method | Filter | Notes |
|---|---|---|---|
| [includes/abilities/reports.php:273-279](includes/abilities/reports.php#L273-L279) | `Query::for('sd_donation')->whereInTaxonomy(...)` then `array_sum(array_column(...,'amount'))` | none | **Canonical**, via Query builder |
| [includes/admin/class-reports.php:633-663](includes/admin/class-reports.php#L633-L663) | raw `$wpdb` join | period date range | Reimplements `Query::sum()` |
| [includes/blocks/register-bindings.php:452-465](includes/blocks/register-bindings.php#L452-L465) | raw `$wpdb` join | none | Used by blocks via binding source |
| [includes/rest/class-rest-controller.php:286-294](includes/rest/class-rest-controller.php#L286-L294) | raw `$wpdb` join | none | Used by REST endpoints |
| (none for memberships) | — | — | Memberships are *never* counted toward a campaign because the taxonomy isn't attached |

Both raw-SQL versions and the REST one use `tr.term_taxonomy_id = %d` while passing the **term_id**. Works only because `sd_campaign` isn't a shared taxonomy (one term_taxonomy_id per term_id). Should be `tt.term_id` via a join through `wp_term_taxonomy`, or use `term_taxonomy_id` after lookup. Easy to get right, currently working by coincidence.

Similarly, "donor count for a campaign" is implemented twice ([register-bindings.php:474-487](includes/blocks/register-bindings.php#L474-L487), [rest-controller.php:297-305](includes/rest/class-rest-controller.php#L297-L305)) with identical SQL.

### 1.4 Block surface

| Block | Editor UX | Server render | Frontend interactivity | Status |
|---|---|---|---|---|
| `starter-shelter/campaign-card` | **no edit.js** → no `campaignId` picker | [render.php](blocks/campaign-card/render.php) | [view.js](blocks/campaign-card/view.js) — own store, scroll-into-view animation | **Broken**: no way to set campaign from editor, fallback fails |
| `starter-shelter/campaign-progress` | [edit.js](blocks/campaign-progress/edit.js) with full picker + layout/refresh controls | [render.php](blocks/campaign-progress/render.php) | [view.js](blocks/campaign-progress/view.js) → [stores/campaigns.js](assets/js/stores/campaigns.js) — REST refresh + derived state | **Mostly works**, auto-refresh wiring broken |

`campaign-card` uses its **own** Interactivity namespace `starter-shelter/campaign-card` ([render.php:120-121](blocks/campaign-card/render.php#L120-L121), [view.js:13](blocks/campaign-card/view.js#L13)).
`campaign-progress` uses `starter-shelter/campaign` ([render.php:62](blocks/campaign-progress/render.php#L62), [stores/campaigns.js:14](assets/js/stores/campaigns.js#L14)).

Two stores for two campaign blocks. They cannot share state if rendered on the same page.

### 1.5 REST endpoints

[includes/rest/class-rest-controller.php:32-53](includes/rest/class-rest-controller.php#L32-L53):

| Route | Method | Auth | Handler |
|---|---|---|---|
| `GET /starter-shelter/v1/campaigns` | READABLE | `__return_true` (public) | `get_campaigns` — returns active campaigns only |
| `GET /starter-shelter/v1/campaign/(?P<id>\d+)` | READABLE | `__return_true` (public) | `get_campaign` — returns one by ID |

Both endpoints are public (no auth), which is fine for fundraising display data. The frontend store hits these via `apiRequest('campaigns/${id}')` ([stores/campaigns.js:34](assets/js/stores/campaigns.js#L34)).

There is **no REST mutation endpoint** for setting a campaign's goal or end_date — and no admin UI either. The only ways to populate term meta today are: hand-written SQL, or a custom plugin/snippet calling `update_term_meta`.

### 1.6 The Block Bindings layer

[includes/blocks/register-bindings.php:184-234](includes/blocks/register-bindings.php#L184-L234) registers a binding source `starter-shelter/campaign-data` that takes `{id, field}` and returns a value from the hydrated campaign object. The campaign-card block calls this directly at [render.php:70-74](blocks/campaign-card/render.php#L70-L74) instead of going through the binding API — half-using its own abstraction.

---

## 2. Bug catalog

Numbered by severity. P0 = feature is broken; P1 = subtle wrong output; P2 = code smell with no immediate user impact.

### P0-1. No writer exists for `_sd_goal` or `_sd_end_date`

A `grep -rn 'update_term_meta\|add_term_meta'` across the entire plugin returns **zero matches**. Six readers, zero writers. Until somebody runs SQL against the database, every campaign shows `Goal: $0`, every progress bar shows `0%`, every `is_active` check returns `true` (because `!$end_date` is true), and `days_remaining` is always `null`. This is the root cause of most other broken Campaigns behavior visible to a user.

What's needed: a meta box (or term-edit form fields) on the `sd_campaign` taxonomy edit screen for setting Goal ($), End Date, and (proposed in §4) Campaign Type. WordPress provides the `{taxonomy}_edit_form_fields` and `{taxonomy}_add_form_fields` action hooks for exactly this. Roughly 60-80 lines of code.

### P0-2. `campaign-card` block has no editor — `campaignId` is unsettable

[blocks/campaign-card/block.json](blocks/campaign-card/block.json) declares an attribute `campaignId: { type: 'number' }` but the block ships with **no `edit.js`**, no `editorScript`, no picker. A user dropping the block into a post has no way to assign a campaign. The fallbacks in render.php fail too:

1. [render.php:28-34](blocks/campaign-card/render.php#L28-L34) tries to read campaign ID from `block->context['postId']` and checks `get_post_type() === 'sd_campaign'` — but **`sd_campaign` is a taxonomy, not a post type**. This branch is dead.
2. [render.php:37-55](blocks/campaign-card/render.php#L37-L55) falls back to "first campaign with `_sd_end_date >= today`" — fails because of P0-1 (no end_date ever set), so `meta_query` returns nothing.
3. [render.php:58-67](blocks/campaign-card/render.php#L58-L67) renders an empty-state placeholder "No active campaign found."

So the campaign-card block can only ever be rendered as the empty placeholder. Net effect: the block is unusable.

Fix: add `edit.js` mirroring `campaign-progress/edit.js` with a campaign select sourced from `window.starterShelterBlocks.campaigns`. Drop the dead post-type branch. Optionally improve the fallback to "most recently created campaign" rather than requiring an end_date.

### P0-3. Ability `campaign_report` reads `'goal'`, everyone else reads `'_sd_goal'`

[abilities/reports.php:283](includes/abilities/reports.php#L283) reads:
```php
$goal = get_term_meta( $campaign_id, 'goal', true );
```
Every other reader uses `'_sd_goal'`:
- [blocks/campaign-progress/render.php:41](blocks/campaign-progress/render.php#L41)
- [blocks/campaign-card/render.php:84](blocks/campaign-card/render.php#L84)
- [includes/blocks/register-bindings.php:203](includes/blocks/register-bindings.php#L203)
- [includes/admin/class-reports.php:420](includes/admin/class-reports.php#L420)
- [includes/rest/class-rest-controller.php:282](includes/rest/class-rest-controller.php#L282)

Once P0-1 is fixed and goals are being written as `_sd_goal`, the campaign-report ability still returns `null` for goal, which then propagates to:
- The Campaign CSV export ([class-reports.php:832](includes/admin/class-reports.php#L832)) shows `0` for Goal.
- The campaign-report's `stats.progress` ([reports.php:303](includes/abilities/reports.php#L303)) is `null` because the conditional `$goal ? ...` short-circuits, so percent is missing.

Fix: change the ability to use `'_sd_goal'`. One-line edit at [reports.php:283](includes/abilities/reports.php#L283).

### P0-4. `campaign-progress` auto-refresh is wired to a non-existent callback

[blocks/campaign-progress/render.php:101](blocks/campaign-progress/render.php#L101):
```php
$wrapper_attrs['data-wp-watch'] = 'callbacks.autoRefresh';
```
[assets/js/stores/campaigns.js:75-138](assets/js/stores/campaigns.js#L75-L138) defines `callbacks` but **does not define `autoRefresh`**. It defines `startAutoRefresh` / `stopAutoRefresh` as *actions*, not callbacks. Result: setting "Auto-Refresh Interval" in the block editor sidebar does nothing — the timer is never started.

Fix: either rename store entries to expose an `autoRefresh` callback (it should run `startAutoRefresh` on first watch tick and clean up via the returned cleanup function), or change `data-wp-watch` to `data-wp-init="callbacks.startAutoRefresh"` after promoting it to a callback.

### P0-5. "Donate Now" deep-link uses wrong query arg name

[blocks/campaign-card/render.php:230](blocks/campaign-card/render.php#L230):
```php
add_query_arg( 'campaign', $campaign_id, '/donate/' )
```
The cart-handler reads `$_REQUEST['sd_campaign']` at [class-cart-handler.php:424](includes/woocommerce/class-cart-handler.php#L424), **not** `$_REQUEST['campaign']`. So arriving at `/donate/?campaign=42` adds a donation product to the cart with **no campaign attached**. The user's intent is silently dropped.

Two other issues with the same line:
- Hardcoded `/donate/` path — assumes the site has a page at that URL.
- Uses a relative URL with leading slash, so it's relative to the document root, not necessarily the site URL.

Fix: `add_query_arg('sd_campaign', $campaign_id, wc_get_page_permalink('shop'))` or a configured donate-page URL.

### P1-1. `format_campaign` REST output uses wrong join column

[includes/rest/class-rest-controller.php:286-294](includes/rest/class-rest-controller.php#L286-L294) and [L297-305](includes/rest/class-rest-controller.php#L297-L305) join via `tr.term_taxonomy_id = %d` while passing `term_id`. Same issue at [register-bindings.php:452-465](includes/blocks/register-bindings.php#L452-L465) and [register-bindings.php:474-487](includes/blocks/register-bindings.php#L474-L487).

For `sd_campaign` (a non-shared taxonomy) `term_id == term_taxonomy_id` always, so it produces correct results today. But it's a latent bug: if someone ever clones the campaign term into another taxonomy via `wp_insert_term` reuse, the IDs would diverge and the SQL would return data for the wrong terms. Using `Query::for('sd_donation')->whereInTaxonomy('sd_campaign', $term_id)->sum('amount')` removes the trap entirely and consolidates the implementations.

### P1-2. `get_campaigns` REST endpoint filters by `is_active` derived from missing end_date

[class-rest-controller.php:247](includes/rest/class-rest-controller.php#L247):
```php
$campaigns = array_filter( $campaigns, fn( $c ) => $c['is_active'] );
```
Because `_sd_end_date` is never set (P0-1), `is_active` is always `true` for every campaign in `format_campaign` ([L308](includes/rest/class-rest-controller.php#L308)). The filter is therefore a no-op today. Once end-dates are settable, the filter starts working — which is the right behavior — but consumers should know the semantics: this endpoint returns "campaigns that have not ended," not "campaigns explicitly marked active."

### P1-3. `campaign-card` and `campaign-progress` use different Interactivity namespaces

`starter-shelter/campaign-card` vs `starter-shelter/campaign`. Two blocks displaying the same campaign on the same page will not share refreshed state. A user clicking refresh in one will not update the other. Symptom: stale numbers in one block while the other shows fresh ones.

Fix: consolidate on `starter-shelter/campaign`. The card block's store at [view.js:13-130](blocks/campaign-card/view.js#L13-L130) duplicates most of [stores/campaigns.js](assets/js/stores/campaigns.js).

### P1-4. `campaign-card` view.js uses a hardcoded REST path

[blocks/campaign-card/view.js:82](blocks/campaign-card/view.js#L82):
```js
fetch( `/wp-json/starter-shelter/v1/campaign/${context.campaignId}` )
```
Hardcoded `/wp-json` prefix. Breaks if WP is in a subdirectory, breaks if the REST URL is customized, doesn't include a nonce (acceptable for read-only public route, but still). The campaign-progress store handles this correctly via `apiRequest()` from [utils.js](assets/js/stores/utils.js).

Fix: drop view.js refresh, use the shared store from P1-3.

### P1-5. Checkout campaign options not filtered by active

[class-checkout-fields.php:494-509](includes/woocommerce/class-checkout-fields.php#L494-L509) shows **all** campaign terms in the checkout dropdown, including ended ones. The REST `get_campaigns` endpoint filters; the checkout select doesn't. So donors can attach a contribution to an already-ended campaign.

Fix: filter the same way the REST endpoint does, or call into the REST function for consistency.

### P1-6. Campaign report doesn't filter donations by donation_date

The `shelter-reports/campaign-report` ability ([reports.php:252-307](includes/abilities/reports.php#L252-L307)) returns *all* donations ever associated with a campaign. The admin Campaigns tab passes a `$period` but the ability ignores it. Compare with [class-reports.php:633-660](includes/admin/class-reports.php#L633-L660) which does apply a date range. So:

- The Campaigns tab table column "Raised" uses `class-reports.php::get_campaign_raised` (period-aware) → correct.
- The Campaigns tab Export button uses `shelter-reports/campaign-report` (period-blind) → CSV shows lifetime numbers regardless of selected period.

Two readers disagreeing about the same column is a UX bug.

Fix: add `date_from`/`date_to` input params to the ability and have both readers go through it.

### P2-1. `dashboard_stats` ability doesn't expose campaigns at all

The main admin dashboard `shelter-reports/dashboard-stats` (audited in [AUDIT-admin-dashboard-reports.md §1](AUDIT-admin-dashboard-reports.md)) returns nothing campaign-related. There is no "top 3 active campaigns" card on the main dashboard, no progress, no totals. For a fundraising-focused plugin this is a notable gap.

### P2-2. Hydrator's campaign field is read-only configuration

[config/entities.json](config/entities.json) defines `campaign` as a `taxonomy` field type for `sd_donation`, so `Entity_Hydrator::get('sd_donation', $id)` should populate `$donation['campaign']` from the first attached `sd_campaign` term. Worth verifying in [class-entity-hydrator.php](includes/core/class-entity-hydrator.php). If it works, the CSV export at [class-reports.php:748](includes/admin/class-reports.php#L748) (`$donation['campaign_name']`) might already work via hydration — but the field is named `campaign`, not `campaign_name`, so it likely returns empty.

### P2-3. No activity-log entries for campaign actions

[class-activity-log.php](includes/admin/class-activity-log.php) listens for `starter_shelter_donation_created` and many other events but has no specific campaign hooks (campaign created, goal updated, goal reached). For a feature where the main user action *is* "create/manage a campaign," there's no audit trail.

### P2-4. The `campaign-card` block's `_sd_end_date` query type DATE

[blocks/campaign-card/render.php:42-48](blocks/campaign-card/render.php#L42-L48) uses `'type' => 'DATE'` in meta_query. WP's meta_query DATE comparison casts both sides as `DATE`, which works only if `_sd_end_date` is consistently stored as `YYYY-MM-DD`. Since no writer exists, no format guarantee exists. The future term-edit UI should enforce the format.

### P2-5. Goal as `float` is a currency precision smell

All readers cast goal to `(float)`. For currency values this is fine for display but accumulates rounding error in `$raised / $goal * 100`. Industry-standard fix is store amounts in cents (int). The wider plugin already does this with mixed conventions — out of scope here, but worth noting.

---

## 3. Editor / UX assessment

The Campaigns "admin" today consists of:

1. **The standard WP taxonomy admin page** at `/wp-admin/edit-tags.php?taxonomy=sd_campaign`. Auto-generated by WP because `show_ui: true`. It only exposes Name, Slug, Description, Parent. There is no goal field, no end-date field, no progress preview.
2. **The Reports → Campaigns tab** ([class-reports.php:394-452](includes/admin/class-reports.php#L394-L452)). Read-only table listing all campaigns with progress bars and per-campaign Export buttons. Useful for *viewing* but doesn't link to a campaign edit screen anywhere.

The minimum-viable admin needed:

1. Term-edit form fields for `_sd_goal`, `_sd_end_date`, `_sd_campaign_type` (proposed in §4) — via `sd_campaign_edit_form_fields` and `sd_campaign_add_form_fields` action hooks, plus `edited_sd_campaign` / `created_sd_campaign` save hooks.
2. Column for Goal and Progress on the term list table — via `manage_edit-sd_campaign_columns` and `manage_sd_campaign_custom_column` filters.
3. Either a "Manage Campaigns" submenu under "Shelter Donations" that redirects to the term page, or move the Reports → Campaigns tab to a proper "Campaigns" submenu with edit links.

Approximate effort: ~150 LOC for #1-#3.

---

## 4. Design: Membership Drive Campaign type

### 4.1 Goal

Today a "campaign" implicitly means "raise $X by date Y from donations." A Membership Drive would mean "sign up N new members by date Y," where the "raised" denominator is a *count of memberships*, not a sum of dollars, and the donation taxonomy is not the right attachment.

### 4.2 The shape of the change

Three options were considered:

| Option | Schema delta | Pros | Cons |
|---|---|---|---|
| **A. Extend `sd_campaign` taxonomy** to also attach to `sd_membership`; add `_sd_campaign_type` term meta | Minimal | Reuses all existing plumbing (term taxonomy, REST, blocks, reports), single admin page, one mental model | Goal field's *meaning* depends on `campaign_type` |
| B. New taxonomy `sd_membership_drive` | Moderate | Type system is rigid (no ambiguity) | Duplicates all the plumbing, two separate Campaigns admin areas, blocks need parameterizing |
| C. Promote `sd_campaign` to a CPT | Large | Rich custom fields via existing meta-box patterns, supports many related types | Breaks existing taxonomy assignments on `sd_donation`, requires data migration, larger admin rewrite |

**Recommendation: Option A**, because it matches how this plugin already does things (config-driven taxonomies, term meta for typed fields, abilities encapsulate the type-specific logic). The rest of the design assumes Option A.

### 4.3 Schema changes

Add to `_sd_*` term meta on `sd_campaign`:

| Key | Type | Values | Used when |
|---|---|---|---|
| `_sd_campaign_type` | string | `donation_drive` (default), `membership_drive`, `mixed` | always |
| `_sd_goal` | float | dollars OR member count, interpretation depends on type | always |
| `_sd_goal_member_count` | int | optional, only for `mixed` (which has both a $ goal and a member-count goal) | type = `mixed` |
| `_sd_end_date` | date | Y-m-d | always |
| `_sd_membership_tier_filter` | string\|null | optional tier slug to restrict membership counting (e.g., "guardian") | type ≠ `donation_drive` |

[config/taxonomies.json](config/taxonomies.json) changes:

```diff
   "sd_campaign": {
     ...
-    "post_types": ["sd_donation"],
+    "post_types": ["sd_donation", "sd_membership"],
```

### 4.4 Checkout integration

[config/products.json](config/products.json) — add the same `campaign_id` mapping to the membership product configs that the donation product already has:

```diff
   "shelter-memberships": {
     ...
     "input_mapping": {
       ...
+      "campaign_id": {
+        "source": "order_meta",
+        "key": "_sd_campaign_id"
+      }
     }
   }
```

[config/abilities.json](config/abilities.json) — extend the `shelter-memberships/create` input schema with optional `campaign_id`.

[includes/abilities/memberships.php](includes/abilities/memberships.php) — mirror what `donations.php:71-72` does:

```php
if ( ! empty( $input['campaign_id'] ) ) {
    wp_set_object_terms( $membership_id, [ (int) $input['campaign_id'] ], 'sd_campaign' );
}
```

[includes/woocommerce/class-checkout-fields.php:120](includes/woocommerce/class-checkout-fields.php#L120) — broaden the `product_types` array on the `campaign_id` checkout field so it shows for memberships too, and ideally only show campaigns whose `_sd_campaign_type` admits this product:

```php
'product_types' => [ 'donation', 'membership' ],
'options'       => 'campaigns_for_context', // filters by product_type at render time
```

### 4.5 Reporting changes

Promote the "raised" / progress logic into a single ability that respects type. Pseudocode for the consolidated `shelter-reports/campaign-progress` (proposed new ability, supersedes the four reimplementations):

```php
function campaign_progress( array $input ): array {
    $term = get_term( $input['campaign_id'], 'sd_campaign' );
    $type = get_term_meta( $term->term_id, '_sd_campaign_type', true ) ?: 'donation_drive';
    $goal = (float) get_term_meta( $term->term_id, '_sd_goal', true );
    $tier = get_term_meta( $term->term_id, '_sd_membership_tier_filter', true ) ?: null;

    $stats = [
        'type'         => $type,
        'goal'         => $goal,
        'goal_unit'    => $type === 'membership_drive' ? 'members' : 'currency',
        'raised'       => 0,
        'member_count' => 0,
    ];

    if ( in_array( $type, [ 'donation_drive', 'mixed' ], true ) ) {
        $stats['raised'] = Query::for( 'sd_donation' )
            ->whereInTaxonomy( 'sd_campaign', $term->term_id )
            ->whereDateBetween( 'donation_date', $input['date_from'] ?? null, $input['date_to'] ?? null )
            ->sum( 'amount' );
    }

    if ( in_array( $type, [ 'membership_drive', 'mixed' ], true ) ) {
        $q = Query::for( 'sd_membership' )
            ->whereInTaxonomy( 'sd_campaign', $term->term_id )
            ->whereDateBetween( 'start_date', $input['date_from'] ?? null, $input['date_to'] ?? null );
        if ( $tier ) {
            $q->where( 'tier', $tier );
        }
        $stats['member_count'] = $q->count();
    }

    // Progress denominator depends on type.
    $stats['progress'] = match ( $type ) {
        'donation_drive'   => $goal > 0 ? min( 100, $stats['raised'] / $goal * 100 ) : 0,
        'membership_drive' => $goal > 0 ? min( 100, $stats['member_count'] / $goal * 100 ) : 0,
        'mixed' => /* compound formula, see §4.7 */,
    };

    return $stats;
}
```

This single ability replaces (or backs) all five raised/progress implementations and removes the term_taxonomy_id-vs-term_id trap by going through `Query::for`.

### 4.6 Block changes

Both blocks render the same way regardless of campaign type — just labels change. Have `format_campaign` return a `progress_label` field that the blocks display:

- `donation_drive` → `$1,234 raised of $5,000 goal`
- `membership_drive` → `42 new members of 100 goal`
- `mixed` → `$1,234 raised · 42 new members` (two progress bars or one combined)

### 4.7 The "mixed" type — open question

For a mixed campaign (e.g., "Spring Fundraiser: raise $10k AND sign up 50 new business members"), what does a single 0-100% progress bar mean?

Options:
1. Use whichever is higher.
2. Average the two percentages.
3. Show two separate bars (cleanest visually, more complex template).
4. Just don't support `mixed` for v1 — pure `donation_drive` and `membership_drive` cover most use cases.

**Recommendation: skip `mixed` for v1.** Real shelter campaigns are usually either-or. Adding it later is non-breaking if the schema reserves the slot.

### 4.8 Migration

All existing `sd_campaign` terms become `donation_drive` by default. No data migration needed; the ability returns `donation_drive` when `_sd_campaign_type` meta is absent (`?: 'donation_drive'`). Existing donations stay attached. The only forward-compat work is the term-edit form needs to show a Type select with the new option, and the new membership-checkout plumbing in §4.4.

### 4.9 Reporting tab UX

Today's Reports → Campaigns tab columns: Campaign · Goal · Raised · Progress · Donations · Actions.

Membership-drive-aware columns:

| Campaign | Type | Goal | Progress | Donations | New Members | Actions |
|---|---|---|---|---|---|---|

Where `Goal` is rendered as `$X` for donation drives or `N members` for membership drives, and `Progress` is the bar with the right denominator. The unused column for the other type stays empty or shows `—`.

---

## 5. Implementation plan

Combined fixes for the audit bugs (§2) and the Membership Drive feature (§4), in dependency order:

### Phase 1 — Make the existing feature actually work (P0 fixes)

| # | Task | Files | Est. |
|---|---|---|---|
| 1 | Add `sd_campaign` term-edit form fields for `_sd_goal`, `_sd_end_date` | new `includes/admin/class-campaign-meta.php` (~80 LOC) | 2h |
| 2 | Fix ability meta key: `'goal'` → `'_sd_goal'` | [abilities/reports.php:283](includes/abilities/reports.php#L283) | 5m |
| 3 | Add `edit.js` for `campaign-card` (mirror campaign-progress) | new `blocks/campaign-card/edit.js`, update `block.json` | 1h |
| 4 | Fix `campaign-progress` auto-refresh wiring | [render.php:101](blocks/campaign-progress/render.php#L101), [stores/campaigns.js](assets/js/stores/campaigns.js) | 30m |
| 5 | Fix campaign-card "Donate Now" deep link query arg name | [render.php:230](blocks/campaign-card/render.php#L230), maybe configurable donate-page URL | 30m |
| 6 | Drop dead post-type branch in campaign-card render fallback | [render.php:28-34](blocks/campaign-card/render.php#L28-L34) | 5m |
| 7 | Filter checkout campaign select by active | [class-checkout-fields.php:494](includes/woocommerce/class-checkout-fields.php#L494) | 15m |

**Outcome:** Campaigns become a functional feature for donation drives.

### Phase 2 — Consolidate readers (P1 fixes)

| # | Task | Files | Est. |
|---|---|---|---|
| 8 | Add `shelter-reports/campaign-progress` ability via `Query::for(...)->sum()` | [abilities/reports.php](includes/abilities/reports.php) | 1h |
| 9 | Replace 5 raised/donor SQL implementations with the new ability | `class-reports.php`, `register-bindings.php`, `class-rest-controller.php`, the existing `campaign-report` | 2h |
| 10 | Unify campaign blocks on `starter-shelter/campaign` Interactivity store | `blocks/campaign-card/view.js`, render | 2h |
| 11 | Add `date_from`/`date_to` to `campaign-report` ability and pass period from admin | [abilities/reports.php](includes/abilities/reports.php), [class-reports.php](includes/admin/class-reports.php) | 1h |
| 12 | Add term list columns: Goal, Progress, Type | `class-campaign-meta.php` | 1h |

**Outcome:** Single source of truth for campaign stats; UI consistency.

### Phase 3 — Membership Drive type (§4)

| # | Task | Files | Est. |
|---|---|---|---|
| 13 | Extend `sd_campaign` taxonomy to attach to `sd_membership` | [config/taxonomies.json](config/taxonomies.json) | 5m |
| 14 | Add `_sd_campaign_type`, `_sd_membership_tier_filter` to term-edit form (Phase 1, #1 extended) | `class-campaign-meta.php` | 30m |
| 15 | Extend `shelter-memberships/create` ability to accept `campaign_id` | [abilities/memberships.php](includes/abilities/memberships.php) | 15m |
| 16 | Add `campaign_id` mapping for memberships in products.json | [config/products.json](config/products.json) | 5m |
| 17 | Show campaign select on membership checkout | [class-checkout-fields.php](includes/woocommerce/class-checkout-fields.php) | 30m |
| 18 | Make `shelter-reports/campaign-progress` ability type-aware (donation vs membership) | [abilities/reports.php](includes/abilities/reports.php) | 1h |
| 19 | Update block render to show member-count progress for membership drives | both campaign blocks | 1h |
| 20 | Reports → Campaigns tab columns for Type and New Members | [class-reports.php](includes/admin/class-reports.php) | 30m |

**Outcome:** Membership drives are a first-class campaign type.

### Phase 4 — Polish (P2)

| # | Task | Est. |
|---|---|---|
| 21 | Add campaign cards to main dashboard ([dashboard widget](includes/admin/class-dashboard-widget.php), [class-menu.php](includes/admin/class-menu.php)) | 1h |
| 22 | Add activity-log hooks for campaign created / goal updated / goal reached | 1h |
| 23 | Migrate currency from float to int-cents (cross-cutting; out of campaign scope) | — |

**Totals:** Phase 1 ~5h, Phase 2 ~7h, Phase 3 ~4h, Phase 4 ~2h. **Roughly 2 dev-days for the full Campaigns + Membership Drive rollout**, assuming no surprises in the editor wiring.

---

## 6. Open design questions for you

1. **Donate-page URL** (P0-5). Is `/donate/` a real page? Should the campaign-card deep-link target a configurable page (settings?) or use `wc_get_page_permalink('shop')` + auto-add a specific product to cart?

2. **Mixed campaign type** (§4.7). Skip for v1 (recommended) or design now?

3. **Tier filter for membership drives** (§4.3). Is "sign up 100 new members of any tier" the most common ask, or do shelters typically run drives for a specific tier (e.g., "100 new Guardian-level members")?

4. **Campaign-card vs campaign-progress block roles.** They overlap significantly. Should the card block stay (richer chrome — header, badge, deadline, donate button) and progress stay (compact bar for use in sidebars)? Or merge into one block with a layout attribute?

5. **REST mutation endpoint.** Would you ever want to update campaign goal/end_date via REST (e.g., a future React admin), or is the term-edit form sufficient?

6. **Permission model.** Currently campaign CRUD is gated by the standard taxonomy capabilities (`manage_categories` etc., remapped per `sd_campaign` registration). The term meta UI would inherit these. Want a separate "campaign manager" role, or is admin/editor sufficient?
