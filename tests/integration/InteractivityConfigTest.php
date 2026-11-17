<?php
/**
 * The shared Interactivity config reaches the page.
 *
 * It was hooked to `wp_interactivity_init`, an action nothing fires, so it was
 * never registered: every store read an empty config, formatted money as "$",
 * and built REST URLs from `undefined` (#28).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

final class InteractivityConfigTest extends WP_UnitTestCase {

	public function test_config_is_registered_on_a_front_end_page_load(): void {
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', 'Starter_Shelter\\Blocks\\register_interactivity_config' ) );

		do_action( 'wp_enqueue_scripts' );
		$config = wp_interactivity_config( 'shelterkit-donations' );

		$this->assertSame( rest_url( 'shelter-donations/v1/' ), $config['restUrl'] ?? null );
		$this->assertNotEmpty( $config['nonce'] ?? '' );
		$this->assertSame( '%1$s%2$s', $config['currency']['format'] ?? null, 'Without WooCommerce: "$1,234.50".' );
	}
}
