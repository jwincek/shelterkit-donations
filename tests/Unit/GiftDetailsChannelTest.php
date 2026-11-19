<?php
/**
 * A gift's details reach its record through one channel that works with
 * either checkout: the form puts them on the cart item, the cart handler
 * copies them to the order line, and order processing reads them there (#57).
 *
 * The plugin also had checkout fields, which only the classic checkout shows.
 * On the block checkout, WooCommerce's default, none appeared, so a
 * donation's dedication and a pet's species could not be given at all:
 * order processing read `_skdon_dedication` and `_skdon_pet_species` from the
 * order line, and nothing wrote them there.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GiftDetailsChannelTest extends TestCase {

	/** Read from the order line, but no longer written there, and why. */
	private const READ_ONLY_FOR_OLD_ORDERS = [
		'_skdon_notify_family'  => 'Nested family notification, from orders placed before it was split into flat keys (CC-2).',
		'_skdon_family_name'    => 'Family name, from orders placed before the notify_family_* rename.',
		'_skdon_family_email'   => 'Family email, from orders placed before the notify_family_* rename.',
		'_skdon_family_address' => 'Family address, from orders placed before the notify_family_* rename.',
		'_skdon_send_card'      => 'Send-a-card flag, from orders placed before the notify_family_* rename.',
	];

	/** Hooks only the classic checkout fires. The block checkout never renders, saves or checks what they add. */
	private const CLASSIC_ONLY_HOOKS = [
		'woocommerce_checkout_fields',
		'woocommerce_after_order_notes',
		'woocommerce_before_order_notes',
		'woocommerce_checkout_process',
		'woocommerce_checkout_update_order_meta',
	];

	private string $root;

	protected function setUp(): void {
		$this->root = dirname( __DIR__, 2 ) . '/';
	}

	public function test_every_order_line_detail_processing_reads_is_one_the_cart_writes(): void {
		$written = $this->keys_the_cart_writes();
		$this->assertContains( '_skdon_honoree_name', $written, 'The scan should find the cart handler\'s list.' );

		$read = [];
		foreach ( $this->manifests() as $name => $manifest ) {
			foreach ( $manifest['products'] ?? [] as $sku => $product ) {
				$this->collect_item_meta( $product['input_mapping'] ?? [], "{$name} {$sku}", $read );
			}
		}
		$this->assertArrayHasKey( '_skdon_dedication', $read, 'The scan should find the input mappings.' );

		$unwritten = [];
		foreach ( $read as $key => $where ) {
			if ( ! in_array( $key, $written, true ) && ! isset( self::READ_ONLY_FOR_OLD_ORDERS[ $key ] ) ) {
				$unwritten[] = $key . ' (read for ' . implode( ', ', array_unique( $where ) ) . ')';
			}
		}
		$this->assertSame( [], $unwritten, 'Order processing reads these from the order line, but the cart never writes them there, so no donor can give them.' );
	}

	public function test_no_manifest_declares_checkout_fields(): void {
		foreach ( $this->manifests() as $name => $manifest ) {
			$this->assertArrayNotHasKey( 'checkout_fields', $manifest, "{$name}: collect it on the form instead; the block checkout shows no checkout fields." );
		}
	}

	public function test_nothing_relies_on_hooks_only_the_classic_checkout_fires(): void {
		$found = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root . 'includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( array_merge( [ $this->root . 'shelterkit-donations.php' ], iterator_to_array( $files ) ) as $file ) {
			$path = (string) $file;
			if ( ! str_ends_with( $path, '.php' ) ) {
				continue;
			}
			$source = (string) file_get_contents( $path );
			foreach ( self::CLASSIC_ONLY_HOOKS as $hook ) {
				if ( preg_match( '/add_(?:action|filter)\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $source ) ) {
					$found[] = $hook . ' in ' . substr( $path, strlen( $this->root ) );
				}
			}
		}
		$this->assertSame( [], $found, 'These run on the classic checkout only. Put the choice on the form, or register it for both checkouts.' );
	}

	/** @return array<string, array> Manifest name => manifest. */
	private function manifests(): array {
		$out = [];
		foreach ( glob( $this->root . 'config/manifests/*.php' ) as $file ) {
			$out[ basename( $file, '.php' ) ] = require $file;
		}
		return $out;
	}

	/**
	 * Every item_meta key in a mapping, its fallbacks and composite parts.
	 *
	 * @param array                   $node  Part of an input_mapping.
	 * @param string                  $where Which product, for the message.
	 * @param array<string, string[]> $read  Key => where, filled in.
	 */
	private function collect_item_meta( array $node, string $where, array &$read ): void {
		if ( 'item_meta' === ( $node['source'] ?? null ) && isset( $node['key'] ) ) {
			$read[ (string) $node['key'] ][] = $where;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_item_meta( $child, $where, $read );
			}
		}
	}

	/** @return string[] The order-line meta keys Cart_Handler::save_cart_item_to_order() writes. */
	private function keys_the_cart_writes(): array {
		$source = (string) file_get_contents( $this->root . 'includes/woocommerce/class-cart-handler.php' );
		preg_match( '/function save_cart_item_to_order\(.*?\$meta_keys = \[(.*?)\];/s', $source, $list );
		preg_match_all( "/'(skdon_[a-z_]+)'/", $list[1] ?? '', $m );
		return array_map( static fn( string $key ): string => '_' . $key, $m[1] );
	}
}
