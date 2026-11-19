# Upgrading a live 2.x install past the `skdon_` storage move

3.1.0 moves everything the plugin stores from the `sd_` prefix to
`skdon_` (#30). WordPress.org asks for prefixes of at least four characters,
and other plugins use `sd_`. The move runs by itself on the first request
after the upgrade and takes well under a second on a shelter-sized site.

Coming from **2.x**, this is the same upgrade as 2.x → 3.0.0 plus the move.
Follow `MIGRATION-3.0.0.md` for the folder swap, with the additions below.

Run every command on the **live server** with its own `wp-cli`.

---

## What moves, and what does not

**Moves** (the full list is `Core\Prefix_Migration` in the plugin):

- The post types `sd_donation`, `sd_donor`, `sd_membership`, `sd_memorial`,
  and the taxonomies `sd_campaign`, `sd_memorial_year`,
  `sd_donation_allocation`, including where their names are stored as values:
  menu items, block attributes such as a Query Loop's post type, a
  Site Editor copy of the Single Memorial template, and the activity log.
- Every `_sd_*` meta key on the plugin's own records, its logo attachments,
  its products, and WooCommerce orders and order items.
- The plugin's options, its Config overrides, and the settings saved for its
  eight emails under **WooCommerce → Settings → Emails**.
- The activity-log table, its cron event, per-user dashboard and screen
  preferences, and carts sitting in a WooCommerce session.

**Does not move:**

- URLs. Memorials, campaigns and memorial years keep their pretty permalinks.
  Only plain-permalink links such as `?sd_memorial=…` change.
- The `sd_amount`, `sd_allocation` and `sd_campaign` add-to-cart URL
  parameters, form field names, CSS classes and the candle cookie.
- Anything another plugin stores under `sd_`. The move renames only names on
  its list, and meta only on objects this plugin owns.

**Cleaned up on the way:**

- `_sd_date`, an unread copy of the donation date written by 1.x, is
  removed. Records that had only the copy keep the date.
- Memorials older than 1.1.2 that kept family-notification details as one
  nested value get the five separate fields every screen reads.

---

## 0. Back up the database first

Non-negotiable. The move can be reversed (see the end), but a backup is the
real safety net.

```bash
wp db export before-skdon-$(date +%Y%m%d).sql
```

## 1. Record counts to compare afterwards

```bash
for t in sd_donation sd_donor sd_membership sd_memorial; do
  printf '%s ' $t; wp post list --post_type=$t --post_status=any --format=count
done
```

## 2. Check custom code for the old names

Theme templates, a child theme, `mu-plugins` or code snippets that read the
plugin's data directly must change with it.

```bash
grep -rnE "_sd_|sd_(donation|donor|membership|memorial|campaign)" \
  wp-content/themes wp-content/mu-plugins
```

Also look for a theme file named `single-sd_memorial.php`, `.html` or
`archive-sd_memorial.*`. Rename it to `skdon_`.

## 3. Decide about the activity log's history

From 3.1.0 the activity log deletes entries once they pass a retention
period, and the first cleanup removes everything older than that.

**After 3.1.0:** the periods are under **Settings → General → Data
Management**. Routine entries are kept 90 days and admin actions 2 years
by default, or choose Forever (#38). The first cleanup runs a day after the
update, so set them right after upgrading.

**On 3.1.0 itself:** the cleanup runs as soon as the site is updated, and
the period can only be changed in code. Export the table first, or extend
the period *before* upgrading with a must-use plugin, for example
`wp-content/mu-plugins/shelterkit-log-retention.php`:

```php
<?php
add_filter( 'starter_shelter_activity_log_retention_days', fn() => 3650 );
```

On 3.1.0, do not return `0`: that deletes every entry. Later releases
ignore 0 and negative values.

## 4. Upgrade

Follow `MIGRATION-3.0.0.md` steps 1 to 6: tax ID, deactivate, swap the
files, activate the new path, translations, Shelter Details. Activating the
new path runs the move. Verify with step 5 below rather than that guide's
step 7, which counts the old post type names.

If you are already on the 3.0.0 folder, just replace the files. The next
request runs the move.

## 5. Verify

```bash
wp shelterkit-donations migrate-prefix --status
# → "Success: Nothing is stored under an sd_ name."

for t in skdon_donation skdon_donor skdon_membership skdon_memorial; do
  printf '%s ' $t; wp post list --post_type=$t --post_status=any --format=count
done
# → the same numbers as step 1
```

The first admin page after the move shows a notice with what moved. Then:

- **Reports:** totals for this year match what they were.
- **WooCommerce → Settings → Emails:** the plugin's emails keep any subject,
  heading or recipient you had changed.
- **A memorial page and the memorial archive:** both load.
- **A test gift:** add one to the cart and check out with a test gateway, or
  watch the next real one. It should appear under Donations with its fund.

## 6. Afterwards

- **Purge any page cache.** Cached pages still post the old action names.
  They keep working for this release, but they will stop in the next one.
- **SEO plugins** keep per-post-type settings under the post type's name.
  Re-save the Memorials and Campaigns settings (titles, indexing) in Yoast,
  SEOPress, Rank Math or similar.
- **Screen options** such as hidden columns and closed boxes carry over. A
  bookmark to `edit.php?post_type=sd_…` needs updating.
- **ShelterKit Donations → Data Tools → Recalculate.** Before #53 was
  fixed, every memorial added its amount to the donor's lifetime giving
  twice. Recalculate rebuilds each donor's total from their records.
- **ShelterKit Donations → Settings → General.** Before #50 was fixed,
  saving the Emails tab turned renewal reminders off, reset the reminder
  period to 30 days and cleared the Donation and Membership pages. If anyone
  has ever saved the Emails tab, check those four and save General again.
  The same save stored the Features switches as off. Three of them did
  nothing until #49, so the update turns them back on, which is what the
  forms have been doing all along, and a notice names them. Turn off any
  you don't want, then save.
- **Memorials → family notifications "Pending".** Until #58, Notify Family
  never sent the family an email, but recorded them as notified. Updating
  clears those dates, and a notice says how many memorials are waiting.
  Before you notify them, check whether anyone told those families another
  way.
- **ShelterKit Donations → Data Tools → Order Records.** Both steps are safe
  to run more than once, and neither emails a donor.
  - If this site ever ran Legacy Order Sync, check the list of records it
    merged and press **Repair** (#40).
  - Press **Apply past refunds** so refunds and cancellations made before
    3.1.0 stop counting (#34). Then match any refund it lists by choosing
    the gift it returned.

---

## If the move stops partway

The plugin switches itself off rather than run on half-moved data. Admins
see a notice with the database error. It retries on every request, so fix
the cause (often disk space or a lost connection), then reload, or run:

```bash
wp shelterkit-donations migrate-prefix
```

## Rolling back to 2.x

```bash
wp shelterkit-donations migrate-prefix --reverse --yes
```

This moves everything back to the `sd_` names and keeps the plugin inert so
it cannot move them forward again. Then follow the rollback in
`MIGRATION-3.0.0.md` to reinstall 2.0.1.

Two cleanups stay done:
- The removed `_sd_date` copies stay removed. 2.x never read them.
- Expanded family-notification fields stay expanded. 2.x reads those fields
  first.

To go forward again instead, run the command without `--reverse`, or
deactivate and reactivate the plugin. Upgrading from the 2.x release again
later works the same way as the first time: activation moves the data.
