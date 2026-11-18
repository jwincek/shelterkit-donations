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

	public function test_a_donor_whose_id_is_cached_still_gets_the_page(): void {
		$user  = self::factory()->user->create();
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish' ] );
		update_post_meta( $donor, '_skdon_user_id', $user );
		wp_set_current_user( $user );

		// What get_transient() returns on a later request when there is no
		// persistent object cache: the database's string. Returning it from
		// an ?int function under strict_types killed every front-end page
		// for the donor, mid-<head> (#42).
		set_transient( "skdon_donor_id_{$user}", (string) $donor, HOUR_IN_SECONDS );

		do_action( 'wp_enqueue_scripts' );

		$this->assertSame( $donor, wp_interactivity_config( 'shelterkit-donations' )['donorId'] ?? null );
	}
}
