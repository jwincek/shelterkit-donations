<?php
/**
 * The activity log, day to day (#39): each record's history on its own
 * screen, links to records and orders, and a CSV of the current view.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Record_Edit_Tracker;
use WP_UnitTestCase;

final class ActivityLogScreenTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Record_Edit_Tracker::reset();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );
	}

	public function tear_down(): void {
		Activity_Log::leave_run();
		unset( $_GET['object'], $_GET['run'], $_GET['source'] );
		parent::tear_down();
	}

	private function box( \WP_Post $post ): string {
		ob_start();
		Activity_Log::render_history_box( $post );
		return (string) ob_get_clean();
	}

	private function donor( string $name ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => $name ] );
		update_post_meta( $id, '_skdon_display_name', $name );
		return $id;
	}

	// ─── History on each record ────────────────────────────────────

	public function test_a_records_history_shows_its_entries_including_what_a_tool_did(): void {
		$membership = self::factory()->post->create_and_get( [ 'post_type' => 'skdon_membership', 'post_status' => 'publish' ] );
		$other      = self::factory()->post->create( [ 'post_type' => 'skdon_membership', 'post_status' => 'publish' ] );
		Activity_Log::record( 'membership_renewed', 'membership', [ 'donor_id' => 0 ], 'skdon_membership', $membership->ID );
		Activity_Log::record( 'membership_renewed', 'membership', [ 'donor_id' => 0 ], 'skdon_membership', $other );
		$run = Activity_Log::begin_run( 'backfill', [], 'tool' );
		Activity_Log::record( 'record_edited', 'membership', [ 'changes' => [ 'tier' => [ 'single', 'family' ] ] ], 'skdon_membership', $membership->ID );
		Activity_Log::finish_run( $run, [ 'records' => 1, 'fixed' => 1 ] );

		$box = $this->box( $membership );

		$this->assertStringContainsString( 'Membership edited: tier: single → family', $box, 'Edits a tool made are in the history…' );
		$this->assertStringContainsString( 'Part of: Backfill &amp; Repair', $box, '…saying which run made them.' );
		$this->assertSame( 2, substr_count( $box, '<tr>' ), 'The other membership\'s entry is not.' );
		$this->assertStringContainsString( 'View all 2 entries in the Activity Log', $box );
	}

	public function test_a_donors_history_includes_their_gifts(): void {
		$donor = $this->donor( 'Pat Donor' );
		Activity_Log::log_donation_created( 9001, $donor, [ 'amount' => 25 ] );

		$this->assertStringContainsString( 'Pat Donor donated $25.00', $this->box( get_post( $donor ) ) );
	}

	public function test_a_record_with_no_entries_says_so(): void {
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish' ] );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		$this->assertStringContainsString( 'No activity recorded for this record yet.', $this->box( $post ) );
	}

	public function test_the_history_panel_is_for_administrators_only(): void {
		global $wp_meta_boxes;
		$wp_meta_boxes = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		Activity_Log::register_history_box();
		$this->assertEmpty( $wp_meta_boxes['skdon_donation'] ?? [], 'Editors don\'t see the log.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Activity_Log::register_history_box();
		foreach ( Record_Edit_Tracker::POST_TYPES as $type ) {
			$this->assertArrayHasKey( 'skdon-activity-history', $wp_meta_boxes[ $type ]['normal']['low'] ?? [], $type );
		}
	}

	// ─── Links ─────────────────────────────────────────────────────

	public function test_an_entry_links_to_its_record_while_it_exists_and_to_its_order(): void {
		$donation = self::factory()->post->create( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish' ] );
		update_post_meta( $donation, '_skdon_wc_order_id', 77 );
		Activity_Log::record( 'record_edited', 'donation', [ 'changes' => [] ], 'skdon_donation', $donation );
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}skdon_activity_log ORDER BY id DESC LIMIT 1" );

		$links = Activity_Log::entry_links( $row );
		$this->assertSame( '#' . $donation, $links[0][0] );
		$this->assertStringContainsString( 'post=' . $donation, $links[0][1], 'The record\'s edit screen.' );
		$this->assertSame( 'order #77', $links[1][0], 'The order the gift came from.' );

		wp_delete_post( $donation, true );
		$this->assertSame( '', Activity_Log::entry_links( $row )[0][1], 'A deleted record is named, not linked.' );
	}

	public function test_an_entry_naming_an_order_in_its_data_links_to_it(): void {
		Activity_Log::record( 'gift_refunded', 'donation', [ 'order_id' => 55, 'refunded' => 50, 'original' => 50 ] );
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}skdon_activity_log ORDER BY id DESC LIMIT 1" );

		$this->assertSame( [ 'order #55' ], array_column( Activity_Log::entry_links( $row ), 0 ) );
	}

	// ─── The screen and its CSV ────────────────────────────────────

	public function test_the_screen_shows_one_records_history(): void {
		$membership = self::factory()->post->create( [ 'post_type' => 'skdon_membership', 'post_status' => 'publish' ] );
		$run        = Activity_Log::begin_run( 'backfill', [], 'tool' );
		Activity_Log::record( 'record_edited', 'membership', [ 'changes' => [ 'tier' => [ 'a', 'b' ] ] ], 'skdon_membership', $membership );
		Activity_Log::finish_run( $run );
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'x' ] ] );

		$_GET['object'] = 'skdon_membership:' . $membership;
		ob_start();
		Activity_Log::render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Showing the history of Membership #' . $membership, $html );
		$this->assertSame( 1, substr_count( $html, '<td class="column-message">' ), 'Its one entry, from inside the run; not the settings change.' );
		$this->assertStringContainsString( 'name="action" value="skdon_export_activity_log"', $html, 'With an export of this view.' );
	}

	private function csv( array $query ): array {
		$handle = fopen( 'php://memory', 'w+' );
		Activity_Log::write_csv( $handle, Activity_Log::request_filters( $query ) );
		rewind( $handle );
		$rows = [];
		while ( false !== ( $line = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			$rows[] = $line;
		}
		fclose( $handle );
		return $rows;
	}

	public function test_the_csv_is_the_current_view_on_every_page(): void {
		$run = Activity_Log::begin_run( 'csv_import', [ 'file' => 'gifts.csv' ] );
		for ( $i = 0; $i < 3; $i++ ) {
			Activity_Log::record( 'donation_created', 'donation', [ 'donor_id' => 0, 'amount' => 10 ], 'skdon_donation', 100 + $i );
		}
		Activity_Log::finish_run( $run, [ 'rows' => 3, 'created' => 3 ] );
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'x' ] ] );

		$all = $this->csv( [] );
		$this->assertSame( 'Activity', $all[0][3], 'A header row.' );
		$this->assertCount( 3, $all, 'Header, the import as one row, and the settings change: as the list shows them.' );
		$this->assertSame( 'CSV import of gifts.csv: 3 rows, 3 created', $all[2][3] );

		$opened = $this->csv( [ 'run' => $run ] );
		$this->assertCount( 5, $opened, 'Header, the import, and its three entries.' );
		$this->assertSame( 'CSV import of gifts.csv: 3 rows, 3 created', $opened[1][7], 'Each entry says which run it was part of.' );

		$this->assertCount( 2, $this->csv( [ 'source' => 'import' ] ), 'Filters apply.' );
	}

	public function test_the_csv_never_starts_a_cell_with_a_formula(): void {
		$donor = $this->donor( '=HYPERLINK("http://example.test","x")' );
		Activity_Log::log_donation_created( 5, $donor, [ 'amount' => 10 ] );

		$this->assertStringStartsWith( "'=HYPERLINK", $this->csv( [] )[1][3] );
	}

	public function test_the_export_has_a_handler(): void {
		$this->assertNotFalse( has_action( 'admin_post_skdon_export_activity_log', [ Activity_Log::class, 'handle_export' ] ) );
	}
}
