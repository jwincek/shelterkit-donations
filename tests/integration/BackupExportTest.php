<?php
/**
 * Integration tests for the full-backup CSV/ZIP export (Option A).
 *
 * Csv_Exporter::build_csv() returns an entity's CSV as a string; build_archive()
 * bundles every entity type's CSV plus a README into a ZIP for a pre-uninstall
 * backup.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;
use Starter_Shelter\Admin\Import_Export\CSV_Exporter;

use function Starter_Shelter\Abilities\Donations\create as create_donation;
use function Starter_Shelter\Helpers\get_or_create_donor;

final class BackupExportTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/includes/admin/import-export/class-csv-exporter.php';
        require_once dirname( __DIR__, 2 ) . '/includes/abilities/donations.php';
    }

    private function seed_donation( string $email ): void {
        $donor = get_or_create_donor( $email, 'Backup Donor' );
        create_donation( [
            'donor_id'      => $donor,
            'amount'        => 30,
            'allocation'    => 'general-fund',
            'donation_date' => '2026-02-01',
        ] );
    }

    public function test_build_csv_has_bom_header_and_data(): void {
        $this->seed_donation( 'csv@example.test' );

        $csv = CSV_Exporter::build_csv( 'donations' );

        $this->assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'UTF-8 BOM present for Excel.' );
        $this->assertStringContainsString( 'Donor Email', $csv, 'Header row present.' );
        $this->assertStringContainsString( 'csv@example.test', $csv, 'Donor email present for re-import relinking.' );
    }

    public function test_build_csv_unknown_type_is_empty(): void {
        $this->assertSame( '', CSV_Exporter::build_csv( 'not_a_real_type' ) );
    }

    public function test_build_archive_bundles_all_entities_and_readme(): void {
        if ( ! class_exists( '\ZipArchive' ) ) {
            $this->markTestSkipped( 'ZipArchive extension not available.' );
        }

        $this->seed_donation( 'zip@example.test' );

        $path = CSV_Exporter::build_archive();
        $this->assertIsString( $path );
        $this->assertFileExists( $path );

        $zip = new \ZipArchive();
        $this->assertTrue( true === $zip->open( $path ) );

        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = $zip->getNameIndex( $i );
        }
        $donations_csv = (string) $zip->getFromName( 'shelterkit-donations.csv' );
        $zip->close();
        wp_delete_file( $path );

        foreach ( [ 'shelter-donors.csv', 'shelterkit-donations.csv', 'shelter-memberships.csv', 'shelter-memorials.csv', 'README.txt' ] as $entry ) {
            $this->assertContains( $entry, $names, "{$entry} is in the backup." );
        }
        $this->assertStringContainsString( 'zip@example.test', $donations_csv, 'Bundled CSV carries the data.' );
    }
}
