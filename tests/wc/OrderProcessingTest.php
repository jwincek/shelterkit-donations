<?php
/**
 * WooCommerce order → entity processing tests.
 *
 * Exercises the historically bug-prone seam: a paid WC order's line items
 * are mapped (by SKU → Product_Mapper config) into ability input and turned
 * into donation / memorial CPT records. Drives the *real* flow — a status
 * transition to a paid state fires Order_Handler::process_order (and
 * satisfies the create abilities' `internal` permission, which only allows
 * the WC order-status action context). Runs against real WooCommerce, so it
 * catches cart-item-vs-order-meta channel mismatches and meta-key drift the
 * WC-free integration suite can't.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use WP_UnitTestCase;
use Starter_Shelter\WooCommerce\Order_Handler;

final class OrderProcessingTest extends WP_UnitTestCase {

    /**
     * Build a WC order carrying one shelter product line item (unpaid).
     *
     * @param string               $sku       SKU (prefix-matched by Product_Mapper).
     * @param float                $amount    Line price (becomes the gift amount).
     * @param array<string,string> $item_meta Order-item meta (the "form" channel).
     * @return \WC_Order The order (status 'pending'; transition it to fire processing).
     */
    private function make_shelter_order( string $sku, float $amount, array $item_meta = [] ): \WC_Order {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Shelter ' . $sku );
        $product->set_regular_price( (string) $amount );
        $product->set_sku( $sku );
        $product->save();

        $order = wc_create_order();
        $order->set_billing_first_name( 'Pat' );
        $order->set_billing_last_name( 'Donor' );
        $order->set_billing_email( 'pat.donor@example.test' );

        $item_id = $order->add_product( wc_get_product( $product->get_id() ), 1 );
        if ( $item_meta ) {
            $item = $order->get_item( $item_id );
            foreach ( $item_meta as $key => $value ) {
                $item->add_meta_data( $key, $value, true );
            }
            $item->save();
        }
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    /**
     * @param int $order_id
     * @param string $post_type
     * @return int[] IDs of entities linked to the order.
     */
    private function entities_for_order( int $order_id, string $post_type ): array {
        return get_posts( [
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => '_sd_wc_order_id',
            'meta_value'  => $order_id,
        ] );
    }

    public function test_donation_order_creates_a_donation_record(): void {
        $order = $this->make_shelter_order( 'shelterkit-donations', 25.00 );

        $order->update_status( 'completed' ); // Fires the real processing flow.

        $donations = $this->entities_for_order( $order->get_id(), 'sd_donation' );
        $this->assertCount( 1, $donations, 'One donation created from the order.' );

        $id = $donations[0];
        $this->assertSame( 25.0, (float) get_post_meta( $id, '_sd_amount', true ) );
        $this->assertSame( 'general-fund', get_post_meta( $id, '_sd_allocation', true ), 'Allocation falls back to the configured default.' );

        $donor_id = (int) get_post_meta( $id, '_sd_donor_id', true );
        $this->assertGreaterThan( 0, $donor_id, 'Donor resolved from billing.' );
        $this->assertSame( 'pat.donor@example.test', get_post_meta( $donor_id, '_sd_email', true ) );
    }

    public function test_paid_transition_does_not_double_count(): void {
        // processing → completed both fire the processing hook; the _sd_processed
        // guard must keep it to a single donation.
        $order = $this->make_shelter_order( 'shelterkit-donations', 10.00 );

        $order->update_status( 'processing' );
        $order->update_status( 'completed' );

        $this->assertCount( 1, $this->entities_for_order( $order->get_id(), 'sd_donation' ), 'No duplicate across paid transitions.' );
        $this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_sd_processed' ) );
    }

    public function test_memorial_order_uses_the_form_meta_channel(): void {
        // Honoree comes from the cart/order-item meta the memorial form sets —
        // the channel that has historically drifted from what the reader expects.
        $order = $this->make_shelter_order(
            'shelterkit-donations-in-memoriam',
            50.00,
            [
                'in-memoriam-type'  => 'Pet',     // Selected attribute → memorial_type.
                '_sd_honoree_name'  => 'Biscuit', // Form-set item meta.
                '_sd_product_type'  => 'memorial',
            ]
        );

        $order->update_status( 'completed' );

        $memorials = $this->entities_for_order( $order->get_id(), 'sd_memorial' );
        $this->assertCount( 1, $memorials, 'One memorial created from the order.' );
        $this->assertSame( 'Biscuit', get_post_meta( $memorials[0], '_sd_honoree_name', true ) );
        $this->assertSame( 'pet', get_post_meta( $memorials[0], '_sd_memorial_type', true ), 'memorial_type resolved from the attribute (lowercased).' );
    }

    public function test_duplicate_trigger_after_completion_is_a_noop(): void {
        // A late/duplicate trigger (e.g. a repeated gateway webhook) after the
        // order already processed must not create a second donation.
        $order = $this->make_shelter_order( 'shelterkit-donations', 20.00 );
        $order->update_status( 'completed' );
        $this->assertCount( 1, $this->entities_for_order( $order->get_id(), 'sd_donation' ) );

        Order_Handler::process_order( $order->get_id() );

        $this->assertCount( 1, $this->entities_for_order( $order->get_id(), 'sd_donation' ), 'No duplicate from a repeat trigger.' );
    }

    public function test_processed_flag_is_read_fresh_from_the_database(): void {
        // The in-lock re-check reads the flag straight from the DB (HPOS-aware),
        // so a value written by a concurrent run is seen even if the order
        // object cache is stale. Exercise that reader directly.
        $order  = $this->make_shelter_order( 'shelterkit-donations', 5.00 );
        $method = new \ReflectionMethod( Order_Handler::class, 'is_processed_in_db' );
        $method->setAccessible( true );

        $this->assertFalse( $method->invoke( null, $order->get_id() ), 'Not processed yet.' );

        $order->update_meta_data( '_sd_processed', current_time( 'mysql' ) );
        $order->save();

        $this->assertTrue( $method->invoke( null, $order->get_id() ), 'Reads the just-written flag from the DB.' );
    }

    public function test_memorial_without_honoree_is_rejected_and_creates_no_record(): void {
        $order = $this->make_shelter_order( 'shelterkit-donations-in-memoriam', 50.00 );

        $order->update_status( 'completed' );

        $this->assertCount( 0, $this->entities_for_order( $order->get_id(), 'sd_memorial' ), 'No memorial when the honoree is missing.' );

        // The error is recorded on the order for admin review/reprocessing.
        $results = wc_get_order( $order->get_id() )->get_meta( '_sd_processing_results' );
        $this->assertNotEmpty( $results );
        $this->assertTrue(
            (bool) array_filter( (array) $results, static fn ( $r ) => is_wp_error( $r ) ),
            'A WP_Error result is stored for the rejected item.'
        );
    }
}
