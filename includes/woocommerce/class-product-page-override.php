<?php
/**
 * Product Page Override — Replace WooCommerce single-product templates
 * with the relevant CPT form block for mapped products.
 *
 * Without this, donors who reach a mapped product's single-product page
 * (via mini-cart, cart, checkout, my account links, or a direct URL) see
 * the default WooCommerce template, which bypasses the contextual fields
 * the form blocks collect (allocation, honoree, tier, donor display name,
 * etc.). This override swaps the product summary for the matching form
 * block so donors get the same UX everywhere.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 2.2.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

/**
 * Replaces single-product templates for donation/membership/memorial products.
 *
 * @since 2.2.0
 */
class Product_Page_Override {

	/**
	 * Map of product_type => block name.
	 *
	 * Business memberships resolve to the membership form block with the
	 * membershipType attribute set to 'business'.
	 *
	 * @since 2.2.0
	 */
	private const BLOCK_MAP = [
		'donation'            => 'starter-shelter/donation-form',
		'membership'          => 'starter-shelter/membership-form',
		'business_membership' => 'starter-shelter/membership-form',
		'memorial'            => 'starter-shelter/memorial-form',
	];

	/**
	 * Hook into WooCommerce single-product rendering.
	 *
	 * @since 2.2.0
	 */
	/**
	 * Cached "is mapped product page" flag for the current request.
	 *
	 * @since 2.2.0
	 */
	private static ?bool $is_mapped = null;

	/**
	 * WC product blocks to suppress on mapped product pages.
	 * The form block already covers price, add-to-cart, and meta.
	 *
	 * @since 2.2.0
	 */
	private const SUPPRESSED_BLOCKS = [
		'woocommerce/add-to-cart-form',
		'woocommerce/product-price',
		'woocommerce/product-sale-badge',
		'woocommerce/product-stock-indicator',
		'woocommerce/product-meta',
		'woocommerce/product-details',
		'woocommerce/related-products',
		'woocommerce/product-summary',
	];

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_override' ] );
		add_filter( 'render_block', [ self::class, 'filter_block_template' ], 10, 2 );
	}

	/**
	 * Suppress core WC product blocks on mapped product pages so the form
	 * block isn't shown alongside a redundant price + variations form.
	 *
	 * @since 2.2.0
	 */
	public static function filter_block_template( string $block_content, array $block ): string {
		if ( empty( $block['blockName'] ) || ! in_array( $block['blockName'], self::SUPPRESSED_BLOCKS, true ) ) {
			return $block_content;
		}
		if ( ! self::is_mapped_product_page() ) {
			return $block_content;
		}
		return '';
	}

	/**
	 * Check (and cache) whether the current request is a mapped product page.
	 *
	 * @since 2.2.0
	 */
	private static function is_mapped_product_page(): bool {
		if ( null !== self::$is_mapped ) {
			return self::$is_mapped;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return self::$is_mapped = false;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product ) {
			return self::$is_mapped = false;
		}
		return self::$is_mapped = (bool) self::resolve_block( $product );
	}

	/**
	 * Resolve the form block name for the queried product, if any.
	 *
	 * @since 2.2.0
	 */
	private static function resolve_block( \WC_Product $product ): ?string {
		$config = Product_Mapper::find_by_sku( (string) $product->get_sku() );
		if ( ! $config ) {
			return null;
		}
		$type = $config['product_type'] ?? null;
		return self::BLOCK_MAP[ $type ] ?? null;
	}

	/**
	 * Resolve the product_type for the queried product (for attribute hints).
	 *
	 * @since 2.2.0
	 */
	private static function resolve_type( \WC_Product $product ): ?string {
		$config = Product_Mapper::find_by_sku( (string) $product->get_sku() );
		return $config['product_type'] ?? null;
	}

	/**
	 * Detect a mapped product page and swap its summary for the form block.
	 *
	 * @since 2.2.0
	 */
	public static function maybe_override(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$block_name = self::resolve_block( $product );
		if ( ! $block_name ) {
			return;
		}

		$product_type = self::resolve_type( $product );

		// Strip default summary pieces (price, attribute selector, add to cart,
		// short description, meta) — the form block replaces them.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );

		// Render the form block in place of the add-to-cart slot.
		add_action(
			'woocommerce_single_product_summary',
			static function () use ( $block_name, $product_type ): void {
				echo do_blocks( self::build_block_markup( $block_name, $product_type ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			},
			30
		);

		// Hide related products and upsells — they're noise on a contribution page.
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
	}

	/**
	 * Build the block comment markup, applying attribute hints where useful.
	 *
	 * @since 2.2.0
	 */
	private static function build_block_markup( string $block_name, ?string $product_type ): string {
		$attrs = [];

		// Default the membership form to the business tab when arriving from
		// a business-membership product page.
		if ( 'starter-shelter/membership-form' === $block_name && 'business_membership' === $product_type ) {
			$attrs['membershipType'] = 'business';
		}

		$attr_json = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs );
		return "<!-- wp:{$block_name}{$attr_json} /-->";
	}
}
