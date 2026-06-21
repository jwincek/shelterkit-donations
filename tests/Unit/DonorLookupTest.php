<?php
/**
 * Unit tests for Donor_Lookup::sanitize_display_name().
 *
 * This sanitizer deliberately preserves characters common in real names
 * (&, apostrophes, quotes, accents, hyphens) while stripping markup,
 * control characters, and excess whitespace — see the method docblock.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Starter_Shelter\Admin\Shared\Donor_Lookup;

final class DonorLookupTest extends TestCase {

    #[DataProvider( 'displayNameProvider' )]
    public function test_sanitize_display_name( string $raw, string $expected ): void {
        $this->assertSame( $expected, Donor_Lookup::sanitize_display_name( $raw ) );
    }

    public static function displayNameProvider(): array {
        return [
            'plain name unchanged'   => [ 'John Smith', 'John Smith' ],
            'strips html tags'       => [ '<b>John</b> Smith', 'John Smith' ],
            'preserves ampersand'    => [ 'John & Mary Smith', 'John & Mary Smith' ],
            'preserves apostrophe'   => [ "O'Brien", "O'Brien" ],
            'preserves quotes'       => [ '"Hector" Mewes', '"Hector" Mewes' ],
            'preserves accents'      => [ 'José García', 'José García' ],
            'preserves hyphen'       => [ 'Anne-Marie', 'Anne-Marie' ],
            'collapses whitespace'   => [ "John    Smith", 'John Smith' ],
            'trims outer whitespace' => [ '   John Smith   ', 'John Smith' ],
            'newlines collapse'      => [ "John\n\tSmith", 'John Smith' ],
            'strips null byte'       => [ "John\0Smith", 'JohnSmith' ],
            'strips script tag'      => [ 'John<script>alert(1)</script>', 'John' ],
            'empty stays empty'      => [ '', '' ],
        ];
    }

    public function test_truncates_to_255_characters(): void {
        $long  = str_repeat( 'a', 300 );
        $result = Donor_Lookup::sanitize_display_name( $long );
        $this->assertSame( 255, mb_strlen( $result ) );
    }

    public function test_multibyte_truncation_counts_characters_not_bytes(): void {
        // 300 multibyte chars (é is 2 bytes) must truncate to 255 chars,
        // not 255 bytes — guards against a byte-based cut mangling UTF-8.
        $long   = str_repeat( 'é', 300 );
        $result = Donor_Lookup::sanitize_display_name( $long );
        $this->assertSame( 255, mb_strlen( $result ) );
    }
}
