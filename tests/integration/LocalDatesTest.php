<?php
/**
 * Stored dates are the site's local time, and are read as such (#62).
 *
 * The plugin read them with strtotime(), as UTC, then showed them with
 * wp_date() in the site's zone. On a site west of UTC that meant:
 *
 * - every date was shown hours early, and a day-only date as the day before
 * - the editor showed the shifted value and saved it back, so each save of a
 *   donation moved it back again (08:04 → 00:04 → 16:04 the day before)
 * - CSV imports, new memberships and renewals stored shifted dates
 *
 * Every test site ran in UTC, which hides all of it. These run in New York.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Meta_Boxes;
use Starter_Shelter\Admin\Record_Edit_Tracker;
use Starter_Shelter\Helpers;
use WP_UnitTestCase;

final class LocalDatesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Record_Edit_Tracker::reset();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );
	}

	public function tear_down(): void {
		Record_Edit_Tracker::reset();
		$_POST = [];
		parent::tear_down();
	}

	// ─── Showing a date ────────────────────────────────────────────

	public function test_a_date_shows_as_the_day_it_is(): void {
		$this->assertSame( 'March 1, 2027', Helpers\format_date( '2027-03-01' ), 'A day-only date used to show as the day before.' );
		$this->assertSame( 'January 5, 2026', Helpers\format_date( '2026-01-05 00:30:00' ) );
		$this->assertSame( 'Mar 2026', wp_date( 'M Y', Helpers\local_timestamp( '2026-03-01' ) ), 'The reports\' month labels.' );
		$this->assertSame( 'not a date', Helpers\format_date( 'not a date' ) );
	}

	public function test_a_date_with_an_offset_is_converted_to_the_site_zone(): void {
		$this->assertSame( '2026-03-01 07:00', Helpers\local_date( '2026-03-01T12:00:00+00:00' )->format( 'Y-m-d H:i' ) );
	}

	// ─── The editor ────────────────────────────────────────────────

	/** Save a record from its editor with every field as the editor shows it, plus $changes. */
	private function save_in_editor( int $post_id, string $box, array $changes = [] ): void {
		$config = ( new \ReflectionMethod( Meta_Boxes::class, 'get_config' ) )->invoke( null );
		$_POST  = [ "sd_meta_box_{$box}_nonce" => wp_create_nonce( "sd_meta_box_{$box}" ) ];
		foreach ( $config[ get_post_type( $post_id ) ]['boxes'][ $box ]['fields'] as $id => $field ) {
			$value = (string) get_post_meta( $post_id, '_skdon_' . $id, true );
			$_POST[ "sd_{$id}" ] = match ( $field['type'] ) {
				'date'     => (string) Helpers\local_date( $value )?->format( 'Y-m-d' ),
				'datetime' => (string) Helpers\local_date( $value )?->format( 'Y-m-d\TH:i' ),
				default    => $value,
			};
			if ( 'checkbox' === $field['type'] && ! $value ) {
				unset( $_POST[ "sd_{$id}" ] );
			}
		}
		foreach ( $changes as $id => $value ) {
			$_POST[ "sd_{$id}" ] = $value;
		}
		Meta_Boxes::save_meta_boxes( $post_id, get_post( $post_id ) );
		Record_Edit_Tracker::flush();
		$_POST = [];
	}

	private function edits(): array {
		global $wpdb;
		return $wpdb->get_col( "SELECT meta FROM {$wpdb->prefix}skdon_activity_log WHERE event_type = 'record_edited'" );
	}

	private function record( string $type, string $date ): int {
		$id = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => 'publish' ] );
		update_post_meta( $id, '_skdon_donation_date', $date );
		update_post_meta( $id, '_skdon_amount', '25' );
		Record_Edit_Tracker::reset();
		return $id;
	}

	public function test_the_editor_shows_the_stored_time(): void {
		$id     = $this->record( 'skdon_donation', '2025-12-20 08:04:00' );
		$render = new \ReflectionMethod( Meta_Boxes::class, 'render_field' );
		$render->setAccessible( true );

		ob_start();
		$render->invoke( null, 'donation_date', [ 'type' => 'datetime', 'label' => 'Date' ], [ 'donation_date' => '2025-12-20 08:04:00' ], get_post( $id ) );
		$render->invoke( null, 'start_date', [ 'type' => 'date', 'label' => 'Start' ], [ 'start_date' => '2026-03-01' ], get_post( $id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="2025-12-20T08:04"', $html, 'It showed 03:04 in New York, and saved that back.' );
		$this->assertStringContainsString( 'value="2026-03-01"', $html, 'A day-only date showed as the day before.' );
	}

	public function test_saving_a_donation_unchanged_leaves_its_date_alone(): void {
		$id = $this->record( 'skdon_donation', '2025-12-20 08:04:00' );

		$this->save_in_editor( $id, 'donation_details' );
		$this->save_in_editor( $id, 'donation_details' );

		$this->assertSame( '2025-12-20 08:04:00', get_post_meta( $id, '_skdon_donation_date', true ), 'Each save used to move it back five hours.' );
		$this->assertSame( [], $this->edits(), 'And nothing is logged as edited.' );
	}

	public function test_a_new_time_is_stored_in_the_stored_format(): void {
		$id = $this->record( 'skdon_donation', '2025-12-20 08:04:00' );

		$this->save_in_editor( $id, 'donation_details', [ 'donation_date' => '2025-12-31T14:30' ] );

		$this->assertSame( '2025-12-31 14:30:00', get_post_meta( $id, '_skdon_donation_date', true ), 'Not "2025-12-31T14:30", which the reports sort after "2025-12-31 23:59:59".' );
		$this->assertCount( 1, $this->edits() );
	}

	public function test_a_memorials_day_keeps_its_time(): void {
		$id = $this->record( 'skdon_memorial', '2026-11-19 03:58:58' );

		$this->save_in_editor( $id, 'donation_info' );
		$this->assertSame( '2026-11-19 03:58:58', get_post_meta( $id, '_skdon_donation_date', true ), 'The day-only field used to drop the time.' );
		$this->assertSame( [], $this->edits() );

		$this->save_in_editor( $id, 'donation_info', [ 'donation_date' => '2026-11-21' ] );
		$this->assertSame( '2026-11-21 03:58:58', get_post_meta( $id, '_skdon_donation_date', true ) );
	}

	public function test_dates_earlier_saves_left_with_a_t_are_normalised_once(): void {
		$id = $this->record( 'skdon_donation', '2025-12-31T10:00' );
		delete_option( 'skdon_dates_normalised' );

		Meta_Boxes::maybe_normalise_dates();
		$this->assertSame( '2025-12-31 10:00:00', get_post_meta( $id, '_skdon_donation_date', true ) );

		update_post_meta( $id, '_skdon_donation_date', '2025-12-31T11:00' );
		Meta_Boxes::maybe_normalise_dates();
		$this->assertSame( '2025-12-31T11:00', get_post_meta( $id, '_skdon_donation_date', true ), 'Once only.' );
	}

	// ─── Dates the plugin writes ───────────────────────────────────

	public function test_a_membership_starts_and_ends_on_the_days_given(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish' ] );
		update_post_meta( $donor, '_skdon_email', 'pat@example.test' );

		Helpers\set_internal_processing( true );
		try {
			$result = \Starter_Shelter\Abilities\Memberships\create( [ 'donor_id' => $donor, 'tier' => 'single', 'amount' => 10, 'date' => '2026-01-01' ] );
		} finally {
			Helpers\set_internal_processing( false );
		}

		$this->assertSame( '2026-01-01', get_post_meta( $result['membership_id'], '_skdon_start_date', true ), 'It started on December 31 in New York.' );
		$this->assertSame( '2027-01-01', get_post_meta( $result['membership_id'], '_skdon_end_date', true ) );
		$this->assertStringContainsString( '2026', get_the_title( $result['membership_id'] ), 'And was titled with the year before.' );
	}

	public function test_a_csv_date_is_imported_as_written(): void {
		$cast = new \ReflectionMethod( \Starter_Shelter\Admin\Import_Export\CSV_Importer::class, 'cast_value' );
		$cast->setAccessible( true );

		$this->assertSame( '2026-03-01 00:00:00', $cast->invoke( null, '2026-03-01', 'date' ), 'It became "2026-02-28 19:00:00".' );
		$this->assertSame( '2026-03-15 00:00:00', $cast->invoke( null, '03/15/2026', 'date' ) );
	}

	public function test_extending_a_membership_adds_thirty_days(): void {
		$extend = new \ReflectionMethod( \Starter_Shelter\Admin\Quick_Actions::class, 'thirty_days_on' );
		$extend->setAccessible( true );
		$future = Helpers\local_date( 'today' )->modify( '+10 days' );

		$this->assertSame( $future->modify( '+30 days' )->format( 'Y-m-d' ), $extend->invoke( null, $future->format( 'Y-m-d' ) ), 'From the end date, not a day short.' );
		$this->assertSame( Helpers\local_date( 'today' )->modify( '+30 days' )->format( 'Y-m-d' ), $extend->invoke( null, '2020-01-01' ), 'From today once it has passed.' );
	}
}
