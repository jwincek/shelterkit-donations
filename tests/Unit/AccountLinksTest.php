<?php
/**
 * Every My Account link the plugin builds goes to an endpoint that exists.
 *
 * The Donor Dashboard's "View All Gifts" linked to the `donations` endpoint,
 * which nothing registers, so it was a 404; the gift history is
 * `giving-history` (#64).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AccountLinksTest extends TestCase {

	/** WooCommerce's own account endpoints. */
	private const WOOCOMMERCE = [ 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'add-payment-method', 'lost-password', 'customer-logout', 'dashboard' ];

	public function test_every_account_link_names_a_registered_endpoint(): void {
		$root = dirname( __DIR__, 2 ) . '/';

		preg_match( '/const ENDPOINTS = \[(.*?)\];/s', (string) file_get_contents( $root . 'includes/woocommerce/class-my-account.php' ), $list );
		preg_match_all( "/'([a-z-]+)'\s*=>/", $list[1] ?? '', $m );
		$known = array_merge( self::WOOCOMMERCE, $m[1] );
		$this->assertContains( 'giving-history', $known, 'The scan should find the plugin\'s endpoints.' );

		$unknown = [];
		foreach ( [ 'includes', 'blocks', 'templates' ] as $dir ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( ! str_ends_with( (string) $file, '.php' ) ) {
					continue;
				}
				$source = (string) file_get_contents( (string) $file );
				preg_match_all( "/wc_get_account_endpoint_url\(\s*'([a-z-]+)'/", $source, $links, PREG_OFFSET_CAPTURE );
				foreach ( $links[1] as [ $endpoint, $offset ] ) {
					if ( ! in_array( $endpoint, $known, true ) ) {
						$unknown[] = $endpoint . ' in ' . substr( (string) $file, strlen( $root ) ) . ':' . ( substr_count( substr( $source, 0, $offset ), "\n" ) + 1 );
					}
				}
			}
		}

		$this->assertSame( [], $unknown, 'These My Account links go to an endpoint nothing registers, so they are 404s.' );
	}
}
