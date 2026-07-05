=== Shelter Donations ===
Contributors: jeromewincek
Tags: donations, memberships, memorials, animal-shelter, nonprofit
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 2.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Donations, memberships, and memorials for animal shelters, built on WooCommerce checkout — with campaigns, donor accounts, and recognition walls.

== Description ==

Shelter Donations gives an animal shelter (or any small nonprofit with similar needs) a complete contribution system on top of the WooCommerce checkout it may already use:

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

= Part of the Shelter plugin family =

Shelter Donations belongs to a family of shelter-management plugins (alongside Shelter Pet Sync and Shelter Events Wrapper). The family was originally built for and battle-tested on [vcpahumane.org](https://vcpahumane.org), the Venango County Humane Society — and is designed so any shelter can use it.

= Privacy =

Donor data lives in your WordPress database as private custom post types; nothing is sent to third-party services. Donors can be anonymous, members can opt out of public recognition, and CSV exports are hardened against spreadsheet formula injection. Uninstall preserves data by default (an explicit opt-in deletes it).

== Installation ==

1. Install and activate **WooCommerce** (9.0 or newer) — Shelter Donations processes contributions through WooCommerce checkout.
2. Install and activate **Shelter Donations**. On activation it creates four WooCommerce products: General Donations, In Memoriam Donations, Individual Memberships, and Business Memberships.
3. Visit **Shelter Donations → Settings** to configure allocations, membership tiers, and donor levels.
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

== Changelog ==

= 2.0.0 =
* Renamed the plugin's public identity to `shelter-donations` (formerly "Starter Shelter Donations") for the first WordPress.org release.
* Breaking for existing installs: the plugin folder, main file, text domain, and block namespace changed. Follow `migration-scripts/MIGRATION-2.0.0.md` from the GitHub repository when upgrading a pre-2.0.0 site.
* No data changes: all content, settings, donors, and WooCommerce products are preserved.

= 1.2.0 =
* Printable annual contribution statement (tax receipt) in My Account.
* Self-service donor contact details, membership auto-renew/cancel, and public-recognition opt-out.
* New Members Wall and Business Member Slideshow blocks; memorial Dedication filter.
* Full-backup CSV ZIP export, uninstall.php with preserve-by-default retention, HPOS compatibility declaration.

Full changelog: https://github.com/jwincek/shelter-donations/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 2.0.0 =
Breaking rename release (folder, main file, text domain, block namespace). Existing pre-2.0.0 installs must follow the migration guide in the GitHub repository; fresh installs are unaffected.
