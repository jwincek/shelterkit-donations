<?php
/**
 * Refunds and cancellations reach the gift records (#34).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Legacy_Sync\Order_Processor;
use Starter_Shelter\WooCommerce\Refund_Handler;
use WP_UnitTestCase;

final class RefundsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function product( string $sku, float $price ): \WC_Product {
		$existing = wc_get_product( wc_get_product_id_by_sku( $sku ) );
		if ( $existing ) {
			return $existing;
		}
		$product = new \WC_Product_Simple();
		$product->set_name( 'Product ' . $sku );
		$product->set_regular_price( (string) $price );
		$product->set_sku( $sku );
		$product->save();
		return $product;
	}

	/**
	 * A paid order, recorded the way checkout records it.
	 *
	 * @param array<int, array{0: string, 1: float}> $lines SKU and amount.
	 */
	private function paid_order( array $lines ): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Donor' );
		$order->set_billing_email( 'pat.donor@example.test' );
		foreach ( $lines as [ $sku, $amount ] ) {
			$order->add_product( $this->product( $sku, $amount ), 1, [ 'subtotal' => $amount, 'total' => $amount ] );
		}
		$order->calculate_totals( false );
		$order->save();
		$order->update_status( 'completed' );
		return wc_get_order( $order->get_id() );
	}

	/** @return int[] The order's records, by line order. */
	private function records( \WC_Order $order ): array {
		return Refund_Handler::records_for_order( $order->get_id() );
	}

	private function line_id( \WC_Order $order, int $index ): int {
		return (int) array_keys( $order->get_items() )[ $index ];
	}

	private function refund( \WC_Order $order, float $amount, array $line_items = [] ): \WC_Order_Refund {
		$refund = wc_create_refund( [
			'order_id'   => $order->get_id(),
			'amount'     => $amount,
			'line_items' => $line_items,
		] );
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		return $refund;
	}

	private function amount( int $record ): float {
		return (float) get_post_meta( $record, '_skdon_amount', true );
	}

	private function lifetime( int $record ): float {
		return (float) get_post_meta( (int) get_post_meta( $record, '_skdon_donor_id', true ), '_skdon_lifetime_giving', true );
	}

	private function donations_total(): float {
		return (float) \Starter_Shelter\Abilities\Reports\dashboard_stats( [ 'period' => 'year' ] )['donations']['total'];
	}

	public function test_refunding_a_whole_line_marks_its_gift_refunded_and_out_of_every_total(): void {
		$order = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );
		[ $first, $second ] = $this->records( $order );
		$this->assertSame( 75.0, $this->lifetime( $first ) );
		$before = $this->donations_total();

		$this->refund( $order, 25, [ $this->line_id( $order, 0 ) => [ 'qty' => 1, 'refund_total' => 25 ] ] );

		$this->assertSame( Refund_Handler::STATUS, get_post_status( $first ) );
		$this->assertSame( 25.0, $this->amount( $first ), 'A refunded gift keeps its amount for the history.' );
		$this->assertSame( 'publish', get_post_status( $second ) );
		$this->assertSame( 50.0, $this->amount( $second ) );
		$this->assertSame( 50.0, $this->lifetime( $first ), 'Lifetime giving leaves the refunded gift out.' );
		$this->assertSame( $before - 25, $this->donations_total(), 'Reports leave it out.' );

		$summary = \Starter_Shelter\Abilities\Reports\annual_summary( [ 'donor_id' => (int) get_post_meta( $first, '_skdon_donor_id', true ) ] );
		$this->assertSame( 50.0, (float) $summary['grand_total'], 'The giving statement leaves it out.' );
		$this->assertSame( 'wc-completed', 'wc-' . wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_refunding_part_of_a_line_counts_the_gift_at_what_the_shelter_kept(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );

		$this->refund( $order, 20, [ $this->line_id( $order, 0 ) => [ 'qty' => 0, 'refund_total' => 20 ] ] );

		$this->assertSame( 'publish', get_post_status( $gift ) );
		$this->assertSame( 30.0, $this->amount( $gift ) );
		$this->assertSame( 50.0, (float) get_post_meta( $gift, Refund_Handler::ORIGINAL_AMOUNT_META, true ) );
		$this->assertSame( 20.0, (float) get_post_meta( $gift, Refund_Handler::REFUNDED_META, true ) );
		$this->assertSame( 30.0, $this->lifetime( $gift ) );

		// A second partial refund adds to the first.
		$this->refund( $order, 5, [ $this->line_id( $order, 0 ) => [ 'qty' => 0, 'refund_total' => 5 ] ] );
		$this->assertSame( 25.0, $this->amount( $gift ) );
	}

	public function test_a_refund_naming_no_lines_on_a_one_line_order_is_applied(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );

		$this->refund( $order, 10 );

		$this->assertSame( 40.0, $this->amount( $gift ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Refund_Handler::REVIEW_META ) );
	}

	public function test_a_refund_naming_no_lines_on_a_two_gift_order_is_flagged_not_guessed(): void {
		$order = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );
		[ $first, $second ] = $this->records( $order );

		$refund = $this->refund( $order, 10 );

		$this->assertSame( 25.0, $this->amount( $first ) );
		$this->assertSame( 50.0, $this->amount( $second ) );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( [ $refund->get_id() ], $fresh->get_meta( Refund_Handler::REVIEW_META ) );
		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'isn\'t clear which gift', $notes );
		$types = wp_list_pluck( \Starter_Shelter\Abilities\Reports\action_items()['items'], 'type' );
		$this->assertContains( 'refunds_to_review', $types );

		// The admin says which gift it returned.
		$result = Refund_Handler::assign_refund( $fresh, $refund->get_id(), $second );

		$this->assertSame( [ $second ], $result['changed'] );
		$this->assertSame( 40.0, $this->amount( $second ) );
		$this->assertSame( 25.0, $this->amount( $first ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Refund_Handler::REVIEW_META ) );
	}

	public function test_a_refund_assigned_to_no_gift_leaves_the_gifts_alone(): void {
		$order  = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );
		$refund = $this->refund( $order, 10 );

		Refund_Handler::assign_refund( wc_get_order( $order->get_id() ), $refund->get_id(), 0 );

		[ $first, $second ] = $this->records( $order );
		$this->assertSame( 25.0, $this->amount( $first ) );
		$this->assertSame( 50.0, $this->amount( $second ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Refund_Handler::REVIEW_META ) );
	}

	public function test_assigning_rejects_a_gift_or_refund_from_another_order(): void {
		$order  = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );
		$other  = $this->paid_order( [ [ 'shelter-donations', 30 ] ] );
		$refund = $this->refund( $order, 10 );

		$this->assertWPError( Refund_Handler::assign_refund( wc_get_order( $order->get_id() ), $refund->get_id(), $this->records( $other )[0] ) );
		$this->assertWPError( Refund_Handler::assign_refund( wc_get_order( $other->get_id() ), $refund->get_id(), 0 ) );
	}

	public function test_a_full_refund_marks_every_gift_refunded(): void {
		$order = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );

		$this->refund( $order, 75 );

		foreach ( $this->records( $order ) as $gift ) {
			$this->assertSame( Refund_Handler::STATUS, get_post_status( $gift ) );
		}
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Refund_Handler::REVIEW_META ), 'Nothing left to attribute.' );
	}

	public function test_cancelling_a_paid_order_refunds_its_gifts_and_completing_it_again_restores_them(): void {
		$order = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );
		$gifts = $this->records( $order );

		$order->update_status( 'cancelled' );
		foreach ( $gifts as $gift ) {
			$this->assertSame( Refund_Handler::STATUS, get_post_status( $gift ) );
		}
		$this->assertSame( 0.0, $this->lifetime( $gifts[0] ) );

		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$this->assertSame( $gifts, $this->records( $order ), 'Completing it again records nothing twice.' );
		foreach ( $gifts as $gift ) {
			$this->assertSame( 'publish', get_post_status( $gift ) );
			$this->assertFalse( metadata_exists( 'post', $gift, Refund_Handler::REFUNDS_META ) );
		}
		$this->assertSame( 75.0, $this->lifetime( $gifts[0] ) );
	}

	public function test_deleting_a_refund_undoes_it(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );
		$refund   = $this->refund( $order, 50, [ $this->line_id( $order, 0 ) => [ 'qty' => 1, 'refund_total' => 50 ] ] );
		$this->assertSame( Refund_Handler::STATUS, get_post_status( $gift ) );

		// What the order screen's delete-refund button does.
		$refund_id = $refund->get_id();
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );
		// The order itself stays Refunded until the admin changes it.
		$this->assertSame( Refund_Handler::STATUS, get_post_status( $gift ) );

		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$this->assertSame( 'publish', get_post_status( $gift ) );
		$this->assertSame( 50.0, $this->amount( $gift ) );
		$this->assertFalse( metadata_exists( 'post', $gift, Refund_Handler::ORIGINAL_AMOUNT_META ) );
	}

	public function test_deleting_a_partial_refund_restores_the_full_amount(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );
		$refund   = $this->refund( $order, 20, [ $this->line_id( $order, 0 ) => [ 'qty' => 0, 'refund_total' => 20 ] ] );
		$this->assertSame( 30.0, $this->amount( $gift ) );

		$refund_id = $refund->get_id();
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );

		$this->assertSame( 50.0, $this->amount( $gift ) );
		$this->assertSame( 50.0, $this->lifetime( $gift ) );
	}

	public function test_refunds_made_before_this_release_are_applied_by_reconcile_all(): void {
		remove_action( 'woocommerce_order_refunded', [ Refund_Handler::class, 'sync_order' ] );
		remove_action( 'woocommerce_order_status_changed', [ Refund_Handler::class, 'sync_order' ] );
		try {
			$partial = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
			$this->refund( $partial, 20, [ $this->line_id( $partial, 0 ) => [ 'qty' => 0, 'refund_total' => 20 ] ] );
			$cancelled = $this->paid_order( [ [ 'shelter-donations', 30 ] ] );
			$cancelled->update_status( 'cancelled' );
			$untouched = $this->paid_order( [ [ 'shelter-donations', 10 ] ] );
		} finally {
			Refund_Handler::init();
		}
		$this->assertSame( 50.0, $this->amount( $this->records( $partial )[0] ), 'Before: the refund never reached the gift.' );

		$result = Refund_Handler::reconcile_all();

		$this->assertSame( 2, $result['orders'] );
		$this->assertSame( 2, $result['changed'] );
		$this->assertSame( 30.0, $this->amount( $this->records( $partial )[0] ) );
		$this->assertSame( Refund_Handler::STATUS, get_post_status( $this->records( $cancelled )[0] ) );
		$this->assertSame( 'publish', get_post_status( $this->records( $untouched )[0] ) );
		$this->assertSame( 0, Refund_Handler::reconcile_all()['changed'], 'Running it again changes nothing.' );
	}

	public function test_saving_a_refunded_record_in_the_editor_does_not_publish_it(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );
		$this->refund( $order, 50 );

		wp_update_post( [ 'ID' => $gift, 'post_status' => 'publish', 'post_title' => 'Edited' ] );

		$this->assertSame( Refund_Handler::STATUS, get_post_status( $gift ) );
	}

	public function test_refunded_records_are_listed_under_all_in_the_admin(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );
		$this->refund( $order, 50 );

		// The list screen's query: no status given, in the admin.
		set_current_screen( 'edit-skdon_donation' );
		$listed = ( new \WP_Query( [ 'post_type' => 'skdon_donation', 'fields' => 'ids', 'posts_per_page' => -1 ] ) )->posts;
		set_current_screen( 'front' );

		$this->assertContains( $gift, array_map( 'intval', $listed ), '"All (n)" counted it; the list must show it too.' );
	}

	public function test_legacy_sync_finds_a_refunded_record_and_leaves_its_amount(): void {
		$order = $this->paid_order( [ [ 'shelter-donations', 25 ], [ 'shelter-donations-x', 50 ] ] );
		[ $first, $second ] = $this->records( $order );
		$this->refund( $order, 25, [ $this->line_id( $order, 0 ) => [ 'qty' => 1, 'refund_total' => 25 ] ] );
		$this->refund( $order, 10, [ $this->line_id( $order, 1 ) => [ 'qty' => 0, 'refund_total' => 10 ] ] );

		Order_Processor::sync( $order->get_id(), false, true );

		$this->assertSame( [ $first, $second ], $this->records( $order ), 'A refunded gift is still its line\'s record, not a missing one.' );
		$this->assertSame( Refund_Handler::STATUS, get_post_status( $first ) );
		$this->assertSame( 40.0, $this->amount( $second ), 'The line\'s full amount does not overwrite the net one.' );
	}

	public function test_an_order_with_no_gifts_is_left_alone(): void {
		$order = wc_create_order();
		$order->add_product( $this->product( 'tote-bag', 15 ), 1 );
		$order->calculate_totals( false );
		$order->save();
		$order->update_status( 'completed' );

		$this->refund( wc_get_order( $order->get_id() ), 15 );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( '', $fresh->get_meta( Refund_Handler::REVIEW_META ) );
		$this->assertSame( 0, did_action( 'starter_shelter_refunds_changed' ) );
	}

	public function test_each_change_is_announced_once(): void {
		$order    = $this->paid_order( [ [ 'shelter-donations', 50 ] ] );
		[ $gift ] = $this->records( $order );
		$seen     = [];
		add_action( 'starter_shelter_gift_refunded', static function ( $record, $order_id, $refunded, $original ) use ( &$seen ) {
			$seen[] = [ $record, $refunded, $original ];
		}, 10, 4 );

		// A full refund fires the order's status change and the refund hook.
		$this->refund( $order, 50 );

		$this->assertSame( [ [ $gift, 50.0, 50.0 ] ], $seen );
	}
}
