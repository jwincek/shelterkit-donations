#!/usr/bin/env bash
###############################################################################
# Install WooCommerce into the throwaway WP core's plugins dir, for the
# WC-enabled test lane (tests/wc). The WC bootstrap auto-discovers it under
# $WP_CORE_DIR/wp-content/plugins/woocommerce.
#
# Downloads the wp.org plugin zip (no svn). "latest" or a pinned version.
#
# Usage:
#   bin/install-woocommerce.sh [version]      # e.g. 10.8.1 or "latest"
#
# Honors WP_CORE_DIR (defaults to $TMPDIR/wordpress). Local Flywheel runs need
# nothing here — the bootstrap finds the sibling woocommerce/ plugin instead.
###############################################################################

set -euo pipefail

WC_VERSION="${1:-latest}"

TMPDIR="${TMPDIR:-/tmp}"
TMPDIR="${TMPDIR%/}"
WP_CORE_DIR="${WP_CORE_DIR:-$TMPDIR/wordpress}"
WC_DIR="$WP_CORE_DIR/wp-content/plugins/woocommerce"

if [ -f "$WC_DIR/woocommerce.php" ]; then
	echo "WooCommerce already present at $WC_DIR"
	exit 0
fi

if [ "$WC_VERSION" = "latest" ]; then
	URL="https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"
else
	URL="https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
fi

mkdir -p "$WP_CORE_DIR/wp-content/plugins"
echo "Downloading WooCommerce ($WC_VERSION)…"
curl -fsSL "$URL" -o "$TMPDIR/woocommerce.zip"
unzip -q -o "$TMPDIR/woocommerce.zip" -d "$WP_CORE_DIR/wp-content/plugins"
rm -f "$TMPDIR/woocommerce.zip"

echo "Done. WooCommerce installed at $WC_DIR"
