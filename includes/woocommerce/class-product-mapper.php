<?php
/**
 * Product Mapper - Routes WooCommerce products to abilities via config.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Helpers;

/**
 * Maps WooCommerce product SKUs to abilities and builds ability input from orders.
 *
 * @since 1.0.0
 */
class Product_Mapper {

    /**
     * Meta key linking a donation, membership or memorial to the order line
     * it was created from. Checkout and Legacy Order Sync both stamp it, and
     * the sync matches existing records by it (#35, #40).
     *
     * @var string
     */
    public const ORDER_ITEM_META = '_skdon_wc_order_item_id';

    /**
     * Options holding the IDs of the products the activator created.
     *
     * @var string[]
     */
    private const PRODUCT_ID_OPTIONS = [
        'skdon_donation_product_id',
        'skdon_membership_product_id',
        'skdon_business_membership_product_id',
        'skdon_memorial_product_id',
    ];

    /**
     * Cached products configuration.
     *
     * @since 1.0.0
     * @var array
     */
    private static array $products = [];

    /**
     * Initialize the product mapper.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        self::$products = Config::get_item( 'products', 'products', [] );
    }

    /**
     * Find product configuration by SKU prefix.
     *
     * Products are matched by SKU prefix to allow for variable products
     * with SKUs like "shelter-donations-monthly" matching "shelter-donations".
     *
     * @since 1.0.0
     *
     * @param string $sku The product SKU.
     * @return array|null Product configuration or null if not found.
     */
    public static function find_by_sku( string $sku ): ?array {
        if ( empty( self::$products ) ) {
            self::init();
        }

        if ( empty( $sku ) ) {
            return null;
        }

        // Sort prefixes by length (longest first) for more specific matching.
        $prefixes = array_keys( self::$products );
        usort( $prefixes, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

        foreach ( $prefixes as $prefix ) {
            if ( str_starts_with( $sku, $prefix ) ) {
                return array_merge(
                    self::$products[ $prefix ],
                    [ 'sku_prefix' => $prefix ]
                );
            }
        }

        return null;
    }

    /**
     * Get product type from configuration.
     *
     * @since 1.0.0
     *
     * @param string $sku The product SKU.
     * @return string|null Product type (donation, membership, memorial) or null.
     */
    public static function get_product_type( string $sku ): ?string {
        $config = self::find_by_sku( $sku );
        return $config['product_type'] ?? null;
    }

    /**
     * Build ability input from order and item using configuration mapping.
     *
     * @since 1.0.0
     *
     * @param \WC_Order      $order  The WooCommerce order.
     * @param \WC_Order_Item $item   The order item.
     * @param array          $config The product configuration.
     * @return array The ability input array.
     */
    public static function build_input( \WC_Order $order, \WC_Order_Item $item, array $config ): array {
        // Base input from order. donor_name is resolved by input_mapping when
        // the product configures it (typically with item_meta + billing fallback);
        // billing name is applied here only as the ultimate default.
        $input = [
            'order_id'    => $order->get_id(),
            'amount'      => (float) $item->get_total(),
            'donor_email' => $order->get_billing_email(),
            'donor_name'  => self::get_donor_name( $order ),
        ];

        // Map additional fields from configuration.
        foreach ( $config['input_mapping'] ?? [] as $field => $mapping ) {
            $value = self::resolve_mapping( $order, $item, $mapping );

            // Only override the base when the mapping produced a real value.
            // This lets products.json donor_name override the billing default
            // when the form supplied one (item_meta _skdon_donor_name).
            if ( null !== $value && '' !== $value ) {
                $input[ $field ] = $value;
            }
        }

        /**
         * Filters the ability input built from a WooCommerce order item.
         *
         * @since 1.0.0
         *
         * @param array          $input  The ability input array.
         * @param \WC_Order      $order  The WooCommerce order.
         * @param \WC_Order_Item $item   The order item.
         * @param array          $config The product configuration.
         */
        return apply_filters( 'starter_shelter_product_mapper_input', $input, $order, $item, $config );
    }

    /**
     * Get donor name from order.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return string The donor's full name.
     */
    private static function get_donor_name( \WC_Order $order ): string {
        $first = $order->get_billing_first_name();
        $last  = $order->get_billing_last_name();

        return trim( "$first $last" );
    }

    /**
     * Resolve a single field mapping to its value.
     *
     * @since 1.0.0
     *
     * @param \WC_Order      $order   The WooCommerce order.
     * @param \WC_Order_Item $item    The order item.
     * @param mixed          $mapping The field mapping configuration.
     * @return mixed The resolved value.
     */
    private static function resolve_mapping( \WC_Order $order, \WC_Order_Item $item, $mapping ) {
        // Static value (non-array mapping).
        if ( ! is_array( $mapping ) ) {
            return $mapping;
        }

        // Static source.
        if ( isset( $mapping['source'] ) && 'static' === $mapping['source'] ) {
            return $mapping['value'] ?? null;
        }

        // Composite source (nested object).
        if ( isset( $mapping['source'] ) && 'composite' === $mapping['source'] ) {
            $result = [];
            foreach ( $mapping['fields'] ?? [] as $sub_field => $sub_mapping ) {
                $sub_value = self::resolve_mapping( $order, $item, $sub_mapping );
                if ( null !== $sub_value ) {
                    $result[ $sub_field ] = $sub_value;
                }
            }
            return ! empty( $result ) ? $result : null;
        }

        // Resolve value from source.
        $value = self::get_value_from_source( $order, $item, $mapping );

        // Try fallback if value is empty.
        if ( ( '' === $value || null === $value ) && isset( $mapping['fallback'] ) ) {
            $value = self::resolve_mapping( $order, $item, $mapping['fallback'] );
        }

        // Apply default if still empty.
        if ( ( '' === $value || null === $value ) && isset( $mapping['default'] ) ) {
            $value = $mapping['default'];
        }

        // Apply transform.
        if ( null !== $value && isset( $mapping['transform'] ) ) {
            $value = self::transform_value( $value, $mapping['transform'] );
        }

        return $value;
    }

    /**
     * Get value from the specified source.
     *
     * @since 1.0.0
     *
     * @param \WC_Order      $order   The WooCommerce order.
     * @param \WC_Order_Item $item    The order item.
     * @param array          $mapping The mapping configuration.
     * @return mixed The value from the source.
     */
    private static function get_value_from_source( \WC_Order $order, \WC_Order_Item $item, array $mapping ) {
        $source = $mapping['source'] ?? 'order_meta';
        $key    = $mapping['key'] ?? '';

        switch ( $source ) {
            case 'attribute':
                // Try with pa_ prefix first (WooCommerce product attributes).
                $value = $item->get_meta( 'pa_' . $key );
                if ( empty( $value ) ) {
                    // Try without prefix (custom attributes).
                    $value = $item->get_meta( $key );
                }
                if ( empty( $value ) ) {
                    // Try getting from product variation attributes.
                    $value = self::get_variation_attribute( $item, $key );
                }
                return $value ?: null;

            case 'order_meta':
                $value = $order->get_meta( $key );
                return '' !== $value ? $value : null;

            case 'item_meta':
                $value = $item->get_meta( $key );
                return '' !== $value ? $value : null;

            case 'order_field':
                $method = 'get_' . $key;
                if ( method_exists( $order, $method ) ) {
                    return $order->$method();
                }
                return null;

            case 'product_meta':
                $product = $item->get_product();
                if ( $product ) {
                    $value = $product->get_meta( $key );
                    return '' !== $value ? $value : null;
                }
                return null;

            default:
                return null;
        }
    }

    /**
     * Get variation attribute value from order item.
     *
     * @since 1.0.0
     *
     * @param \WC_Order_Item $item The order item.
     * @param string         $key  The attribute key.
     * @return string|null The attribute value or null.
     */
    private static function get_variation_attribute( \WC_Order_Item $item, string $key ): ?string {
        $product = $item->get_product();

        if ( ! $product || ! $product->is_type( 'variation' ) ) {
            return null;
        }

        $attributes = $product->get_variation_attributes();

        // Try exact key.
        if ( isset( $attributes[ $key ] ) ) {
            return $attributes[ $key ];
        }

        // Try with attribute_ prefix.
        $prefixed_key = 'attribute_' . $key;
        if ( isset( $attributes[ $prefixed_key ] ) ) {
            return $attributes[ $prefixed_key ];
        }

        // Try with pa_ prefix.
        $pa_key = 'attribute_pa_' . $key;
        if ( isset( $attributes[ $pa_key ] ) ) {
            return $attributes[ $pa_key ];
        }

        return null;
    }

    /**
     * Transform a value using the specified transform function.
     *
     * @since 1.0.0
     *
     * @param mixed  $value     The value to transform.
     * @param string $transform The transform function name.
     * @return mixed The transformed value.
     */
    private static function transform_value( $value, string $transform ) {
        return match ( $transform ) {
            'boolean'             => filter_var( $value, FILTER_VALIDATE_BOOLEAN ),
            'integer'             => (int) $value,
            'float'               => (float) $value,
            'lowercase'           => strtolower( (string) $value ),
            'uppercase'           => strtoupper( (string) $value ),
            'normalize_tier'      => Helpers\normalize_tier( (string) $value ),
            'normalize_allocation' => self::normalize_allocation( (string) $value ),
            'sanitize_email'      => sanitize_email( (string) $value ),
            'sanitize_text'       => sanitize_text_field( (string) $value ),
            default               => $value,
        };
    }

    /**
     * Normalize allocation value.
     *
     * @since 1.0.0
     *
     * @param string $allocation The allocation value.
     * @return string Normalized allocation slug.
     */
    private static function normalize_allocation( string $allocation ): string {
        $slug = \Starter_Shelter\Core\Allocations::canonical( $allocation );

        // An unknown value passes through as a slug so the ability rejects it
        // visibly (an order note and the reprocess button) instead of it being
        // quietly recorded against some other fund.
        return '' !== $slug ? $slug : sanitize_title( $allocation );
    }

    /**
     * Get all configured product SKU prefixes.
     *
     * @since 1.0.0
     *
     * @return array Array of SKU prefixes.
     */
    public static function get_sku_prefixes(): array {
        if ( empty( self::$products ) ) {
            self::init();
        }

        return array_keys( self::$products );
    }

    /**
     * Check if an order contains any shelter products.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return bool True if order contains shelter products.
     */
    public static function order_has_shelter_products( \WC_Order $order ): bool {
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && ( self::find_by_sku( $product->get_sku() ) || self::is_plugin_product( $product ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a product is one the plugin created (or one of its variations),
     * whatever its SKU says now.
     *
     * Matching by SKU alone treated a plugin product whose SKU had changed
     * like any shop product, so its order lines were skipped without a word
     * (#35, and #16 before it).
     *
     * @param \WC_Product|null|false $product The product.
     * @return bool
     */
    public static function is_plugin_product( $product ): bool {
        if ( ! $product instanceof \WC_Product ) {
            return false;
        }
        $ids = array_filter( array_map( static fn ( string $option ): int => (int) get_option( $option, 0 ), self::PRODUCT_ID_OPTIONS ) );
        return in_array( $product->get_id(), $ids, true ) || in_array( $product->get_parent_id(), $ids, true );
    }

    /**
     * The donation, membership or memorial an order line produced, if any.
     *
     * Records carry their order line from this release on (#35). An older
     * record carries only its order, which identifies the line only when the
     * order has a single line of that kind; such a record is matched, and
     * linked to the line for next time. Used by Reprocess and by refunds.
     *
     * @param \WC_Order      $order The order.
     * @param \WC_Order_Item $item  The order line.
     * @return int Post ID, or 0.
     */
    public static function line_record_id( \WC_Order $order, \WC_Order_Item $item ): int {
        $product      = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
        $config       = $product ? self::find_by_sku( $product->get_sku() ) : null;
        $product_type = (string) ( $config['product_type'] ?? '' );
        $post_type    = self::post_type_for( $product_type );

        $find = static fn ( array $meta_query, string $type ): array => get_posts( [
            'post_type'      => '' !== $type ? $type : [ 'skdon_donation', 'skdon_membership', 'skdon_memorial' ],
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one order's lines.
        ] );

        // A stamped record identifies its line whatever its type.
        $found = $find( [ [ 'key' => self::ORDER_ITEM_META, 'value' => $item->get_id(), 'type' => 'NUMERIC' ] ], $post_type );
        if ( $found ) {
            return (int) $found[0];
        }
        if ( '' === $post_type ) {
            return 0;
        }

        $same_kind = 0;
        foreach ( $order->get_items() as $line ) {
            $line_product = $line instanceof \WC_Order_Item_Product ? $line->get_product() : null;
            $line_config  = $line_product ? self::find_by_sku( $line_product->get_sku() ) : null;
            if ( $line_config && $product_type === ( $line_config['product_type'] ?? '' ) ) {
                ++$same_kind;
            }
        }
        if ( 1 !== $same_kind ) {
            return 0;
        }

        $found = $find( [
            [ 'key' => '_skdon_wc_order_id', 'value' => $order->get_id(), 'type' => 'NUMERIC' ],
            [ 'key' => self::ORDER_ITEM_META, 'compare' => 'NOT EXISTS' ],
        ], $post_type );
        if ( ! $found ) {
            return 0;
        }
        update_post_meta( (int) $found[0], self::ORDER_ITEM_META, (int) $item->get_id() );
        return (int) $found[0];
    }

    /**
     * The post type a product type records into.
     *
     * @param string $product_type donation, membership or memorial.
     * @return string Post type, or '' for anything else.
     */
    public static function post_type_for( string $product_type ): string {
        return match ( $product_type ) {
            'donation'   => 'skdon_donation',
            'membership' => 'skdon_membership',
            'memorial'   => 'skdon_memorial',
            default      => '',
        };
    }

    /**
     * The record an ability result refers to.
     *
     * @param mixed $result An ability's create or update result.
     * @return int Post ID, or 0.
     */
    public static function record_id( $result ): int {
        if ( ! is_array( $result ) ) {
            return 0;
        }
        foreach ( [ 'donation_id', 'membership_id', 'memorial_id', 'id' ] as $key ) {
            if ( ! empty( $result[ $key ] ) ) {
                return (int) $result[ $key ];
            }
        }
        return 0;
    }

    /**
     * Link the record an ability result refers to with its order line.
     *
     * @param mixed $result  The ability result.
     * @param int   $item_id The order line's item ID.
     */
    public static function stamp_order_line( $result, int $item_id ): void {
        $record = self::record_id( $result );
        if ( $record && $item_id > 0 ) {
            update_post_meta( $record, self::ORDER_ITEM_META, $item_id );
        }
    }

    /**
     * Get checkout field set for a product type.
     *
     * @since 1.0.0
     *
     * @param string $product_type The product type (donation, membership, memorial).
     * @return array Field configuration array.
     */
    public static function get_checkout_fields( string $product_type ): array {
        $field_sets = Config::get_item( 'products', 'checkout_field_sets', [] );

        return $field_sets[ $product_type ] ?? [];
    }
}
