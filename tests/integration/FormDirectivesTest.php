<?php
/**
 * The form blocks' directives are paths the Interactivity runtime can follow,
 * naming things their store defines.
 *
 * The runtime splits a directive value on "." and nothing else: no brackets,
 * no comparisons. 31 directives in the three form blocks read
 * `state.forms['<form id>'].x`, and six added `=== 'value'`. None of them ever
 * resolved, so success and error messages never appeared, the radios started
 * with nothing selected, and nothing failed loudly (#20).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

final class FormDirectivesTest extends WP_UnitTestCase {

    /** Directive attributes whose value is JSON or a key, not a path. */
    private const NOT_PATHS = [ 'data-wp-interactive', 'data-wp-context', 'data-wp-key', 'data-wp-each-key' ];

    /**
     * @dataProvider form_blocks
     */
    public function test_every_directive_is_a_resolvable_path( string $block ): void {
        foreach ( $this->directives( $block ) as [ $attribute, $value ] ) {
            $this->assertMatchesRegularExpression(
                '/^!?[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/',
                $value,
                "{$attribute}=\"{$value}\" in {$block} is not a plain path, so the runtime resolves it to undefined."
            );
        }
    }

    /**
     * @dataProvider form_blocks
     */
    public function test_every_directive_names_something_the_store_defines( string $block, string $store ): void {
        $source = file_get_contents( STARTER_SHELTER_PATH . "assets/js/stores/{$store}.js" )
            . file_get_contents( STARTER_SHELTER_PATH . 'assets/js/stores/form-base.js' );

        foreach ( $this->directives( $block ) as [ $attribute, $value ] ) {
            if ( ! preg_match( '/^!?(state|actions|callbacks)\.([A-Za-z_$][\w$]*)/', $value, $m ) ) {
                continue;
            }
            $name = preg_quote( $m[2], '/' );
            $this->assertMatchesRegularExpression(
                "/(\\bget\\s+{$name}\\s*\\(|\\*?\\b{$name}\\s*\\(|\\b{$name}\\s*:)/",
                $source,
                "{$attribute}=\"{$value}\" in {$block}: nothing named {$m[2]} is defined in {$store}.js or form-base.js."
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function form_blocks(): array {
        return [
            'donation form'   => [ 'donation-form', 'donation-form' ],
            'membership form' => [ 'membership-form', 'membership-form' ],
            'memorial form'   => [ 'memorial-form', 'memorial-form' ],
        ];
    }

    /**
     * Every path-valued directive in a block's rendered markup.
     *
     * @param string $block Block name without namespace.
     * @return array<int, array{0: string, 1: string}> [ attribute, value ] pairs.
     */
    private function directives( string $block ): array {
        $html = do_blocks( "<!-- wp:shelter-donations/{$block} /-->" );
        $this->assertStringContainsString( 'data-wp-interactive', $html, "{$block} rendered its interactive markup." );

        $found = [];
        $tags  = new WP_HTML_Tag_Processor( $html );
        while ( $tags->next_tag() ) {
            foreach ( (array) $tags->get_attribute_names_with_prefix( 'data-wp-' ) as $attribute ) {
                $value = $tags->get_attribute( $attribute );
                if ( in_array( $attribute, self::NOT_PATHS, true ) || ! is_string( $value ) || '' === $value ) {
                    continue;
                }
                $found[] = [ $attribute, $value ];
            }
        }

        $this->assertNotEmpty( $found, "{$block} has directives to check." );

        return $found;
    }
}
