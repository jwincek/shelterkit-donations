# Releasing to WordPress.org

## Gates, and what each one is for

| Command | Catches |
| --- | --- |
| `bin/check-versions.sh` | The version recorded in the plugin header, the `STARTER_SHELTER_VERSION` constant, `readme.txt`'s `Stable tag`, `CHANGELOG.md` and the `.pot` disagreeing — plus `Requires at least`, `Requires PHP` and composer's platform floor |
| `bin/build-dist.sh` | A development file reaching the package, or a runtime file going missing |
| `bin/check-screenshots.sh` | `screenshot-N.png` files not lining up with the readme's captions |
| `composer lint` | WordPress coding standards, with every `WordPress.Security.*` sniff enabled |
| `composer test` | The pure-function unit suite |
| Plugin Check (CI) | What WordPress.org's own review tooling reports, run against the **built** package |

Run the whole chain locally before tagging:

```sh
bin/check-versions.sh
bin/build-dist.sh build
bin/check-screenshots.sh
composer lint
composer test
```

## Publishing

Publishing is driven entirely by pushing a version tag:

```sh
git tag v2.1.0 && git push origin v2.1.0
```

`.github/workflows/release.yml` validates the metadata, re-runs the gates,
builds the package, deploys to SVN, and attaches an installable zip to the
GitHub release. The SVN step is skipped until the `SVN_USERNAME` and
`SVN_PASSWORD` repository secrets exist, so the workflow is safe to merge
before the plugin is approved.

## Things that bite

- **The slug is permanent.** It is derived from the plugin name at submission.
- **Text domain must equal the slug.** A mismatch makes translations silently
  fail to load.
- **One plugin in review at a time.** A second submission is not looked at
  until the first is resolved, and each slot is weeks.
- **SVN `assets/` is a sibling of `trunk/`**, not inside it. That is why
  `.wordpress-org/` is excluded from the build and passed to the deploy action
  as `ASSETS_DIR`.
- **Screenshot captions pair by number**, not by filename. A gap attaches
  captions to the wrong image, silently.
- **Plugin Check derives the expected text domain from the directory name**, so
  check a directory actually named `shelterkit-donations`, or pass
  `--slug=shelterkit-donations`.
- **Run Plugin Check against the built output**, never the repository. The
  repository reports errors on dev files users never receive, and misses
  whatever packaging actually produced.


## The shared ShelterKit files

`includes/shelterkit/` holds `ShelterKit_Profile` and
`ShelterKit_Profile_Versions`, carried by every ShelterKit plugin. Each plugin
registers its copy and its version at file scope; on `plugins_loaded` the
highest version present is the one that loads. That is Action Scheduler's
pattern, and it means no plugin in the family depends on a sibling being
installed.

**Copy these files unchanged — with exactly one exception.** The text domain
must be the host plugin's own slug. Two separate things break otherwise, and
neither is obvious:

- Plugin Check treats a foreign text domain as an **error**, not a warning, so
  a copy carrying a sibling's domain cannot pass WordPress.org review at all.
  This surfaced when Donations first took the file: 18 errors, on a plugin that
  was otherwise clean.
- `wp i18n make-pot` extracts by domain, so a foreign one silently leaves every
  label in the file out of the POT — untranslatable in *both* plugins.

The rest of the file is single-sourced. If you change its logic, change it in
one plugin and copy it to the others, bumping `VERSION` so the negotiation
picks up the newer copy.

### Which plugin renders the Shelter Details screen

Whichever one's copy won. Gate the registration on
`ShelterKit_Profile_Versions::winner()` matching this plugin's own path —
**not** on `class_exists( 'ShelterKit_Profile' )`, which is true in every
plugin carrying a copy and therefore registers one menu entry per installed
ShelterKit plugin, all editing the same option.

### Current versions

| Plugin | Profile copy |
| --- | --- |
| ShelterKit Donations | 1.3.0 — adds `tax_id`, merges on save |
| ShelterKit Pets | 1.1.0 — sync when convenient |

A lower copy is not a bug: the highest present wins, and nothing in Pets reads
`tax_id`. As of 1.3.0 `save()` merges into the stored option rather than
replacing it, so an older copy winning the negotiation no longer drops fields
it does not declare — the copies no longer have to be kept in lockstep.

## Security sniff baseline

`composer lint` reports sites where the WordPress security sniffs cannot see a
guard that is present. Each was checked by hand against the code; the guard and
the reason the sniff misses it are recorded below. **A finding in a function
not on this list is new and should be treated as real.**

| Pattern | Where | Guard, and why the sniff misses it |
| --- | --- | --- |
| Dispatcher reads a routing key before dispatching | `My_Account::handle_account_actions()`, `Settings::handle_product_actions()` | The dispatcher reads only the action name; the nonce is checked inside the branch it dispatches to. The sniff scopes nonce checks per function. |
| Nonce checked via a wrapper | `My_Account::handle_update_profile()`, `handle_recognition()`, `handle_cancel_membership()`, `handle_toggle_auto_renew()` | All four call `verify_action_nonce()`, which wraps `wp_verify_nonce()`. Registered in `phpcs.xml.dist` as a custom nonce function; the remaining hits are inside closures, which are their own function scope. |
| Ownership additionally enforced | `handle_cancel_membership()`, `handle_toggle_auto_renew()` | Both call `current_user_owns_membership()` before mutating, so a valid nonce alone does not grant access to another donor's membership. |
| Private helper behind a guarded caller | `Cart_Handler::handle_logo_upload()`, `Data_Integrity::count_*()` / `get_ids_missing_meta()`, `Order_Scanner::count_*()`, `Logo_Moderation::get_logos()`, `Activity_Log::get_client_ip()` / `cleanup_old_logs()` | Reached only from a caller that checks nonce and capability. `handle_logo_upload()` in particular runs from `ajax_add_to_cart()`, behind `check_ajax_referer( 'sd_add_to_cart' )` and a rate limit. |
| Guard is the registered permission callback | `abilities/reports.php`, `abilities/donations.php` | Abilities are registered through `Provider::register()`, which resolves a `permission_callback` for each. The callback is not in the function the sniff is reading. |
| Guard is the REST permission callback | `Candles_Controller` | `/candles/*` is deliberately public — anonymous candle-lighting is the feature — and rate-limited per sender. Every `/donor/me/*` route requires `is_user_logged_in` and is scoped to the current user; the only writing route, `/donors/find-or-create`, requires `manage_options`. |
| WooCommerce verifies before the hook fires | `Checkout_Fields::validate_checkout_fields()`, `save_checkout_fields()` | These run on WooCommerce checkout hooks, after WooCommerce has verified its own checkout nonce. |
| Table name from `$wpdb->prefix`, values prepared | `reports.php`, `class-data-integrity.php`, `class-order-scanner.php` | The interpolated part is a `$wpdb` property or a generated `%d,%d` placeholder list; every user value is bound through `$wpdb->prepare()`. `ReplacementsWrongNumber` fires because the sniff cannot follow a `...$args` spread or a `%d` inside an interpolated fragment. |
| Read-only, no state change | `register-bindings.php::calculate_stat()`, `helpers.php::resolve_campaign_id_from_request()`, `Query::sum()`, `Legacy_Sync_Page::extract_filters()` | Read a filter/campaign id off the request to scope a public read. No write, so a nonce would not add anything. |

### Upload handling

`handle_logo_upload()` accepts a file from a logged-out visitor by design — a
business membership includes a logo. It is guarded by the add-to-cart nonce, a
rate limit, `wp_check_filetype_and_ext()` against a PNG/JPEG allowlist (SVG is
deliberately excluded, since SVGs can carry script), a 2 MB cap, and
admin moderation before the logo appears anywhere public.
