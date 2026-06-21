<?php
/**
 * Integration tests for dedication_type surfacing on the display layers.
 *
 * Confirms the computed dedication_type_label flows through Entity_Hydrator
 * (the source the memorial wall and admin list table read) and that the
 * CSV exporter's "Dedication" column resolves it. Mirrors the throwaway
 * wp-cli harness used to verify the feature, promoted to a durable test.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use ReflectionMethod;
use Starter_Shelter\Admin\Import_Export\Csv_Exporter;
use Starter_Shelter\Core\Config;
use Starter_Shelter\Core\Entity_Hydrator;
use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memorials\create;

final class MemorialDisplaySurfacingTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memorials\\create' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memorials.php';
        }
    }

    private function make_memorial( string $dedication ): int {
        $result = create( [
            'donor_email'     => 'surface@example.test',
            'donor_name'      => 'Surface Tester',
            'honoree_name'    => "Honoree {$dedication}",
            'memorial_type'   => 'person',
            'dedication_type' => $dedication,
            'amount'          => 20,
        ] );
        $this->assertIsArray( $result );
        return $result['memorial_id'];
    }

    /**
     * Entity_Hydrator must expose both the raw value and the computed
     * label so the wall + list table can render "In Memory/Honor Of".
     *
     * @dataProvider labelProvider
     */
    public function test_hydration_exposes_dedication_label( string $dedication, string $expected_label ): void {
        $entity = Entity_Hydrator::get( 'sd_memorial', $this->make_memorial( $dedication ) );

        $this->assertIsArray( $entity );
        $this->assertSame( $dedication, $entity['dedication_type'] ?? null, 'Raw dedication_type field hydrated.' );
        $this->assertSame( $expected_label, $entity['dedication_type_label'] ?? null, 'Computed label hydrated.' );
    }

    public static function labelProvider(): array {
        return [
            'memory => In Memory Of' => [ 'memory', 'In Memory Of' ],
            'honor => In Honor Of'   => [ 'honor', 'In Honor Of' ],
        ];
    }

    /**
     * Empty/legacy rows (no dedication_type written) still hydrate a label,
     * defaulting to "In Memory Of".
     */
    public function test_legacy_row_without_dedication_hydrates_default_label(): void {
        $memorial_id = self::factory()->post->create( [ 'post_type' => 'sd_memorial', 'post_title' => 'Legacy' ] );
        // No _sd_dedication_type meta set — simulates a pre-1.1.4 row.

        $entity = Entity_Hydrator::get( 'sd_memorial', $memorial_id );
        $this->assertSame( 'In Memory Of', $entity['dedication_type_label'] ?? null );
    }

    /**
     * The memorial CSV export config carries a "Dedication" column wired to
     * the computed label.
     */
    public function test_csv_export_config_has_dedication_column(): void {
        $columns = Config::get_path( 'import-export', 'entity_types.memorials.export.columns', [] );
        $this->assertIsArray( $columns );

        $headers = array_column( $columns, 'header' );
        $this->assertContains( 'Dedication', $headers );

        $dedication_col = null;
        foreach ( $columns as $col ) {
            if ( ( $col['header'] ?? '' ) === 'Dedication' ) {
                $dedication_col = $col;
                break;
            }
        }
        $this->assertSame( 'dedication_type_label', $dedication_col['computed'] ?? null );
    }

    /**
     * The CSV exporter resolves the "Dedication" column to the human label
     * for a real hydrated memorial. resolve_column_value is private and the
     * public export() exits, so reach it via reflection.
     */
    public function test_csv_exporter_resolves_dedication_label(): void {
        $memorial_id = $this->make_memorial( 'honor' );
        $post        = get_post( $memorial_id );
        $entity      = Entity_Hydrator::get( 'sd_memorial', $memorial_id );

        $resolve = new ReflectionMethod( Csv_Exporter::class, 'resolve_column_value' );
        $resolve->setAccessible( true );

        $value = $resolve->invoke(
            null,
            $post,
            $entity,
            [ 'header' => 'Dedication', 'computed' => 'dedication_type_label' ],
            'sd_memorial'
        );

        $this->assertSame( 'In Honor Of', $value );
    }
}
