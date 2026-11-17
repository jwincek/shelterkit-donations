<?php
/**
 * Every block ships what core needs to load it.
 *
 * There is no build step, so nothing generates the `.asset.php` files core
 * reads a script's dependencies from. Without them the editor scripts ran
 * before wp.element existed and no block could be inserted (#17), the view
 * modules failed on classic themes (#18), and Donor Stats had no editor script
 * at all (#25). A development site with other plugins hid all three.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

final class BlockAssetsTest extends WP_UnitTestCase {

    /** The wp.* global each package exposes => its script handle. */
    private const PACKAGES = [
        'blocks'           => 'wp-blocks',
        'blockEditor'      => 'wp-block-editor',
        'components'       => 'wp-components',
        'element'          => 'wp-element',
        'i18n'             => 'wp-i18n',
        'serverSideRender' => 'wp-server-side-render',
        'data'             => 'wp-data',
        'apiFetch'         => 'wp-api-fetch',
        'compose'          => 'wp-compose',
        'hooks'            => 'wp-hooks',
    ];

    /**
     * @dataProvider blocks
     */
    public function test_an_insertable_block_has_an_editor_script( string $dir, array $meta ): void {
        if ( false === ( $meta['supports']['inserter'] ?? true ) ) {
            $this->assertTrue( true, 'Hidden from the inserter on purpose.' );
            return;
        }

        $this->assertSame( 'file:./edit.js', $meta['editorScript'] ?? null, "{$meta['name']} cannot be inserted: no editor script." );
        $this->assertFileExists( "{$dir}/edit.js" );
    }

    /**
     * @dataProvider blocks
     */
    public function test_an_editor_script_declares_every_package_it_reads( string $dir, array $meta ): void {
        if ( ! is_file( "{$dir}/edit.js" ) ) {
            $this->assertArrayNotHasKey( 'editorScript', $meta );
            return;
        }

        $this->assertFileExists( "{$dir}/edit.asset.php", "{$meta['name']}: core would load edit.js with no dependencies." );
        $asset = include "{$dir}/edit.asset.php";

        preg_match_all( '/\bwp\.([A-Za-z]+)\b/', (string) file_get_contents( "{$dir}/edit.js" ), $m );
        foreach ( array_unique( $m[1] ) as $global ) {
            $this->assertArrayHasKey( $global, self::PACKAGES, "{$meta['name']} reads wp.{$global}; add its handle to this test." );
            $this->assertContains( self::PACKAGES[ $global ], $asset['dependencies'], "{$meta['name']} reads wp.{$global} but does not declare it." );
        }
    }

    /**
     * @dataProvider blocks
     */
    public function test_a_view_module_declares_the_interactivity_api( string $dir, array $meta ): void {
        if ( empty( $meta['viewScriptModule'] ) ) {
            $this->assertFileDoesNotExist( "{$dir}/view.asset.php" );
            return;
        }

        $this->assertFileExists( "{$dir}/view.asset.php", "{$meta['name']}: the import map would lack @wordpress/interactivity on a classic theme." );
        $asset = include "{$dir}/view.asset.php";
        $this->assertContains( '@wordpress/interactivity', $asset['dependencies'] );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public function blocks(): array {
        $out = [];
        foreach ( glob( dirname( __DIR__, 2 ) . '/blocks/*/block.json' ) as $file ) {
            $meta = json_decode( (string) file_get_contents( $file ), true );
            $out[ $meta['name'] ] = [ dirname( $file ), $meta ];
        }
        return $out;
    }
}
