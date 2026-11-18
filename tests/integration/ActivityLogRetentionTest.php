<?php
/**
 * How long the activity log keeps entries, and the only ways it deletes
 * them: retention, a person's erasure, and one import's entries (#38).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Privacy;
use Starter_Shelter\Admin\Record_Edit_Tracker;
use Starter_Shelter\Admin\Settings;
use WP_UnitTestCase;

final class ActivityLogRetentionTest extends WP_UnitTestCase {

	private const OPTION = 'starter_shelter_options';

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Record_Edit_Tracker::reset();
		delete_option( self::OPTION );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );
	}

	public function tear_down(): void {
		Activity_Log::leave_run();
		remove_all_filters( 'starter_shelter_activity_log_retention_days' );
		parent::tear_down();
	}

	/** Write an entry and age it by some days. */
	private function entry( string $event, string $category, int $days_old, array $data = [], ?string $run = null ): int {
		global $wpdb;
		Activity_Log::record_in_run( $run, $event, $category, $data );
		$id = (int) $wpdb->insert_id;
		$at = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( "-{$days_old} days" )->format( 'Y-m-d H:i:s' );
		$wpdb->update( $wpdb->prefix . 'skdon_activity_log', [ 'created_at' => $at ], [ 'id' => $id ] );
		return $id;
	}

	private function exists( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}skdon_activity_log WHERE id = %d", $id ) );
	}

	private function row( int $id ): object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}skdon_activity_log WHERE id = %d", $id ) );
	}

	// ─── Retention ─────────────────────────────────────────────────

	public function test_the_setting_replaces_the_default(): void {
		$forty  = $this->entry( 'order_processed', 'system', 40 );
		$twenty = $this->entry( 'order_processed', 'system', 20 );

		Activity_Log::cleanup_old_logs();
		$this->assertTrue( $this->exists( $forty ), 'The default keeps 90 days.' );

		update_option( self::OPTION, [ 'log_retention_days' => 30 ] );
		Activity_Log::cleanup_old_logs();
		$this->assertFalse( $this->exists( $forty ), 'With 30 days set, the 40-day-old entry goes…' );
		$this->assertTrue( $this->exists( $twenty ), '…and the 20-day-old one stays.' );
	}

	public function test_keep_forever_deletes_nothing(): void {
		update_option( self::OPTION, [ 'log_retention_days' => 'forever', 'log_retention_admin_days' => 'forever' ] );
		$old   = $this->entry( 'order_processed', 'system', 3000 );
		$admin = $this->entry( 'settings_changed', 'admin', 3000 );

		Activity_Log::cleanup_old_logs();

		$this->assertTrue( $this->exists( $old ) );
		$this->assertTrue( $this->exists( $admin ) );
	}

	public function test_zero_and_negative_periods_are_rejected(): void {
		Settings::register_settings();
		foreach ( [ '0' => 90, '-5' => 90, '29' => 90, 'abc' => 90, '45' => 45, '365' => 365, 'forever' => 'forever' ] as $given => $kept ) {
			update_option( self::OPTION, [ '_tab' => 'general', 'log_retention_days' => (string) $given ] );
			$this->assertSame( $kept, get_option( self::OPTION )['log_retention_days'], "Setting {$given}." );
		}

		// The filter used to take 0 as "delete everything".
		delete_option( self::OPTION );
		add_filter( 'starter_shelter_activity_log_retention_days', '__return_zero' );
		$entry = $this->entry( 'order_processed', 'system', 40 );
		Activity_Log::cleanup_old_logs();
		$this->assertTrue( $this->exists( $entry ), 'A filtered 0 is ignored; the 90-day setting stands.' );
	}

	public function test_admin_actions_are_kept_longer_with_what_their_tools_did(): void {
		$routine  = $this->entry( 'order_processed', 'system', 200 );
		$admin    = $this->entry( 'settings_changed', 'admin', 200 );
		$tool     = Activity_Log::begin_run( 'purge', [ 'post_type' => 'skdon_memorial' ], 'tool' );
		Activity_Log::leave_run();
		$deletion = $this->entry( 'record_deleted', 'donation', 200, [ 'amount' => 25 ], $tool );

		Activity_Log::cleanup_old_logs();

		$this->assertFalse( $this->exists( $routine ), 'Routine entries go after 90 days…' );
		$this->assertTrue( $this->exists( $admin ), '…admin actions are kept for two years…' );
		$this->assertTrue( $this->exists( $deletion ), '…and so is what a tool did, under its run.' );
	}

	public function test_a_cleanup_that_deletes_says_so(): void {
		$this->entry( 'order_processed', 'system', 100 );
		$this->entry( 'order_processed', 'system', 100 );

		Activity_Log::cleanup_old_logs();

		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}skdon_activity_log WHERE event_type = 'log_cleaned'" );
		$this->assertNotNull( $row );
		$this->assertSame( 'Cleanup deleted 2 entries past their retention period', Activity_Log::render_message( $row ) );
	}

	public function test_the_first_cleanup_waits_a_day(): void {
		wp_clear_scheduled_hook( 'skdon_cleanup_activity_log' );

		Activity_Log::init();

		$this->assertGreaterThan( time() + 23 * HOUR_IN_SECONDS, wp_next_scheduled( 'skdon_cleanup_activity_log' ), 'A site that has just updated gets a day to choose.' );
	}

	// ─── A person's data ───────────────────────────────────────────

	private function donor( string $first, string $last, string $email ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => "$first $last" ] );
		update_post_meta( $id, '_skdon_first_name', $first );
		update_post_meta( $id, '_skdon_last_name', $last );
		update_post_meta( $id, '_skdon_display_name', "$first $last" );
		update_post_meta( $id, '_skdon_email', $email );
		return $id;
	}

	public function test_the_eraser_removes_one_donors_details_and_leaves_the_rest(): void {
		$pat   = $this->donor( 'Pat', 'Alpha', 'pat@example.test' );
		$robin = $this->donor( 'Robin', 'Beta', 'robin@example.test' );
		$gift  = $this->entry( 'donation_created', 'donation', 1, [ 'donor_id' => $pat, 'amount' => 25 ] );
		$mail  = $this->entry( 'email_sent', 'email', 1, [ 'email_type' => 'receipt', 'recipient' => 'pat@example.test' ] );
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'skdon_activity_log', [
			'event_type'     => 'donation_created',
			'event_category' => 'donation',
			'message'        => 'Pat Alpha donated $5.00',
			'meta'           => wp_json_encode( [ 'donor_id' => $pat ] ),
			'ip_address'     => '203.0.113.9',
			'created_at'     => current_time( 'mysql' ),
		] );
		$legacy = (int) $wpdb->insert_id;
		$other  = $this->entry( 'donation_created', 'donation', 1, [ 'donor_id' => $robin, 'amount' => 30 ] );

		$result = Privacy::erase_activity( 'pat@example.test', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'Donor and gift records are kept, and the eraser says so.' );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertTrue( $result['done'] );

		$this->assertSame( 'Unknown Donor donated $25.00', Activity_Log::render_message( $this->row( $gift ) ) );
		$this->assertSame( 'Receipt email sent to (removed)', Activity_Log::render_message( $this->row( $mail ) ) );
		$this->assertSame( '[removed] donated $5.00', $this->row( $legacy )->message, 'The name in an older sentence goes too…' );
		$this->assertNull( $this->row( $legacy )->ip_address, '…and so does the address it was written from.' );
		$this->assertSame( 'Robin Beta donated $30.00', Activity_Log::render_message( $this->row( $other ) ), 'Another donor is untouched.' );

		$again = Privacy::erase_activity( 'pat@example.test', 2 );
		$this->assertFalse( $again['items_removed'], 'Nothing is left to remove, so WordPress stops calling.' );
	}

	public function test_the_exporters_return_the_donor_their_gifts_and_their_log(): void {
		$pat      = $this->donor( 'Pat', 'Alpha', 'pat@example.test' );
		$donation = self::factory()->post->create( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish' ] );
		update_post_meta( $donation, '_skdon_donor_id', $pat );
		update_post_meta( $donation, '_skdon_amount', '25' );
		$this->entry( 'donation_created', 'donation', 1, [ 'donor_id' => $pat, 'amount' => 25 ] );

		$donor = Privacy::export_donor( 'pat@example.test', 1 );
		$this->assertSame( [ 'shelterkit-donor', 'shelterkit-gifts' ], array_column( $donor['data'], 'group_id' ) );
		$this->assertContains( [ 'name' => 'Email', 'value' => 'pat@example.test' ], $donor['data'][0]['data'] );
		$this->assertTrue( $donor['done'] );

		$log = Privacy::export_activity( 'pat@example.test', 1 );
		$this->assertCount( 1, $log['data'] );
		$this->assertContains( [ 'name' => 'Activity', 'value' => 'Pat Alpha donated $25.00' ], $log['data'][0]['data'] );

		$this->assertArrayHasKey( 'shelterkit-donations-activity', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
		$this->assertArrayHasKey( 'shelterkit-donations-donor', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
	}

	// ─── One import's entries ──────────────────────────────────────

	public function test_deleting_an_imports_entries_keeps_its_own_and_records_the_deletion(): void {
		$run = Activity_Log::begin_run( 'csv_import', [ 'file' => 'test.csv' ] );
		for ( $i = 0; $i < 3; $i++ ) {
			Activity_Log::record( 'donation_created', 'donation', [ 'donor_id' => 0, 'amount' => 1 ] );
		}
		Activity_Log::finish_run( $run, [ 'rows' => 3, 'created' => 3 ] );

		$this->assertSame( 3, Activity_Log::delete_run_entries( $run ) );

		global $wpdb;
		$left = $wpdb->get_col( $wpdb->prepare( "SELECT event_type FROM {$wpdb->prefix}skdon_activity_log WHERE run_id = %s", $run ) );
		$this->assertSame( [ 'import_finished' ], $left, 'The import itself is still on record.' );
		$note = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}skdon_activity_log WHERE event_type = 'log_entries_deleted'" );
		$this->assertSame( 'Deleted 3 entries of CSV import of test.csv: 3 rows, 3 created', Activity_Log::render_message( $note ) );
		$this->assertNull( $note->run_id, 'The deletion is its own entry, outside the run.' );
	}

	public function test_a_tools_entries_cannot_be_deleted(): void {
		$run = Activity_Log::begin_run( 'purge', [], 'tool' );
		Activity_Log::record( 'record_deleted', 'donation', [] );
		Activity_Log::finish_run( $run, [ 'deleted' => 1 ] );

		$this->assertWPError( Activity_Log::delete_run_entries( $run ) );
	}
}
