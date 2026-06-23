<?php
/**
 * Smoke tests for the WooCommerce-enabled test lane.
 *
 * Confirms WooCommerce is loaded and installed, our plugin is loaded
 * alongside it, and WC product/order factories work — the foundation the
 * order-processing tests build on.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use WP_UnitTestCase;

final class WcBootstrapTest extends WP_UnitTestCase {

    public function test_woocommerce_is_loaded(): void {
        $this->assertTrue( function_exists( 'WC' ), 'WC() bootstrap function present.' );
        $this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce class present.' );
        $this->assertNotEmpty( WC()->version );
    }

    public function test_wc_tables_are_installed(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_order_stats';
        $this->assertSame(
            $table,
            $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ), // phpcs:ignore WordPress.DB
            'WC_Install::install() created its tables.'
        );
    }

    public function test_plugin_is_loaded_alongside_wc(): void {
        $this->assertTrue( class_exists( 'Starter_Shelter\\WooCommerce\\Order_Handler' ) );
        $this->assertTrue( class_exists( 'Starter_Shelter\\WooCommerce\\Product_Mapper' ) );
    }

    public function test_can_create_product_and_order(): void {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Smoke Test Product' );
        $product->set_regular_price( '10' );
        $product->set_sku( 'smoke-test-sku' );
        $product->save();
        $this->assertGreaterThan( 0, $product->get_id() );

        $order = wc_create_order();
        $order->add_product( wc_get_product( $product->get_id() ), 1 );
        $order->calculate_totals();
        $order->save();

        $this->assertGreaterThan( 0, $order->get_id() );
        $this->assertCount( 1, $order->get_items() );
    }
}
