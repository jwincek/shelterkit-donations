<?php
/**
 * Integration tests for the shelter-memorials/list dedication filter.
 *
 * Confirms list_memorials() scopes results by the dedication occasion
 * (memory / honor) — the query layer behind the memorial wall's
 * dedication filter dropdown — and that an absent or "all" filter is a
 * no-op.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memorials\create;
use function Starter_Shelter\Abilities\Memorials\list_memorials;

final class MemorialListFilterTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memorials\\list_memorials' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memorials.php';
        }
    }

    /**
     * Seed two "In Memory Of" and one "In Honor Of" memorial.
     *
     * @return array{memory: int[], honor: int[]}
     */
    private function seed(): array {
        $ids = [ 'memory' => [], 'honor' => [] ];
        foreach ( [ 'memory', 'memory', 'honor' ] as $i => $dedication ) {
            $result = create( [
                'donor_email'     => "list{$i}@example.test",
                'donor_name'      => "Lister {$i}",
                'honoree_name'    => "Honoree {$i}",
                'memorial_type'   => 'person',
                'dedication_type' => $dedication,
                'amount'          => 10,
            ] );
            $this->assertIsArray( $result );
            $ids[ $dedication ][] = $result['memorial_id'];
        }
        return $ids;
    }

    public function test_filter_memory_returns_only_memory(): void {
        $this->seed();

        $result = list_memorials( [ 'dedication' => 'memory', 'per_page' => 50 ] );

        $this->assertSame( 2, $result['total'] );
        foreach ( $result['items'] as $item ) {
            $this->assertSame( 'memory', $item['dedication_type'] );
        }
    }

    public function test_filter_honor_returns_only_honor(): void {
        $this->seed();

        $result = list_memorials( [ 'dedication' => 'honor', 'per_page' => 50 ] );

        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 'honor', $result['items'][0]['dedication_type'] );
    }

    public function test_filter_all_is_a_no_op(): void {
        $this->seed();

        $all     = list_memorials( [ 'dedication' => 'all', 'per_page' => 50 ] );
        $omitted = list_memorials( [ 'per_page' => 50 ] );

        $this->assertSame( 3, $all['total'] );
        $this->assertSame( 3, $omitted['total'], 'Omitting dedication must not filter.' );
    }

    /**
     * The dedication filter composes with the memorial_type filter — the
     * two axes are independent, so filtering by both narrows correctly.
     */
    public function test_dedication_composes_with_type_filter(): void {
        // One honor/pet alongside the person seed.
        create( [
            'donor_email'     => 'pethonor@example.test',
            'donor_name'      => 'Pet Honorer',
            'honoree_name'    => 'Good Dog',
            'memorial_type'   => 'pet',
            'dedication_type' => 'honor',
            'amount'          => 10,
        ] );
        $this->seed(); // 2 memory/person, 1 honor/person

        $honor_pets = list_memorials( [ 'dedication' => 'honor', 'type' => 'pet', 'per_page' => 50 ] );

        $this->assertSame( 1, $honor_pets['total'] );
        $this->assertSame( 'honor', $honor_pets['items'][0]['dedication_type'] );
        $this->assertSame( 'pet', $honor_pets['items'][0]['memorial_type'] );
    }
}
