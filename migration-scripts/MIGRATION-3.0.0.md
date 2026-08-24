# Migrating a live install: 2.x → 3.0.0 (the `shelterkit-donations` rename)

3.0.0 renames the plugin's **public identity only** — folder, main file, text
domain and display name — as it joins the ShelterKit family. It is a far
smaller change than 2.0.0 was.

> **The important difference from 2.0.0:** block names are *not* changing.
> They stay `shelter-donations/*`. There is no `wp search-replace` in this
> migration, and no post content is touched.

Run every command on the **live server** with its own `wp-cli`.

---

## What changes, and why each needs care

| Change | Impact | Handled by |
|---|---|---|
| **Folder `shelter-donations` → `shelterkit-donations`, main file renamed to match** | The `active_plugins` entry points at the old path. Swapping files while active makes WordPress silently deactivate the plugin and leave a stale entry. | Deactivate **first** (step 2), activate the new path after (step 4). |
| **Text domain `shelter-donations` → `shelterkit-donations`** | Runtime-only for the plugin itself. Any custom `.mo`/`.po` files stop loading until renamed. | Step 5, only if you have translations. |
| **Admin page slugs `shelter-donations*` → `shelterkit-donations*`** | Bookmarks to plugin admin screens 404. Per-user screen-option preferences (hidden columns) reset. | Nothing — re-bookmark. |
| **Version 2.x → 3.0.0** | None. | Nothing. |

**Preserved — no action needed.** Everything in the database:

- Post types `sd_donation`, `sd_donor`, `sd_membership`, `sd_memorial`
- All 78 `_sd_*` meta keys, and every `sd_*` option
- `starter_shelter_options`, and all `starter_shelter_*` hook, filter and cron names
- **All 12 `shelter-donations/*` block names** — placed blocks keep rendering
- The 9 Interactivity store namespaces and the `shelter-donations/v1` REST namespace
- The `Starter_Shelter\` namespace and `STARTER_SHELTER_*` constants — deliberate, not an oversight
- WooCommerce products, orders and order-item meta

---

## 0. Back up first (non-negotiable)

```bash
wp db export ~/backup-pre-3.0.0-$(date +%F).sql
cd wp-content/plugins && tar czf ~/shelter-donations-pre-3.0.0.tgz shelter-donations
```

## 1. Note your tax ID

3.0.0 moves the shelter's tax ID into the shared **Shelter Details** screen.
Before upgrading, copy what is in *Shelter Donations → Settings → EIN (Tax ID)*
— you will re-enter it in step 6.

If your emailed annual statements have been printing `[EIN Number]`, that is
the bug this release fixes; the value you need is the one in that settings
field, not what the emails showed.

## 2. Deactivate

```bash
wp plugin deactivate shelter-donations
```

## 3. Swap the files

```bash
cd wp-content/plugins
git -C shelter-donations pull            # or upload the 3.0.0 build
mv shelter-donations shelterkit-donations
```

The main file inside must be `shelterkit-donations.php`. A release build
already has it; a `git pull` renames it for you.

## 4. Activate the new path

```bash
wp plugin activate shelterkit-donations
wp plugin list --name=shelterkit-donations
```

## 5. Translations (skip if you have none)

```bash
cd wp-content/languages/plugins
for f in shelter-donations-*.mo shelter-donations-*.po; do
  [ -e "$f" ] && mv "$f" "shelterkit-${f#shelter-}"
done
```

## 6. Fill in Shelter Details

Go to **ShelterKit Donations → Shelter Details** and enter the shelter's name,
address, contact details and the tax ID from step 1. This screen is shared: if
you also run ShelterKit Pets, it reads the same record, and whichever plugin
carries the newest copy of the profile is the one that hosts the screen.

## 7. Verify

```bash
# Blocks still resolve — this should be unchanged from before the upgrade
wp db query "SELECT COUNT(*) FROM $(wp db prefix --allow-root 2>/dev/null || echo wp_)posts \
  WHERE post_content LIKE '%wp:shelter-donations/%' AND post_status != 'trash';"

# Stored data untouched
wp post list --post_type=sd_donation --format=count
wp post list --post_type=sd_donor --format=count
```

Then load a page carrying a donation form, and send yourself an annual
statement from **Donors → Quick Actions** to confirm the tax ID now appears.

## Rolling back

```bash
wp plugin deactivate shelterkit-donations
cd wp-content/plugins && rm -rf shelterkit-donations
tar xzf ~/shelter-donations-pre-3.0.0.tgz
wp plugin activate shelter-donations
```

Nothing in the database changed, so a rollback needs no data restore.
