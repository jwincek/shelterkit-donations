<?php
/**
 * Saving one tab of the settings screen leaves the other tab alone (#50).
 *
 * The form shows one tab at a time and the sanitiser rebuilt the whole
 * option from what that tab posted, so saving the Emails tab turned renewal
 * reminders off, reset the reminder period and cleared the donation and
 * membership pages.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Settings;
use WP_UnitTestCase;

final class SettingsTabsTest extends WP_UnitTestCase {

	private const OPTION = 'starter_shelter_options';

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// What admin_init does: registers the option's sanitiser and the fields.
		Settings::register_settings();
		delete_option( self::OPTION );
		// A site with its General settings set.
		update_option( self::OPTION, [
			'renewal_reminder_days'     => 27,
			'donation_page'             => 12,
			'membership_page'           => 13,
			'feature_renewal_reminders' => true,
			'feature_dedications'       => true,
			'email_from_name'           => 'Valley Shelter',
		] );
	}

	private function saved(): array {
		return (array) get_option( self::OPTION );
	}

	public function test_saving_the_emails_tab_leaves_the_general_settings_alone(): void {
		// What the Emails tab posts: its own three fields and the tab marker.
		update_option( self::OPTION, [
			'_tab'                  => 'emails',
			'email_from_name'       => 'Valley Animal Shelter',
			'email_from_address'    => 'hello@example.test',
			'logo_moderation_email' => '',
		] );

		$saved = $this->saved();
		$this->assertSame( 'Valley Animal Shelter', $saved['email_from_name'] );
		$this->assertTrue( $saved['feature_renewal_reminders'], 'Renewal reminders stay on.' );
		$this->assertSame( 27, $saved['renewal_reminder_days'] );
		$this->assertSame( 12, $saved['donation_page'] );
		$this->assertSame( 13, $saved['membership_page'] );
	}

	public function test_saving_the_general_tab_leaves_the_emails_settings_alone_and_unchecked_boxes_go_off(): void {
		// The General tab with Dedications unchecked: an unchecked box isn't posted.
		update_option( self::OPTION, [
			'_tab'                      => 'general',
			'fiscal_year_start_month'   => '7',
			'renewal_reminder_days'     => '21',
			'donation_page'             => '12',
			'membership_page'           => '13',
			'feature_renewal_reminders' => '1',
		] );

		$saved = $this->saved();
		$this->assertSame( 'Valley Shelter', $saved['email_from_name'], 'The Emails tab is untouched.' );
		$this->assertSame( 21, $saved['renewal_reminder_days'] );
		$this->assertTrue( $saved['feature_renewal_reminders'] );
		$this->assertFalse( $saved['feature_dedications'], 'A box left unchecked on the saved tab is off.' );
	}

	public function test_the_tab_marker_is_not_stored(): void {
		update_option( self::OPTION, [ '_tab' => 'emails', 'email_from_name' => 'X' ] );

		$this->assertArrayNotHasKey( '_tab', $this->saved() );
	}

	public function test_a_save_in_code_changes_only_the_keys_it_passes(): void {
		update_option( self::OPTION, [ 'renewal_reminder_days' => 45 ] );

		$saved = $this->saved();
		$this->assertSame( 45, $saved['renewal_reminder_days'] );
		$this->assertTrue( $saved['feature_renewal_reminders'] );
		$this->assertSame( 12, $saved['donation_page'] );
	}

	public function test_the_form_says_which_tab_it_saves(): void {
		$_GET['tab'] = 'emails';
		ob_start();
		Settings::render_settings_page();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'name="starter_shelter_options[_tab]" value="emails"', $html );
	}
}
