=== ShelterKit Donations ===
Contributors: jeromewincek
Tags: donations, memberships, memorials, animal-shelter, nonprofit
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 3.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donations, memberships, and memorials for animal shelters, built on WooCommerce checkout — with campaigns, donor accounts, and recognition walls.

== Description ==

ShelterKit Donations gives an animal shelter (or any small nonprofit with similar needs) a complete contribution system on top of the WooCommerce checkout it may already use:

* **Donations** — allocation-based giving tiers (general fund, medical fund, etc.) plus "In Memoriam" tribute donations, all processed through standard WooCommerce checkout, so every payment gateway you already have keeps working.
* **Memberships** — tiered annual memberships for individuals and businesses, with renewal reminders, self-service auto-renew and cancellation, and an opt-out public recognition wall. Business members can submit a logo (admin-moderated) and appear in a rotating showcase.
* **Memorials** — a public memorial wall honoring people and pets, "in memory of" and "in honor of" dedications, visitor candle-lighting, and family notification emails.
* **Campaigns** — time-boxed donation or membership drives with goals, live progress bars, and campaign-filterable reporting.
* **Donor accounts** — donors see their giving history in My Account, manage contact details and memberships, control their public recognition, and print an itemized annual contribution statement for tax time.
* **Reporting & data tools** — an admin dashboard with campaign- and type-aware reports, CSV import/export, and a full-backup ZIP export for pre-uninstall safety.

= Blocks =

Twelve blocks, all server-rendered with no build step: Donation Form, Membership Form, Memorial Form, Memorial Wall, Memorial Archive, Members Wall, Business Member Slideshow, Campaign Card, Campaign Progress, Contribution Tabs, Donor Dashboard, and Donor Stats. Interactive behavior (filters, candles, slideshows, live stats) uses the WordPress Interactivity API.

= Built on modern WordPress =

The plugin requires WordPress 6.9 because its operations are registered through the Abilities API with JSON Schema validation — every create/list/report operation is a discrete, schema-checked ability, which is also what makes the plugin's data layer scriptable and testable. It declares WooCommerce HPOS compatibility and honors donor anonymity everywhere names could appear.

= Part of ShelterKit =

ShelterKit is a family of plugins for animal shelters. **ShelterKit Pets** puts your adoptable animals on your own site; **ShelterKit Donations** handles contributions, memberships and memorials; a companion plugin covers events. Each one works on its own, and they share a single "Shelter Details" screen — your shelter's name, address, contact details and tax ID are entered once and used by whichever of them is installed. The family was originally built for and battle-tested on [vcpahumane.org](https://vcpahumane.org), the Venango County Humane Society, and is designed so any shelter can use it.

= Privacy =

Donor data lives in your WordPress database as private custom post types; nothing is sent to third-party services. Donors can be anonymous, members can opt out of public recognition, and CSV exports are hardened against spreadsheet formula injection. Uninstall preserves data by default (an explicit opt-in deletes it).

== Installation ==

1. Install and activate **WooCommerce** (9.0 or newer) — Shelter Donations processes contributions through WooCommerce checkout.
2. Install and activate **ShelterKit Donations**. On activation it creates four WooCommerce products: General Donations, In Memoriam Donations, Individual Memberships, and Business Memberships.
3. Visit **ShelterKit Donations → Settings** to configure allocations, membership tiers, and donor levels.
4. Add the Donation Form, Membership Form, or Memorial Form block (or the all-in-one Contribution Tabs block) to a page.

== Frequently Asked Questions ==

= Does it require WooCommerce? =

Yes, for taking payments — contributions go through the standard WooCommerce checkout, so every gateway, tax, and email setting you already have applies. Admin data management works without WooCommerce active, but the contribution forms need it.

= Why WordPress 6.9+? =

The plugin's operations are built on the Abilities API introduced in WordPress 6.9. There is no fallback for older versions.

= Is this only for the shelter it was built for? =

No. It was originally built for the Venango County Humane Society (vcpahumane.org), but every label, allocation, tier, and species list is configurable, and nothing is hard-coded to one organization.

= Where is donor data stored, and can donors stay anonymous? =

Everything is stored locally as private custom post types (donations, donors, memberships; memorials are public). Donors can give anonymously, and members can opt out of the public Members Wall from their own account.

= Does uninstalling delete my data? =

No — uninstall preserves all data by default. Deletion is an explicit opt-in, and the admin tools include a full-backup CSV ZIP export you can take first.

== Screenshots ==

1. The reports dashboard. Totals, a donation trend over time, and a breakdown by fund — filterable by period and by campaign, with a CSV export of whatever is on screen.
2. The contribution form on the front end. One block offers giving, joining and honouring a loved one as tabs; checkout runs through the WooCommerce checkout the site already uses.
3. The memorial wall. Visitors search and filter tributes by type, dedication and year, light a candle on one, and open a full memorial page for any of them.
4. The members wall, grouping members by tier. Members opt out of appearing here from their own account.
5. Campaign reporting. Each drive shows its goal, live progress and end date, and every other report can be narrowed to one campaign.
6. Donation allocations are configured, not hard-coded — as are membership tiers and donor levels, so the plugin fits an organisation other than the one it was built for.
7. The WooCommerce products the plugin uses are mapped here, and can be created for you if they do not exist yet.
8. CSV export for every record type, plus a full-backup ZIP to take before uninstalling.

== Changelog ==

= 3.0.0 =
* Renamed to **ShelterKit Donations**, joining ShelterKit Pets in the ShelterKit family. Your data is unaffected — donations, donors, memberships, memorials, settings and every placed block carry over untouched.
* New shared **Shelter Details** screen holding your shelter's name, address, contact details and tax ID. Enter them once; every ShelterKit plugin you install reads the same record.
* Fixed: the emailed annual contribution statement printed the literal text "[EIN Number]" instead of your tax ID. It read a setting that nothing ever saved, so no configuration could correct it. The printed receipt was unaffected, which is why the two disagreed.
* Fixed: the annual statement's letterhead took its address from your WooCommerce store settings — the address goods ship from, which matches the shelter only by coincidence. It now uses the Shelter Details address, falling back to the store address when that is blank.
* A statement for a shelter with no tax ID recorded now omits the line entirely rather than printing a placeholder.
* Your shelter's name, address, phone and tax ID are now entered once, on Shelter Details, instead of also having their own fields under Settings. Anything you had already filled in is carried across for you.
* Fixed: the admin screens still said "Shelter Donations" after the rename — the menu, page titles and the dashboard widget now all read ShelterKit Donations. The WooCommerce product keeps its existing name so your order history stays intact.

= 2.0.1 =
* Fixed a WordPress 6.7+ "translation loading triggered too early" notice on admin pages: meta-box configuration (which translates field labels) is now built lazily after the `init` action instead of at `plugins_loaded`.

= 2.0.0 =
* Renamed the plugin's public identity to `shelter-donations` (formerly "Starter Shelter Donations") for the first WordPress.org release.
* Breaking for existing installs: the plugin folder, main file, text domain, and block namespace changed. Follow `migration-scripts/MIGRATION-2.0.0.md` from the GitHub repository when upgrading a pre-2.0.0 site.
* No data changes: all content, settings, donors, and WooCommerce products are preserved.

= 1.2.0 =
* Printable annual contribution statement (tax receipt) in My Account.
* Self-service donor contact details, membership auto-renew/cancel, and public-recognition opt-out.
* New Members Wall and Business Member Slideshow blocks; memorial Dedication filter.
* Full-backup CSV ZIP export, uninstall.php with preserve-by-default retention, HPOS compatibility declaration.

Full changelog: https://github.com/jwincek/shelterkit-donations/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 3.0.0 =
Renamed to ShelterKit Donations. Your data carries over untouched and placed blocks keep working. Fixes an emailed annual statement that printed "[EIN Number]" in place of your tax ID — re-enter it under Shelter Details after upgrading. Existing installs: follow migration-scripts/MIGRATION-3.0.0.md.

= 2.0.0 =
Breaking rename release (folder, main file, text domain, block namespace). Existing pre-2.0.0 installs must follow the migration guide in the GitHub repository; fresh installs are unaffected.
