<?php
/**
 * A feature switched off under Settings → General is off at checkout and in
 * the cart, not only on the forms (#49).
 *
 * The forms just hide a choice; a request made by hand, or an old link with
 * sd_anonymous in it, would still set it. So the cart and the checkout check
 * the switch too. An order placed while a feature was on keeps showing its
 * value.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\WooCommerce\Cart_Handler;
use Starter_Shelter\WooCommerce\Checkout_Fields;
use WP_UnitTestCase;

final class FeatureSwitchesCheckoutTest extends WP_UnitTestCase {

	private const OPTION = 'starter_shelter_options';

	public function set_up(): void {
		parent::set_up();
		delete_option( self::OPTION );
	}

	public function tear_down(): void {
		$_POST    = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	private function switch_off(): void {
		update_option( self::OPTION, [
			'feature_anonymous_donations'  => false,
			'feature_dedications'          => false,
			'feature_family_notifications' => false,
		] );
	}

	/** What the memorial form posts with every choice ticked. */
	private function cart_item( string $type ): array {
		$build = new \ReflectionMethod( Cart_Handler::class, 'build_cart_item_data' );
		$build->setAccessible( true );
		return $build->invoke( null, [
			'amount'                => 25,
			'is_anonymous'          => '1',
			'dedication_enabled'    => '1',
			'honoree_name'          => 'Rex',
			'notify_family_enabled' => '1',
			'notify_family_name'    => 'The Smiths',
			'notify_family_email'   => 'family@example.test',
		], $type );
	}

	public function test_the_cart_takes_only_what_the_site_offers(): void {
		$on = $this->cart_item( 'memorial' );
		$this->assertTrue( $on['skdon_is_anonymous'] );
		$this->assertTrue( $on['skdon_notify_family_enabled'] );

		$this->switch_off();
		$off = $this->cart_item( 'memorial' );

		$this->assertArrayNotHasKey( 'skdon_is_anonymous', $off );
		$this->assertArrayNotHasKey( 'skdon_notify_family_enabled', $off );
		$this->assertArrayNotHasKey( 'skdon_notify_family_email', $off );
		$this->assertSame( 'Rex', $off['skdon_honoree_name'], 'The tribute itself is unaffected.' );

		// The add-to-cart link form: ?add-to-cart=…&sd_anonymous=1.
		$_REQUEST['sd_anonymous'] = '1';
		$this->assertArrayNotHasKey( 'skdon_is_anonymous', Cart_Handler::add_cart_item_data( [], 1, 0 ) );
	}

	public function test_checkout_offers_and_saves_only_what_the_site_offers(): void {
		$fields = new \ReflectionMethod( Checkout_Fields::class, 'get_fields_for_product_types' );
		$fields->setAccessible( true );
		$offered = static fn() => array_keys( $fields->invoke( null, [ 'donation', 'memorial' ] ) );

		$this->assertContains( 'is_anonymous', $offered() );
		$this->assertContains( 'dedication', $offered() );
		$this->assertContains( 'notify_family_enabled', $offered() );

		$this->switch_off();
		$this->assertSame( [], array_values( array_intersect( [ 'is_anonymous', 'dedication', 'notify_family_enabled', 'notify_family_email' ], $offered() ) ) );
		$this->assertContains( 'honoree_name', $offered(), 'Other fields are still offered.' );

		// Posted anyway: not saved.
		$order = wc_create_order();
		$order->save();
		$_POST['sd_is_anonymous']        = '1';
		$_POST['sd_dedication']          = 'In honor of Rex';
		$_POST['sd_notify_family_email'] = 'family@example.test';
		$_POST['sd_tribute_message']     = 'Loved always';
		Checkout_Fields::save_checkout_fields( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( '', $fresh->get_meta( '_skdon_is_anonymous' ) );
		$this->assertSame( '', $fresh->get_meta( '_skdon_dedication' ) );
		$this->assertSame( '', $fresh->get_meta( '_skdon_notify_family_email' ) );
		$this->assertSame( 'Loved always', $fresh->get_meta( '_skdon_tribute_message' ) );
	}

	public function test_an_order_placed_while_a_feature_was_on_still_shows_it(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_skdon_dedication', 'In honor of Rex' );
		$order->save();

		$this->switch_off();
		ob_start();
		Checkout_Fields::display_admin_order_fields( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'In honor of Rex', $html );
	}
}
