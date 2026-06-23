<?php
/**
 * Restore round-trip tests for the newly-closed backup gaps.
 *
 * Exports a membership/memorial that carries a campaign association and a
 * logo/photo attachment, deletes the record, re-imports the CSV, and asserts
 * the campaign (relinked by name) and the attachment reference are restored.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;
use Starter_Shelter\Admin\Import_Export\CSV_Exporter;
use Starter_Shelter\Admin\Import_Export\CSV_Importer;

use function Starter_Shelter\Abilities\Memberships\create as create_membership;
use function Starter_Shelter\Abilities\Memorials\create as create_memorial;

final class RestoreRoundTripTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/includes/admin/import-export/class-csv-exporter.php';
        require_once dirname( __DIR__, 2 ) . '/includes/admin/import-export/class-csv-importer.php';
        require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        require_once dirname( __DIR__, 2 ) . '/includes/abilities/memorials.php';
    }

    private function campaign( string $name ): int {
        $t = wp_insert_term( $name, 'sd_campaign' );
        return is_array( $t ) ? (int) $t['term_id'] : 0;
    }

    private function write_csv( string $csv ): string {
        $path = wp_tempnam( 'sd-roundtrip.csv' );
        file_put_contents( $path, $csv );
        return $path;
    }

    /** @return string[] Names of sd_campaign terms on a post. */
    private function campaign_names( int $post_id ): array {
        $terms = wp_get_object_terms( $post_id, 'sd_campaign' );
        return is_wp_error( $terms ) ? [] : wp_list_pluck( $terms, 'name' );
    }

    public function test_membership_export_carries_campaign_and_logo(): void {
        $logo = self::factory()->attachment->create();
        create_membership( [
            'donor_email'        => 'expm@example.test',
            'membership_type'    => 'business',
            'tier'               => 'donor',
            'amount'             => 250,
            'date'               => '2026-01-15',
            'business_name'      => 'Acme',
            'logo_attachment_id' => $logo,
            'campaign_id'        => $this->campaign( 'Spring Drive' ),
        ] );

        $csv = CSV_Exporter::build_csv( 'memberships' );

        $this->assertStringContainsString( 'Spring Drive', $csv, 'Campaign name exported.' );
        $this->assertStringContainsString( (string) $logo, $csv, 'Logo attachment id exported.' );
    }

    public function test_membership_restores_campaign_and_logo(): void {
        $logo        = self::factory()->attachment->create();
        $campaign_id = $this->campaign( 'Capital Campaign' );
        $res         = create_membership( [
            'donor_email'        => 'm@example.test',
            'membership_type'    => 'business',
            'tier'               => 'donor',
            'amount'             => 250,
            'date'               => '2026-01-15',
            'business_name'      => 'Acme',
            'logo_attachment_id' => $logo,
            'campaign_id'        => $campaign_id,
        ] );

        $csv  = CSV_Exporter::build_csv( 'memberships' );
        $file = $this->write_csv( $csv );

        // Simulate a purge of the record + its campaign term (media is kept).
        wp_delete_post( $res['membership_id'], true );
        wp_delete_term( $campaign_id, 'sd_campaign' );

        CSV_Importer::process( 'memberships', $file, [ 'create_donors' => true ] );
        wp_delete_file( $file );

        $restored = get_posts( [ 'post_type' => 'sd_membership', 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'publish' ] );
        $this->assertCount( 1, $restored, 'Membership restored.' );
        $id = $restored[0];

        $this->assertContains( 'Capital Campaign', $this->campaign_names( $id ), 'Campaign relinked by name.' );
        $this->assertSame( (string) $logo, (string) get_post_meta( $id, '_sd_logo_attachment_id', true ), 'Logo reference restored.' );
    }

    public function test_memorial_restores_campaign_and_photo(): void {
        $photo       = self::factory()->attachment->create();
        $campaign_id = $this->campaign( 'In Memoriam Fund' );
        $res         = create_memorial( [
            'donor_email'  => 'mem@example.test',
            'honoree_name' => 'Old Rex',
            'amount'       => 40,
            'photo_id'     => $photo,
            'campaign_id'  => $campaign_id,
        ] );

        $csv  = CSV_Exporter::build_csv( 'memorials' );
        $file = $this->write_csv( $csv );

        wp_delete_post( $res['memorial_id'], true );
        wp_delete_term( $campaign_id, 'sd_campaign' );

        CSV_Importer::process( 'memorials', $file, [ 'create_donors' => true ] );
        wp_delete_file( $file );

        $restored = get_posts( [ 'post_type' => 'sd_memorial', 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'publish' ] );
        $this->assertCount( 1, $restored );
        $id = $restored[0];

        $this->assertSame( 'Old Rex', get_post_meta( $id, '_sd_honoree_name', true ) );
        $this->assertContains( 'In Memoriam Fund', $this->campaign_names( $id ), 'Campaign relinked by name.' );
        $this->assertSame( (string) $photo, (string) get_post_meta( $id, '_sd_photo_id', true ), 'Photo reference restored.' );
    }
}
