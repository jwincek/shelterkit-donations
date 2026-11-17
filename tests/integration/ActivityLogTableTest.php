<?php
/**
 * Integration tests for the activity log's table and scheduled cleanup.
 *
 * The table used to be created only when someone opened the Activity Log
 * screen: its register_activation_hook() call ran on plugins_loaded, after
 * activation had already happened, so every event before that first visit
 * was lost (#22).
 *
 * The suite's bootstrap loads the plugin, so the real table already exists
 * here, and a test cannot drop it cleanly (the test case turns DROP TABLE into
 * DROP TEMPORARY TABLE). These tests therefore pin the wiring: what activation
 * and the per-request check do, and that nothing is left scheduled. The
 * clean-install pass is what proves a fresh site gets the table.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Core\Activator;
use WP_UnitTestCase;

final class ActivityLogTableTest extends WP_UnitTestCase {

    private const VERSION_OPTION = 'sd_activity_log_db_version';

    /** @var string[] DDL statements seen while a test runs. */
    private array $ddl = [];

    public function capture_ddl( string $query ): string {
        if ( preg_match( '/^\s*(CREATE|ALTER|DESCRIBE)\b/i', $query ) ) {
            $this->ddl[] = $query;
        }
        return $query;
    }

    public function tear_down(): void {
        remove_filter( 'query', [ $this, 'capture_ddl' ] );
        wp_clear_scheduled_hook( 'sd_cleanup_activity_log' );
        wp_clear_scheduled_hook( 'starter_shelter_renewal_reminders' );
        delete_option( 'starter_shelter_options' );
        parent::tear_down();
    }

    public function test_activation_builds_the_table(): void {
        delete_option( self::VERSION_OPTION );

        Activator::activate();

        $this->assertSame( '1', get_option( self::VERSION_OPTION ), 'Activation ran create_table(), which records the schema version.' );
    }

    public function test_a_site_activated_before_the_fix_gets_the_table_on_its_next_request(): void {
        // Such a site has no version recorded, because nothing ever ran create_table().
        delete_option( self::VERSION_OPTION );
        add_filter( 'query', [ $this, 'capture_ddl' ] );

        Activity_Log::maybe_create_table();

        $this->assertNotEmpty( $this->ddl, 'dbDelta() ran.' );
        $this->assertSame( '1', get_option( self::VERSION_OPTION ) );
    }

    public function test_a_current_schema_costs_no_queries_against_the_table(): void {
        update_option( self::VERSION_OPTION, '1' );
        add_filter( 'query', [ $this, 'capture_ddl' ] );

        Activity_Log::maybe_create_table();

        $this->assertSame( [], $this->ddl );
    }

    public function test_the_schema_is_one_dbdelta_can_update(): void {
        // dbDelta() takes the table name from the word after CREATE TABLE. With
        // IF NOT EXISTS that word is "IF", so a later schema change would never
        // reach the real table.
        delete_option( self::VERSION_OPTION );
        add_filter( 'query', [ $this, 'capture_ddl' ] );

        Activity_Log::create_table();

        global $wpdb;
        $describe = preg_grep( '/^\s*DESCRIBE\b/i', $this->ddl );
        $this->assertNotEmpty( $describe );
        $this->assertStringContainsString( $wpdb->prefix . 'sd_activity_log', implode( "\n", $describe ) );
    }

    public function test_deactivation_clears_the_cleanup_event(): void {
        wp_schedule_event( time(), 'daily', 'sd_cleanup_activity_log' );

        starter_shelter_deactivate();

        $this->assertFalse( wp_next_scheduled( 'sd_cleanup_activity_log' ) );
    }

    public function test_uninstall_clears_scheduled_events_even_when_preserving_data(): void {
        require_once dirname( __DIR__, 2 ) . '/includes/uninstall-cleanup.php';
        wp_schedule_event( time(), 'daily', 'sd_cleanup_activity_log' );
        wp_schedule_event( time(), 'daily', 'starter_shelter_renewal_reminders' );
        update_option( 'starter_shelter_options', [ 'delete_data_on_uninstall' => false ] );

        starter_shelter_run_uninstall();

        $this->assertFalse( wp_next_scheduled( 'sd_cleanup_activity_log' ) );
        $this->assertFalse( wp_next_scheduled( 'starter_shelter_renewal_reminders' ) );
    }

    public function test_listeners_are_registered_outside_wp_admin(): void {
        // The suite runs as a front-end request (is_admin() is false), which
        // is where checkout, webhooks and WP-Cron happen.
        $this->assertFalse( is_admin() );
        $this->assertNotFalse( has_action( 'starter_shelter_donation_created', [ Activity_Log::class, 'log_donation_created' ] ) );
        $this->assertNotFalse( has_action( 'starter_shelter_order_processed', [ Activity_Log::class, 'log_order_processed' ] ) );
        $this->assertNotFalse( has_action( 'sd_cleanup_activity_log', [ Activity_Log::class, 'cleanup_old_logs' ] ), 'The cleanup event has a callback when wp-cron.php runs it.' );
    }

    public function test_an_address_is_recorded_only_for_wp_admin_requests(): void {
        global $wpdb;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $ip_for = static function ( string $event ) use ( $wpdb ): ?string {
            return $wpdb->get_var( $wpdb->prepare( "SELECT ip_address FROM {$wpdb->prefix}sd_activity_log WHERE event_type = %s", $event ) );
        };

        Activity_Log::log( 'probe_front', 'system', 'A donor-triggered event.' );
        set_current_screen( 'dashboard' );
        Activity_Log::log( 'probe_admin', 'system', 'A staff action in wp-admin.' );
        set_current_screen( 'front' );

        $this->assertNull( $ip_for( 'probe_front' ), 'Events outside wp-admin do not store the requester\'s address.' );
        $this->assertSame( '203.0.113.9', $ip_for( 'probe_admin' ) );
    }
}
