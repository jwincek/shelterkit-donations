# Tests

Two PHPUnit suites, both run under PHPUnit 9.6 (the version the WordPress
core test framework supports — it still uses PHPUnit ≤9 APIs).

## Unit suite (`tests/Unit/`)

Pure functions only — **no WordPress, no database**. The few WP functions
the code calls (`__`, `wp_strip_all_tags`) are stubbed in
`tests/bootstrap.php`. Fast (~15ms).

```bash
composer test          # or: composer test:unit
```

## Integration suite (`tests/integration/`)

Boots a real WordPress + MySQL, loads this plugin (WooCommerce absent —
the code under test is WC-free and the plugin self-guards), and exercises
`create()`, `Entity_Hydrator`, and the CSV exporter against real data.

### One-time setup

Install the WP test library, a throwaway WP core, and a test database.
The script uses `curl` (no Subversion required):

```bash
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
```

`db-host` accepts `host`, `host:port`, or `host:/path/to/mysqld.sock`.

### Running against Local by Flywheel

Local's MySQL listens on a socket and its client isn't on `PATH`, so add
it and pass the socket as the host. Use a **dedicated** DB name — the
suite drops and recreates its tables:

```bash
MYSQLBIN=$(dirname "$(ls ~/Library/Application\ Support/Local/lightning-services/mysql-*/bin/darwin/bin/mysqladmin | head -1)")
SOCK=$(ls ~/Library/Application\ Support/Local/run/*/mysql/mysqld.sock | head -1)
PHP=$(ls ~/Library/Application\ Support/Local/lightning-services/php-8.2*/bin/darwin/bin/php | tail -1)

export PATH="$MYSQLBIN:$PATH"
export WP_TESTS_DIR="$TMPDIR/wp-tests-lib" WP_CORE_DIR="$TMPDIR/wp-core"

bin/install-wp-tests.sh wp_phpunit_tests root root "localhost:$SOCK" 7.0
"$PHP" vendor/bin/phpunit -c phpunit-integration.xml.dist   # == composer test:integration
```

Run on PHP 8.2 or 8.3 — PHPUnit 9.6 predates PHP 8.4.

### Environment variables

- `WP_TESTS_DIR` — WP test library location (default
  `$TMPDIR/wordpress-tests-lib`).
- `WP_CORE_DIR` — throwaway WP core location (default `$TMPDIR/wordpress`).

CI (`.github/workflows/ci.yml`, `integration-tests` job) runs the same
script against a MySQL service container.
