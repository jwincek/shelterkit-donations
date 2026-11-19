<?php
/**
 * A feature switched off under Settings → General is off in the cart, not
 * only on the forms (#49).
 *
 * The forms just hide a choice; a request made by hand, or an old link with
 * sd_anonymous in it, would still set it. So the cart checks the switch too.
 * The cart item is where every choice a donor makes now arrives, whichever
 * checkout the site uses (#57).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\WooCommerce\Cart_Handler;
use WP_UnitTestCase;

final class FeatureSwitchesCartTest extends WP_UnitTestCase {

	private const OPTION = 'starter_shelter_options';

	public function set_up(): void {
		parent::set_up();
		delete_option( self::OPTION );
	}

	public function tear_down(): void {
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

	/** What a form posts with every choice ticked. */
	private function cart_item( string $type, array $posted = [] ): array {
		$build = new \ReflectionMethod( Cart_Handler::class, 'build_cart_item_data' );
		$build->setAccessible( true );
		return $build->invoke( null, $posted + [
			'amount'                => 25,
			'is_anonymous'          => '1',
			'dedication_enabled'    => '1',
			'honoree_name'          => 'Rex',
			'honoree_type'          => 'pet',
			'pet_species'           => 'dog',
			'dedication'            => 'In honor of Rex',
			'notify_family_enabled' => '1',
			'notify_family_name'    => 'The Smiths',
			'notify_family_email'   => 'family@example.test',
		], $type );
	}

	public function test_the_cart_takes_only_what_the_site_offers(): void {
		$on = $this->cart_item( 'memorial' );
		$this->assertTrue( $on['skdon_is_anonymous'] );
		$this->assertTrue( $on['skdon_notify_family_enabled'] );
		$this->assertSame( 'In honor of Rex', $this->cart_item( 'donation' )['skdon_dedication'] );

		$this->switch_off();
		$off = $this->cart_item( 'memorial' );

		$this->assertArrayNotHasKey( 'skdon_is_anonymous', $off );
		$this->assertArrayNotHasKey( 'skdon_notify_family_enabled', $off );
		$this->assertArrayNotHasKey( 'skdon_notify_family_email', $off );
		$this->assertArrayNotHasKey( 'skdon_dedication', $this->cart_item( 'donation' ) );
		$this->assertSame( 'Rex', $off['skdon_honoree_name'], 'The tribute itself is unaffected.' );
		$this->assertSame( 'dog', $off['skdon_pet_species'] );

		// The add-to-cart link form: ?add-to-cart=…&sd_anonymous=1.
		$_REQUEST['sd_anonymous'] = '1';
		$this->assertArrayNotHasKey( 'skdon_is_anonymous', Cart_Handler::add_cart_item_data( [], 1, 0 ) );
	}

	public function test_a_dedication_is_kept_for_a_donation_only(): void {
		$this->assertArrayNotHasKey( 'skdon_dedication', $this->cart_item( 'memorial' ), 'A tribute gift has its own honoree instead.' );
		$this->assertSame( 200, mb_strlen( $this->cart_item( 'donation', [ 'dedication' => str_repeat( 'é', 300 ) ] )['skdon_dedication'] ) );
		$this->assertArrayNotHasKey( 'skdon_dedication', $this->cart_item( 'donation', [ 'dedication' => [ 'not', 'text' ] ] ) );
	}

	public function test_a_species_is_kept_for_a_pet_and_only_from_the_list(): void {
		$this->assertArrayNotHasKey( 'skdon_pet_species', $this->cart_item( 'memorial', [ 'honoree_type' => 'person' ] ) );
		$this->assertArrayNotHasKey( 'skdon_pet_species', $this->cart_item( 'memorial', [ 'pet_species' => 'dragon' ] ) );
	}
}
