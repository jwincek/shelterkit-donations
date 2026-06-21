#!/usr/bin/env bash
###############################################################################
# Install the WordPress PHPUnit test library + a throwaway WP core + test DB.
#
# Unlike the canonical wp-cli scaffold script this uses curl + the GitHub
# wordpress-develop tarball instead of `svn export`, so it runs on machines
# without Subversion (e.g. stock macOS) — the same script CI uses.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# db-host may be "host", "host:port", or "host:/path/to/mysqld.sock".
# Honors WP_CORE_DIR / WP_TESTS_DIR env vars for install locations.
###############################################################################

set -euo pipefail

DB_NAME="${1:-}"
DB_USER="${2:-}"
DB_PASS="${3:-}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-7.0}"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
	exit 1
fi

TMPDIR="${TMPDIR:-/tmp}"
TMPDIR="${TMPDIR%/}"
WP_TESTS_DIR="${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-$TMPDIR/wordpress}"

download() {
	curl -fsSL "$1" -o "$2"
}

install_wp() {
	if [ -d "$WP_CORE_DIR" ] && [ -f "$WP_CORE_DIR/wp-load.php" ]; then
		echo "WP core already present at $WP_CORE_DIR"
		return
	fi

	mkdir -p "$WP_CORE_DIR"
	echo "Downloading WordPress $WP_VERSION core…"
	download "https://wordpress.org/wordpress-${WP_VERSION}.zip" "$TMPDIR/wordpress.zip"
	unzip -q -o "$TMPDIR/wordpress.zip" -d "$TMPDIR/wp-core-unzip"
	# The zip extracts to a top-level wordpress/ dir.
	cp -r "$TMPDIR/wp-core-unzip/wordpress/." "$WP_CORE_DIR/"
	rm -rf "$TMPDIR/wp-core-unzip" "$TMPDIR/wordpress.zip"
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR/includes" ]; then
		echo "Test library already present at $WP_TESTS_DIR"
	else
		mkdir -p "$WP_TESTS_DIR"
		# wordpress.org ships X.Y for ".0" releases (wordpress-7.0.zip) but
		# the wordpress-develop repo tags them as semver (7.0.0). Normalize:
		# an X.Y version maps to the X.Y.0 develop tag; X.Y.Z is used as-is.
		local develop_tag="$WP_VERSION"
		if echo "$WP_VERSION" | grep -qE '^[0-9]+\.[0-9]+$'; then
			develop_tag="${WP_VERSION}.0"
		fi
		echo "Downloading wordpress-develop $develop_tag test library…"
		download "https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/tags/${develop_tag}" "$TMPDIR/wp-develop.tar.gz"
		mkdir -p "$TMPDIR/wp-develop"
		tar --strip-components=1 -xzf "$TMPDIR/wp-develop.tar.gz" -C "$TMPDIR/wp-develop"
		cp -r "$TMPDIR/wp-develop/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -r "$TMPDIR/wp-develop/tests/phpunit/data" "$WP_TESTS_DIR/data"
		cp "$TMPDIR/wp-develop/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
		rm -rf "$TMPDIR/wp-develop" "$TMPDIR/wp-develop.tar.gz"
	fi

	# Always (re)write the config so DB creds / paths stay correct.
	local config="$WP_TESTS_DIR/wp-tests-config.php"
	cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$config"

	# ABSPATH must end with a trailing slash.
	local abspath="${WP_CORE_DIR%/}/"
	# Portable in-place sed (BSD vs GNU): write to a temp then move.
	sed \
		-e "s#dirname( __FILE__ ) . '/src/'#'$abspath'#" \
		-e "s/youremptytestdbnamehere/$DB_NAME/" \
		-e "s/yourusernamehere/$DB_USER/" \
		-e "s/yourpasswordhere/$DB_PASS/" \
		-e "s|localhost|$DB_HOST|" \
		"$config" > "$config.tmp"
	mv "$config.tmp" "$config"
}

install_db() {
	# Parse host / port / socket from DB_HOST into an arg array (an array
	# survives socket paths that contain spaces, e.g. Local's "Application
	# Support" — an unquoted string would word-split on them).
	local -a extra=()
	local host="$DB_HOST"
	if echo "$DB_HOST" | grep -qe ':[0-9]\{1,\}$'; then
		host="$(echo "$DB_HOST" | cut -d: -f1)"
		local port
		port="$(echo "$DB_HOST" | cut -d: -f2)"
		extra=(--host="$host" --port="$port" --protocol=tcp)
	elif echo "$DB_HOST" | grep -qe ':/'; then
		host="$(echo "$DB_HOST" | cut -d: -f1)"
		local sock
		sock="$(echo "$DB_HOST" | cut -d: -f2-)"
		extra=(--socket="$sock")
	else
		extra=(--host="$host" --protocol=tcp)
	fi

	echo "Creating test database '$DB_NAME' (dropped if it exists)…"
	mysqladmin --user="$DB_USER" --password="$DB_PASS" "${extra[@]}" --force drop "$DB_NAME" 2>/dev/null || true
	mysqladmin --user="$DB_USER" --password="$DB_PASS" "${extra[@]}" create "$DB_NAME"
}

install_wp
install_test_suite
install_db

echo "Done. WP_TESTS_DIR=$WP_TESTS_DIR WP_CORE_DIR=$WP_CORE_DIR"
