# Shelter Donations

Animal shelter donations, memberships, and memorials management for WordPress 6.9+ using the Abilities API, Block Bindings, and the Interactivity API.

## Requirements

- WordPress 6.9+
- PHP 8.1+
- WooCommerce 9.0+

## Installation

1. Clone or download this repository into `wp-content/plugins/shelter-donations/` (the directory name must match the `shelter-donations` slug/textdomain).
2. Run `composer install --no-dev` for production, or `composer install` to include dev dependencies (PHPCS, PHPCompatibility, PHPUnit polyfills).
3. Activate the plugin in **Plugins → Installed Plugins**.
4. Activate WooCommerce if not already active.
5. Visit **Shelter Donations → Settings → Products** to set up donation products.

## Development Setup

This plugin uses [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for local development (`.wp-env.json` is in the repo root):

```bash
# Start the environment (WP 6.9 + WooCommerce pre-installed)
npx wp-env start

# Run PHPCS (PHPCompatibilityWP, testVersion 8.1-)
composer lint

# Auto-fix what PHPCS can
composer lint:fix

# Run the config/code contract validator (13 checks; see "Validator" below)
wp shelter-donations validate
```

CI runs the syntax check on PHP 8.1/8.2/8.3 plus the PHPCS lint on every push to `main`.

## Architecture

The plugin follows a **config-driven, layered architecture**:

- **Manifest Layer** — Per-entity PHP-array files in `config/manifests/` (`sd_donation.php`, `sd_membership.php`, `sd_memorial.php`, `sd_donor.php`, plus `_shared.php`) are the single source of truth for each entity's field declarations, abilities, products, meta-boxes, checkout-fields, and emails. `Field_Manifest` merges them into the runtime config before consumers see it.
- **Legacy JSON Layer** — A few cross-cutting files remain in `config/` for things that aren't entity-owned by design: `products.json` (cross-product blocks like `legacy_products`, `sku_attribute_mapping`, `checkout_field_sets`), `abilities.json` (cross-entity `shelter-reports/*` aggregations), `post-types.json`, `taxonomies.json`, and shared `schemas/`.
- **Infrastructure Layer** — Reusable PHP classes (`Config`, `Field_Manifest`, `Entity_Hydrator`, `Query`, `CPT_Registry`) read config and do the heavy lifting.
- **Abilities Layer** — Discrete operations registered via the WordPress 6.9+ Abilities API with JSON Schema validation.
- **Consumer Layer** — Thin integrations (WooCommerce, blocks, admin pages) that delegate to abilities.

### Field Manifests

A manifest is a PHP file returning an array; it owns *every* layer-mention of every field for one entity. Excerpt:

```php
// config/manifests/sd_donation.php
return [
    'entity' => [
        'post_type'   => 'sd_donation',
        'meta_prefix' => '_sd_',
        'fields'      => [
            'amount' => [ 'type' => 'number', 'show_in_rest' => true ],
            // …
        ],
    ],
    'abilities' => [
        'shelter-donations/create' => [
            'input_schema'  => [ 'properties' => [
                'amount' => [ '$entity' => 'amount' ],   // pull from entity, override siblings
            ] ],
            // …
        ],
    ],
    'meta_boxes'      => [ /* admin meta-box layout */ ],
    'checkout_fields' => [ /* WC checkout-field declarations */ ],
    'products'        => [ /* product → ability input mapping */ ],
    'emails'          => [ /* email triggers + trigger_args schema */ ],
];
```

The `$entity` ref convention lets ability/product/email/checkout-field schemas reuse entity field declarations without duplication (no schema-vs-schema drift). External `$ref` resolution, composite-save directives on meta-boxes, and typed `trigger_args` for emails are all supported. See `config/manifests/*.php` for working examples.

### Validator

`wp shelter-donations validate` runs 13 static checks against the manifests + PHP source: ability references, manifest coverage of every layer, producer arg-counts vs declared `trigger_args`, ability return-shape vs `output_schema`, template field references through the entity walker, and more. The validator never executes plugin code — it's pure JSON + PHP-token analysis, fast enough to run on every change. Expect 0 findings on a healthy tree.

### Key Directories

```
config/manifests/    → Per-entity manifests (the source of truth for everything entity-owned)
config/              → Cross-cutting JSON (products legacy/SKU blocks, post-types, taxonomies, schemas)
includes/abilities/  → Ability callbacks and registration
includes/admin/      → Admin pages, meta boxes, reports
includes/blocks/     → Block bindings, interactivity stores, editor registration
includes/cli/        → wp-cli commands (validator)
includes/core/       → Config loader, Field_Manifest, entity hydrator, query builder, CPT registry
includes/emails/     → Config-driven WooCommerce email integration
includes/woocommerce/→ Order handler, product mapper, checkout fields, cart, My Account
blocks/              → Block definitions (block.json, render.php, edit.js, style.css)
templates/           → Email templates (HTML and plain text)
```

### Custom Post Types

| Post Type | Slug | Visibility |
|-----------|------|------------|
| Donation | `sd_donation` | Private |
| Membership | `sd_membership` | Private |
| Memorial | `sd_memorial` | Public |
| Donor | `sd_donor` | Private |

### WooCommerce Products

The plugin creates four variable products on activation, mapped from `config/products.json`:

- **General Donations** — allocation-based tiers
- **In Memoriam Donations** — memorial tribute tiers
- **Individual Memberships** — tiered annual memberships
- **Business Memberships** — tiered annual business memberships

## Extending the Plugin

### Adding a new entity

1. Add a post type to `config/post-types.json` (and any taxonomies to `config/taxonomies.json`).
2. Create `config/manifests/<post_type>.php` returning an array with `entity`, `abilities`, `products`, `meta_boxes`, `checkout_fields`, and `emails` sections as needed. Use `$entity` refs in ability/product schemas to reuse field declarations.
3. Write the ability callbacks (typically ~50 lines per entity) in `includes/abilities/<entity>.php`.
4. Run `wp shelter-donations validate` — fix any findings before considering the entity done.

### Adding a new email

1. Add the email definition under the owning entity's manifest `emails` block. Use `$ability_input` refs in `trigger_args` to reuse the producing ability's input schema.
2. Create HTML and plain-text templates in `templates/emails/`.
3. The producer (the code that does `do_action( 'starter_shelter_…' )`) must pass exactly the args declared in `trigger_args` — the validator's `check_producer_arg_counts` will catch mismatches.

For cross-cutting patterns (autoloader, security, REST routes, block development, etc.) the family of plugins this one belongs to shares a common style guide. In a full development site checkout it lives at `PLUGIN-DEVELOPMENT-GUIDE.md` next to `wp-content/`.

## License

GPL-2.0-or-later. See WordPress [license](https://wordpress.org/about/license/) for details.
