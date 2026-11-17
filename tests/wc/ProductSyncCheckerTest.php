<?php
/**
 * The Product Sync check reports what is actually wrong (#23).
 *
 * It used to test "virtual" on the variable parent, which WooCommerce never
 * treats as virtual, so every site carried four warnings that Repair could not
 * clear. Meanwhile a SKU that stopped the plugin recognising its own orders
 * was just one more warning among them (#16).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Product_Sync_Checker;
use Starter_Shelter\Core\Config;
use WP_UnitTestCase;

final class ProductSyncCheckerTest extends WP_UnitTestCase {

    public function tear_down(): void {
        delete_option( 'sd_donation_product_id' );
        Config::delete_override( 'settings', 'allocations' );
        parent::tear_down();
    }

    public function test_virtual_variations_raise_no_virtual_warning(): void {
        $this->donation_product( 'shelter-donations', [ true, true ] );

        $this->assertSame( [], $this->issues( 'virtual' ) );
    }

    public function test_a_variation_that_is_not_virtual_is_reported_and_repaired(): void {
        $product_id = $this->donation_product( 'shelter-donations', [ true, false ] );

        $issues = $this->issues( 'virtual' );
        $this->assertCount( 1, $issues );
        $this->assertSame( 'warning', $issues[0]['severity'] );
        $this->assertStringContainsString( '1 of 2 variations', $issues[0]['message'] );

        Product_Sync_Checker::repair();

        foreach ( wc_get_product( $product_id )->get_children() as $id ) {
            $this->assertTrue( wc_get_product( $id )->is_virtual() );
        }
        $this->assertSame( [], $this->issues( 'virtual' ), 'Cleared by Repair, which it never could be before.' );
    }

    public function test_a_sku_that_no_longer_resolves_is_an_error_and_repair_restores_it(): void {
        // As a fresh install of the 3.0.0 tag created it.
        $product_id = $this->donation_product( 'shelterkit-donations', [ true ] );

        $issues = $this->issues( 'SKU' );
        $this->assertCount( 1, $issues );
        $this->assertSame( 'error', $issues[0]['severity'], 'Its orders are being skipped; that is not a warning.' );
        $this->assertStringContainsString( 'not being recorded', $issues[0]['message'] );

        Product_Sync_Checker::repair();

        $this->assertSame( 'shelter-donations', wc_get_product( $product_id )->get_sku() );
        $this->assertSame( [], $this->issues( 'SKU' ) );
    }

    public function test_a_sku_that_extends_the_prefix_still_resolves(): void {
        $this->donation_product( 'shelter-donations-2024', [ true ] );

        $this->assertSame( [], $this->issues( 'SKU' ) );
    }

    public function test_repair_says_when_another_product_already_has_the_sku(): void {
        $this->donation_product( 'shelterkit-donations', [ true ] );
        $other = new \WC_Product_Simple();
        $other->set_name( 'Something else' );
        $other->set_sku( 'shelter-donations' );
        $other->save();

        $details = Product_Sync_Checker::repair()['details'];

        $this->assertNotEmpty( preg_grep( '/shelter-donations: could not set the SKU/', $details ) );
    }

    /**
     * Issues for the donation product whose message mentions $topic.
     *
     * @param string $topic Word to look for.
     * @return array<int, array{severity: string, message: string}>
     */
    private function issues( string $topic ): array {
        Product_Sync_Checker::invalidate_cache();

        return array_values(
            array_filter(
                Product_Sync_Checker::check()['issues'],
                static fn( $i ) => 'shelter-donations' === $i['context'] && false !== stripos( $i['message'], $topic )
            )
        );
    }

    /**
     * @param string $sku     SKU for the parent.
     * @param bool[] $virtual One entry per variation: whether it is virtual.
     * @return int Product ID, stored where the plugin looks for it.
     */
    private function donation_product( string $sku, array $virtual ): int {
        $labels = array_slice( [ 'General Fund', 'Medical Care', 'Food & Supplies' ], 0, count( $virtual ) );
        Config::save_override( 'settings', 'allocations', array_combine( array_map( 'sanitize_title', $labels ), $labels ) );

        $attribute = new \WC_Product_Attribute();
        $attribute->set_name( 'Preferred Allocation' );
        $attribute->set_options( $labels );
        $attribute->set_visible( true );
        $attribute->set_variation( true );

        $product = new \WC_Product_Variable();
        $product->set_name( 'Shelter Donations' );
        $product->set_sku( $sku );
        $product->set_attributes( [ $attribute ] );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_tax_status( 'none' );
        $product_id = $product->save();

        foreach ( $labels as $i => $label ) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_regular_price( '10' );
            $variation->set_virtual( $virtual[ $i ] );
            $variation->set_attributes( [ 'preferred-allocation' => $label ] );
            $variation->save();
        }

        update_option( 'sd_donation_product_id', $product_id );

        return $product_id;
    }
}
