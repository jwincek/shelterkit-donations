<?php
/**
 * Unit tests for the pure memorial/dedication helper functions.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Starter_Shelter\Helpers\get_dedication_type_label;
use function Starter_Shelter\Helpers\get_memorial_type_label;
use function Starter_Shelter\Helpers\normalize_dedication_type;
use function Starter_Shelter\Helpers\normalize_memorial_type;

final class HelpersTest extends TestCase {

    // ─── normalize_memorial_type ─────────────────────────────────────

    #[DataProvider( 'memorialTypeProvider' )]
    public function test_normalize_memorial_type( string $raw, string $expected ): void {
        $this->assertSame( $expected, normalize_memorial_type( $raw ) );
    }

    public static function memorialTypeProvider(): array {
        return [
            'pet stays pet'          => [ 'pet', 'pet' ],
            'uppercase PET'          => [ 'PET', 'pet' ],
            'whitespace trimmed'     => [ '  pet  ', 'pet' ],
            'person stays person'    => [ 'person', 'person' ],
            'empty defaults person'  => [ '', 'person' ],
            'legacy human => person' => [ 'human', 'person' ],
            'legacy honor => person' => [ 'honor', 'person' ],
            'garbage => person'      => [ 'xyzzy', 'person' ],
        ];
    }

    // ─── normalize_dedication_type ───────────────────────────────────

    #[DataProvider( 'dedicationTypeProvider' )]
    public function test_normalize_dedication_type( string $raw, string $expected ): void {
        $this->assertSame( $expected, normalize_dedication_type( $raw ) );
    }

    public static function dedicationTypeProvider(): array {
        return [
            'honor stays honor'      => [ 'honor', 'honor' ],
            'uppercase HONOR'        => [ 'HONOR', 'honor' ],
            'whitespace trimmed'     => [ '  honor  ', 'honor' ],
            'memory stays memory'    => [ 'memory', 'memory' ],
            'empty defaults memory'  => [ '', 'memory' ],
            'garbage => memory'      => [ 'xyzzy', 'memory' ],
        ];
    }

    // ─── get_memorial_type_label ─────────────────────────────────────

    #[DataProvider( 'memorialLabelProvider' )]
    public function test_get_memorial_type_label( string $raw, string $expected ): void {
        $this->assertSame( $expected, get_memorial_type_label( $raw ) );
    }

    public static function memorialLabelProvider(): array {
        return [
            'pet'              => [ 'pet', 'Pet' ],
            'person'           => [ 'person', 'Person' ],
            'empty => Person'  => [ '', 'Person' ],
            'legacy honor'     => [ 'honor', 'Person' ],
        ];
    }

    // ─── get_dedication_type_label ───────────────────────────────────

    #[DataProvider( 'dedicationLabelProvider' )]
    public function test_get_dedication_type_label( string $raw, string $expected ): void {
        $this->assertSame( $expected, get_dedication_type_label( $raw ) );
    }

    public static function dedicationLabelProvider(): array {
        return [
            'memory'                => [ 'memory', 'In Memory Of' ],
            'honor'                 => [ 'honor', 'In Honor Of' ],
            'uppercase HONOR'       => [ 'HONOR', 'In Honor Of' ],
            'empty => In Memory Of' => [ '', 'In Memory Of' ],
            'garbage => In Memory'  => [ 'xyzzy', 'In Memory Of' ],
        ];
    }

    /**
     * The two axes are orthogonal: a pet honoree can be an "In Honor Of"
     * dedication (a living pet), so the two normalizers must not collapse
     * into one another. This guards the conflation bug the split fixed.
     */
    public function test_axes_are_independent(): void {
        $this->assertSame( 'pet', normalize_memorial_type( 'pet' ) );
        $this->assertSame( 'honor', normalize_dedication_type( 'honor' ) );
        // A legacy 'honor' written to the memorial_type slot still reads
        // as a person subject, while dedication carries the occasion.
        $this->assertSame( 'person', normalize_memorial_type( 'honor' ) );
    }
}
