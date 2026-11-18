<?php
/**
 * Admin tools, settings and record edits reach the activity log (#36).
 *
 * The actions most worth an audit trail weren't recorded: Purge
 * force-deleted records, Backfill and Recalculate rewrote them, settings
 * kept as Config overrides changed silently, and a gift record could change
 * type, tier and amount with no trace.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Data_Integrity;
use Starter_Shelter\Admin\Record_Edit_Tracker;
use Starter_Shelter\Core\Allocations;
use Starter_Shelter\Core\Config;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

final class ActivityLogAdminActionsTest extends WP_Ajax_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Record_Edit_Tracker::reset();
	}

	public function tear_down(): void {
		Activity_Log::leave_run();
		Record_Edit_Tracker::reset();
		$_POST = [];
		parent::tear_down();
	}

	/** @return object[] Every log row, oldest first. */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}skdon_activity_log ORDER BY id ASC" );
	}

	/** @return object[] The rows the list shows: entries outside runs, and each run's own entry. */
	private function visible(): array {
		return array_values( array_filter( $this->rows(), static fn ( $r ) => null === $r->run_id || in_array( $r->event_type, [ 'import_started', 'import_finished', 'tool_started', 'tool_finished' ], true ) ) );
	}

	/**
	 * Call a Data Tools handler as admin-ajax would, and return its JSON.
	 *
	 * @param string $method Handler method.
	 * @param array  $post   Request fields.
	 */
	private function ajax( string $method, array $post ): array {
		$_POST = array_merge( [ 'nonce' => wp_create_nonce( 'sd_data_integrity' ) ], $post );
		$_REQUEST = $_POST;
		$this->_last_response = '';
		ob_start();
		try {
			Data_Integrity::$method();
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
			// wp_send_json() ends here. The test case's die handler has
			// collected the output into _last_response and closed the buffer.
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, "{$method} answered with JSON." );
		return $response;
	}

	private function memorial( float $amount = 25 ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'skdon_memorial', 'post_status' => 'publish' ] );
		update_post_meta( $id, '_skdon_amount', $amount );
		return $id;
	}

	// ─── Admin tools: one entry per run ────────────────────────────

	public function test_a_purge_is_one_entry_and_each_deleted_record_is_kept_under_it(): void {
		$this->memorial();
		$this->memorial();
		$this->memorial();
		Record_Edit_Tracker::reset(); // A later request.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" ); // Their "created" entries.

		$run = '';
		do {
			$response = $this->ajax( 'ajax_purge', [ 'post_type' => 'skdon_memorial', 'confirmation' => 'skdon_memorial', 'run_id' => $run ] );
			$run      = $response['data']['run_id'];
		} while ( empty( $response['data']['done'] ) );

		$visible = $this->visible();
		$this->assertCount( 1, $visible, 'The purge is one entry.' );
		$this->assertSame( 'tool_finished', $visible[0]->event_type );
		$this->assertSame( 'tool', $visible[0]->source );
		$this->assertSame( 'Purged 3 records: Memorial', Activity_Log::render_message( $visible[0] ) );

		$deleted = array_filter( $this->rows(), static fn ( $r ) => 'record_deleted' === $r->event_type );
		$this->assertCount( 3, $deleted, 'Each deleted record keeps an entry…' );
		foreach ( $deleted as $row ) {
			$this->assertSame( $run, $row->run_id, '…under the purge.' );
		}
	}

	public function test_a_purge_with_records_in_the_trash_finishes(): void {
		$this->memorial();
		wp_trash_post( $this->memorial() );

		$requests = 0;
		$run      = '';
		do {
			$response = $this->ajax( 'ajax_purge', [ 'post_type' => 'skdon_memorial', 'confirmation' => 'skdon_memorial', 'run_id' => $run ] );
			$run      = $response['data']['run_id'];
			++$requests;
		} while ( empty( $response['data']['done'] ) && $requests < 5 );

		$this->assertTrue( $response['data']['done'], 'Trashed records are purged too, so the purge ends.' );
		$this->assertSame( [], get_posts( [ 'post_type' => 'skdon_memorial', 'post_status' => 'any', 'fields' => 'ids' ] ) );
		$this->assertSame( [], get_posts( [ 'post_type' => 'skdon_memorial', 'post_status' => 'trash', 'fields' => 'ids' ] ) );
	}

	public function test_backfill_is_one_entry_across_record_types_and_batches(): void {
		// A memorial without its donor's name copied onto it, which Backfill
		// fills in. (A field left at its registered default is not changed by
		// writing that default, so it is no edit.)
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish' ] );
		update_post_meta( $donor, '_skdon_display_name', 'Pat Donor' );
		$memorial = $this->memorial();
		update_post_meta( $memorial, '_skdon_donor_id', $donor );
		Record_Edit_Tracker::reset(); // A later request.
		$run   = '';
		$types = [ 'skdon_donation', 'skdon_memorial' ];
		foreach ( $types as $i => $type ) {
			$offset = 0;
			do {
				$response = $this->ajax( 'ajax_backfill', [ 'post_type' => $type, 'offset' => $offset, 'run_id' => $run, 'final' => count( $types ) - 1 === $i ? 1 : 0 ] );
				$run      = $response['data']['run_id'];
				$offset   = $response['data']['offset'];
			} while ( empty( $response['data']['done'] ) );
		}

		$runs = array_values( array_filter( $this->visible(), static fn ( $r ) => str_starts_with( $r->event_type, 'tool_' ) ) );
		$this->assertCount( 1, $runs, 'One entry for the whole Backfill.' );
		$this->assertSame( 'tool_finished', $runs[0]->event_type );

		$edits = array_values( array_filter( $this->rows(), static fn ( $r ) => 'record_edited' === $r->event_type && (int) $r->object_id === $memorial ) );
		$this->assertCount( 1, $edits, 'What it filled in is recorded on the record…' );
		$this->assertSame( $runs[0]->run_id, $edits[0]->run_id, '…under the Backfill.' );
		$this->assertStringContainsString( 'donor_display_name: (empty) → Pat Donor', Activity_Log::render_message( $edits[0] ) );
	}

	public function test_recalculating_donor_totals_is_one_entry(): void {
		self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish' ] );

		$response = $this->ajax( 'ajax_recalc_donors', [ 'offset' => 0, 'run_id' => '' ] );

		$this->assertTrue( $response['data']['done'] );
		$visible = $this->visible();
		$this->assertCount( 1, $visible );
		$this->assertSame( 'Recalculated donor totals: 1 donors, 0 changed', Activity_Log::render_message( $visible[0] ) );
	}

	// ─── Settings ──────────────────────────────────────────────────

	public function test_a_settings_save_records_old_and_new_values(): void {
		update_option( 'starter_shelter_options', [ 'renewal_reminder_days' => 30, 'donation_page' => 12 ] );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		update_option( 'starter_shelter_options', [ 'renewal_reminder_days' => 14, 'donation_page' => 12, 'api_key' => 'abc123' ] );

		$row = $this->rows()[0];
		$this->assertSame( 'settings_changed', $row->event_type );
		$this->assertStringNotContainsString( 'abc123', (string) $row->meta, 'Anything that looks like a credential keeps its name, not its value.' );
		$this->assertSame( 'Settings updated: renewal_reminder_days: 30 → 14; api_key', Activity_Log::render_message( $row ) );
	}

	public function test_a_sites_first_settings_save_is_recorded_too(): void {
		delete_option( 'starter_shelter_options' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		// What the form sends: its own fields only, as strings, blanks included.
		update_option( 'starter_shelter_options', [ 'renewal_reminder_days' => '21', 'fiscal_year_start_month' => '7', 'org_ein' => '' ] );

		$this->assertSame( 'Settings updated: renewal_reminder_days: 30 → 21', Activity_Log::render_message( $this->rows()[0] ) );
	}

	public function test_a_config_override_records_what_changed(): void {
		Config::save_override( 'settings', 'pet_species', [ 'dog' => 'Dog', 'cat' => 'Cat' ] );
		Config::save_override( 'settings', 'pet_species', [ 'dog' => 'Dog', 'cat' => 'Cat', 'rabbit' => 'Rabbit' ] );
		Config::delete_override( 'settings', 'pet_species' );

		$messages = array_map( [ Activity_Log::class, 'render_message' ], $this->rows() );
		$this->assertSame(
			[
				'Pet species changed: dog: (empty) → Dog; cat: (empty) → Cat',
				'Pet species changed: rabbit: (empty) → Rabbit',
				'Pet species reset to the defaults',
			],
			$messages
		);
	}

	public function test_retiring_and_restoring_a_fund(): void {
		$funds = Allocations::active();
		$first = array_key_first( $funds );

		Allocations::save( array_diff_key( $funds, [ $first => true ] ) );
		Allocations::save( array_diff_key( $funds, [ $first => true ] ), [ $first ] );

		$messages = array_map( [ Activity_Log::class, 'render_message' ], $this->rows() );
		$this->assertSame( [ 'Funds changed: retired ' . $funds[ $first ], 'Funds changed: restored ' . $funds[ $first ] ], $messages, 'One entry each, not one per stored list.' );
	}

	// ─── Record edits ──────────────────────────────────────────────

	public function test_an_edit_to_a_membership_is_one_entry_with_old_and_new_values(): void {
		$membership = self::factory()->post->create( [ 'post_type' => 'skdon_membership', 'post_status' => 'publish' ] );
		update_post_meta( $membership, '_skdon_tier', 'single' );
		update_post_meta( $membership, '_skdon_amount', '10' );
		Record_Edit_Tracker::flush();
		Record_Edit_Tracker::reset(); // What Legacy Sync did in a later request…
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		update_post_meta( $membership, '_skdon_tier', 'family' );
		update_post_meta( $membership, '_skdon_amount', '50' );
		update_post_meta( $membership, '_skdon_membership_type', 'business' );
		update_post_meta( $membership, '_skdon_lifetime_giving', '999' ); // Not an edit: the plugin keeps it.
		update_post_meta( $membership, '_skdon_logo_attachment_id', '0' ); // Nor is "no logo" saved as 0.
		Record_Edit_Tracker::flush();

		$rows = $this->rows();
		$this->assertCount( 1, $rows, '…is one entry, not one per field.' );
		$this->assertSame( 'record_edited', $rows[0]->event_type );
		$this->assertSame( 'Membership edited: tier: single → family; amount: 10 → 50; membership_type: (empty) → business', Activity_Log::render_message( $rows[0] ) );
	}

	public function test_a_new_record_is_not_an_edit(): void {
		$donation = wp_insert_post( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish', 'post_title' => 'Gift' ] );
		update_post_meta( $donation, '_skdon_amount', '25' );
		// The abilities create records with meta_input, which WordPress
		// writes before any hook says the post is new.
		wp_insert_post( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish', 'post_title' => 'Gift', 'meta_input' => [ '_skdon_amount' => '30' ] ] );
		Record_Edit_Tracker::flush();

		$this->assertSame( [], array_filter( $this->rows(), static fn ( $r ) => 'record_edited' === $r->event_type ) );
	}

	public function test_a_change_another_entry_describes_is_not_logged_twice(): void {
		$donation = self::factory()->post->create( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish' ] );
		update_post_meta( $donation, '_skdon_amount', '50' );
		Record_Edit_Tracker::reset();

		update_post_meta( $donation, '_skdon_amount', '30' ); // A refund…
		Activity_Log::record( 'gift_partly_refunded', 'donation', [ 'refunded' => 20, 'original' => 50 ], 'skdon_donation', $donation );
		Record_Edit_Tracker::flush();

		$this->assertSame( [ 'gift_partly_refunded' ], wp_list_pluck( $this->rows(), 'event_type' ), '…is the refund entry, not also an edit.' );
	}

	public function test_a_donor_edit_names_the_fields_but_not_their_values(): void {
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish' ] );
		update_post_meta( $donor, '_skdon_email', 'old@example.test' );
		Record_Edit_Tracker::reset();

		update_post_meta( $donor, '_skdon_email', 'new@example.test' );
		Record_Edit_Tracker::flush();

		$row = $this->rows()[0];
		$this->assertStringNotContainsString( 'example.test', (string) $row->meta );
		$this->assertSame( 'Donor edited: email', Activity_Log::render_message( $row ) );
	}

	public function test_deleting_a_gift_keeps_what_it_was(): void {
		$donor    = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => 'Pat Donor' ] );
		update_post_meta( $donor, '_skdon_display_name', 'Pat Donor' );
		$donation = self::factory()->post->create( [ 'post_type' => 'skdon_donation', 'post_status' => 'publish' ] );
		update_post_meta( $donation, '_skdon_amount', '40' );
		update_post_meta( $donation, '_skdon_donor_id', $donor );

		wp_delete_post( $donation, true );

		$deleted = array_values( array_filter( $this->rows(), static fn ( $r ) => 'record_deleted' === $r->event_type ) );
		$this->assertCount( 1, $deleted );
		$this->assertSame( 'Donation deleted: $40.00 from Pat Donor', Activity_Log::render_message( $deleted[0] ) );
	}
}
