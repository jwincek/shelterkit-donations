# WooCommerce Integration — Deep Audit + Substrate Redesign

Plugin: `vcpahumane-wc-donations` (Starter Shelter Donations v1.1.1)
Scope: the cart→order→ability substrate that underlies every donation, membership, and memorial submission.

Files audited in full:
- [includes/woocommerce/class-cart-handler.php](includes/woocommerce/class-cart-handler.php) — 735 LOC
- [includes/woocommerce/class-checkout-fields.php](includes/woocommerce/class-checkout-fields.php) — 764 LOC
- [includes/woocommerce/class-order-handler.php](includes/woocommerce/class-order-handler.php) — 669 LOC
- [includes/woocommerce/class-product-mapper.php](includes/woocommerce/class-product-mapper.php) — 384 LOC
- [config/products.json](config/products.json) — 197 LOC

Companion to [AUDIT-pattern-sweep.md](AUDIT-pattern-sweep.md). This audit goes deep on the substrate layer where root cause **CC-1** (cart→order-meta gap) and a related architectural confusion live. Fixing this layer cascades benefits to donation, membership, and memorial flows.

---

## TL;DR

The cart→ability handoff is two parallel data channels — **item meta** (per cart line) and **order meta** (per order) — with no clear ownership rule and an unintentional priority inversion. The Cart_Handler writes 19 fields to item meta but only 4 to order meta; the Product_Mapper reads almost everything via `source: order_meta`. The two channels disagree on most fields, so most of what the form collects is silently dropped at the ability call.

Three specific consequences:

1. **Form data lost at the handoff.** Memorial loses 6 fields (whole family-notification payload); membership loses `business_name` and `logo_attachment_id`; donation loses `dedication` text.
2. **Multi-item carts collapse.** The 4 fields that *do* reach order meta are written in a per-item loop, so the last item overwrites the first. Two memorials for different honorees → both abilities run with the same honoree name.
3. **Checkout-fields silently overrides the form.** Checkout-fields collects a near-duplicate of what the form already collected, runs *after* the cart save, and writes to order meta — so it overwrites whatever the form wrote, then the form value is lost.

The redesign is small. **Switch the `source` in `products.json` from `order_meta` to `item_meta` for per-item fields, drop the four lines that double-write to order meta, and have `Product_Mapper` fall back from item meta to order meta when both exist.** Three files, roughly 100 LOC of changes, no schema migration. Section 4 details this.

Plus a half-dozen smaller findings: a logo upload that's orphaned because no `products.json` mapping forwards it, a duplicate-collection problem with checkout-fields, an enum mismatch on `memorial_type`, a misleading SVG error message, and trust-the-browser MIME validation on uploads.

---

## 1. End-to-end data path (the substrate map)

Every shelter submission travels this path. Each "→" is a place where data can be lost or transformed.

```
1. Form block render.php emits a form with name="X" fields
       │
       │  JS submit POSTs all form fields via fetch() to /wp-ajax.php?action=sd_add_to_cart
       ▼
2. Cart_Handler::ajax_add_to_cart  (cart-handler.php:56)
       │
       │  - Nonce check (sd_add_to_cart)
       │  - Rate limit (1 per 3s per user/IP)
       │  - Amount validation (>= 1)
       │  - If business_membership: handle_logo_upload() creates wp_attachment, returns ID
       │  - build_cart_item_data($_POST, $product_type)
       │      maps ~19 form fields → sd_X cart-item-data keys
       │      (cart-handler.php:186-271)
       │  - Find variation (for variable products)
       │  - WC()->cart->add_to_cart($product_id, 1, $variation_id, $variation, $cart_item_data)
       ▼
3. Cart sits with the cart-item-data attached
       │
       │  User clicks checkout, fills WC billing fields + custom checkout-fields
       │  (more on those below)
       ▼
4. WC checkout submit fires woocommerce_checkout_create_order_line_item
       │
       │  → Cart_Handler::save_cart_item_to_order  (cart-handler.php:559)
       │     - For EACH meta key in $meta_keys (19 keys), if set on cart item:
       │         $item->add_meta_data('_' . $key, $values[$key])
       │       → writes to ORDER ITEM meta
       │     - Then unconditionally:
       │         $order->update_meta_data('_sd_campaign_id', ...)        ← ONLY 4 fields
       │         $order->update_meta_data('_sd_is_anonymous', ...)
       │         $order->update_meta_data('_sd_dedication_type', ...)
       │         $order->update_meta_data('_sd_honoree_name', ...)
       │       → writes to ORDER meta
       │     - This runs PER ITEM in a loop. The 4 order-meta writes overwrite each
       │       other for multi-item carts. (BUG: §2.3)
       ▼
5. Also during checkout submit: woocommerce_checkout_update_order_meta
       │
       │  → Checkout_Fields::save_checkout_fields  (checkout-fields.php:518)
       │     - For EACH custom field definition (~12 fields), if user filled it:
       │         $order->update_meta_data($meta_key, sanitized_value)
       │       → writes to ORDER meta
       │     - This runs ONCE per order.
       │     - Field definitions OVERLAP with what the form already collected:
       │         memorial: honoree_name, tribute_message, pet_species, is_anonymous,
       │                   notify_family, family_name, family_email, family_address, send_card
       │         business_membership: business_name
       │         donation: dedication, is_anonymous, campaign_id
       │     - Because this fires AFTER the cart-handler hook (different action), checkout
       │       VALUES OVERRIDE form values. (BUG: §2.4)
       ▼
6. Order reaches status processing or completed
       │
       │  → Order_Handler::process_order  (order-handler.php:68)
       │     - Idempotency guard (_sd_processed meta)
       │     - For each item in order:
       │         → Order_Handler::process_item
       │             - find_by_sku to get product config
       │             - validate_item_meta (light validation; tweaked rules per type)
       │             - Product_Mapper::build_input($order, $item, $config)
       │                  - Base: order_id, amount (item total), donor_email (billing),
       │                          donor_name (billing first+last)
       │                  - For each input_mapping entry: resolve from order_meta /
       │                    item_meta / attribute / order_field / product_meta / static /
       │                    composite, with fallback + default + transform
       │                  - apply_filters('starter_shelter_product_mapper_input', ...)
       │             - apply_filters('starter_shelter_order_item_input', ...)
       │             - wp_get_ability($config['ability'])->execute($input)
       │             - Save result as item meta '_sd_ability_result'  (never read; dead)
       ▼
7. Ability creates the CPT (sd_donation, sd_membership, sd_memorial)
       │
       │  Ability fires domain action starter_shelter_X_created
       │
       └── consumed by Activity_Log, email triggers, cron schedulers, etc.
```

**The critical bottleneck is step 6's `Product_Mapper::build_input`.** Its `input_mapping` config in `products.json` is overwhelmingly `source: order_meta`. So even though step 4 writes data to item meta, step 6 looks in order meta. Step 5's checkout-fields *do* write to order meta — but only the fields the user fills there, which overlap with form fields but aren't identical. Net: data from the form arrives intact at the cart, gets stored on the item, then the ability can't find it.

---

## 2. Architecture findings (the substrate-level bugs)

### 2.1 The dual-channel meta storage with no contract

Cart_Handler writes 19 fields to **item meta** ([cart-handler.php:560-580](includes/woocommerce/class-cart-handler.php#L560-L580)):

```
sd_product_type, sd_donor_name, sd_allocation, sd_campaign_id, sd_is_anonymous,
sd_dedication_enabled, sd_dedication_type, sd_honoree_name, sd_honoree_type,
sd_tribute_message, sd_notify_family, sd_family_name, sd_family_email,
sd_family_address, sd_send_card, sd_membership_tier, sd_business_name,
sd_logo_attachment_id, sd_custom_price
```

It then writes 4 fields to **order meta** ([cart-handler.php:589-598](includes/woocommerce/class-cart-handler.php#L589-L598)):

```
_sd_campaign_id, _sd_is_anonymous, _sd_dedication_type, _sd_honoree_name
```

The 4 are a subset of the 19. There's no rule about *which* fields belong in which channel, no comment explaining why these 4 are duplicated, no helper that abstracts the dual write. The pattern just exists.

Product_Mapper resolves fields via `input_mapping` source declarations. In `products.json`, here's the source distribution:

| `source` value | Count |
|---|---|
| `order_meta` | 13 |
| `attribute` | 5 |
| `static` | 2 |
| `composite` | 1 (nested order_meta) |
| `item_meta` | **0** |
| `order_field` | 1 (fallback only) |
| `product_meta` | 0 |

**The Product_Mapper's `item_meta` source is fully implemented** ([class-product-mapper.php:233-235](includes/woocommerce/class-product-mapper.php#L233-L235)) — it's just never used in the config. So the architecture supports the right thing, the config doesn't.

### 2.2 What the form actually collects vs what the ability gets

Verified field-by-field by tracing each product type through steps 2→4→6.

**Donation** (form → `shelter-donations/create` ability):

| Form field | Cart-item meta | Order meta | products.json input | Result |
|---|---|---|---|---|
| `amount` | `_sd_custom_price` | — | (uses `$item->get_total()`) | ✓ via item total |
| `allocation` | `_sd_allocation` | — | source: attribute | ✓ via WC variation attr |
| `campaign_id` | `_sd_campaign_id` | `_sd_campaign_id` | source: order_meta | ✓ (but per-item overwrite for multi-item) |
| `is_anonymous` | `_sd_is_anonymous` | `_sd_is_anonymous` | source: order_meta | ✓ (but per-item overwrite) |
| `donor_name` | `_sd_donor_name` | — | (overwritten by billing) | ✗ **dropped** |
| `dedication` | — | — | source: order_meta | ✗ **dropped** (cart-handler has no handler; only checkout-fields writes) |

**Business Membership** (form → `shelter-memberships/create` ability):

| Form field | Cart-item meta | Order meta | products.json input | Result |
|---|---|---|---|---|
| `tier` | `_sd_membership_tier` | — | source: attribute | ✓ via WC variation attr |
| `business_name` | `_sd_business_name` | — | source: order_meta + fallback billing_company | ✗ **dropped** (unless user re-types in checkout-fields, or has billing_company) |
| `logo_attachment_id` | `_sd_logo_attachment_id` | — | **no mapping** | ✗ **completely dropped** |
| `donor_name` | `_sd_donor_name` | — | (overwritten by billing) | ✗ **dropped** |
| `is_anonymous` | `_sd_is_anonymous` | `_sd_is_anonymous` | source: order_meta | ✓ (per-item overwrite caveat) |

**Memorial** (form → `shelter-memorials/create` ability):

| Form field | Cart-item meta | Order meta | products.json input | Result |
|---|---|---|---|---|
| `memorial_type` (from `honoree_type`) | (via variation) | — | source: attribute | ✓ (but see §3.1 enum mismatch) |
| `honoree_name` | `_sd_honoree_name` | `_sd_honoree_name` (last-item-wins) | source: order_meta | ⚠ per-item overwrite |
| `tribute_message` | `_sd_tribute_message` | — | source: order_meta | ✗ **dropped** unless re-typed in checkout-fields |
| `pet_species` | — (no form field) | — | source: order_meta | ✗ checkout-fields only |
| `is_anonymous` | `_sd_is_anonymous` | `_sd_is_anonymous` | source: order_meta | ⚠ per-item overwrite |
| `notify_family.enabled` | `_sd_notify_family` | — | source: composite/order_meta | ✗ **dropped** unless re-typed in checkout-fields |
| `notify_family.name` | `_sd_family_name` | — | source: composite/order_meta | ✗ **dropped** |
| `notify_family.email` | `_sd_family_email` | — | source: composite/order_meta | ✗ **dropped** |
| `notify_family.address` | `_sd_family_address` | — | source: composite/order_meta | ✗ **dropped** |
| `notify_family.send_card` | `_sd_send_card` | — | source: composite/order_meta | ✗ **dropped** |

The memorial form has the worst loss: **5 fields plus a 4-subfield composite all silently dropped** unless the donor *also* fills the duplicate checkout-fields section. The checkout-fields section even has the same labels and validation as the form — there's a redundancy of design here that compounds the bug (§2.4).

### 2.3 Per-item loop overwrite bug

[cart-handler.php:559-598](includes/woocommerce/class-cart-handler.php#L559-L598) — the `save_cart_item_to_order` hook fires **once per order item** (`woocommerce_checkout_create_order_line_item`). Inside that loop, the per-item meta writes are correct (different keys per item). But the order-meta writes (lines 589-598) target the *order*, not the item — so each item's iteration overwrites the previous item's values.

Symptom: a cart with two memorials for different honorees results in an order where `_sd_honoree_name` equals the second memorial's honoree. Product_Mapper then reads order meta for *every* item, so the first memorial's ability gets the wrong honoree name.

This is a real, reproducible bug for anyone who submits two memorials in one cart. Memorial gift orders for multiple loved ones are a real use case for shelters.

Same defect for `_sd_campaign_id`, `_sd_dedication_type`, `_sd_is_anonymous` — though `_sd_is_anonymous` happens to be order-level (semantic: "this donor wants this order to be anonymous") so it's arguably correct as order-meta, just shouldn't be inside the per-item loop.

### 2.4 Checkout-fields silently overrides form values

[checkout-fields.php:518-537](includes/woocommerce/class-checkout-fields.php#L518-L537) — `save_checkout_fields` runs on `woocommerce_checkout_update_order_meta`, which fires *after* `woocommerce_checkout_create_order_line_item`. So if a user filled both the form (step 2) AND the duplicate checkout-fields entry (step 5), the checkout value wins.

[checkout-fields.php:88-231](includes/woocommerce/class-checkout-fields.php#L88-L231) declares 12 custom checkout fields. The overlap with form blocks is near-total:

| Field | Collected by form block? | Collected by checkout-fields? | Winner today |
|---|---|---|---|
| `dedication` (text) | Yes (donation form) | Yes | Checkout |
| `is_anonymous` | Yes (all forms) | Yes | Checkout |
| `campaign_id` | Yes (donation form) | Yes | Checkout (only if user re-picked) |
| `business_name` | Yes (membership form) | Yes (required!) | Checkout — and the field is `required`, so the form value is required to be re-entered |
| `honoree_name` | Yes (memorial form) | Yes (required!) | Checkout (form value lost; required field re-entered) |
| `tribute_message` | Yes (memorial form) | Yes | Checkout (form value lost) |
| `pet_species` | No | Yes | Checkout-only |
| `notify_family` | Yes (memorial form) | Yes | Checkout (form value lost) |
| `family_name/email/address/send_card` | Yes (memorial form) | Yes | Checkout (form value lost) |

The design is genuinely confused. Two paths exist for collecting almost the same data; one wins by accident-of-action-firing-order rather than by stated rule. Donors filling the form *and* the duplicate checkout-fields get prompted twice; donors filling only the form lose their data; donors filling only the checkout-fields get correct behavior.

This is also why the dedication free-text input survives at all — only the checkout-fields path writes it.

### 2.5 Logo upload is end-to-end orphaned

The chain:

1. [cart-handler.php:97-105](includes/woocommerce/class-cart-handler.php#L97-L105) handles the multipart file upload during add-to-cart. `handle_logo_upload()` ([:640-680](includes/woocommerce/class-cart-handler.php#L640-L680)) creates a WordPress attachment via `media_handle_upload()` and stores `_sd_logo_status: pending` and `_sd_logo_source: membership_form` on the **attachment** post (not on the membership post — there is no membership post yet).
2. The attachment ID is injected into `$_POST['sd_logo_attachment_id']`.
3. [cart-handler.php:256-258](includes/woocommerce/class-cart-handler.php#L256-L258) stashes it as `sd_logo_attachment_id` cart-item data.
4. [cart-handler.php:578](includes/woocommerce/class-cart-handler.php#L578) saves it as item meta `_sd_logo_attachment_id`.
5. [config/products.json:54-77](config/products.json#L54-L77) `shelter-memberships-business` has **no `logo_attachment_id` entry in `input_mapping`**.
6. So `Product_Mapper::build_input` never adds `logo_attachment_id` to the ability input.
7. The membership `create()` ability never receives it, never copies it to the membership post.
8. `Logo_Moderation` queries `sd_membership` posts with `_sd_logo_attachment_id` set — finds zero from checkout submissions.

The orphan attachment exists in `wp_posts` with `_sd_logo_source: membership_form` meta, untracked by anything. The admin moderation queue is empty regardless of how many business memberships were submitted.

**Trivial fix:** Add one entry to `products.json`:

```json
"logo_attachment_id": {
  "source": "item_meta",
  "key": "_sd_logo_attachment_id",
  "transform": "integer"
}
```

Plus confirm `abilities/memberships.php` `create()` reads `$input['logo_attachment_id']` and writes it as `_sd_logo_attachment_id` on the membership post (per Memberships sweep, it does at [memberships.php:87](includes/abilities/memberships.php#L87)).

### 2.6 `donor_name` is unconditionally overwritten

[product-mapper.php:106](includes/woocommerce/class-product-mapper.php#L106) — `build_input` unconditionally sets:

```php
'donor_name' => self::get_donor_name( $order ),
```

…which concatenates `billing_first_name + ' ' + billing_last_name`. There is no `input_mapping` entry that could override this; the assignment happens *before* the `input_mapping` loop. So the form's chosen `donor_name` (cart-item `sd_donor_name`, item meta `_sd_donor_name`) is silently dropped.

The form might collect `donor_name` for a reason — e.g., "Donate as: [Jane Doe] (your business name) (anonymous)". The product mapper can't honor it.

### 2.7 The `dedication` / `dedication_enabled` tangle

`dedication` means two different things in two places:

- **In donation-form**, `dedication` is a plain text input ("In memory of...") submitted as `dedication`. Cart-handler has no handler for this POST field. Only checkout-fields' `dedication` field writes it.
- **In memorial-form**, `dedication_enabled` is a boolean gate that controls a whole block of fields (`dedication_type`, `honoree_name`, `honoree_type`, `tribute_message`, `notify_family`, `family_*`, `send_card`). Cart-handler handles this at [cart-handler.php:209-246](includes/woocommerce/class-cart-handler.php#L209-L246).

Two different fields, both named `dedication`, with overlapping semantics. The donation case is essentially a memorial-lite ("I'm donating in honor/memory of [name], no further details"). The current form design forces users to either fill the rich memorial form (which routes to a different product entirely) or write a single line in donation-form's `dedication` text field that nobody persists.

### 2.8 `validate_item_meta` validates only one of three storage locations

[order-handler.php:222-229](includes/woocommerce/class-order-handler.php#L222-L229) requires `_sd_honoree_name` item meta to exist for memorial items. But the same data is also stored in order meta (by cart-handler:597 and checkout-fields:530) and can come from the checkout-fields path entirely. So this validation:

- ✗ Fails to catch memorials where item meta is missing but order meta has it (checkout-only flow).
- ✓ Catches direct-add-to-cart items (the actual goal).

The fix is to validate against the *resolved input* (after Product_Mapper runs) rather than against raw item meta. Or skip the pre-build validation entirely and have the ability itself enforce required fields via its `input_schema`.

---

## 3. Specific bugs (file:line catalog)

Beyond the architectural items above:

### 3.1 `memorial_type` enum mismatch (already in pattern sweep)

[abilities.json:462-465](config/abilities.json#L462-L465) restricts to `["person","pet"]`. [entities.json](config/entities.json) declares `["human","pet","honor"]`. [class-list-columns.php:377,383](includes/admin/class-list-columns.php#L377) defaults to `'human'`. [class-import-ajax-handler.php:562](includes/admin/import-export/class-import-ajax-handler.php#L562) writes `'human'`. [memorials.php:60](includes/abilities/memorials.php#L60) defaults to `'memory'`. Five sources, three enums, one impossible default value.

The WC layer touches this at [cart-handler.php:391](includes/woocommerce/class-cart-handler.php#L391) (defaults `honoree_type` to `'person'`) and [products.json:84-88](config/products.json#L84-L88) (lowercases the variation attr). So WC-side enforces `["person","pet"]`, which then collides with everything else.

### 3.2 Misleading SVG error message

[cart-handler.php:653](includes/woocommerce/class-cart-handler.php#L653) — error says "Logo must be a PNG, JPG, or SVG file" but [:649](includes/woocommerce/class-cart-handler.php#L649) only permits PNG/JPG. SVG is deliberately excluded ([:646-648](includes/woocommerce/class-cart-handler.php#L646-L648) has a security comment about it). The error message just lies. One-line fix.

### 3.3 Trust-the-browser MIME validation

[cart-handler.php:650](includes/woocommerce/class-cart-handler.php#L650) — `$_FILES['business_logo']['type']` is the client-provided MIME, easily spoofed. A user could upload `logo.png.svg` with `Content-Type: image/png` and bypass the SVG exclusion. WP core's `wp_check_filetype_and_ext` exists for exactly this. Replace with:

```php
$file_check = wp_check_filetype_and_ext(
    $_FILES['business_logo']['tmp_name'],
    $_FILES['business_logo']['name'],
    [ 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg' ]
);
if ( ! $file_check['ext'] || ! $file_check['type'] ) {
    return new WP_Error( 'invalid_file_type', __( 'Logo must be a PNG or JPG file.', 'starter-shelter' ) );
}
```

### 3.4 `$_REQUEST` reads in cart-handler

[cart-handler.php:412,416,424,428,710](includes/woocommerce/class-cart-handler.php#L412) — uses `$_REQUEST` (combines GET, POST, COOKIE per PHP's `request_order` ini). For an add-to-cart URL handler this should be `$_GET`. Not a vulnerability, but indicates uncertainty about where the data comes from.

### 3.5 Dead writes

- [order-handler.php:189](includes/woocommerce/class-order-handler.php#L189) `_sd_ability_result` written per item, **read nowhere**. Only `_sd_processing_results` (order meta) is read at [:530,665](includes/woocommerce/class-order-handler.php#L530).
- [cart-handler.php:189,579](includes/woocommerce/class-cart-handler.php#L189) `_sd_custom_price` written, **read nowhere**. `set_custom_price` reads from in-memory cart_item, not from saved meta.
- [cart-handler.php:677](includes/woocommerce/class-cart-handler.php#L677) `_sd_logo_source` written to attachment post, **read nowhere**.

### 3.6 Hardcoded product type checks scatter

[cart-handler.php:97](includes/woocommerce/class-cart-handler.php#L97) checks `'business_membership' === $product_type` via string compare. [checkout-fields.php:323-329](includes/woocommerce/class-checkout-fields.php#L323-L329) checks `str_contains($sku_prefix, 'business')` — a different test for the same concept. Product_Mapper has no concept of `business_membership` at all (both individual and business have `product_type: 'membership'`); the SKU prefix is the only distinguisher there.

### 3.7 Subscription renewal hook fires without WC Subscriptions check

[order-handler.php:52](includes/woocommerce/class-order-handler.php#L52) — adds listener for `woocommerce_subscription_renewal_payment_complete`. No `class_exists('WC_Subscriptions')` guard. If the plugin isn't installed, the hook never fires — no error. But if a future minor WC change renames the action or breaks the API, the renewal path silently stops working. Add an explicit feature check.

### 3.8 Idempotency guard is fragile

[order-handler.php:76](includes/woocommerce/class-order-handler.php#L76) — `_sd_processed` meta blocks reprocessing. If an order transitions `processing → on-hold → completed`, this works. If a third-party plugin clears the meta (unlikely but possible), duplicate CPTs could be created. Acceptable for now; document the assumption.

### 3.9 Variation matching is fragile

[cart-handler.php:283-351](includes/woocommerce/class-cart-handler.php#L283-L351) — `find_variation` tries four attribute formats then walks all variations comparing slugs. If a product has variations with non-distinct slug-normalized values (e.g., "Memorial - Person" and "Memorial Person"), the first match wins. Edge case; not common.

### 3.10 `display_admin_order_fields` echoes raw HTML

[checkout-fields.php:633](includes/woocommerce/class-checkout-fields.php#L633) — `echo $output` with a `phpcs:ignore`. The `$output` is built via `sprintf` with `esc_html($display_value)`, so the values are escaped — but a `<strong>` tag is literal. Acceptable; the suppress directive is honest.

### 3.11 Email order-table render is near-duplicate of admin render

[checkout-fields.php:610-635](includes/woocommerce/class-checkout-fields.php#L610-L635) and [:645-662](includes/woocommerce/class-checkout-fields.php#L645-L662) are 90% the same code with different output templates. Extract a `get_order_field_summary($order): array` and let callers format.

---

## 4. Substrate redesign

The minimal, low-risk fix is to **shift the contract from "form writes both, ability reads order_meta" to "form writes item_meta, ability reads item_meta with order_meta fallback."** Three changes, in order:

### 4.1 Change 1 — `products.json` source switch (~30 min, no code)

For each per-item field, switch `source: order_meta` → `source: item_meta`. The keys stay identical because cart-handler already writes both `_sd_X` (item meta) using the same name.

Per-item fields (one per cart line): `business_name`, `logo_attachment_id`, `honoree_name`, `tribute_message`, `pet_species`, `notify_family.*`, `dedication`, `dedication_type`, `honoree_type`. These are properties of an individual gift.

Order-level fields (one per order): `is_anonymous`, arguably `campaign_id` (debatable — a donor might attribute one item to a campaign and another not). For these, keep `source: order_meta` *or* switch to `item_meta` and rely on the cart-handler writing the same value to every item.

**Recommended config** (full replacement for `products.json` `input_mapping` sections):

```jsonc
"shelter-donations": {
  "ability": "shelter-donations/create",
  "product_type": "donation",
  "input_mapping": {
    "allocation":   { "source": "attribute", "key": "preferred-allocation",
                      "transform": "normalize_allocation", "default": "general-fund" },
    "campaign_id":  { "source": "item_meta", "key": "_sd_campaign_id",
                      "fallback": { "source": "order_meta", "key": "_sd_campaign_id" } },
    "is_anonymous": { "source": "item_meta", "key": "_sd_is_anonymous",
                      "fallback": { "source": "order_meta", "key": "_sd_is_anonymous" },
                      "transform": "boolean", "default": false },
    "dedication":   { "source": "item_meta", "key": "_sd_dedication",
                      "fallback": { "source": "order_meta", "key": "_sd_dedication" } }
  }
},
"shelter-memberships-business": {
  // ... same pattern
  "input_mapping": {
    "tier":               { "source": "attribute", "key": "membership-level", "transform": "normalize_tier" },
    "membership_type":    { "source": "static", "value": "business" },
    "business_name":      { "source": "item_meta", "key": "_sd_business_name",
                            "fallback": { "source": "order_meta", "key": "_sd_business_name",
                                          "fallback": { "source": "order_field", "key": "billing_company" } } },
    "logo_attachment_id": { "source": "item_meta", "key": "_sd_logo_attachment_id",
                            "transform": "integer" }
  }
},
"shelter-donations-in-memoriam": {
  "input_mapping": {
    "memorial_type":   { "source": "attribute", "key": "in-memoriam-type", "transform": "lowercase" },
    "honoree_name":    { "source": "item_meta", "key": "_sd_honoree_name",
                         "fallback": { "source": "order_meta", "key": "_sd_honoree_name" } },
    "tribute_message": { "source": "item_meta", "key": "_sd_tribute_message",
                         "fallback": { "source": "order_meta", "key": "_sd_tribute_message" } },
    "pet_species":     { "source": "item_meta", "key": "_sd_pet_species",
                         "fallback": { "source": "order_meta", "key": "_sd_pet_species" } },
    "is_anonymous":    { "source": "item_meta", "key": "_sd_is_anonymous",
                         "fallback": { "source": "order_meta", "key": "_sd_is_anonymous" },
                         "transform": "boolean", "default": false },
    "notify_family":   { "source": "composite", "fields": {
      "enabled":   { "source": "item_meta", "key": "_sd_notify_family",
                     "fallback": { "source": "order_meta", "key": "_sd_notify_family" },
                     "transform": "boolean", "default": false },
      "name":      { "source": "item_meta", "key": "_sd_family_name",
                     "fallback": { "source": "order_meta", "key": "_sd_family_name" } },
      "email":     { "source": "item_meta", "key": "_sd_family_email",
                     "fallback": { "source": "order_meta", "key": "_sd_family_email" } },
      "address":   { "source": "item_meta", "key": "_sd_family_address",
                     "fallback": { "source": "order_meta", "key": "_sd_family_address" } },
      "send_card": { "source": "item_meta", "key": "_sd_send_card",
                     "fallback": { "source": "order_meta", "key": "_sd_send_card" },
                     "transform": "boolean", "default": false }
    } }
  }
}
```

`Product_Mapper::resolve_mapping` already supports `fallback` ([class-product-mapper.php:184-186](includes/woocommerce/class-product-mapper.php#L184-L186)), so this is a pure config change.

Effect: form-submitted data (cart-item meta) is read first; if missing, fall back to order-meta (the checkout-fields path); if missing, fall back to default.

### 4.2 Change 2 — drop the order-meta duplicate writes in cart-handler (~5 min, 10 LOC)

Delete [cart-handler.php:588-598](includes/woocommerce/class-cart-handler.php#L588-L598). The duplicate order-meta writes were a coping mechanism for the order_meta-only Product_Mapper config; once Product_Mapper reads item meta, they're unnecessary. Removing them also fixes the per-item loop overwrite bug (§2.3) because nothing inside the loop touches order meta anymore.

```diff
 foreach ( $meta_keys as $key ) {
     if ( isset( $values[ $key ] ) ) {
         $item->add_meta_data( '_' . $key, $values[ $key ], true );
     }
 }
-
-// Also save to order meta for easy access.
-if ( ! empty( $values['sd_campaign_id'] ) ) {
-    $order->update_meta_data( '_sd_campaign_id', $values['sd_campaign_id'] );
-}
-if ( ! empty( $values['sd_is_anonymous'] ) ) {
-    $order->update_meta_data( '_sd_is_anonymous', true );
-}
-if ( ! empty( $values['sd_dedication_enabled'] ) ) {
-    $order->update_meta_data( '_sd_dedication_type', $values['sd_dedication_type'] ?? 'honor' );
-    $order->update_meta_data( '_sd_honoree_name', $values['sd_honoree_name'] ?? '' );
-}
```

`save_checkout_fields` ([checkout-fields.php:518-537](includes/woocommerce/class-checkout-fields.php#L518-L537)) still writes order meta from the checkout-fields path. That covers items added without using the form (direct shop page, URL adds), and the fallback in §4.1 handles them.

### 4.3 Change 3 — establish a precedence rule for the form/checkout-fields overlap (~1h, design decision)

Two options:

**Option A: Form is authoritative; checkout-fields is a fallback for non-form items.**

Reduce the checkout-fields field set to only those needed when the user *didn't* use a form block. For memorial via memorial-form, hide `honoree_name`, `tribute_message`, `pet_species`, `notify_family`, `family_*`, `send_card` from checkout-fields display. They're already collected; showing them again is confusing.

Implementation: `Checkout_Fields::display_conditional_fields` checks the cart items for `_sd_product_type` meta. If present (form-added items), exclude fields whose data already lives in cart-item meta.

Cleaner UX; matches user intent. Form values win.

**Option B: Eliminate the overlap entirely.**

Move the entire family-notification / memorial detail collection out of checkout-fields. Make memorial-form the only entry point. For users who reach checkout with a memorial product added some other way, show a generic "please use the memorial tribute form" notice and block checkout for that item.

More invasive. Probably the right end state — checkout-fields exists today as a hedge against the form being unreliable, and once the form pipeline works (§4.1, §4.2), the hedge can go.

**Recommendation: Option A for v1**, because it's less work and preserves the safety net. Revisit Option B once §4.1 + §4.2 are deployed and verified.

### 4.4 Migration / data impact

Zero data migration needed. The change only affects how *new* orders are processed. Existing CPTs created from prior orders aren't touched. Existing order meta isn't deleted or moved.

Optional follow-up: a one-shot script that backfills item meta for unprocessed orders, but most plugins won't have any unprocessed orders sitting around because the idempotency guard ([order-handler.php:76](includes/woocommerce/class-order-handler.php#L76)) processes them on first eligible status change.

### 4.5 Test plan

For each of donation / individual membership / business membership / memorial:

1. Submit a single-item via the form block, complete checkout. Verify the corresponding CPT has all fields populated.
2. Submit a multi-item cart (two memorials for different honorees, or one donation + one membership). Verify each CPT has its own values, not the last item's.
3. Submit a single-item via the form block, then fill the duplicate checkout-fields entries with different values. Verify form wins (per Option A) or checkout wins (current behavior, if Option A isn't deployed yet).
4. Submit a single-item via direct add-to-cart (e.g., shop page URL with no form), then fill the checkout-fields. Verify the checkout-fields values reach the CPT via the order_meta fallback.
5. For business membership: upload a logo, complete checkout, verify Logo Moderation queue shows the pending logo and the membership post has `_sd_logo_attachment_id` set.
6. For memorial with family notification: complete checkout, verify the family-notification meta on the memorial post is populated (and the email handler can read it once the email-system bugs are fixed).

---

## 5. Implementation phases

| Phase | Tasks | Est. | Outcome |
|---|---|---|---|
| **0. Pre-reqs from sweep** | Fix `entities.json` `sd_membership` missing fields (CC-3 from sweep). Confirm `abilities/memberships.php:create()` reads `logo_attachment_id` from input. | 30m | Hydrator emits the right fields; ability stores logo. |
| **1. Substrate redesign** | §4.1 config change + §4.2 code change. | 1.5h | Form data reaches abilities for all product types. Per-item overwrite bug fixed. Logo flow works end-to-end. |
| **2. UX rationalization** | §4.3 Option A — hide duplicate checkout-fields when form is the source. | 1.5h | Donors don't see duplicate prompts. |
| **3. Bug-list cleanup** | §3.1 enum unification (decide canonical: `["person","pet"]` likely). §3.2 SVG message. §3.3 server-side MIME check. §3.5 remove dead writes. §3.6 centralize product-type detection. §3.7 add WC Subs feature check. | 2h | Minor correctness + security tightening. |
| **4. Verification** | §4.5 test plan, with one real cart per product type plus a multi-item memorial cart. | 1h | Confidence the substrate fix actually works. |

**Total: ~6 hours** for a stable WC integration substrate. Most P3 hits from the [pattern sweep](AUDIT-pattern-sweep.md) for Memorials, Memberships, and Email dissolve after Phase 1.

---

## 6. Resolved design decisions

The user reviewed the open questions and decided:

1. **`campaign_id` is per-item.** Donors may attribute different items to different campaigns. Implementation: form-side collects per item via cart-item meta; no order-level write. Reports must not assume a single campaign per order.

2. **`is_anonymous` is per-item.** Per-item flag respected for donations and memberships independently. Sensible defaults: membership defaults to named; donation respects per-item flag.

3. **`donor_name` from form overrides billing.** Add a `donor_name` input_mapping entry with `source: item_meta, key: _sd_donor_name`, falling back to billing first+last when absent. Remove the unconditional billing-name overwrite at [product-mapper.php:106](includes/woocommerce/class-product-mapper.php#L106) and make `donor_name` a normal mapped field that defaults to billing.

4. **Keep checkout-fields (Option A in §4.3).** Reason: PayPal Pay Now / Express checkout bypasses the WC checkout page, which would break custom field collection for items added without a form block. Traditional WC checkout stays. So `Checkout_Fields` remains as a fallback for non-form-added items; the form is authoritative when present (form values reach the ability via `item_meta`, with `order_meta` from checkout-fields as fallback per §4.1's config).

5. **Rename donation's free-text `dedication` field.** Keep `dedication_enabled` as the boolean flag controlling the rich memorial-style block. Rename the standalone donation text input to `donation_message` (or `tribute_text`) to remove the name collision in §2.7. Update [donation-form](blocks/donation-form/), [class-cart-handler.php](includes/woocommerce/class-cart-handler.php) (add a handler for the new field name), [class-checkout-fields.php:101-110](includes/woocommerce/class-checkout-fields.php#L101-L110), and the donations ability input.

6. **SVG remains disallowed.** Keep the current PNG/JPG-only allowlist. Two cleanups: fix the misleading error message at [cart-handler.php:653](includes/woocommerce/class-cart-handler.php#L653) ("PNG, JPG, or SVG" → "PNG or JPG"), and replace the trust-the-browser MIME check at [:650](includes/woocommerce/class-cart-handler.php#L650) with `wp_check_filetype_and_ext` server-side validation (§3.3).

7. **Remove the WC Subscriptions renewal hook.** User does not intend to use WC Subscriptions and plans to design a simple custom renewal mechanism instead. Delete [order-handler.php:52](includes/woocommerce/class-order-handler.php#L52) and the `process_renewal` method at [:277-339](includes/woocommerce/class-order-handler.php#L277-L339); the custom renewal flow is a separate design exercise (see §7 below).

---

## 7. Future work: custom membership renewal

Out of scope for this audit, but flagged for separate design. The plugin already has the building blocks:

- Renewal-reminder cron at [includes/cron/class-renewal-reminder.php](includes/cron/class-renewal-reminder.php) (currently has the `_sd_email_preferences` key-mismatch bug per the sweep — Pattern 2).
- `shelter-memberships/renew` ability at [includes/abilities/memberships.php](includes/abilities/memberships.php).
- Email templates: `membership-renewal.php` / `-plain.php`.

A simple custom renewal mechanism likely needs:
- A renewal-due query (memberships with `_sd_end_date` in next N days, no recent renewal).
- An email with a magic-link or pre-checkout cart URL that adds the same membership product with `_sd_renewal_of: <original_membership_id>` cart-item meta.
- On order completion, `Order_Handler` detects the renewal meta and calls `shelter-memberships/renew` instead of `create`.
- Idempotency keyed on the original membership ID.

Worth a dedicated design pass once the substrate redesign is deployed.
