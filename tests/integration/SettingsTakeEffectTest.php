<?php
/**
 * The settings that did nothing now do what they say (#49), and the upgrade
 * that makes them count doesn't change what a site offers.
 *
 * Checkout's side of the feature switches is in tests/wc/FeatureSwitchesCheckoutTest.php.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Settings;
use Starter_Shelter\Helpers;
use WP_UnitTestCase;

final class SettingsTakeEffectTest extends WP_UnitTestCase {

	private const OPTION = 'starter_shelter_options';

	public function set_up(): void {
		parent::set_up();
		delete_option( self::OPTION );
		delete_option( Settings::VERSION_OPTION );
		delete_option( Settings::NOTICE_OPTION );
	}

	// ─── The upgrade ───────────────────────────────────────────────

	/** What a 2.0.1 site can have stored: its Emails tab saved after General. */
	private function stored_before(): array {
		return [
			'fiscal_year_start_month'      => 7,
			'renewal_reminder_days'        => 21,
			'org_ein'                      => '12-3456789',
			'email_from_name'              => 'Valley Shelter',
			'email_from_address'           => '',
			'logo_moderation_email'        => '',
			'feature_anonymous_donations'  => false,
			'feature_dedications'          => true,
			'feature_family_notifications' => false,
			'feature_renewal_reminders'    => false,
			'feature_annual_statements'    => false,
		];
	}

	/** @dataProvider sanitiser_states */
	public function test_the_upgrade_turns_back_on_what_never_worked_and_deletes_what_was_removed( bool $sanitiser ): void {
		update_option( self::OPTION, $this->stored_before() );
		if ( $sanitiser ) {
			Settings::register_settings(); // As if admin_init had already run.
		}
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );

		Settings::maybe_upgrade();

		$saved = (array) get_option( self::OPTION );
		$this->assertTrue( $saved['feature_anonymous_donations'], 'Forms kept offering it, so on is what the site has been doing.' );
		$this->assertTrue( $saved['feature_family_notifications'] );
		$this->assertTrue( $saved['feature_dedications'] );
		$this->assertFalse( $saved['feature_renewal_reminders'], 'Renewal reminders already worked, so their stored value is the one the site has.' );
		$this->assertSame( 21, $saved['renewal_reminder_days'] );
		$this->assertSame( '12-3456789', $saved['org_ein'], 'Kept for the receipts.' );
		foreach ( [ 'email_from_name', 'email_from_address', 'logo_moderation_email', 'feature_annual_statements' ] as $removed ) {
			$this->assertArrayNotHasKey( $removed, $saved );
		}
		$this->assertSame( [ 'feature_anonymous_donations', 'feature_family_notifications' ], get_option( Settings::NOTICE_OPTION ) );

		$entry = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}skdon_activity_log WHERE event_type = 'settings_changed' ORDER BY id DESC LIMIT 1" );
		$this->assertSame(
			'Settings updated: feature_anonymous_donations: false → true; feature_family_notifications: false → true; removed email_from_name, email_from_address, logo_moderation_email, feature_annual_statements',
			Activity_Log::render_message( $entry ),
			'The log says the old settings went, not that they were emptied.'
		);
	}

	public function sanitiser_states(): array {
		return [ 'front end' => [ false ], 'admin' => [ true ] ];
	}

	public function test_the_upgrade_runs_once(): void {
		update_option( self::OPTION, $this->stored_before() );
		Settings::maybe_upgrade();

		// Turned off afterwards, deliberately: it stays off.
		update_option( self::OPTION, [ 'feature_anonymous_donations' => false ] + (array) get_option( self::OPTION ) );
		Settings::maybe_upgrade();

		$this->assertFalse( get_option( self::OPTION )['feature_anonymous_donations'] );
	}

	public function test_a_site_with_nothing_to_turn_on_gets_no_notice(): void {
		Settings::maybe_upgrade();
		$this->assertFalse( get_option( self::OPTION ), 'A new site has no settings stored, and none are made up.' );
		$this->assertFalse( get_option( Settings::NOTICE_OPTION ) );

		delete_option( Settings::VERSION_OPTION );
		update_option( self::OPTION, [ 'feature_anonymous_donations' => true, 'email_from_name' => 'X' ] );
		Settings::maybe_upgrade();
		$this->assertFalse( get_option( Settings::NOTICE_OPTION ) );
		$this->assertArrayNotHasKey( 'email_from_name', get_option( self::OPTION ) );
	}

	public function test_the_notice_names_what_was_turned_on_until_general_is_saved(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );
		update_option( Settings::NOTICE_OPTION, [ 'feature_anonymous_donations', 'feature_family_notifications' ] );

		ob_start();
		Settings::upgrade_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '“Allow Anonymous Donations” and “Enable Memorial Family Notifications” were turned off in Settings', $html );
		$this->assertStringContainsString( 'action=skdon_dismiss_settings_notice', $html );

		Settings::register_settings();
		update_option( self::OPTION, [ '_tab' => 'general', 'feature_anonymous_donations' => '1' ] );
		$this->assertFalse( get_option( Settings::NOTICE_OPTION ), 'Saving the switches answers it.' );

		ob_start();
		Settings::upgrade_notice();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
		$GLOBALS['current_screen'] = null;
	}

	// ─── The forms ─────────────────────────────────────────────────

	private function forms(): string {
		return do_blocks( '<!-- wp:shelter-donations/donation-form /-->' )
			. do_blocks( '<!-- wp:shelter-donations/memorial-form /-->' )
			. do_blocks( '<!-- wp:shelter-donations/membership-form {"showAnonymous":true} /-->' );
	}

	public function test_a_feature_switched_off_is_off_on_every_form(): void {
		$on = $this->forms();
		$this->assertSame( 3, substr_count( $on, 'sd-anonymous-section' ), 'Each form offers it while the site does.' );
		$this->assertStringContainsString( 'sd-family-section', $on );

		update_option( self::OPTION, [ 'feature_anonymous_donations' => false, 'feature_family_notifications' => false ] );
		$off = $this->forms();

		$this->assertStringNotContainsString( 'sd-anonymous-section', $off, 'The site-wide switch beats each form\'s own.' );
		$this->assertStringNotContainsString( 'sd-family-section', $off );
		$this->assertStringContainsString( 'data-wp-interactive', $off, 'The forms themselves still render.' );
	}

	// ─── The fiscal year ───────────────────────────────────────────

	public function test_the_fiscal_year_starts_in_the_month_set(): void {
		$this->assertSame( [ 'start' => '2025-07-01', 'end' => '2026-06-30' ], Helpers\get_fiscal_year_range( 2025 ), 'July unless set.' );

		$cases = [
			1  => [ 'start' => '2025-01-01', 'end' => '2025-12-31' ],
			3  => [ 'start' => '2025-03-01', 'end' => '2026-02-28' ],
			10 => [ 'start' => '2025-10-01', 'end' => '2026-09-30' ],
		];
		foreach ( $cases as $month => $range ) {
			update_option( self::OPTION, [ 'fiscal_year_start_month' => $month ] );
			$this->assertSame( $range, Helpers\get_fiscal_year_range( 2025 ), "Starting in month {$month}." );
		}
	}

	public function test_this_fiscal_year_is_the_one_today_falls_in(): void {
		$march = strtotime( '2026-03-15 12:00:00' );
		$july  = strtotime( '2026-07-01 12:00:00' );

		$this->assertSame( 2025, Helpers\current_fiscal_year( $march ), 'With July, March 2026 is in the year that began in July 2025.' );
		$this->assertSame( 2026, Helpers\current_fiscal_year( $july ) );

		update_option( self::OPTION, [ 'fiscal_year_start_month' => 1 ] );
		$this->assertSame( 2026, Helpers\current_fiscal_year( $march ) );

		// Reports' "Fiscal Year" asks for no year, and gets one that has
		// started and not ended. It used to get the calendar year's, which
		// from January until the start month hadn't begun. A start month
		// after this one is that case today.
		$month = (int) wp_date( 'n' );
		update_option( self::OPTION, [ 'fiscal_year_start_month' => $month < 12 ? $month + 1 : 1 ] );
		$range = Helpers\get_date_range_for_period( 'fiscal_year' );
		$this->assertLessThanOrEqual( wp_date( 'Y-m-d' ), $range['start'] );
		$this->assertGreaterThanOrEqual( wp_date( 'Y-m-d' ), $range['end'] );
		$this->assertSame( Helpers\get_fiscal_year_range( 2024 ), Helpers\get_date_range_for_period( 'fiscal_year', null, 2024 ), 'A year asked for is that year.' );
	}
}
