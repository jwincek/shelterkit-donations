# Migrating a live install: 1.x → 2.0.0 (the `shelter-donations` rename)

This upgrade is **not** a normal code bump. 2.0.0 renames the plugin's public
identity — folder, main file, text domain, and **block namespace** — from
`starter-shelter` to `shelter-donations`. Done in the wrong order, the plugin
silently drops out of `active_plugins` and every page using its blocks renders
"This block contains unexpected content." Done in the order below, it's safe
and reversible.

> Run every command on the **live server** with its own `wp-cli` (plain `wp …`,
> not the local Flywheel socket override). Replace `PLUGIN_DIR` with the actual
> plugin folder name on the server (likely `vcpahumane-wc-donations`).

---

## What changes, and why each needs care

| Change | Impact | Handled by |
|---|---|---|
| **Folder → `shelter-donations`, main file `starter-shelter.php` → `shelter-donations.php`** | The `active_plugins` entry points at the old path. If you swap files while the plugin is active, WP silently deactivates it and leaves a stale entry. | Deactivate **first** (step 2), activate the new path after (step 4). |
| **Block names `starter-shelter/*` → `shelter-donations/*`** (14 blocks, plus block-binding sources) | Every page/template/widget using the old block names breaks ("unexpected content"); bindings go blank. | `wp search-replace` (step 5). |
| **Text domain `starter-shelter` → `shelter-donations`** | Runtime-only for the plugin itself, but any custom translation files stop loading (see edge cases). | Rename `.mo`/`.po` files if present. |
| **Interactivity stores / `window.starterShelterBlocks` → `shelterDonationsBlocks`** | Runtime-only — nothing stored. Only matters if a theme or snippet reads these names. | grep your theme (step 6). |
| **Version 1.x → 2.0.0** | None — deploying via git ignores version ordering. | nothing — just expect it. |

**Preserved — no action needed:** all content and data. The `sd_donation`,
`sd_donor`, `sd_membership`, `sd_memorial` post types and every `_sd_*` meta
key are unchanged; all `sd_*` option keys (`sd_config_*` settings overrides,
`sd_products_created`, …) survive as-is; all `starter_shelter_*` hook, filter,
and cron event names are unchanged; WooCommerce products, orders, and
order-item meta are untouched.
The `Starter_Shelter\` PHP namespace and `STARTER_SHELTER_*` constants remain
internally — that's deliberate, not an oversight.

---

## 0. Back up first (non-negotiable)

```bash
# Database
wp db export ~/backup-pre-2.0.0-$(date +%F).sql

# Plugin files (so you can restore the exact old tree)
cd wp-content/plugins
tar czf ~/PLUGIN_DIR-pre-2.0.0.tgz PLUGIN_DIR
```

Confirm both files exist and are non-empty before continuing.

## 1. Maintenance mode on

During steps 2–5 the donation/membership/memorial blocks are unregistered or
mis-named, so take the front end down:

```bash
wp maintenance-mode activate
```

## 2. Deactivate the old plugin — BEFORE touching any files

```bash
wp plugin deactivate PLUGIN_DIR
```

**Order matters.** If you rename the folder or pull the new code first, WP
finds `PLUGIN_DIR/starter-shelter.php` missing, silently deactivates the
plugin, and leaves a stale `active_plugins` entry behind. (This exact
leftover has been observed from earlier pet-sync renames — if you suspect
stale entries from past upgrades, `wp eval 'require_once ABSPATH .
"wp-admin/includes/plugin.php"; validate_active_plugins();'` purges them.)

## 3. Replace the folder with the renamed checkout

```bash
cd wp-content/plugins
mv PLUGIN_DIR shelter-donations
cd shelter-donations
git fetch origin && git checkout main && git pull --ff-only origin main
```

The 2.0.0 tree's main file is `shelter-donations.php`; the old
`starter-shelter.php` disappears with the pull. No `composer install` is
needed — the plugin has no runtime Composer dependencies (vendor/ is
dev-tooling only and isn't shipped).

> Deploying from the built ZIP instead of git? Delete the old folder and
> unzip the new one as `wp-content/plugins/shelter-donations/`. Note that
> `migration-scripts/` is excluded from the ZIP — keep this file handy
> separately.

## 4. Activate under the new identity

```bash
wp plugin activate shelter-donations
```

Expected: `Plugin 'shelter-donations' activated.` The activation hook
re-registers post types and flushes rewrites.

## 5. Update block names in stored content

Renames `wp:starter-shelter/*` block delimiters and `starter-shelter/*`
block-binding sources to `shelter-donations/*`. **Dry-run first** and review
the count:

```bash
# Preview
wp search-replace 'starter-shelter/' 'shelter-donations/' wp_posts \
  --include-columns=post_content --dry-run --report-changed-only

# Apply
wp search-replace 'starter-shelter/' 'shelter-donations/' wp_posts \
  --include-columns=post_content --report-changed-only
```

`wp_posts` covers regular pages/posts **and** Site-Editor templates/parts and
reusable blocks (all stored there). If you use **block-based widgets** with
these blocks, also run it for `wp_options --include-columns=option_value`
(dry-run first). The string `starter-shelter/` only occurs in block names and
binding sources, so this is targeted — it cannot match option keys (`sd_*`) or
hook/cron names (`starter_shelter_*` with underscores), all of which are
unchanged anyway.

On the reference local install this replaced 50 occurrences and left zero
`starter-shelter` strings anywhere in `wp_posts`, `wp_postmeta`, or plugin
options — if a post-apply dry-run still reports matches, investigate before
leaving maintenance mode.

## 6. Verify (still in maintenance mode)

```bash
wp post list --post_type=sd_donation --format=count     # data intact?
wp post list --post_type=sd_memorial --format=count

# Settings overrides intact? (count should match what you had before)
wp eval 'global $wpdb; echo (int) $wpdb->get_var(
  "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"sd\_config\_%\""
), " sd_config_* option rows\n";'

# Any page using the blocks should render the new namespace and no stale one:
wp eval '$q = new WP_Query(["post_type"=>"any","posts_per_page"=>1,"s"=>"wp:shelter-donations/"]);
foreach ($q->posts as $p) { $h = do_blocks($p->post_content);
echo strpos($h,"starter-shelter")===false ? "clean\n" : "STALE MARKUP in {$p->ID}\n"; }'
```

Also grep your active theme for the old names (only needed if the theme was
customized against this plugin):

```bash
grep -rn 'starter-shelter\|starterShelterBlocks' wp-content/themes/YOUR-THEME/ || echo "theme clean"
```

## 7. Maintenance mode off

```bash
wp maintenance-mode deactivate
```

## 8. Post-deploy checks

- Load the donation form page, the memorial wall, and the members wall —
  confirm blocks render (not "unexpected content").
- Submit a **test donation** through checkout and confirm it appears in
  **Shelter Donations → Donations** (exercises the WC integration end-to-end).
- On the memorial wall, use the Type and Dedication filters and light a candle
  — this exercises the renamed `shelter-donations/*` Interactivity stores.
- In **My Account → Donor Dashboard**, confirm the statement/print view loads.
- `wp cron event list | grep starter_shelter` — scheduled events (renewal
  reminders etc.) should still be present; their hook names are unchanged.

---

## Rollback (if anything looks wrong)

```bash
wp maintenance-mode activate
cd wp-content/plugins
wp plugin deactivate shelter-donations
rm -rf shelter-donations
tar xzf ~/PLUGIN_DIR-pre-2.0.0.tgz          # restores the old plugin folder
wp db import ~/backup-pre-2.0.0-$(date +%F).sql
wp plugin activate PLUGIN_DIR                # original path
wp maintenance-mode deactivate
```

Because the DB dump predates the content search-replace, restoring it undoes
the block-name migration in one step.

## Edge cases to check

- **Custom translations.** WP loads plugin language packs by text domain:
  `wp-content/languages/plugins/starter-shelter-{locale}.mo` will no longer
  load. If any exist, regenerate them against `languages/shelter-donations.pot`
  (or at minimum rename the files to `shelter-donations-{locale}.mo` and fix
  the domain header).
- **Customized Site-Editor templates.** The block-name search-replace fixes
  block names *inside* customized templates, but if a template customization
  was saved against a plugin-provided template slug, re-check it renders after
  the upgrade.
- **Code snippets / must-use plugins** referencing `starter-shelter/*` block
  names, `window.starterShelterBlocks`, or registering block styles/variations
  for the old names — grep `wp-content/mu-plugins/` the same way as the theme.
- **Multisite.** Run steps 4–6 per site with `--url=…`, or loop with
  `wp site list --field=url`.
