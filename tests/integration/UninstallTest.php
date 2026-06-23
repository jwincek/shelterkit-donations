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
        $memorial = self::factory()->post->create( [ 'post_type' => 'sd_memorial' ] );
        $donor    = self::factory()->post->create( [ 'post_type' => 'sd_donor' ] );

        $term = wp_insert_term( 'Spring Drive', 'sd_campaign' );
        $term_id = is_array( $term ) ? (int) $term['term_id'] : 0;

        $user = self::factory()->user->create();
        update_user_meta( $user, '_sd_candles', [ $memorial ] );

        update_option( 'sd_products_created', 1 );
        set_transient( 'sd_candle_rate_test', time(), 60 );

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
        $this->assertSame( 0, (int) ( new \WP_Term_Query() )->query( [ 'taxonomy' => 'sd_campaign', 'hide_empty' => false, 'count' => true ] ) );
        $this->assertSame( '', get_user_meta( $s['user'], '_sd_candles', true ) );
        $this->assertFalse( get_option( 'sd_products_created' ) );
        $this->assertFalse( get_transient( 'sd_candle_rate_test' ) );

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
        $this->assertSame( [ $s['memorial'] ], array_map( 'intval', get_user_meta( $s['user'], '_sd_candles', true ) ) );
        $this->assertSame( 1, (int) get_option( 'sd_products_created' ) );
    }
}
