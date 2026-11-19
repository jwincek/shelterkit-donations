<?php
/**
 * WooCommerce write-path tests: cart → order-item meta → record.
 *
 * A gift's details come from its form, through the cart item; there are no
 * checkout fields (#57). An order placed when there were still has its
 * details read from order meta, as a fallback.
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
        $item_id = $order->add_product( wc_get_product( $this->product( 'shelter-donations-in-memoriam', 50 )->get_id() ), 1 );
        $item    = $order->get_item( $item_id );

        // The values ajax_add_to_cart / the form stashed on the cart item.
        $values = [
            'skdon_product_type'    => 'memorial',
            'skdon_honoree_name'    => 'Biscuit',
            'skdon_is_anonymous'    => true,
            'skdon_tribute_message' => 'In loving memory',
            'not_whitelisted'    => 'ignore me',
        ];

        Cart_Handler::save_cart_item_to_order( $item, 'cart_key', $values, $order );
        $item->save();

        // Reload from the order to confirm persistence, not just in-memory.
        $fresh = wc_get_order( $order->get_id() )->get_item( $item_id );

        $this->assertSame( 'Biscuit', $fresh->get_meta( '_skdon_honoree_name' ) );
        $this->assertSame( 'memorial', $fresh->get_meta( '_skdon_product_type' ) );
        $this->assertSame( 'In loving memory', $fresh->get_meta( '_skdon_tribute_message' ) );
        $this->assertNotEmpty( $fresh->get_meta( '_skdon_is_anonymous' ) );
        $this->assertSame( '', $fresh->get_meta( '_not_whitelisted' ), 'Only whitelisted _skdon_* keys are copied.' );
    }

    public function test_cart_write_feeds_order_processing_read(): void {
        // Full channel: cart values → order-item meta (write) → memorial (read).
        // Proves the write keys (_skdon_honoree_name) are exactly what build_input
        // consumes — the seam that has historically broken.
        $order = wc_create_order();
        $order->set_billing_first_name( 'Pat' );
        $order->set_billing_last_name( 'Donor' );
        $order->set_billing_email( 'pat.donor@example.test' );

        $item_id = $order->add_product( wc_get_product( $this->product( 'shelter-donations-in-memoriam', 50 )->get_id() ), 1 );
        $item    = $order->get_item( $item_id );
        $item->add_meta_data( 'in-memoriam-type', 'Pet', true ); // Selected attribute → memorial_type.

        Cart_Handler::save_cart_item_to_order(
            $item,
            'cart_key',
            [ 'skdon_product_type' => 'memorial', 'skdon_honoree_name' => 'Biscuit' ],
            $order
        );
        $item->save();
        $order->calculate_totals();
        $order->save();

        $order->update_status( 'completed' );

        $memorials = get_posts( [
            'post_type'   => 'skdon_memorial',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => '_skdon_wc_order_id',
            'meta_value'  => $order->get_id(),
        ] );

        $this->assertCount( 1, $memorials, 'Memorial created from the cart-written meta.' );
        $this->assertSame( 'Biscuit', get_post_meta( $memorials[0], '_skdon_honoree_name', true ) );
        $this->assertSame( 'pet', get_post_meta( $memorials[0], '_skdon_memorial_type', true ) );
    }

    /** A paid order with one line, whose cart item held $values. */
    private function order_from_cart( string $sku, float $price, array $values, array $order_meta = [], array $item_meta = [] ): \WC_Order {
        $order = wc_create_order();
        $order->set_billing_first_name( 'Pat' );
        $order->set_billing_last_name( 'Donor' );
        $order->set_billing_email( 'pat.donor@example.test' );
        foreach ( $order_meta as $key => $value ) {
            $order->update_meta_data( $key, $value );
        }
        $item_id = $order->add_product( wc_get_product( $this->product( $sku, $price )->get_id() ), 1 );
        $item    = $order->get_item( $item_id );
        foreach ( $item_meta as $key => $value ) {
            $item->add_meta_data( $key, $value, true );
        }
        Cart_Handler::save_cart_item_to_order( $item, 'cart_key', $values, $order );
        $item->save();
        $order->calculate_totals();
        $order->save();
        $order->update_status( 'completed' );
        return $order;
    }

    private function record( string $post_type, \WC_Order $order ): int {
        $ids = get_posts( [
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_key'    => '_skdon_wc_order_id',
            'meta_value'  => $order->get_id(),
        ] );
        $this->assertCount( 1, $ids, "One {$post_type} from the order." );
        return (int) $ids[0];
    }

    public function test_a_donations_dedication_reaches_its_record(): void {
        // The donation form's Dedication line (#57): it used to be a checkout
        // field, which the block checkout never shows.
        $order = $this->order_from_cart( 'shelter-donations', 25, [
            'skdon_product_type' => 'donation',
            'skdon_dedication'   => 'In honor of Rex',
        ] );

        $this->assertSame( 'In honor of Rex', get_post_meta( $this->record( 'skdon_donation', $order ), '_skdon_dedication', true ) );
    }

    public function test_a_pets_species_reaches_its_memorial(): void {
        $order = $this->order_from_cart( 'shelter-donations-in-memoriam', 50, [
            'skdon_product_type' => 'memorial',
            'skdon_honoree_name' => 'Biscuit',
            'skdon_honoree_type' => 'pet',
            'skdon_pet_species'  => 'dog',
        ], [], [ 'in-memoriam-type' => 'Pet' ] ); // The variation chosen for a pet.

        $this->assertSame( 'dog', get_post_meta( $this->record( 'skdon_memorial', $order ), '_skdon_pet_species', true ) );
    }

    public function test_an_order_from_the_old_checkout_fields_is_still_read(): void {
        // Placed through the classic checkout's fields before #57, completed after.
        $order = $this->order_from_cart( 'shelter-donations', 25, [ 'skdon_product_type' => 'donation' ], [
            '_skdon_dedication'   => 'In memory of Max',
            '_skdon_is_anonymous' => '1',
        ] );
        $donation = $this->record( 'skdon_donation', $order );

        $this->assertSame( 'In memory of Max', get_post_meta( $donation, '_skdon_dedication', true ) );
        $this->assertNotEmpty( get_post_meta( $donation, '_skdon_is_anonymous', true ) );
    }
}
