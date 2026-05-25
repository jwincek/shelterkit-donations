# Plugin-Wide Pattern Sweep

Plugin: `vcpahumane-wc-donations` (Starter Shelter Donations v1.1.1)
Method: five parallel sweeps across Memorials, Memberships+Logo Moderation, Donor portal, Form blocks+WooCommerce, and Email system. Each sweep applied the same 5-pattern checklist established by the two prior deep audits ([AUDIT-admin-dashboard-reports.md](AUDIT-admin-dashboard-reports.md), [AUDIT-campaigns.md](AUDIT-campaigns.md)).

This is a **hit-list, not a deep audit.** Each entry is a file:line ref with a one-line description. Use it to prioritize the next deep audit and to plan a unified fix strategy that doesn't re-discover the same root causes one feature at a time.

---

## The five patterns (carried over from prior audits)

1. **Ability ↔ consumer shape mismatch** — renderer/handler reads fields the ability doesn't produce, masked by `?? 0` / `?? ''`.
2. **Meta-key inconsistency** — same conceptual field stored/read under different keys.
3. **Read fields with no writer** — meta read in N places, written nowhere.
4. **Duplicate raw SQL bypassing `Query::for`** — same query reimplemented across files.
5. **References to identifiers that don't exist** — hooks, callbacks, ability names, REST routes, block attributes-without-UI.

---

## Cross-cutting root causes (most important section)

Three failure patterns recur across nearly every feature. Fixing the underlying mechanism in one place would close dozens of downstream symptoms.

### CC-1. The cart→order→ability handoff loses fields

`Cart_Handler::save_cart_item_to_order` ([class-cart-handler.php:559-598](includes/woocommerce/class-cart-handler.php#L559-L598)) writes most fields to **order-item meta** (`_sd_X` keyed by item), but `products.json` `input_mapping` for every product type reads via `source: order_meta` (keyed by order). The result: the form pipeline silently drops most of what users type. Examples:

- **Memorial:** `tribute_message`, `pet_species`, `notify_family`, `family_name`, `family_email`, `family_address`, `send_card` all dropped. Only `dedication_type` and `honoree_name` survive.
- **Membership (business):** `business_name`, `logo_attachment_id`, `business_website`, `business_description` all dropped. (For logos: `products.json` doesn't even have an `input_mapping` entry — they're orphaned.)
- **Donation:** `dedication` text dropped (only the `dedication_type` boolean-ish survives).
- **Per-item loop writing to order meta:** the fields that *do* get written (`_sd_campaign_id`, `_sd_is_anonymous`, `_sd_dedication_type`, `_sd_honoree_name` at [L589-598](includes/woocommerce/class-cart-handler.php#L589-L598)) are written *inside* a per-item loop. For multi-item carts, **last item wins** and every item's ability gets the same value.

This is the root cause of:
- Memorial family notifications never having a recipient (sweep §Memorials Pattern 3)
- Logo Moderation queue being empty for every checkout-uploaded logo (sweep §Memberships+Logo Pattern 3)
- Business membership emails always showing "Your business" fallback (sweep §Email Pattern 3)
- Memorial confirmation/notification templates rendering the wrong condition (sweep §Email Pattern 1)

### CC-2. The `_sd_*` meta layer has unreconciled key variants

The same conceptual field is stored/read under different keys across layers. The worst offender is `notify_family`:

| Writer | Key shape |
|---|---|
| `abilities/memorials.php:68` (form pipeline, never actually reached due to CC-1) | nested array `_sd_notify_family` = `[enabled, name, email, address, send_card]` |
| `admin/class-meta-boxes.php:374-399` (classic-editor save only) | flat `_sd_notify_family_enabled`, `_sd_notify_family_name`, `_sd_notify_family_email` |
| `admin/class-import-export.php:1920` | nested array form |

| Reader | Expected key shape |
|---|---|
| Admin badges/dashboards/queries (`class-quick-actions`, `class-dashboard-widget`, `class-menu`) | flat keys |
| Helpers (`core/helpers.php:794-796`) | flat keys |
| Email templates (`memorial-family-notification.php:25-27`) | nested array |
| `class-list-columns.php:425-431` "family_notified" column | nested array |

Net: memorials saved from the front-end pipeline never appear in admin pending lists; memorials saved via classic-admin meta box never trigger the family-notification email.

Other meta inconsistencies of this kind:
- `_sd_email_preferences` (cron reader) vs `_sd_communication_preferences` (donor handler writer) vs `email_preferences` (ability input schema). Three names, one concept, never round-trips. [renewal-reminder.php:164](includes/cron/class-renewal-reminder.php#L164), [donors.php:259](includes/abilities/donors.php#L259), [abilities.json:685](config/abilities.json#L685).
- `_sd_membership_tier` (order item meta) vs `_sd_tier` (CPT meta). Cart writes one, abilities write the other; the order validator at [class-order-handler.php:253](includes/woocommerce/class-order-handler.php#L253) checks the cart-side key and never confirms the post-side key was set.
- `_sd_first_donation_date` (declared in entities.json:349) vs `_sd_first_gift_date` / `_sd_last_gift_date` (read by donor-dashboard render.php:42-43). No writer for any of them.
- `'goal'` (one reader) vs `'_sd_goal'` (everyone else) — already documented in [AUDIT-campaigns.md §P0-3](AUDIT-campaigns.md).

### CC-3. The `config/entities.json` declared-field list is incomplete

`Entity_Hydrator` only emits **declared** fields. Many `_sd_*` meta keys are written by abilities/cart-handler/import but are **not declared** in `entities.json` — so they're invisible to every consumer that goes through `Entity_Hydrator::get(...)`. Consumers mask with `?? ''` and silently show empty values.

**`sd_membership` is missing ~8 declared fields:** `business_name`, `business_website`, `business_description`, `logo_attachment_id`, `logo_status`, `logo_rejection_reason`, `status`, `cancelled_at`. All are written somewhere; all are read off hydrated arrays in 8+ places. None of those reads ever return data. This is why business-membership emails render "Your business," why the Logo Moderation status badge is blank in meta-boxes, and why the cancel ability's "already cancelled" guard never fires.

---

## Per-area hit-list

For each area: short summary, then bullets grouped by pattern. References use file:line. Patterns with zero hits are omitted.

### A. Email system

**Severity:** ~10 distinct hits. Most are masked by silent fallback rendering. Worst single hit: the entire `donor-annual-summary` email is unreachable (no producer, and template+config disagree about data shape).

**P1 (shape mismatch):**
- [admin/class-logo-moderation.php:547](includes/admin/class-logo-moderation.php#L547) — producer fires `starter_shelter_logo_rejected` with an array as 3rd arg, but [config/emails.json:189](config/emails.json#L189) declares positional `trigger_args` of `["membership_id","donor_id","reason"]` (string). `Config_Email::trigger()` indexes positionally; the template renders the whole array via `wp_kses_post()`.
- [admin/class-logo-moderation.php:597](includes/admin/class-logo-moderation.php#L597) — same bug in bulk path.
- [templates/emails/donor-annual-summary.php:60-80](templates/emails/donor-annual-summary.php#L60-L80) — template reads `$summary['donations']['formatted']`, `['by_allocation']`, etc.; [config/emails.json:155-156](config/emails.json#L155-L156) placeholders reference a totally different shape (`args.summary.total_formatted`). No producer reconciles either.

**P2 (meta keys):**
- [core/helpers.php:794-796](includes/core/helpers.php#L794-L796) — see CC-2 `notify_family` family discussion. Email recipient resolution silently fails for the front-end-created memorial path.
- [cron/class-renewal-reminder.php:164](includes/cron/class-renewal-reminder.php#L164) — see CC-2 `_sd_email_preferences` vs `_sd_communication_preferences`. Opt-out check is effectively dead.

**P3 (no writer):**
- [templates/emails/membership-welcome.php:61,64](templates/emails/membership-welcome.php#L61), [logo-approved.php:20,30](templates/emails/logo-approved.php#L20), [logo-rejected.php:20](templates/emails/logo-rejected.php#L20) — all read `$membership['business_name']`; never populated (CC-3).
- [templates/emails/membership-welcome.php:82-89](templates/emails/membership-welcome.php#L82-L89) — reads `$membership['benefits']`; computed only in the ability's return value, never persisted as meta, not declared in entity config.
- [templates/emails/memorial-confirmation.php:93-98](templates/emails/memorial-confirmation.php#L93-L98), [memorial-family-notification.php:25-27](templates/emails/memorial-family-notification.php#L25-L27) — read `$memorial['notify_family']` nested object; only present if legacy nested writer was used (CC-2).
- [config/emails.json:153-156](config/emails.json#L153-L156) (`donor-annual-summary`) — placeholders reference shape no producer creates.
- [admin/class-activity-log.php:321](includes/admin/class-activity-log.php#L321) — `log_email_sent` reads `$data['object_type']`; nothing fires `starter_shelter_email_sent`, so this listener never runs.

**P5 (broken refs):**
- [config/emails.json:140](config/emails.json#L140) — `donor-annual-summary` trigger hook `starter_shelter_annual_summary` never fired.
- [admin/class-activity-log.php:59,65,66](includes/admin/class-activity-log.php#L59) — listeners for `starter_shelter_email_sent`, `_membership_extended`, `_family_notified` all dead (no producer).

---

### B. Memberships + Logo Moderation

**Severity:** ~25 distinct hits. The worst-hit area by raw count. End-to-end logo upload is broken; entity config is missing ~8 fields; tier-config call signature is wrong in half the codebase.

**P1 (shape mismatch):**
- [abilities/memberships.php:96-105](includes/abilities/memberships.php#L96-L105) — `create()` returns `expiration_date`; every consumer reads `end_date`. `renew()` returns `new_expiration_date`; no reader exists.
- [abilities/memberships.php:292](includes/abilities/memberships.php#L292) — `cancel()` reads `$membership['status']`; not in declared entity fields (CC-3); `?? ''` fallback makes the "already cancelled" guard never fire.
- [admin/class-meta-boxes.php:359](includes/admin/class-meta-boxes.php#L359) — `status_badge` reads `$entity['logo_attachment_id']`; undeclared field, always empty.
- [config/emails.json:181,206](config/emails.json#L181), [config/entities.json `sd_membership`](config/entities.json) — `membership.business_name` placeholder unresolvable (CC-3).

**P2 (meta keys):**
- `_sd_membership_tier` (order-item meta) vs `_sd_tier` (CPT meta); see CC-2.
- `_sd_status` (cancel ability writes) vs `_sd_end_date` date-based check (quick-actions filters). Two status models, no reconciliation.

**P3 (no writer / missing entity-config declarations):**
- All these meta keys are written but missing from `entities.json` `sd_membership.fields`:
  - `business_name`, `business_website`, `business_description`, `logo_attachment_id`, `logo_status`, `logo_rejection_reason`, `status`, `cancelled_at`. See CC-3.
- `_sd_cancellation_reason`, `_sd_cancelled_at`, `_sd_renewal_orders`, `_sd_auto_renew` — all written by `memberships.php`, **read nowhere**.
- `_sd_logo_attachment_id` — **end-to-end logo flow is broken**. Cart-handler ([class-cart-handler.php:584](includes/woocommerce/class-cart-handler.php#L584)) writes it as item meta; [config/products.json:54-77](config/products.json#L54-L77) `shelter-memberships-business` has **no `input_mapping` entry** for `logo_attachment_id`, so `Product_Mapper::build_input` never forwards it to the ability. Logos uploaded via checkout never reach `Logo_Moderation`.

**P4 (duplicate SQL):**
- 4 different files express "expiring memberships in next N days" 4 different ways: [class-dashboard-widget.php:397-404](includes/admin/class-dashboard-widget.php#L397-L404), [class-quick-actions.php:645](includes/admin/class-quick-actions.php#L645), [class-reports.php:572-580](includes/admin/class-reports.php#L572-L580), [class-my-account.php:224](includes/woocommerce/class-my-account.php#L224).
- [class-logo-moderation.php:105-119](includes/admin/class-logo-moderation.php#L105-L119), [:371-402](includes/admin/class-logo-moderation.php#L371-L402), [:447-461](includes/admin/class-logo-moderation.php#L447-L461) — three near-duplicate raw SQL aggregations for logo queue counting/listing.

**P5 (broken refs):**
- [rest/class-rest-controller.php:371](includes/rest/class-rest-controller.php#L371) — calls `wp_get_ability('shelter-reports/donor-summary')` — **not registered**. Fallback SQL is always used.
- [rest/class-rest-controller.php:522](includes/rest/class-rest-controller.php#L522) — calls `wp_get_ability('shelter-reports/annual-statement')` — **not registered** (real ability is `annual-summary`). `/donor/me/statement/{year}` always returns `generation_failed` 500.
- [abilities/memberships.php:41](includes/abilities/memberships.php#L41) — defaults `tier` to `'friend'`, which is **not a valid membership tier** (it's a donor-recognition label from `helpers.php`).
- [core/helpers.php:182-200](includes/core/helpers.php#L182-L200) — `get_tier_label()` reads `$tier['name']`; `config/tiers.json` uses `'label'`. **Configured tier labels are never displayed** anywhere — always falls through to slug fallback.
- **Wrong tiers-config accessor in 5 files**: `class-logo-moderation.php:410`, `class-rest-controller.php:456-457,628`, `register-editor.php:67-68`, `register-bindings.php:253`, `class-list-columns.php:269` all call `Config::get_item('tiers', 'individual'|'business', [])` — but the top-level key in `tiers.json` is `'tiers'`. Correct accessor is `Config::get_item('tiers', 'tiers', [])[$type]`. **Wrong-pattern callers silently produce empty tier sets.**
- [config/abilities.json:280-289](config/abilities.json#L280-L289) — `shelter-memberships/create` `output_schema` declares `end_date`; implementation returns `expiration_date`. Schema lies.
- [config/abilities.json:324](config/abilities.json#L324) — `shelter-memberships/get-status` input schema requires `donor_id` only; implementation accepts `membership_id` too. Schema-enforced REST calls reject valid input.
- [blocks/membership-form/block.json:60](blocks/membership-form/block.json#L60) — `layout` enum is `["cards","list"]`; [edit.js:54](blocks/membership-form/edit.js#L54) offers a third option `"table"`. Saved value silently ignored by render.
- [blocks/membership-form/block.json](blocks/membership-form/block.json) — declares attributes `formId`, `defaultTier`, `columns`; editor has no UI for `formId` or `defaultTier`.

---

### C. Donor portal (REST + donor blocks)

**Severity:** ~15 distinct hits. The donor-side JS calls REST endpoints that don't exist; the `/donor/me/statement/{year}` endpoint always 500s; Interactivity callbacks are misnamed.

**P1 (shape mismatch):**
- [rest/class-rest-controller.php:371](includes/rest/class-rest-controller.php#L371) — `get_donor_summary` fallback SQL returns `donation_count`, `lifetime_giving`, `ytd_giving`; [donor.js:88-102](assets/js/stores/donor.js#L88-L102) reads `total_giving`, `year_to_date`, `gift_count`, `consecutive_years`. Names don't intersect.
- [donor.js:108-111](assets/js/stores/donor.js#L108-L111), [donor-stats/view.js:108-111](blocks/donor-stats/view.js#L108-L111) — `refreshStats` reads `data.donation_count`, `data.active_members` from `/stats`; [class-rest-controller.php:579-583](includes/rest/class-rest-controller.php#L579-L583) returns neither.
- [donor-dashboard/render.php:159,165,174](blocks/donor-dashboard/render.php#L159) — binds `state.donor.display_name`; hydrator produces `full_name`, not `display_name`.
- [rest/class-rest-controller.php:460](includes/rest/class-rest-controller.php#L460) — treats `tiers` config as hash by slug; [helpers.php:621-628](includes/core/helpers.php#L621-L628) treats same config as list-of-objects. Two incompatible shape assumptions on the same config.

**P2 (meta keys):**
- `_sd_email_preferences` vs `_sd_communication_preferences` vs `email_preferences` — see CC-2.
- `_sd_first_donation_date` (entities.json:349) vs `_sd_first_gift_date` / `_sd_last_gift_date` (donor-dashboard render). Three names, one concept.

**P3 (no writer):**
- `_sd_donation_count`, `_sd_first_gift_date`, `_sd_last_gift_date`, `_sd_first_donation_date` — all read; **none ever written**.
- `_sd_address_last_updated`, `_sd_address_update_source` — written by [donors.php:138-139](includes/abilities/donors.php#L138-L139); never read.

**P4 (duplicate SQL):**
- [class-rest-controller.php:386-398](includes/rest/class-rest-controller.php#L386-L398) (lifetime + YTD) — duplicates [abilities/reports.php:25-124](includes/abilities/reports.php#L25-L124) and [abilities/donors.php:180-205](includes/abilities/donors.php#L180-L205). Three sites, one raw SQL.
- [class-rest-controller.php:563-577](includes/rest/class-rest-controller.php#L563-L577) (all-time stats) duplicates [register-bindings.php:386-416](includes/blocks/register-bindings.php#L386-L416).
- [class-rest-controller.php:724-744](includes/rest/class-rest-controller.php#L724-L744) reimplements `\Starter_Shelter\Blocks\get_current_user_donor_id` from [register-bindings.php:303-337](includes/blocks/register-bindings.php#L303-L337); REST copy has no transient cache.

**P5 (broken refs):**
- **REST endpoints called from JS that don't exist**: `donors/{id}`, `donors/{id}/stats`, `donors/{id}/gifts`, `donors/{id}/membership` ([donor.js:41-44](assets/js/stores/donor.js#L41-L44)). The actual routes are `/donor/me/*`. **Store can never populate from REST** — only the SSR-injected `wp_interactivity_state` ever provides data.
- Already noted under Memberships: `shelter-reports/donor-summary`, `shelter-reports/annual-statement` — both unregistered.
- [donor-dashboard/render.php:123,165,188](blocks/donor-dashboard/render.php#L123) — references `callbacks.init`, `callbacks.donorLevelLabel`, `callbacks.lifetimeGivingFormatted`. Store defines `init` under `actions` (not `callbacks`), and the rest under different names (`getDonorLevel`, `getTotalGiving`).
- [donor.js:136](assets/js/stores/donor.js#L136) — `actions.callbacks?.getMembershipDaysRemaining?.()` — `callbacks` is a sibling of `actions`, not a property. Optional chaining hides the bug.
- [donor-dashboard/render.php:267](blocks/donor-dashboard/render.php#L267) — `wc_get_account_endpoint_url('donations')`; registered slug is `'giving-history'` (per [class-my-account.php:30-36](includes/woocommerce/class-my-account.php#L30-L36)). **Link 404s.**
- [donor-stats/block.json:62](blocks/donor-stats/block.json#L62) — declares `refreshInterval`, `period`, `showMembers`, `animateNumbers` attributes; **block has no `edit.js`** (same pattern as campaign-card).

---

### D. Form blocks + WooCommerce integration

**Severity:** ~15 distinct hits. **Architecturally the worst** because all bugs occur in the SHARED infrastructure underneath donation/membership/memorial submission. Fixing this layer cascades benefits to every product type. Root cause CC-1 lives here.

**P1 (shape mismatch):**
- [memorial-form.js:160-170](assets/js/stores/memorial-form.js#L160-L170) — form posts `tribute_message`, `family_name`, `family_email`, `family_address`, `send_card`, `notify_family`; cart-handler stores as **item meta**, but `products.json` reads via **order_meta**. **Six memorial fields silently dropped.** (Root cause CC-1.)
- [memorial-form.js:161](assets/js/stores/memorial-form.js#L161) — `_sd_honoree_name` written to order meta inside per-item loop ([class-cart-handler.php:597](includes/woocommerce/class-cart-handler.php#L597)). **Multi-memorial orders: last item's name overwrites all earlier items.**
- [membership-form.js:152-157](assets/js/stores/membership-form.js#L152-L157) — form posts `donor_name`; [Product_Mapper::build_input:106](includes/woocommerce/class-product-mapper.php#L106) unconditionally overwrites with `get_donor_name($order)` (billing first+last). Form's chosen display name dropped.
- [membership-form.js:156](assets/js/stores/membership-form.js#L156) — business `business_name`: form → cart-item → item meta `_sd_business_name`; products.json reads from `order_meta`. Same CC-1 drop.
- [donation-form.js:87](assets/js/stores/donation-form.js#L87) — form posts `dedication`; cart-handler has no PHP handler for it. Value silently dropped.
- [class-order-handler.php:189](includes/woocommerce/class-order-handler.php#L189) — stores `_sd_ability_result` per item meta; read nowhere. Dead write.

**P2 (meta keys):**
- `notify_family` nested vs flat — see CC-2.
- `_sd_is_anonymous`, `_sd_campaign_id`, `_sd_dedication_type`, `_sd_honoree_name` written to **order meta** in per-item loop ([class-cart-handler.php:589-598](includes/woocommerce/class-cart-handler.php#L589-L598)). Multi-item order: last-item-wins; all items' abilities resolve the same value.
- Two writers for the same field at different layers: `_sd_campaign_id` (cart-handler:590 + checkout-fields:118), `_sd_is_anonymous` (cart-handler:593 + checkout-fields:97). Checkout submission runs after cart, so checkout overrides whatever the form submitted.

**P3 (no writer / dead JS):**
- Dead callbacks defined in stores but never invoked from render.php: [form-base.js:269-282](assets/js/stores/form-base.js#L269-L282) `hasFieldError`, `getFieldError` (callers don't set `ctx.fieldName`); [memorial-form.js:268-274](assets/js/stores/memorial-form.js#L268-L274) `getDedicationTypeLabel`; [membership-form.js:244-258,308-314](assets/js/stores/membership-form.js#L244-L258) `getTierBenefits`, `getTierPrice`, `getMembershipTypeLabel`.
- [donation-form.js:60-66](assets/js/stores/donation-form.js#L60-L66) — `setDedication` action defined and `dedication` in state; no render.php uses it, and the submitToCart sends `dedication` to PHP which has no handler.
- [membership-form.js:169-170](assets/js/stores/membership-form.js#L169-L170) — `resetForm` sets `businessName: ''` but state field is `donorName`. Typo'd dead write.
- [class-cart-handler.php:189,579](includes/woocommerce/class-cart-handler.php#L189) — `_sd_custom_price` written, never read.

**P5 (broken refs):**
- All ability names called by the WC layer are registered (verified across `shelter-donations/create`, `shelter-memberships/create`, `shelter-memorials/create`, `shelter-memberships/renew`).

**Notes:**
- [class-cart-handler.php:653](includes/woocommerce/class-cart-handler.php#L653) — error message says "PNG, JPG, or SVG" but `$allowed` array (`:649`) only permits `image/png`, `image/jpeg`. SVG rejected with misleading message.
- [class-order-handler.php:163](includes/woocommerce/class-order-handler.php#L163) — validation requires `_sd_honoree_name` item meta for memorials; only written inside `sd_dedication_enabled` branch. Memorial form always sends `dedication_enabled=1`, so the path works, but it's a fragile dependency.

---

### E. Memorials

**Severity:** ~15 distinct hits. Three are critical: the front-end form pipeline silently drops the family-notification payload (CC-1), the "Send Family Notification" admin button updates a meta value but **dispatches no email** (no action listener), and `memorial_type` enum has 3-way confusion with a default value that's invalid against its own schema.

**P1 (shape mismatch):**
- [abilities/memorials.php:87-93](includes/abilities/memorials.php#L87-L93) — `create()` returns `[memorial_id, donor_id, honoree_name, permalink, status]`; [config/abilities.json:476-484](config/abilities.json#L476-L484) `output_schema` declares `[memorial_id, donor_id, permalink, family_notified]`. **`family_notified` is never produced; `honoree_name` and `status` are undeclared.** Schema lies.
- [abilities/memorials.php:60](includes/abilities/memorials.php#L60) — `$input['memorial_type'] ?? 'memory'`. Schema restricts to `["person","pet"]`. Entities.json has `["human","pet","honor"]`. Meta-box options use `["human","pet","honor"]`. List-columns default fallback is `'human'`. Legacy importer writes `'human'`. JS card renderer ([memorials.js:157](assets/js/stores/memorials.js#L157)) treats only `'pet'` vs anything-else-as-Person. Five sources, three enums, one impossible default. Existing `'human'`/`'honor'` rows display as "Person."

**P2 (meta keys):**
- `_sd_notify_family` (nested) vs `_sd_notify_family_enabled/_name/_email` (flat). See CC-2 for full table.

**P3 (no writer):**
- `_sd_notify_family_enabled`, `_sd_notify_family_name`, `_sd_notify_family_email` — read in 5+ places (admin badges, helpers, dashboards). Only writer is the classic-editor save loop in `class-meta-boxes.php`, which is intentionally disabled for `sd_memorial` in the block editor. **For all front-end-created memorials, these are read-with-no-writer.**

**P4 (duplicate SQL):**
- "Pending family notifications" query is byte-for-byte identical in [class-dashboard-widget.php:415-426](includes/admin/class-dashboard-widget.php#L415-L426) and [class-menu.php:257-268](includes/admin/class-menu.php#L257-L268). Already flagged in [AUDIT-admin-dashboard-reports.md](AUDIT-admin-dashboard-reports.md).
- [blocks/memorial-wall/render.php:128-138](blocks/memorial-wall/render.php#L128-L138) — raw `$wpdb->get_col` for distinct years from `_sd_donation_date`; could use `get_terms('sd_memorial_year')` since that taxonomy already exists.

**P5 (broken refs):**
- [class-quick-actions.php:161,418](includes/admin/class-quick-actions.php#L161) — fires `starter_shelter_memorial_family_notification`. **No subscriber sends an email.** [config/emails.json:112](config/emails.json#L112) wires `memorial-family-notification` to `starter_shelter_memorial_created` instead. **Admin "Send Notification" button shows success; no email leaves the server.**
- [blocks/memorial-archive/block.json:22](blocks/memorial-archive/block.json#L22) — declares `enhancedPagination`; render.php never reads it. Dead attribute.

**Notes:**
- The order pipeline only writes 2 of 8 memorial fields to ORDER meta (root cause CC-1).
- No `update` ability for memorials — admin meta-box saves go directly to post meta, bypassing every business rule in `create()` (year taxonomy assignment, lifetime giving update, donor resolution).

---

### F. Import/Export + Legacy Sync (added in follow-up sweep)

**Severity:** ~25 distinct hits across the live code paths. **The most important finding is structural**: roughly 190KB of dead code sits in the plugin. `includes/admin/class-import-export.php` (2499 lines) and `includes/admin/class-legacy-order-sync.php` (~88KB) are **not wired up** — `starter-shelter.php:235,238` only initializes `Import_Export_Page` and `Legacy_Sync_Page`. The dead files repeat many of the live bugs and would compound them if ever resurrected.

**Live code paths:**
- `includes/admin/class-import-export-page.php` + `includes/admin/import-export/` (CSV import/export)
- `includes/admin/class-legacy-sync-page.php` + `includes/admin/legacy-sync/` (predecessor-plugin migration)

**P1 (shape mismatch):**
- [config/import-export.json:340-355](config/import-export.json#L340-L355) — memberships import field_map writes `start_date`, `end_date`, `business_name`, `business_website`, `business_description` into the ability input, but [config/abilities.json:235-279](config/abilities.json#L235-L279) `shelter-memberships/create` only declares `business_name`. The others are passed but undeclared; ability silently ignores them.
- [config/import-export.json:571-576](config/import-export.json#L571-L576) — memorial import enum is `[person, pet, human, honor]`; [abilities.json:462-465](config/abilities.json#L462-L465) only accepts `[person, pet]`. Importing `human` or `honor` fails ability validation.
- [class-legacy-input-builder.php:152,295](includes/admin/legacy-sync/class-legacy-input-builder.php#L152) — defaults `memorial_type` to `'person'`. Ability accepts it, but entities.json enum is `[human, pet, honor]` (no `person`). Sync succeeds but the persisted value violates the entity schema. Combined with [class-list-columns.php:377](includes/admin/class-list-columns.php#L377) defaulting to `'human'`, the memorial_type field has **five sources, four enums, one impossible default value** (`'memory'` from [memorials.php:60](includes/abilities/memorials.php#L60)).
- [class-order-processor.php:380-396](includes/admin/legacy-sync/class-order-processor.php#L380-L396) `update_existing_record` builds `field_map` with `'date' => 'date'` — writes `_sd_date` instead of the canonical `_sd_donation_date`. See Pattern 2.

**P2 (meta keys):**
- **NEW finding: `_sd_date` vs `_sd_donation_date`.** [class-legacy-memorial-parser.php:368-369](includes/admin/legacy-sync/class-legacy-memorial-parser.php#L368-L369) writes BOTH (same value). `_sd_date` is not declared in entities.json — hydrator ignores it. Update path in `Order_Processor::update_existing_record` uses ONLY `_sd_date` (bug). Hash-based import dedupe at [class-csv-importer.php:932-955](includes/admin/import-export/class-csv-importer.php#L932-L955) uses `_sd_date`, so re-import after legacy sync produces non-matching hashes.
- [class-import-export.php:1920-1923](includes/admin/class-import-export.php#L1920-L1923) (dead) — writes `_sd_notify_family` as nested array; live `CSV_Importer` sends flat `notify_family_name/email` to ability, which doesn't declare them. Reinforces CC-2.
- [class-legacy-memorial-parser.php:371-372](includes/admin/legacy-sync/class-legacy-memorial-parser.php#L371-L372) — writes `_sd_import_source` and `_sd_import_line` (not declared, invisible to hydrator). Low severity — audit-only.

**P3 (no writer / unreachable export columns):**
- [config/import-export.json:241-243](config/import-export.json#L241-L243) — memberships export reads `field: business_name`, but `sd_membership.fields` in entities.json (lines 82-127) lacks it (CC-3). **Export column will be empty even when the meta is populated.** Same for `business_website`, `business_description`, `logo_attachment_id`, `logo_status`, `cancelled_at`, `status`.
- [config/import-export.json:759-773](config/import-export.json#L759-L773) — donor export reads `donation_count`, `last_donation_date` via `meta:` accessor (bypasses hydrator, works). But [class-import-export.php:789,792](includes/admin/class-import-export.php#L789) (dead) reads `$donor['donation_count']` via hydrator — would be empty.
- [config/import-export.json:483](config/import-export.json#L483) — memorials export reads `meta: _sd_family_notified_date` — no writer found in import or sync code paths (only written by quick-actions admin send-notification flow).
- `Legacy_Memorial_Parser` defaults `_sd_amount` to 0; entities.json says `amount.minimum: 0` — soft mismatch.

**P4 (duplicate SQL):**
- "Admin counts" raw SQL exists THREE places: [class-import-export.php:1520-1543](includes/admin/class-import-export.php#L1520-L1543) (dead), [class-import-ajax-handler.php:658-691](includes/admin/import-export/class-import-ajax-handler.php#L658-L691) (live), and re-implemented in [class-csv-exporter.php:386-393](includes/admin/import-export/class-csv-exporter.php#L386-L393) via the `meta_filter` config block. Three implementations of "active vs expired memberships."
- [class-order-scanner.php:447-465,477-495](includes/admin/legacy-sync/class-order-scanner.php#L447-L465) — raw `$wpdb` counts for orders (HPOS + non-HPOS).
- [class-legacy-sync-page.php:370-383](includes/admin/class-legacy-sync-page.php#L370-L383) — raw `$wpdb->delete` against postmeta and `wc_orders_meta` in the reset path.
- [class-csv-exporter.php:60,79](includes/admin/import-export/class-csv-exporter.php#L60) — uses `\WP_Query` directly instead of `Query::for`. Every CSV export bypasses the Query builder.
- [class-csv-importer.php:976-985](includes/admin/import-export/class-csv-importer.php#L976-L985) — raw `$wpdb` join for hash lookups (legitimate aggregation but bypasses Query).

**P5 (broken refs):**
- **Stale JS would 404.** [class-import-export.php:46-58](includes/admin/class-import-export.php#L46-L58) (dead) registered `admin_post_sd_export_donations|memberships|donors|memorials`, `admin_post_sd_import_donors|donations`, and `wp_ajax_sd_process_import` (bare). The live code only registers entity-suffixed variants (`sd_process_import_donations`, etc.). Any stale JS still posting to the old action names will silently 0-out.
- Live extension hooks fired but unconsumed: `starter_shelter_legacy_order_synced`, `starter_shelter_legacy_sync_input`, `starter_shelter_legacy_record_updated` ([class-order-processor.php:433,481](includes/admin/legacy-sync/class-order-processor.php#L433), [class-legacy-input-builder.php:113](includes/admin/legacy-sync/class-legacy-input-builder.php#L113)). Extension points only — informational.
- [class-import-export.php:1572](includes/admin/class-import-export.php#L1572) (dead) donation template example uses `allocation: 'medical-care'` — not in `[general-fund, spay-neuter-clinic]` enum.
- [class-import-export.php:1588-1590](includes/admin/class-import-export.php#L1588-L1590) (dead) membership template uses tiers `bronze/silver/gold` — not in the configured tier slugs.
- Memberships import enum_values list omits `family` (which exists in entities.json) — round-trip export→import of a `family` membership would fail.

**CC alignment:**
- **CC-2 strongly reinforced.** New variant: `_sd_date` vs `_sd_donation_date` written by the legacy parser; the update path uses the non-canonical key, breaking hash-based deduplication. Plus the existing `_sd_notify_family` nested-vs-flat split shows up here too.
- **CC-3 strongly reinforced.** The membership entity-config gap causes export columns to be silently empty (not just admin display). Once `entities.json` is fixed for Phase 0, six membership export columns start working.

**Notes:**
- **Dead code: ~190KB.** The two dead files repeat live patterns; if ever re-wired they'd compound the issues. Recommend deletion as a low-risk cleanup BEFORE the substrate fixes — reduces audit confusion and ensures no future contributor accidentally extends them.
- [class-csv-importer.php:932-955](includes/admin/import-export/class-csv-importer.php#L932-L955) `compute_post_hash()` always prefixes meta keys with `_sd_` but uses field-name as-is — so the date field hashes against `_sd_date` while live runtime canonicalizes to `_sd_donation_date`. Hash-backfill produces non-matching hashes, defeating dedupe.
- `Import_Ajax_Handler::import()` derives entity type from the POST action name via `str_replace('sd_process_import_', '', $action)` ([line 127](includes/admin/import-export/class-import-ajax-handler.php#L127)). Validation at lines 130-133 catches bad types; OK.
- `Legacy_Sync_Page` is live with a React UI (`assets/js/admin-legacy-sync.js`); the legacy-sync subsystem is actively in use.

---

## Severity summary

| Area | P1 hits | P2 hits | P3 hits | P4 hits | P5 hits | User-visible broken features |
|---|---|---|---|---|---|---|
| Email | 3 | 2 | 5 | 0 | 6 | annual-summary email unreachable; logo-rejected template corrupted; family notification mismatched recipients |
| Memberships+Logo | 5 | 2 | 9 | 5 | 8 | **end-to-end logo flow broken**; entity missing 8 fields; tier labels never displayed; tier-config wrong in 5 files; default tier invalid; 2 unregistered REST abilities |
| Donor portal | 5 | 2 | 5 | 4 | 7 | donor-side JS calls nonexistent REST routes; statement endpoint always 500s; 3 Interactivity callbacks misnamed; WC account link 404s |
| Form blocks + WC | 6 | 3 | 7 | 0 | 0 | **memorial form drops 6 fields**; membership business_name/donor_name dropped; donation dedication dropped; per-item meta overwrites |
| Memorials | 2 | 1 | 3 | 3 | 2 | **"Send Family Notification" doesn't send**; memorial_type enum mismatch; output_schema lies |
| Import/Export + Legacy Sync | 4 | 3 | 4 | 6 | 5 | **~190KB dead code**; memorial_type enum 5-way confusion; `_sd_date` vs `_sd_donation_date` breaks hash dedupe; 6 membership export columns silently empty (CC-3); stale JS posting to dead AJAX actions |
| **Total** | **25** | **13** | **33** | **18** | **28** | **~120 distinct hits, most cascading from 3 root causes** |

---

## Which area to deep-audit next?

Three are tied for "worst" by different metrics:

| Candidate | Why pick it | Why not |
|---|---|---|
| **WooCommerce integration layer** (cart-handler, checkout-fields, order-handler, product-mapper) | This is where CC-1 lives. **All three product types (donation, membership, memorial) silently lose data here.** Fixing the cart→order-meta gap + adding missing `products.json` mappings closes a huge fraction of the symptoms found in every other area. Highest leverage per hour. | Not "a feature" — more like the substrate. The deliverable would be schema-level changes rather than per-feature redesign. |
| **Memberships + Logo Moderation** | Highest raw hit count (~25). Most affected user-visible features (every business membership; every logo upload; every tier-config consumer). | Many of these dissolve once CC-1 and CC-3 are fixed at the substrate, so a deep audit here may re-document things already known. |
| **Memorials end-to-end** | Worst user-visible failure mode (admin clicks "Send", sees success, no email actually sent). Plus memorial wall + candles + family notifications are recent/active work. | Same as Memberships — much of it dissolves once CC-1 is fixed. |

**Recommendation: WooCommerce integration layer.** Reasoning: the two prior deep audits both produced redesign proposals (dashboard_stats reshape; campaign-progress unified ability). A WC integration deep audit would produce a similar deliverable — a redesigned cart→order→ability handoff (probably: standardize on order-item meta + extend `Product_Mapper` to read item meta + update `products.json` mappings). That fix multiplies across donation/membership/memorial flows. Memberships and Memorials deep audits can come *after* this is done because their P3 hits will largely disappear.

---

## Cross-area fix order (rough)

Even before deciding the deep audit, here's a recommended *fix* order that addresses the cross-cutting causes first:

1. **CC-3 first** (smallest, biggest blast radius): extend `config/entities.json` `sd_membership` to declare the 8 missing fields. 30 minutes. Fixes ~10 downstream hits across email, admin, REST.
2. **CC-1 second**: fix cart→order-meta handoff. Either (a) extend `Product_Mapper` to read item meta with order meta as fallback, or (b) make cart-handler write to both. Update `products.json` mappings for the missing fields (especially `logo_attachment_id`, all memorial fields). 2-4 hours. Fixes ~12 hits across memorials, memberships, logos, dedication.
3. **CC-2 third**: pick canonical key for each duplicated concept and migrate. Likely `notify_family` flat keys win (simpler queries), `_sd_tier` wins for memberships, `_sd_communication_preferences` wins for donors. 2-3 hours plus a one-shot data migration. Fixes ~6 hits across email, memorials, donors.
4. Then start the area-specific work: fix the unregistered ability names called from REST (Memberships P5, Donor portal P5); fix the misnamed Interactivity callbacks in donor-dashboard; fix the wrong `Config::get_item('tiers',...)` accessor in 5 files; fix `memorial_type` enum confusion; wire `starter_shelter_memorial_family_notification` to actually send the email; etc.
5. After substrate is sound, revisit the per-feature deep audits — many P1 shape-mismatches will be easier to fix because the data they're trying to read will actually exist.

---

## Out of scope for this sweep

Following the follow-up Import/Export sweep, remaining areas:

- **Settings system** (`includes/admin/class-settings.php`, 43 KB) — partially touched in admin audit. Mid-priority.
- **Activator + data integrity** (`includes/core/class-activator.php`, `includes/admin/class-data-integrity.php`) — activation-time code, lower priority.
- **Cron jobs other than `class-renewal-reminder.php`** — directory not fully scanned. Lower priority.

Worth a follow-up pass if scope allows. Both audited and not-audited areas point to the same three root causes — additional sweeps would produce more *instances* of CC-1, CC-2, and CC-3 rather than new categories.
