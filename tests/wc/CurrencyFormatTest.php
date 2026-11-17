<?php
/**
 * Amounts follow the store's currency settings on the server and in the
 * shared client config alike (#28).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use WP_UnitTestCase;

use function Starter_Shelter\Helpers\format_currency;

final class CurrencyFormatTest extends WP_UnitTestCase {

	private function euro_store(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_currency_pos', 'right_space' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option( 'woocommerce_price_num_decimals', '2' );
	}

	public function test_default_store_formats_dollars(): void {
		$this->assertSame( '$1,234.50', format_currency( 1234.5 ) );
		$this->assertSame( '-$50.00', format_currency( -50 ) );
	}

	public function test_a_euro_store_gets_its_symbol_position_and_separators(): void {
		$this->euro_store();
		$this->assertSame( "1.234,50\u{00A0}€", format_currency( 1234.5 ), 'Plain text: the &euro; entity and &nbsp; are decoded.' );
	}

	public function test_decimals_follow_the_store(): void {
		update_option( 'woocommerce_currency', 'JPY' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		$this->assertSame( '¥1,235', format_currency( 1234.5 ) );
	}

	public function test_the_client_config_carries_the_same_settings(): void {
		$this->euro_store();
		\Starter_Shelter\Blocks\register_interactivity_config();
		$currency = wp_interactivity_config( 'shelterkit-donations' )['currency'];

		$this->assertSame( '€', $currency['symbol'], 'Decoded, or the client would print "&euro;".' );
		$this->assertSame( "%2\$s\u{00A0}%1\$s", $currency['format'] );
		$this->assertSame( ',', $currency['decimalSep'] );
		$this->assertSame( '.', $currency['thousandSep'] );
		$this->assertSame( 2, $currency['decimals'] );
	}

	public function test_donor_statistics_renders_in_the_store_currency(): void {
		$this->euro_store();
		$html = render_block( [ 'blockName' => 'shelter-donations/donor-stats', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );
		$this->assertStringContainsString( "0,00\u{00A0}€", $html );
		$this->assertStringNotContainsString( '$', $html );
	}
}
