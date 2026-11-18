<?php
/**
 * What an activity log entry is (#37): one entry per import, a source on
 * every entry, and data rather than a finished sentence.
 *
 * 275 of the 357 entries on the development site came from six test
 * imports, one per record. Entries didn't say where they came from, and each
 * stored a sentence in the language of whoever triggered it, with the
 * donor's name inside it.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Import_Export\CSV_Importer;
use WP_UnitTestCase;

final class ActivityLogEntriesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		Activity_Log::leave_run();
		unset( $_GET['run'], $_GET['source'], $_GET['s'] );
		parent::tear_down();
	}

	/** @return object[] Every log row, oldest first. */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}skdon_activity_log ORDER BY id ASC" );
	}

	private function donations_csv( int $rows ): string {
		$file  = wp_tempnam( 'donations.csv' );
		$lines = [ 'email,amount,date,first_name,last_name,allocation' ];
		for ( $i = 1; $i <= $rows; $i++ ) {
			$lines[] = "donor{$i}@example.test," . ( 10 + $i ) . ".00,2026-03-0" . ( ( $i % 9 ) + 1 ) . ",Pat{$i},Donor,general-fund";
		}
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );
		return $file;
	}

	public function test_an_import_of_n_rows_shows_as_one_entry(): void {
		$file    = $this->donations_csv( 4 );
		$results = CSV_Importer::process( 'donations', $file, [ 'file_name' => 'spring-gifts.csv' ] );
		wp_delete_file( $file );
		$this->assertSame( 4, $results['created'] );

		$rows    = $this->rows();
		$anchors = array_values( array_filter( $rows, static fn ( $r ) => in_array( $r->event_type, [ 'import_started', 'import_finished' ], true ) ) );
		$this->assertCount( 1, $anchors, 'One entry stands for the import.' );
		$anchor = $anchors[0];
		$this->assertSame( 'import_finished', $anchor->event_type );
		$this->assertSame( 'import', $anchor->source );

		$created = array_filter( $rows, static fn ( $r ) => 'donation_created' === $r->event_type );
		$this->assertCount( 4, $created, 'Each record keeps its own entry, for its history…' );
		foreach ( $created as $row ) {
			$this->assertSame( $anchor->run_id, $row->run_id, '…attached to the import.' );
		}

		$this->assertSame( 'CSV import of spring-gifts.csv: 4 rows, 4 created', Activity_Log::render_message( $anchor ) );
	}

	public function test_the_list_shows_the_import_once_and_opens_it_on_request(): void {
		$file = $this->donations_csv( 3 );
		CSV_Importer::process( 'donations', $file, [ 'file_name' => 'gifts.csv' ] );
		wp_delete_file( $file );
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'currency' ] ] );

		$list = $this->render_page();
		$this->assertSame( 2, substr_count( $list, '<td class="column-message">' ), 'The import and the settings change.' );
		$this->assertStringContainsString( 'View its 3 entries', $list );

		$_GET['run'] = $this->rows()[0]->run_id;
		$opened      = $this->render_page();
		$this->assertSame( 4, substr_count( $opened, '<td class="column-message">' ), 'The import and its three donations.' );
	}

	public function test_a_batched_import_is_one_run_across_its_requests(): void {
		$file    = $this->donations_csv( 3 );
		$started = CSV_Importer::start_import( 'donations', $file, [ 'file_name' => 'batched.csv' ] );
		wp_delete_file( $file );

		// Each batch is its own request; nothing carries over but the session.
		do {
			$batch = CSV_Importer::process_batch( $started['import_key'] );
		} while ( ! $batch['done'] );

		$rows   = $this->rows();
		$anchor = $rows[0];
		$this->assertSame( 'import_finished', $anchor->event_type );
		$this->assertCount( 4, array_filter( $rows, static fn ( $r ) => $r->run_id === $anchor->run_id ) );
		$this->assertStringContainsString( '3 created', Activity_Log::render_message( $anchor ) );
	}

	public function test_an_import_left_unfinished_still_shows(): void {
		$file    = $this->donations_csv( 2 );
		$started = CSV_Importer::start_import( 'donations', $file, [ 'file_name' => 'abandoned.csv' ] );
		wp_delete_file( $file );
		delete_transient( $started['import_key'] ); // The tab was closed.

		$anchor = $this->rows()[0];
		$this->assertSame( 'import_started', $anchor->event_type );
		$this->assertSame( 'CSV import of abandoned.csv started and has not finished', Activity_Log::render_message( $anchor ) );
		$this->assertStringContainsString( 'abandoned.csv', $this->render_page() );
	}

	public function test_a_run_adds_up_its_batches(): void {
		$run = Activity_Log::begin_run( 'legacy_order_sync' );
		Activity_Log::leave_run();

		foreach ( [ [ 'orders' => 50, 'donations' => 40 ], [ 'orders' => 12, 'donations' => 9, 'errors' => 1 ] ] as $batch ) {
			$this->assertTrue( Activity_Log::resume_run( $run ) );
			Activity_Log::add_to_run( $run, $batch );
			Activity_Log::leave_run();
		}
		Activity_Log::finish_run( $run );

		$this->assertSame( 'Legacy Order Sync: 62 orders, 1 failed, 49 donations', Activity_Log::render_message( $this->rows()[0] ) );
		$this->assertFalse( Activity_Log::resume_run( wp_generate_uuid4() ), 'An unknown run is not joined.' );
	}

	public function test_every_entry_has_a_source(): void {
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'a' ] ] );
		set_current_screen( 'dashboard' );
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'b' ] ] );
		set_current_screen( 'front' );
		add_filter( 'wp_doing_cron', '__return_true' );
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'c' ] ] );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( [ 'site', 'admin', 'cron' ], wp_list_pluck( $this->rows(), 'source' ) );
	}

	public function test_a_message_is_worded_when_shown_in_the_viewers_language(): void {
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => 'Pat Donor' ] );
		update_post_meta( $donor, '_skdon_display_name', 'Pat Donor' );
		Activity_Log::log_donation_created( 123, $donor, [ 'amount' => 25, 'allocation' => 'general-fund' ] );

		$row = $this->rows()[0];
		$this->assertSame( '', $row->message, 'No sentence is stored…' );
		$this->assertStringNotContainsString( 'Pat', (string) $row->meta, '…and no name: the donor is referred to by ID.' );

		// The viewer's language: a translation in place when the log is shown.
		$translate = static fn ( $translation, $text ) => '%1$s donated %2$s' === $text ? '%1$s a donné %2$s' : $translation;
		add_filter( 'gettext_shelterkit-donations', $translate, 10, 2 );
		$shown = Activity_Log::render_message( $row );
		remove_filter( 'gettext_shelterkit-donations', $translate, 10 );

		$this->assertSame( 'Pat Donor a donné $25.00', $shown );
	}

	public function test_entries_written_before_keep_their_sentence(): void {
		Activity_Log::log( 'donation_created', 'donation', 'Pat donated $25.00' );

		$this->assertSame( 'Pat donated $25.00', Activity_Log::render_message( $this->rows()[0] ) );
	}

	public function test_search_finds_an_entry_by_the_donors_name(): void {
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => 'Robin Quayle' ] );
		Activity_Log::log_donation_created( 5, $donor, [ 'amount' => 25 ] );
		Activity_Log::record( 'settings_changed', 'admin', [ 'changed_fields' => [ 'currency' ] ] );

		$_GET['s'] = 'Quayle';
		$this->assertSame( 1, substr_count( $this->render_page(), '<td class="column-message">' ) );
	}

	private function render_page(): string {
		ob_start();
		Activity_Log::render_page();
		return (string) ob_get_clean();
	}
}
