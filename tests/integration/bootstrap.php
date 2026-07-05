<?php
/**
 * Bootstrap for the WordPress integration test suite.
 *
 * Loads the WordPress PHPUnit test library (installed by
 * bin/install-wp-tests.sh), then loads this plugin on `muplugins_loaded`
 * so its real CPTs, manifests, hydrator, abilities, and helpers are wired
 * up against a real WordPress + database. WooCommerce is intentionally
 * absent — the plugin's WC integration self-guards, and the code under
 * test (create / hydration / CSV) is WC-free.
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
        "Run bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] first,\n" .
        "or set WP_TESTS_DIR to its location.\n"
    );
    exit( 1 );
}

// Composer autoload first so the PHPUnit Polyfills the WP suite requires
// are available, then point the suite at them explicitly.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $_tests_dir . '/includes/functions.php';

// WooCommerce is absent in this suite, but the donor-area sub-nav uses one WC
// helper to build endpoint URLs. Stub just that so render-path tests work; the
// plugin's WC integration otherwise self-guards on class_exists( 'WooCommerce' ).
if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
    function wc_get_account_endpoint_url( $endpoint ) {
        return home_url( '/my-account/' . $endpoint . '/' );
    }
}

// Load the plugin into the test WordPress instance.
tests_add_filter( 'muplugins_loaded', static function (): void {
    require dirname( __DIR__, 2 ) . '/shelter-donations.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
