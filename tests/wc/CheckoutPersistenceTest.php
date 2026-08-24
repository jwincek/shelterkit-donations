<?php
/**
 * WooCommerce write-path tests: cart → order-item meta, and checkout fields
 * → order meta.
 *
 * OrderProcessingTest covers the *read* half (order meta → entity). This
 * covers the *write* half that feeds it — the cart-item-vs-order-meta channel
 * that has historically drifted — plus a full round trip proving the keys the
 * cart writes are exactly the keys order processing reads.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use WP_UnitTestCase;
use Starter_Shelter\WooCommerce\Cart_Handler;
use Starter_Shelter\WooCommerce\Checkout_Fields;

final class CheckoutPersistenceTest extends WP_UnitTestCase {

    public function tear_down(): void {
        $_POST = [];
        parent::tear_down();
    }

    private function product( string $sku, float $price ): \WC_Product_Simple {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Product ' . $sku );
        $product->set_regular_price( (string) $price );
        $product->set_sku( $sku );
        $product->save();
        return $product;
    }

    public function test_cart_item_values_are_written_to_order_item_meta(): void {
        $order   = wc_create_order();
        $item_id = $order->add_product( wc_get_product( $this->product( 'shelterkit-donations-in-memoriam', 50 )->get_id() ), 1 );
        $item    = $order->get_item( $item_id );

        // The values ajax_add_to_cart / the form stashed on the cart item.
        $values = [
            'sd_product_type'    => 'memorial',
            'sd_honoree_name'    => 'Biscuit',
            'sd_is_anonymous'    => true,
            'sd_tribute_message' => 'In loving memory',
            'not_whitelisted'    => 'ignore me',
        ];

        Cart_Handler::save_cart_item_to_order( $item, 'cart_key', $values, $order );
        $item->save();

        // Reload from the order to confirm persistence, not just in-memory.
        $fresh = wc_get_order( $order->get_id() )->get_item( $item_id );

        $this->assertSame( 'Biscuit', $fresh->get_meta( '_sd_honoree_name' ) );
        $this->assertSame( 'memorial', $fresh->get_meta( '_sd_product_type' ) );
        $this->assertSame( 'In loving memory', $fresh->get_meta( '_sd_tribute_message' ) );
        $this->assertNotEmpty( $fresh->get_meta( '_sd_is_anonymous' ) );
        $this->assertSame( '', $fresh->get_meta( '_not_whitelisted' ), 'Only whitelisted sd_* keys are copied.' );
    }

    public function test_cart_write_feeds_order_processing_read(): void {
        // Full channel: cart values → order-item meta (write) → memorial (read).
        // Proves the write keys (_sd_honoree_name) are exactly what build_input
        // consumes — the seam that has historically broken.
        $order = wc_create_order();
        $order->set_billing_first_name( 'Pat' );
        $order->set_billing_last_name( 'Donor' );
        $order->set_billing_email( 'pat.donor@example.test' );

        $item_id = $order->add_product( wc_get_product( $this->product( 'shelterkit-donations-in-memoriam', 50 )->get_id() ), 1 );
        $item    = $order->get_item( $item_id );
        $item->add_meta_data( 'in-memoriam-type', 'Pet', true ); // Selected attribute → memorial_type.

        Cart_Handler::save_cart_item_to_order(
            $item,
            'cart_key',
            [ 'sd_product_type' => 'memorial', 'sd_honoree_name' => 'Biscuit' ],
            $order
        );
        $item->save();
        $order->calculate_totals();
        $order->save();

        $order->update_status( 'completed' );

        $memorials = get_posts( [
            'post_type'   => 'sd_memorial',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => '_sd_wc_order_id',
            'meta_value'  => $order->get_id(),
        ] );

        $this->assertCount( 1, $memorials, 'Memorial created from the cart-written meta.' );
        $this->assertSame( 'Biscuit', get_post_meta( $memorials[0], '_sd_honoree_name', true ) );
        $this->assertSame( 'pet', get_post_meta( $memorials[0], '_sd_memorial_type', true ) );
    }

    public function test_checkout_fields_are_saved_to_order_meta(): void {
        $order = wc_create_order();
        $order->save();

        $_POST['sd_dedication']          = 'In honor of Rex';
        $_POST['sd_is_anonymous']        = '1';                       // checkbox
        $_POST['sd_notify_family_email'] = 'family@example.test';      // email
        $_POST['sd_tribute_message']     = '<b>Loved</b> always';      // textarea (tags stripped)

        Checkout_Fields::save_checkout_fields( $order->get_id() );

        $fresh = wc_get_order( $order->get_id() );

        $this->assertSame( 'In honor of Rex', $fresh->get_meta( '_sd_dedication' ) );
        $this->assertNotEmpty( $fresh->get_meta( '_sd_is_anonymous' ), 'Checkbox saved truthy.' );
        $this->assertSame( 'family@example.test', $fresh->get_meta( '_sd_notify_family_email' ) );
        $this->assertStringNotContainsString( '<b>', (string) $fresh->get_meta( '_sd_tribute_message' ), 'Textarea is sanitized.' );
    }

    public function test_checkout_fields_absent_from_post_are_not_written(): void {
        $order = wc_create_order();
        $order->save();

        $_POST['sd_dedication'] = 'Only this one';

        Checkout_Fields::save_checkout_fields( $order->get_id() );

        $fresh = wc_get_order( $order->get_id() );
        $this->assertSame( 'Only this one', $fresh->get_meta( '_sd_dedication' ) );
        $this->assertSame( '', $fresh->get_meta( '_sd_honoree_name' ), 'Unposted fields are left unset.' );
    }
}
