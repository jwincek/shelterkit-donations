<?php
/**
 * The family a donor asked to be told is emailed: at checkout, by Notify
 * Family, and from Quick Add (#58).
 *
 * Until #58 the family email never went out from anywhere. Its condition
 * read a field nothing stored, its template called a helper with the wrong
 * arguments, and Notify Family fired a hook no email listened to while
 * recording the family as notified.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Quick_Actions;
use Starter_Shelter\Core\Entity_Hydrator;
use Starter_Shelter\Core\Family_Notification;
use Starter_Shelter\Helpers;
use WP_UnitTestCase;

final class FamilyNotificationTest extends WP_UnitTestCase {

	/** @var array<int, array{to: string, subject: string, message: string}> */
	private array $sent = [];

	private int $pat;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->sent = [];
		add_filter( 'pre_wp_mail', function ( $null, $atts ) {
			$this->sent[] = [ 'to' => (string) $atts['to'], 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message'] ];
			return true;
		}, 10, 2 );
		$this->hook_emails();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		$this->pat = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => 'Pat Alpha' ] );
		update_post_meta( $this->pat, '_skdon_display_name', 'Pat Alpha' );
		update_post_meta( $this->pat, '_skdon_first_name', 'Pat' );
		update_post_meta( $this->pat, '_skdon_email', 'pat@example.test' );
	}

	/**
	 * The plugin's emails subscribe to their triggers when WooCommerce builds
	 * them, once per process; the suite resets hooks between tests. Hook
	 * them exactly once for this test, so nothing is sent twice.
	 */
	private function hook_emails(): void {
		WC()->mailer();
		global $wp_filter;
		foreach ( [ 'starter_shelter_memorial_created' ] as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \Starter_Shelter\Emails\Config_Email ) {
						remove_action( $hook, $callback['function'], $priority );
					}
				}
			}
		}
		\Starter_Shelter\Emails\Email_Factory::register_emails( [] );
	}

	/** A memorial as checkout creates one: through the ability, emails on. */
	private function memorial( array $input ): int {
		$result = wp_get_ability( 'shelter-memorials/create' )->execute( $input + [
			'donor_id'      => $this->pat,
			'memorial_type' => 'person',
			'amount'        => 25,
		] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return (int) $result['memorial_id'];
	}

	private function family_request( string $email = 'smiths@example.test' ): array {
		return [ 'enabled' => true, 'name' => 'The Smiths', 'email' => $email ];
	}

	private function to( string $address ): array {
		return array_values( array_filter( $this->sent, static fn( $m ) => $m['to'] === $address ) );
	}

	private function logged( string $event ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}skdon_activity_log WHERE event_type = %s", $event ) );
	}

	// ─── At checkout ───────────────────────────────────────────────

	public function test_a_family_asked_for_at_checkout_is_emailed_once_and_recorded(): void {
		$id = $this->memorial( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] );

		$family = $this->to( 'smiths@example.test' );
		$this->assertCount( 1, $family, 'The family the donor asked to be told is emailed, once.' );
		$this->assertSame( 'A gift in tribute to Rex', $family[0]['subject'] );
		$this->assertCount( 1, $this->to( 'pat@example.test' ), 'The donor still gets their confirmation.' );

		$this->assertNotEmpty( get_post_meta( $id, '_skdon_family_notified_date', true ), 'Recorded, so Notify Family won\'t email them again.' );
		$this->assertSame( [ 'sent' => 0, 'failed' => 0 ], Quick_Actions::notify_families( [ $id ] ), 'Notify Family skips it.' );
		$this->assertCount( 1, $this->to( 'smiths@example.test' ) );

		$this->assertSame( 'Family notification sent for memorial of Rex to smiths@example.test', Activity_Log::render_message( $this->logged( 'family_notified' )[0] ) );
		$this->assertCount( 1, $this->logged( 'email_sent' ), 'One line per email: the confirmation; the family email is the line above.' );
	}

	public function test_a_family_not_asked_for_is_not_emailed(): void {
		$this->memorial( [ 'honoree_name' => 'Rex' ] );

		$this->assertSame( [ 'pat@example.test' ], array_column( $this->sent, 'to' ) );
	}

	public function test_the_memorial_carries_the_familys_details(): void {
		$id = $this->memorial( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] );

		$memorial = Entity_Hydrator::get( 'skdon_memorial', $id );
		$this->assertTrue( $memorial['notify_family']['enabled'], 'It hydrated to [] before, so the email\'s condition was always false.' );
		$this->assertSame( 'smiths@example.test', $memorial['notify_family']['email'] );
	}

	// ─── The words ─────────────────────────────────────────────────

	public function test_a_gift_in_memory_reads_as_one(): void {
		$this->memorial( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] );
		$family = $this->to( 'smiths@example.test' )[0]['message'];

		$this->assertStringContainsString( 'Dear The Smiths,', $family );
		$this->assertStringContainsString( 'Pat Alpha</strong> has made a memorial donation to', $family, 'The donor by name: the template used to call a helper with the wrong arguments and die.' );
		$this->assertStringContainsString( 'In Loving Memory Of', $family );
		$this->assertStringContainsString( 'condolences', $family );
		$this->assertStringContainsString( 'In Memory Of', $this->to( 'pat@example.test' )[0]['message'] );
	}

	public function test_a_gift_in_honor_of_someone_living_says_so(): void {
		$this->memorial( [ 'honoree_name' => 'Grandma Jo', 'dedication_type' => 'honor', 'is_anonymous' => true, 'notify_family' => $this->family_request() ] );
		$family = $this->to( 'smiths@example.test' )[0]['message'];

		$this->assertStringContainsString( 'a generous donor has made a gift to', $family, 'Anonymous: no name.' );
		$this->assertStringContainsString( 'in honor of <strong>Grandma Jo</strong>', $family );
		$this->assertStringContainsString( 'In Honor Of', $family );
		foreach ( [ 'memory', 'Memory', 'condolences', 'sympathy' ] as $word ) {
			$this->assertStringNotContainsString( $word, $family, "No \"{$word}\" for someone living." );
		}
		$this->assertStringNotContainsString( 'Pat Alpha', $family );

		$confirmation = $this->to( 'pat@example.test' )[0]['message'];
		$this->assertStringContainsString( 'In Honor Of', $confirmation );
		$this->assertStringNotContainsString( 'In Memory Of', $confirmation );
	}

	// ─── Notify Family ─────────────────────────────────────────────

	/** A memorial staff entered, as an import or Quick Add does: no email by itself. */
	private function entered( array $input ): int {
		Helpers\set_internal_processing( true );
		try {
			return $this->memorial( $input );
		} finally {
			Helpers\set_internal_processing( false );
		}
	}

	public function test_notify_family_emails_the_family_and_records_it(): void {
		$asked     = $this->entered( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] );
		$not_asked = $this->entered( [ 'honoree_name' => 'Biscuit' ] );
		$this->assertSame( [], $this->sent, 'Entered by staff: nothing yet.' );

		$this->assertSame( [ 'sent' => 1, 'failed' => 0 ], Quick_Actions::notify_families( [ $asked, $not_asked ] ) );
		$this->assertSame( [ 'smiths@example.test' ], array_column( $this->sent, 'to' ) );
		$this->assertNotEmpty( get_post_meta( $asked, '_skdon_family_notified_date', true ) );
		$this->assertCount( 1, $this->logged( 'family_notified' ) );

		$this->assertSame( [ 'sent' => 0, 'failed' => 0 ], Quick_Actions::notify_families( [ $asked ] ), 'Already notified: not again.' );
	}

	public function test_a_family_that_cant_be_emailed_is_not_recorded_as_notified(): void {
		$id = $this->entered( [ 'honoree_name' => 'Rex', 'notify_family' => [ 'enabled' => true, 'name' => 'The Smiths', 'address' => '1 Main St' ] ] );

		$result = Family_Notification::send( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'no_family_email', $result->get_error_code() );
		$this->assertSame( '', (string) get_post_meta( $id, '_skdon_family_notified_date', true ) );
		$this->assertSame( [], $this->logged( 'family_notified' ) );
	}

	public function test_notify_family_is_offered_only_where_it_can_email(): void {
		$offered = static fn( int $id ): bool => isset( \Starter_Shelter\Admin\List_Columns::add_row_actions( [], get_post( $id ) )['notify_family'] );

		$by_email = $this->entered( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] );
		$by_post  = $this->entered( [ 'honoree_name' => 'Biscuit', 'notify_family' => [ 'enabled' => true, 'address' => '1 Main St' ] ] );
		$at_checkout = $this->memorial( [ 'honoree_name' => 'Mittens', 'notify_family' => $this->family_request( 'jones@example.test' ) ] );

		$this->assertTrue( $offered( $by_email ) );
		$this->assertFalse( $offered( $by_post ), 'A postal address only: there is nothing to email.' );
		$this->assertFalse( $offered( $at_checkout ), 'Emailed at checkout already.' );
	}

	public function test_notify_family_no_longer_fires_a_hook_nothing_hears(): void {
		$fired = 0;
		add_action( 'starter_shelter_memorial_family_notification', static function () use ( &$fired ) {
			++$fired;
		} );
		Quick_Actions::notify_families( [ $this->entered( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] ) ] );

		$this->assertSame( 0, $fired );
		$this->assertCount( 1, $this->to( 'smiths@example.test' ) );
	}

	// ─── Dates the old action recorded ─────────────────────────────

	public function test_the_dates_recorded_without_an_email_are_cleared_once(): void {
		$a = $this->entered( [ 'honoree_name' => 'Rex', 'notify_family' => $this->family_request() ] );
		$b = $this->entered( [ 'honoree_name' => 'Biscuit', 'notify_family' => $this->family_request( 'jones@example.test' ) ] );
		$c = $this->entered( [ 'honoree_name' => 'Mittens' ] );
		// What the old Notify Family action left: a date, and no email.
		update_post_meta( $a, '_skdon_family_notified_date', '2026-10-01 10:00:00' );
		update_post_meta( $b, '_skdon_family_notified_date', '2026-10-02 10:00:00' );
		delete_option( Family_Notification::REPAIR_OPTION );
		delete_option( Family_Notification::NOTICE_OPTION );

		Family_Notification::maybe_repair();

		$this->assertSame( '', (string) get_post_meta( $a, '_skdon_family_notified_date', true ) );
		$this->assertSame( '', (string) get_post_meta( $b, '_skdon_family_notified_date', true ) );
		$this->assertSame( 2, (int) get_option( Family_Notification::NOTICE_OPTION ) );
		$this->assertSame(
			'Notify Family had never sent its email: 2 memorials marked as notified are waiting to notify the family again',
			Activity_Log::render_message( $this->logged( 'family_notified_reset' )[0] )
		);
		$this->assertSame( [ 'sent' => 2, 'failed' => 0 ], Quick_Actions::notify_families( [ $a, $b, $c ] ), 'Notify Family is offered again, and works.' );

		// Once only: a date recorded after the update is a real one.
		Family_Notification::maybe_repair();
		$this->assertNotEmpty( get_post_meta( $a, '_skdon_family_notified_date', true ) );
	}

	public function test_the_notice_says_how_many_and_where(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'dashboard' );
		update_option( Family_Notification::NOTICE_OPTION, 3 );

		ob_start();
		Family_Notification::repair_notice();
		$html = (string) ob_get_clean();
		$GLOBALS['current_screen'] = null;

		$this->assertStringContainsString( '3 memorials were marked “family notified”', $html );
		$this->assertStringContainsString( 'notify_status=pending', $html );
		$this->assertStringContainsString( 'action=skdon_dismiss_family_notice', $html );
	}
}
