<?php
/**
 * PHPUnit bootstrap for the pure-function unit suite.
 *
 * These tests deliberately do NOT load WordPress. They cover helpers and
 * sanitizers whose logic is self-contained, so we stub the handful of WP
 * functions they call (resolved via the global-function fallback from the
 * plugin's namespaces) and load just the source files under test.
 *
 * Integration tests that need a real WP runtime (create()/hydration/CSV)
 * are a separate, heavier suite — not part of this no-bootstrap run.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

// ─── Minimal WP function stubs ───────────────────────────────────────
// Unqualified calls inside the plugin's namespaces fall back to these
// global definitions when WordPress isn't loaded.

if ( ! function_exists( '__' ) ) {
    /**
     * Pass-through i18n stub: returns the text unchanged.
     */
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    /**
     * Faithful port of WordPress's wp_strip_all_tags() for tests.
     */
    function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
        $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
        $text = strip_tags( $text );

        if ( $remove_breaks ) {
            $text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
        }

        return trim( $text );
    }
}

// ─── Autoloader + source under test ──────────────────────────────────
// vendor/autoload.php pulls in PHPUnit and (via the composer "files"
// entry) includes/core/helpers.php. The WP-style class-*.php files are
// not PSR-4, so load the ones we test explicitly.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/admin/shared/class-donor-lookup.php';
