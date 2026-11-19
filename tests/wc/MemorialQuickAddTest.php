<?php
/**
 * Memorial Quick Add: memorial gifts received by mail or in person, entered
 * one or several at a time from the Memorials list.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Memorial_Quick_Add;
use WP_UnitTestCase;

final class MemorialQuickAddTest extends WP_UnitTestCase {

	/** @var string[] Subjects of the emails sent. */
	private array $sent = [];

	private int $pat;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->sent = [];
		add_filter( 'pre_wp_mail', function ( $null, $atts ) {
			$this->sent[] = $atts['to'] . ': ' . $atts['subject'];
			return true;
		}, 10, 2 );
		// The plugin's emails hook their triggers when WooCommerce builds them.
		WC()->mailer();
		\Starter_Shelter\Emails\Email_Factory::register_emails( [] );

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		$this->pat = $this->donor( 'Pat Alpha', 'pat@example.test' );
	}

	private function donor( string $name, string $email = '' ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => $name ] );
		update_post_meta( $id, '_skdon_display_name', $name );
		update_post_meta( $id, '_skdon_email', $email );
		return $id;
	}

	/** A row as the form posts it, with what this test changes. */
	private function row( array $values ): array {
		return $values + [
			'dedication_type' => 'memory',
			'memorial_type'   => 'person',
			'date'            => wp_date( 'Y-m-d' ),
		];
	}

	private function memorial_ids(): array {
		return get_posts( [ 'post_type' => 'skdon_memorial', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );
	}

	private function logged( string $event ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}skdon_activity_log WHERE event_type = %s", $event ) );
	}

	// ─── Adding ────────────────────────────────────────────────────

	public function test_several_memorials_are_added_at_once_and_counted_once_each(): void {
		$outcome = Memorial_Quick_Add::submit( [
			$this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'memorial_type' => 'pet', 'pet_species' => 'dog', 'amount' => '25' ] ),
			$this->row( [ 'donor_name' => 'Sam Beta', 'donor_email' => 'sam@example.test', 'honoree_name' => 'Grandma Jo', 'dedication_type' => 'honor', 'amount' => '$1,250.00', 'tribute_message' => 'With love' ] ),
			$this->row( [] ), // Added and left empty.
		] );

		$this->assertArrayHasKey( 'results', $outcome, wp_json_encode( $outcome ) );
		$this->assertCount( 2, $outcome['results'], 'The empty row is not a memorial.' );
		[ $rex, $jo ] = $this->memorial_ids();

		$this->assertSame( 'Rex', get_post_meta( $rex, '_skdon_honoree_name', true ) );
		$this->assertSame( 'pet', get_post_meta( $rex, '_skdon_memorial_type', true ) );
		$this->assertSame( 'dog', get_post_meta( $rex, '_skdon_pet_species', true ) );
		$this->assertSame( $this->pat, (int) get_post_meta( $rex, '_skdon_donor_id', true ) );
		$this->assertEquals( 25, (float) get_post_meta( $this->pat, '_skdon_lifetime_giving', true ), 'Counted toward the donor\'s giving, once.' );

		$sam = (int) get_post_meta( $jo, '_skdon_donor_id', true );
		$this->assertSame( 'sam@example.test', get_post_meta( $sam, '_skdon_email', true ), 'A new donor, by email.' );
		$this->assertTrue( $outcome['results'][1]['donor_created'] );
		$this->assertSame( 'honor', get_post_meta( $jo, '_skdon_dedication_type', true ) );
		$this->assertEquals( 1250, (float) get_post_meta( $jo, '_skdon_amount', true ) );
		$this->assertSame( 'With love', get_post_meta( $jo, '_skdon_tribute_message', true ) );
		$this->assertSame( 0, (int) get_post_meta( $jo, '_skdon_wc_order_id', true ), 'No WooCommerce order.' );
		$this->assertSame( [], wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) );
		$this->assertSame( 'publish', get_post_status( $jo ) );

		$this->assertSame( 2, $this->logged( 'memorial_created' ), 'Each is logged as created, once.' );
		$this->assertSame( [], $this->sent, 'Nothing ticked, so no one is emailed.' );
	}

	public function test_an_existing_email_reuses_that_donor(): void {
		Memorial_Quick_Add::submit( [ $this->row( [ 'donor_name' => 'P. Alpha', 'donor_email' => 'pat@example.test', 'honoree_name' => 'Rex', 'amount' => '10' ] ) ] );

		$this->assertSame( $this->pat, (int) get_post_meta( $this->memorial_ids()[0], '_skdon_donor_id', true ) );
	}

	public function test_a_confirmation_goes_only_when_ticked_and_the_family_is_recorded(): void {
		$outcome = Memorial_Quick_Add::submit( [
			$this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'amount' => '25', 'email_donor' => '1' ] ),
			$this->row( [
				'donor_id'         => (string) $this->pat,
				'honoree_name'     => 'Biscuit',
				'amount'           => '30',
				'notify_family'    => '1',
				'family_name'      => 'The Smiths',
				'family_email'     => 'smiths@example.test',
				'family_address'   => '1 Main St',
				'family_send_card' => '1',
			] ),
		] );
		[ , $biscuit ] = $this->memorial_ids();

		$this->assertTrue( $outcome['results'][0]['donor_email'] );
		$this->assertArrayNotHasKey( 'donor_email', $outcome['results'][1] );
		$this->assertSame( [ 'pat@example.test: Your memorial for Rex has been created' ], $this->sent, 'One confirmation, the one ticked; the family isn\'t emailed.' );
		$this->assertSame( 1, $this->logged( 'email_sent' ) );

		$this->assertSame(
			[ 'enabled' => true, 'name' => 'The Smiths', 'email' => 'smiths@example.test', 'address' => '1 Main St', 'send_card' => true ],
			\Starter_Shelter\Helpers\get_memorial_notify_family( $biscuit ),
			'What the donor asked for the family is on the memorial.'
		);
		$this->assertSame( '', (string) get_post_meta( $biscuit, '_skdon_family_notified_date', true ) );
	}

	public function test_the_family_is_emailed_when_ticked(): void {
		$outcome = Memorial_Quick_Add::submit( [
			$this->row( [
				'donor_id'      => (string) $this->pat,
				'honoree_name'  => 'Biscuit',
				'amount'        => '30',
				'notify_family' => '1',
				'family_name'   => 'The Smiths',
				'family_email'  => 'smiths@example.test',
				'email_family'  => '1',
			] ),
		] );
		$biscuit = $this->memorial_ids()[0];

		$this->assertTrue( $outcome['results'][0]['family_email'] );
		$this->assertSame( [ 'smiths@example.test: A gift in tribute to Biscuit' ], $this->sent, 'The family, and not the donor: their box wasn\'t ticked.' );
		$this->assertNotEmpty( get_post_meta( $biscuit, '_skdon_family_notified_date', true ), 'Recorded as notified (#58).' );
		$this->assertSame( 1, $this->logged( 'family_notified' ) );

		$missing = Memorial_Quick_Add::submit( [ $this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'amount' => '5', 'email_family' => '1' ] ) ] );
		$this->assertArrayHasKey( 'family_email', $missing['errors'][0], 'Emailing the family needs their address.' );
	}

	public function test_the_name_can_be_kept_off_the_memorial(): void {
		Memorial_Quick_Add::submit( [ $this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'amount' => '25', 'is_anonymous' => '1' ] ) ] );
		$id = $this->memorial_ids()[0];

		$this->assertTrue( (bool) get_post_meta( $id, '_skdon_is_anonymous', true ) );
		$this->assertSame( '', get_post_meta( $id, '_skdon_donor_display_name', true ) );
		$this->assertSame( $this->pat, (int) get_post_meta( $id, '_skdon_donor_id', true ), 'Still the donor\'s gift.' );
	}

	public function test_an_earlier_date_is_kept(): void {
		Memorial_Quick_Add::submit( [ $this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'amount' => '25', 'date' => '2025-12-03' ] ) ] );
		$id = $this->memorial_ids()[0];

		$this->assertSame( '2025-12-03 12:00:00', get_post_meta( $id, '_skdon_donation_date', true ) );
		$this->assertSame( '2025-12-03', get_the_date( 'Y-m-d', $id ) );
	}

	// ─── Mistakes ──────────────────────────────────────────────────

	public function test_one_mistake_adds_nothing_and_keeps_what_was_typed(): void {
		$outcome = Memorial_Quick_Add::submit( [
			$this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'amount' => '25' ] ),
			$this->row( [ 'donor_name' => 'Sam Beta', 'donor_email' => 'not-an-email', 'honoree_name' => '', 'amount' => 'abc', 'date' => '2999-01-01' ] ),
		] );

		$this->assertSame( [], $this->memorial_ids(), 'All or nothing: the valid first row isn\'t added either.' );
		$this->assertSame( [ 1 ], array_keys( $outcome['errors'] ) );
		$this->assertEqualsCanonicalizing( [ 'donor_email', 'honoree_name', 'amount', 'date' ], array_keys( $outcome['errors'][1] ) );
		$this->assertSame( 'Rex', $outcome['rows'][0]['honoree_name'], 'What was typed comes back.' );
		$this->assertSame( 'abc', $outcome['rows'][1]['amount'] );
	}

	public function test_a_name_already_on_file_is_asked_about(): void {
		$this->donor( 'Lee Gamma' ); // On file, no email: from a mailed check.
		$row = $this->row( [ 'donor_name' => 'Lee Gamma', 'honoree_name' => 'Rex', 'amount' => '25' ] );

		$first = Memorial_Quick_Add::submit( [ $row ] );
		$this->assertArrayHasKey( 'donor_duplicate', $first['errors'][0] );
		$this->assertSame( [], $this->memorial_ids() );

		Memorial_Quick_Add::submit( [ $row + [ 'new_donor_confirmed' => '1' ] ] );
		$this->assertCount( 2, get_posts( [ 'post_type' => 'skdon_donor', 'title' => 'Lee Gamma', 'fields' => 'ids', 'post_status' => 'publish' ] ), 'Confirmed as a different person: a second donor.' );
	}

	public function test_a_confirmation_needs_an_email_address(): void {
		$lee = $this->donor( 'Lee Gamma' );
		$outcome = Memorial_Quick_Add::submit( [
			$this->row( [ 'donor_id' => (string) $lee, 'honoree_name' => 'Rex', 'amount' => '25', 'email_donor' => '1' ] ),
			$this->row( [ 'donor_name' => 'Sam Beta', 'honoree_name' => 'Rex', 'amount' => '25', 'email_donor' => '1' ] ),
			$this->row( [ 'donor_id' => (string) $this->pat, 'honoree_name' => 'Rex', 'amount' => '25', 'notify_family' => '1', 'family_email' => 'smiths@' ] ),
		] );

		$this->assertArrayHasKey( 'email_donor', $outcome['errors'][0], 'A donor on file without an email…' );
		$this->assertArrayHasKey( 'email_donor', $outcome['errors'][1], '…or a new donor entered without one.' );
		$this->assertArrayHasKey( 'family_email', $outcome['errors'][2] );
	}

	public function test_amounts_are_read_as_typed(): void {
		$this->assertSame( 25.0, Memorial_Quick_Add::parse_amount( '25' ) );
		$this->assertSame( 1250.0, Memorial_Quick_Add::parse_amount( '$1,250.00' ) );
		$this->assertSame( 12.5, Memorial_Quick_Add::parse_amount( ' 12.50 ' ) );
		$this->assertNull( Memorial_Quick_Add::parse_amount( 'abc' ) );
		$this->assertNull( Memorial_Quick_Add::parse_amount( '' ) );

		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		$this->assertSame( 25.5, Memorial_Quick_Add::parse_amount( '25,50' ), 'A store that writes decimals with a comma.' );
	}

	// ─── Finding a donor ───────────────────────────────────────────

	public function test_the_donor_search_finds_by_name_or_email_and_shows_the_email(): void {
		$this->donor( 'Pat Alpha' ); // Same name, different person, no email.

		$by_name = wp_list_pluck( Memorial_Quick_Add::search_donors( 'Pat Al' ), 'text' );
		$this->assertContains( 'Pat Alpha — pat@example.test', $by_name );
		$this->assertContains( 'Pat Alpha (no email)', $by_name, 'Two people with one name can be told apart.' );

		$by_email = Memorial_Quick_Add::search_donors( 'pat@exa' );
		$this->assertSame( [ $this->pat ], wp_list_pluck( $by_email, 'id' ) );
		$this->assertSame( [], Memorial_Quick_Add::search_donors( 'P' ), 'Two letters at least.' );
	}

	// ─── The screens ───────────────────────────────────────────────

	public function test_the_memorials_list_has_a_quick_add_button(): void {
		set_current_screen( 'edit-skdon_memorial' );
		Memorial_Quick_Add::add_list_button();
		ob_start();
		do_action( 'admin_footer' );
		$html = (string) ob_get_clean();
		$GLOBALS['current_screen'] = null;

		$this->assertStringContainsString( '"Quick Add"', $html );
		$this->assertStringContainsString( 'page=' . Memorial_Quick_Add::PAGE, str_replace( '\\/', '/', $html ) );
	}

	public function test_the_screen_shows_each_mistake_by_its_field(): void {
		$html = $this->page();
		$this->assertStringContainsString( 'name="qa[0][honoree_name]"', $html );
		$this->assertStringContainsString( 'id="sd-qa-row-template"', $html, 'Rows can be added.' );
		$this->assertStringContainsString( 'name="qa[__INDEX__][amount]"', $html );
		$this->assertMatchesRegularExpression( '/name="qa\[0\]\[email_donor\]"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="qa\[0\]\[email_donor\]"[^>]*checked/', $html, 'The confirmation box is unticked by default.' );

		$this->donor( 'Lee Gamma' );
		$_POST = [
			'skdon_quick_add' => '1',
			'_wpnonce'        => wp_create_nonce( 'skdon_quick_add_memorials' ),
			'qa'              => [ $this->row( [ 'donor_name' => 'Lee Gamma', 'honoree_name' => '', 'amount' => '25' ] ) ],
		];
		$_REQUEST = $_POST;
		Memorial_Quick_Add::load();
		$html = $this->page();
		$_POST = $_REQUEST = [];

		$this->assertStringContainsString( 'Nothing was added.', $html );
		$this->assertStringContainsString( 'Enter the name of the person or pet the gift remembers.', $html );
		$this->assertStringContainsString( 'A donor named “Lee Gamma” is already on file.', $html );
		$this->assertStringContainsString( 'name="qa[0][new_donor_confirmed]"', $html, 'The way out of the name question is offered.' );
		$this->assertStringContainsString( 'value="Lee Gamma"', $html, 'What was typed comes back.' );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
	}

	private function page(): string {
		ob_start();
		Memorial_Quick_Add::render_page();
		return (string) ob_get_clean();
	}
}
