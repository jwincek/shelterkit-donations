<?php
/**
 * Export Selected on the Donations, Memberships and Memorials lists (#31).
 *
 * The bulk action exports exactly the chosen records through the same CSV
 * builder as the Export page, and only for users who may use that page.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Import_Export\CSV_Exporter;
use Starter_Shelter\Admin\Quick_Actions;
use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Donations\create as create_donation;
use function Starter_Shelter\Helpers\get_or_create_donor;

final class ExportSelectedTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once dirname( __DIR__, 2 ) . '/includes/admin/import-export/class-csv-exporter.php';
		require_once dirname( __DIR__, 2 ) . '/includes/abilities/donations.php';
	}

	private function donation( string $email ): int {
		$result = create_donation( [
			'donor_id'      => get_or_create_donor( $email, 'Export Donor' ),
			'amount'        => 25,
			'allocation'    => 'general-fund',
			'donation_date' => '2026-03-01',
		] );
		return (int) ( $result['donation_id'] ?? 0 );
	}

	public function test_ids_limit_the_export_to_those_records(): void {
		$a = $this->donation( 'a@example.test' );
		$b = $this->donation( 'b@example.test' );
		$this->donation( 'c@example.test' );

		$csv = CSV_Exporter::build_csv( 'donations', [ 'ids' => [ $a, $b ] ] );

		$this->assertStringContainsString( 'a@example.test', $csv );
		$this->assertStringContainsString( 'b@example.test', $csv );
		$this->assertStringNotContainsString( 'c@example.test', $csv );
		$this->assertCount( 3, array_filter( explode( "\n", trim( $csv ) ) ), 'Header plus two rows.' );
	}

	public function test_selected_drafts_are_exported_and_bad_ids_match_nothing(): void {
		$draft = $this->donation( 'draft@example.test' );
		wp_update_post( [ 'ID' => $draft, 'post_status' => 'draft' ] );
		$this->assertStringContainsString( 'draft@example.test', CSV_Exporter::build_csv( 'donations', [ 'ids' => [ $draft ] ] ), 'A selected record is exported whatever its status.' );

		// A negative id must not become a real record's id (absint would make -N into N).
		$csv = CSV_Exporter::build_csv( 'donations', [ 'ids' => [ -$draft, 0, 'x' ] ] );
		$this->assertStringNotContainsString( 'draft@example.test', $csv );
	}

	public function test_only_users_who_may_export_are_offered_it_or_can_run_it(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		foreach ( [ 'register_donation_bulk_actions', 'register_membership_bulk_actions', 'register_memorial_bulk_actions' ] as $register ) {
			$this->assertArrayNotHasKey( 'sd_export_selected', Quick_Actions::$register( [] ), "$register: editors can see the list but not export." );
		}
		$this->assertSame( 'back', Quick_Actions::handle_donation_bulk_actions( 'back', 'sd_export_selected', [ $this->donation( 'e@example.test' ) ] ), 'A forged request from an editor exports nothing.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		foreach ( [ 'register_donation_bulk_actions', 'register_membership_bulk_actions', 'register_memorial_bulk_actions' ] as $register ) {
			$this->assertArrayHasKey( 'sd_export_selected', Quick_Actions::$register( [] ), $register );
		}
	}
}
