<?php
/**
 * Integration tests for uninstall data handling.
 *
 * Data is preserved by default; only an explicit opt-in purges plugin-owned
 * records, and even then WooCommerce products and the media library survive.
 *
 * (The activity-log table DROP is guarded by an existence check and not
 * created here, so these tests stay within the suite's per-test transaction —
 * DDL would implicitly commit it.)
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/includes/uninstall-cleanup.php';
    }

    public function tear_down(): void {
        delete_option( 'starter_shelter_options' );
        parent::tear_down();
    }

    /** @return array{memorial:int,donor:int,term:int,user:int,attachment:int,post:int} */
    private function seed(): array {
        $memorial = self::factory()->post->create( [ 'post_type' => 'skdon_memorial' ] );
        $donor    = self::factory()->post->create( [ 'post_type' => 'skdon_donor' ] );

        $term = wp_insert_term( 'Spring Drive', 'skdon_campaign' );
        $term_id = is_array( $term ) ? (int) $term['term_id'] : 0;

        $user = self::factory()->user->create();
        update_user_meta( $user, '_skdon_candles', [ $memorial ] );

        update_option( 'skdon_products_created', 1 );
        set_transient( 'skdon_candle_rate_test', time(), 60 );

        // Controls that must survive a purge.
        $attachment = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
        $post       = self::factory()->post->create(); // ordinary post

        return compact( 'memorial', 'donor', 'term_id', 'user', 'attachment', 'post' );
    }

    public function test_opt_in_purges_plugin_data_but_keeps_wc_and_media(): void {
        $s = $this->seed();
        update_option( 'starter_shelter_options', [ 'delete_data_on_uninstall' => true ] );

        starter_shelter_run_uninstall();
        wp_cache_flush(); // Raw $wpdb deletes bypass the object cache.

        // Plugin-owned data is gone.
        $this->assertNull( get_post( $s['memorial'] ) );
        $this->assertNull( get_post( $s['donor'] ) );
        $this->assertSame( 0, (int) ( new \WP_Term_Query() )->query( [ 'taxonomy' => 'skdon_campaign', 'hide_empty' => false, 'count' => true ] ) );
        $this->assertSame( '', get_user_meta( $s['user'], '_skdon_candles', true ) );
        $this->assertFalse( get_option( 'skdon_products_created' ) );
        $this->assertFalse( get_transient( 'skdon_candle_rate_test' ) );

        // Shared / WooCommerce-owned data is preserved.
        $this->assertInstanceOf( \WP_Post::class, get_post( $s['attachment'] ), 'Media library kept.' );
        $this->assertInstanceOf( \WP_Post::class, get_post( $s['post'] ), 'Non-plugin posts kept.' );
    }

    public function test_preserves_everything_without_opt_in(): void {
        $s = $this->seed();
        // No opt-in (option absent / flag off).
        update_option( 'starter_shelter_options', [ 'delete_data_on_uninstall' => false ] );

        starter_shelter_run_uninstall();
        wp_cache_flush();

        $this->assertInstanceOf( \WP_Post::class, get_post( $s['memorial'] ), 'Records preserved by default.' );
        $this->assertSame( [ $s['memorial'] ], array_map( 'intval', get_user_meta( $s['user'], '_skdon_candles', true ) ) );
        $this->assertSame( 1, (int) get_option( 'skdon_products_created' ) );
    }

    /**
     * Purging deletes by name (#30). It used to delete every option and
     * transient matching `sd\_%`, which included other plugins'.
     */
    public function test_purge_leaves_other_plugins_sd_options_alone(): void {
        update_option( 'sd_neighbour_setting', 'keep' );
        set_transient( 'sd_neighbour_cache', 'keep' );
        update_option( 'starter_shelter_options', [ 'delete_data_on_uninstall' => true ] );

        starter_shelter_run_uninstall();
        wp_cache_flush();

        $this->assertSame( 'keep', get_option( 'sd_neighbour_setting' ) );
        $this->assertSame( 'keep', get_transient( 'sd_neighbour_cache' ) );
    }

    /**
     * A site can be uninstalled before it ever loaded the release that moved
     * its data, so the purge also removes the 2.x names.
     */
    public function test_purge_removes_data_still_under_the_old_names(): void {
        $memorial = self::factory()->post->create( [ 'post_type' => 'sd_memorial' ] );
        $user     = self::factory()->user->create();
        update_user_meta( $user, '_sd_candles', [ $memorial ] );
        update_option( 'sd_donation_product_id', 42 );
        update_option( 'woocommerce_sd_donation_receipt_settings', [ 'enabled' => 'no' ] );
        update_option( 'sd_organization', [ 'name' => 'Old Shelter' ] );
        set_transient( 'sd_menu_pending_count', 2 );
        set_transient( 'sd_donor_id_9', 3 );
        update_option( 'starter_shelter_options', [ 'delete_data_on_uninstall' => true ] );

        starter_shelter_run_uninstall();
        wp_cache_flush();

        $this->assertNull( get_post( $memorial ) );
        $this->assertSame( '', get_user_meta( $user, '_sd_candles', true ) );
        $this->assertFalse( get_option( 'sd_donation_product_id' ) );
        $this->assertFalse( get_option( 'woocommerce_sd_donation_receipt_settings' ) );
        $this->assertFalse( get_option( 'sd_organization' ) );
        $this->assertFalse( get_transient( 'sd_menu_pending_count' ) );
        $this->assertFalse( get_transient( 'sd_donor_id_9' ) );
    }
}
