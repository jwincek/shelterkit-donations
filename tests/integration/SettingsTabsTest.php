<?php
/**
 * Saving one tab of the settings screen leaves the other tab alone (#50).
 *
 * The form shows one tab at a time and the sanitiser rebuilt the whole
 * option from what that tab posted, so saving the Emails tab turned renewal
 * reminders off, reset the reminder period and cleared the donation and
 * membership pages. The Emails tab has had no settings of its own since
 * #49, but a save still changes only the tab it came from.
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
		] );
	}

	private function saved(): array {
		return (array) get_option( self::OPTION );
	}

	public function test_a_save_from_another_tab_leaves_the_general_settings_alone(): void {
		// A post naming a tab with no settings, and a field that is no longer one.
		update_option( self::OPTION, [
			'_tab'            => 'emails',
			'email_from_name' => 'Valley Animal Shelter',
		] );

		$saved = $this->saved();
		$this->assertArrayNotHasKey( 'email_from_name', $saved, 'A removed setting is not stored (#49).' );
		$this->assertTrue( $saved['feature_renewal_reminders'], 'Renewal reminders stay on.' );
		$this->assertSame( 27, $saved['renewal_reminder_days'] );
		$this->assertSame( 12, $saved['donation_page'] );
		$this->assertSame( 13, $saved['membership_page'] );
	}

	public function test_unchecked_boxes_on_the_saved_tab_go_off(): void {
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
		$this->assertSame( 21, $saved['renewal_reminder_days'] );
		$this->assertTrue( $saved['feature_renewal_reminders'] );
		$this->assertFalse( $saved['feature_dedications'], 'A box left unchecked on the saved tab is off.' );
	}

	public function test_the_tab_marker_is_not_stored(): void {
		update_option( self::OPTION, [ '_tab' => 'general', 'renewal_reminder_days' => '30' ] );

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
		$this->assertStringContainsString( 'name="starter_shelter_options[_tab]" value="general"', $this->page( 'general' ) );
	}

	public function test_the_emails_tab_has_no_form_and_says_where_the_sender_is_set(): void {
		$html = $this->page( 'emails' );

		$this->assertStringNotContainsString( 'options.php', $html, 'Nothing on the tab to save.' );
		$this->assertStringContainsString( 'page=wc-settings&#038;tab=email', $html );
		$this->assertStringContainsString( 'Email sender options', $html );
	}

	public function test_a_setting_that_was_removed_goes_on_the_next_save(): void {
		// Stored before #49, when the Emails tab had its own From fields.
		remove_all_filters( 'sanitize_option_' . self::OPTION );
		update_option( self::OPTION, $this->saved() + [ 'email_from_name' => 'Valley Shelter', 'logo_moderation_email' => 'a@example.test' ] );
		Settings::register_settings();

		update_option( self::OPTION, [ 'renewal_reminder_days' => 40 ] );

		$this->assertArrayNotHasKey( 'email_from_name', $this->saved() );
		$this->assertArrayNotHasKey( 'logo_moderation_email', $this->saved() );
		$this->assertSame( 40, $this->saved()['renewal_reminder_days'] );
	}

	private function page( string $tab ): string {
		$_GET['tab'] = $tab;
		ob_start();
		Settings::render_settings_page();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		return $html;
	}
}
