<?php
/**
 * The sd_ → skdon_ storage move (#30), WooCommerce side: order meta (HPOS
 * or posts storage, whichever the suite runs), order-item meta, and carts
 * saved in a session before the upgrade.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Core\Prefix_Migration;
use WP_UnitTestCase;

final class PrefixMigrationWcTest extends WP_UnitTestCase {

	public function test_order_and_order_item_meta_move(): void {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '25' );
		$product->save();

		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->update_meta_data( '_sd_processed', '2026-01-02 03:04:05' );
		$order->update_meta_data( '_sd_honoree_name', 'Biscuit' );
		$order->save();

		$item = $order->get_item( $item_id );
		$item->add_meta_data( '_sd_custom_price', '25', true );
		$item->add_meta_data( '_sd_allocation', 'facility', true );
		$item->add_meta_data( '_sd_notify_family', '1', true );
		$item->save();

		Prefix_Migration::run();

		// Check the row itself, in whichever table this suite stores orders.
		global $wpdb;
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$this->assertSame(
			'2026-01-02 03:04:05',
			$hpos
				? $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = '_skdon_processed'", $order->get_id() ) )
				: $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_skdon_processed'", $order->get_id() ) ),
			$hpos ? 'HPOS order meta.' : 'Posts-storage order meta.'
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( '2026-01-02 03:04:05', $fresh->get_meta( '_skdon_processed' ) );
		$this->assertSame( 'Biscuit', $fresh->get_meta( '_skdon_honoree_name' ) );
		$this->assertSame( '', $fresh->get_meta( '_sd_processed' ) );

		$fresh_item = $fresh->get_item( $item_id );
		$this->assertSame( '25', $fresh_item->get_meta( '_skdon_custom_price' ) );
		$this->assertSame( 'facility', $fresh_item->get_meta( '_skdon_allocation' ) );
		$this->assertSame( '1', $fresh_item->get_meta( '_skdon_notify_family' ), 'The order-item flag is a different key from the memorial array, and moves.' );

		$this->assertSame( [], Prefix_Migration::leftovers() );
	}

	/**
	 * The suite stores orders as posts, so the test above never reaches the
	 * HPOS meta table that most live stores use. Exercise it directly.
	 */
	public function test_hpos_order_meta_rows_move(): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'wc_orders_meta';
		$suppress = $wpdb->suppress_errors( true );
		$exists   = false !== $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" );
		$wpdb->suppress_errors( $suppress );
		if ( ! $exists ) {
			// WooCommerce skips its HPOS tables in this install. A stand-in with
			// the columns the migration uses; the suite makes it TEMPORARY.
			$wpdb->query( "CREATE TABLE `{$table}` ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned DEFAULT NULL, meta_key varchar(255) DEFAULT NULL, meta_value text, PRIMARY KEY (id) )" );
		}

		$wpdb->insert( $table, [ 'order_id' => 987654, 'meta_key' => '_sd_legacy_synced', 'meta_value' => 'yes' ] );
		$wpdb->insert( $table, [ 'order_id' => 987654, 'meta_key' => '_skdon_processed', 'meta_value' => 'new' ] );
		$wpdb->insert( $table, [ 'order_id' => 987654, 'meta_key' => '_sd_processed', 'meta_value' => 'old' ] );

		Prefix_Migration::run();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM `{$table}` WHERE order_id = %d ORDER BY meta_key", 987654 ), ARRAY_A );
		$this->assertSame(
			[
				[ 'meta_key' => '_skdon_legacy_synced', 'meta_value' => 'yes' ],
				[ 'meta_key' => '_skdon_processed', 'meta_value' => 'new' ],
			],
			$rows,
			'Moved, and where the new key already existed it was kept and the old row dropped.'
		);
	}

	public function test_a_cart_saved_before_the_upgrade_keeps_its_item_data(): void {
		$restored = Prefix_Migration::restore_session_cart_item(
			[ 'product_id' => 7, 'skdon_allocation' => 'already-new' ],
			[ 'product_id' => 7, 'sd_custom_price' => 40.0, 'sd_allocation' => 'facility', 'sd_honoree_name' => 'Rex', 'unrelated' => 'x' ]
		);

		$this->assertSame( 40.0, $restored['skdon_custom_price'] );
		$this->assertSame( 'Rex', $restored['skdon_honoree_name'] );
		$this->assertSame( 'already-new', $restored['skdon_allocation'], 'A value under the new key wins.' );
		$this->assertArrayNotHasKey( 'skdon_unrelated', $restored );
	}

	public function test_the_session_shim_is_hooked(): void {
		$this->assertSame(
			5,
			has_filter( 'woocommerce_get_cart_item_from_session', [ Prefix_Migration::class, 'restore_session_cart_item' ] )
		);
	}
}
