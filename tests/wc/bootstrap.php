<?php
/**
 * Bootstrap for the WooCommerce-enabled test suite.
 *
 * Unlike tests/integration (which runs WC-free), this lane loads WooCommerce
 * into the WP test instance and installs its tables, so the plugin's WC
 * integration — order → entity mapping, cart-item meta channel, checkout
 * fields — can be exercised against the real WC runtime.
 *
 * WooCommerce is resolved from, in order: the WC_PLUGIN_DIR env var, the WP
 * test core's plugins dir (CI drops it there via bin/install-woocommerce.sh),
 * or the sibling woocommerce/ plugin (a Local by Flywheel checkout). So local
 * runs need no extra install; CI installs WooCommerce explicitly.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    fwrite(
        STDERR,
        "WordPress test library not found at {$_tests_dir}.\n" .
        "Run bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] first.\n"
    );
    exit( 1 );
}

// Resolve the WooCommerce plugin directory.
$_wc_dir = getenv( 'WC_PLUGIN_DIR' ) ?: '';
if ( '' === $_wc_dir ) {
    $_core_dir   = getenv( 'WP_CORE_DIR' ) ? rtrim( getenv( 'WP_CORE_DIR' ), '/\\' ) : '';
    $_candidates = [
        $_core_dir ? $_core_dir . '/wp-content/plugins/woocommerce' : '',
        dirname( __DIR__, 3 ) . '/woocommerce', // Sibling plugin in wp-content/plugins.
    ];
    foreach ( $_candidates as $_candidate ) {
        if ( $_candidate && file_exists( $_candidate . '/woocommerce.php' ) ) {
            $_wc_dir = $_candidate;
            break;
        }
    }
}

if ( '' === $_wc_dir || ! file_exists( $_wc_dir . '/woocommerce.php' ) ) {
    fwrite(
        STDERR,
        "WooCommerce not found. Set WC_PLUGIN_DIR, or run bin/install-woocommerce.sh,\n" .
        "or run from a WordPress install that has the woocommerce plugin alongside this one.\n"
    );
    exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $_tests_dir . '/includes/functions.php';

// Load WooCommerce, then this plugin, into the test instance.
tests_add_filter( 'muplugins_loaded', static function () use ( $_wc_dir ): void {
    require $_wc_dir . '/woocommerce.php';
    require dirname( __DIR__, 2 ) . '/shelter-donations.php';
} );

// Install WooCommerce's tables/options once WC has initialized (plugins_loaded
// has fired by setup_theme), so order/product data stores are ready.
tests_add_filter( 'setup_theme', static function (): void {
    if ( class_exists( '\WC_Install' ) ) {
        \WC_Install::install();

        // Re-prime roles/capabilities WooCommerce registers on install.
        $GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
        wp_roles();
    }
} );

require $_tests_dir . '/includes/bootstrap.php';
