# Contributing to ShelterKit Donations

Part of [ShelterKit](README.md#part-of-shelterkit). Bug reports and pull
requests are welcome. Support questions belong on the WordPress.org forum —
see `.github/ISSUE_TEMPLATE/config.yml`.

## Requirements

- PHP 8.1 or newer (8.1 is the floor the plugin's header promises, and CI pins
  a job to it deliberately — see below)
- WordPress 6.9+ and WooCommerce 9.0+
- Composer

## Setup

```sh
composer install
```

There is no npm step. The blocks are server-rendered with no build, so what is
in the repository is what runs.

## Quality gates

Run the whole chain before opening a PR:

```sh
bin/check-versions.sh     # version metadata agrees with itself
composer lint             # WordPress coding standards
composer test             # unit suite
bin/build-dist.sh build   # packaging, with its leak guard
```

`RELEASING.md` explains what each gate exists to catch and how to publish.

### Coding standards

`phpcs.xml.dist` is the full `WordPress` ruleset. Every `WordPress.Security.*`
sniff is enabled; the exclusions are style-only and each carries a comment
saying why.

**Errors fail the build; warnings do not.** Warnings here are advisory sniffs —
object-cache hints, `meta_query` cost, callback parameters a hook signature
requires but the body does not use. Errors are treated as real.

Where a security sniff cannot see a guard that exists, the code carries a
`phpcs:disable` with the reason on the same line, and `RELEASING.md` records the
pattern. **Do not add one without checking the guard actually exists.** If you
are annotating rather than fixing, say which guard covers the code and where it
is.

Two placement traps, both of which have bitten this repository:

- A `phpcs:disable` on a line that is *inside* a multi-line string or an inline
  HTML block is not a comment — it is content. It ends up in the SQL, or on the
  rendered page. `php -l` still passes.
- A `phpcs:ignore` applies to the **next** line. Inserting anything between it
  and the code it guards silently moves the exemption.

### Indentation

Four spaces, not tabs. `Generic.WhiteSpace.DisallowSpaceIndent` is excluded for
that reason. Please match the file you are editing.

### Unit tests

```sh
composer test              # pure functions, no WordPress bootstrap
composer test:integration  # needs the WP test library
composer test:wc           # needs WooCommerce too
```

`bin/install-wp-tests.sh` needs an explicit WordPress version — `latest` is not
a tag in the `wordpress-develop` repository and returns a 404.

### Translations

Every user-facing string uses the `shelterkit-donations` text domain. CI
regenerates the template and fails if it drifts:

```sh
wp i18n make-pot . languages/shelterkit-donations.pot \
  --domain=shelterkit-donations \
  --exclude=tests,vendor,node_modules,build,migration-scripts
```

Regenerate and commit whenever you add, move, or remove a translatable string —
line references count.

### Plugin Check

The gate WordPress.org actually applies. Run it against the **built** package,
never the repository:

```sh
bin/build-dist.sh build
wp plugin check build/shelterkit-donations --slug=shelterkit-donations
```

Checking the repository reports errors on dev files users never receive, and
misses whatever packaging actually produced.

## What must not change

Renaming any of these breaks existing installs, because they are persisted in
the database or in post content rather than being code:

- Post types `sd_donation`, `sd_donor`, `sd_membership`, `sd_memorial`
- The `sd_campaign` taxonomy and every `_sd_*` meta key
- `starter_shelter_options` and the `sd_*` option keys
- The cron hook `sd_cleanup_activity_log`
- **The 12 `shelter-donations/*` block names** and the 9 Interactivity store
  namespaces — these appear in saved post content
- The `shelter-donations/v1` REST namespace

The `Starter_Shelter\` namespace, `STARTER_SHELTER_*` constants and `sd_` prefix
are internal and deliberately do **not** track the plugin's public name. That
decoupling is what made the ShelterKit rename a day's work rather than a week's.

## The shared ShelterKit files

`includes/shelterkit/` is carried by every ShelterKit plugin and is
single-sourced. Copy it unchanged **except for the text domain**, which must be
this plugin's slug — Plugin Check treats a foreign domain as an error, and
`make-pot` extracts by domain. `RELEASING.md` has the details.
