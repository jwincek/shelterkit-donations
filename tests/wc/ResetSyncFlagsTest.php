<?php
/**
 * The shared Legacy Order Sync reset behind both screens (#32).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Legacy_Sync\Order_Scanner;
use WP_UnitTestCase;

final class ResetSyncFlagsTest extends WP_UnitTestCase {

	private function synced_order(): int {
		$order = wc_create_order();
		$order->update_meta_data( Order_Scanner::SYNCED_META_KEY, '2026-02-07 15:57:23' );
		$order->update_meta_data( Order_Scanner::RESULTS_META_KEY, [ 1 => [ 'action' => 'created' ] ] );
		$order->save();
		return $order->get_id();
	}

	private function is_synced( int $order_id ): bool {
		wp_cache_flush();
		return '' !== wc_get_order( $order_id )->get_meta( Order_Scanner::SYNCED_META_KEY );
	}

	public function test_reset_all_clears_every_order(): void {
		$a = $this->synced_order();
		$b = $this->synced_order();

		$this->assertSame( 2, Order_Scanner::reset_sync_flags() );
		$this->assertFalse( $this->is_synced( $a ) );
		$this->assertFalse( $this->is_synced( $b ) );
		$this->assertSame( '', wc_get_order( $a )->get_meta( Order_Scanner::RESULTS_META_KEY ) );
	}

	public function test_reset_by_id_skips_negative_ids(): void {
		$a = $this->synced_order();
		$b = $this->synced_order();

		// absint() would turn -$b into $b and reset an order nobody chose.
		$this->assertSame( 1, Order_Scanner::reset_sync_flags( [ $a, -$b, 0 ] ) );
		$this->assertFalse( $this->is_synced( $a ) );
		$this->assertTrue( $this->is_synced( $b ) );
	}
}
