<?php
/**
 * Cart Handler - AJAX add-to-cart for donation products.
 *
 * Handles variable products with custom amounts and metadata.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use WP_Error;

/**
 * Handles cart operations for shelter donation products.
 *
 * @since 2.0.0
 */
class Cart_Handler {

    /**
     * Initialize the cart handler.
     *
     * @since 2.0.0
     */
    public static function init(): void {
        // AJAX handlers.
        add_action( 'wp_ajax_sd_add_to_cart', [ self::class, 'ajax_add_to_cart' ] );
        add_action( 'wp_ajax_nopriv_sd_add_to_cart', [ self::class, 'ajax_add_to_cart' ] );

        // Modify cart item data.
        add_filter( 'woocommerce_add_cart_item_data', [ self::class, 'add_cart_item_data' ], 10, 3 );

        // Display custom data in cart.
        add_filter( 'woocommerce_get_item_data', [ self::class, 'display_cart_item_data' ], 10, 2 );

        // Save custom data to order.
        add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'save_cart_item_to_order' ], 10, 4 );

        // Custom price handling for donations.
        add_action( 'woocommerce_before_calculate_totals', [ self::class, 'set_custom_price' ], 20 );

        // Validate cart items.
        add_filter( 'woocommerce_add_to_cart_validation', [ self::class, 'validate_add_to_cart' ], 10, 5 );
    }

    /**
     * AJAX handler for adding donation to cart.
     *
     * @since 2.0.0
     */
    public static function ajax_add_to_cart(): void {
        check_ajax_referer( 'sd_add_to_cart', 'nonce' );

        // Rate limit: 1 add-to-cart per 3 seconds per user/IP.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- check_ajax_referer( 'sd_add_to_cart' ) runs on the first line; the flagged read is REMOTE_ADDR, hashed into a rate-limit key rather than rendered or stored as text.
        $rate_key = 'sd_cart_rate_' . ( get_current_user_id() ?: wp_hash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if ( false !== get_transient( $rate_key ) ) {
            wp_send_json_error( [
                'message' => __( 'Please wait a moment before adding another item.', 'shelterkit-donations' ),
            ] );
        }
        set_transient( $rate_key, 1, 3 );

        $product_type = sanitize_key( $_POST['product_type'] ?? 'donation' );
        $amount = floatval( $_POST['amount'] ?? 0 );

        if ( $amount < 1 ) {
            wp_send_json_error( [
                'message' => __( 'Please enter a valid amount.', 'shelterkit-donations' ),
            ] );
        }

        // Get the product ID based on type.
        $product_id = self::get_product_id_for_type( $product_type );

        if ( ! $product_id ) {
            wp_send_json_error( [
                'message' => __( 'Donation product not configured. Please contact the site administrator.', 'shelterkit-donations' ),
            ] );
        }

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [
                'message' => __( 'Product not found.', 'shelterkit-donations' ),
            ] );
        }

        // Handle business logo upload before building cart data.
        // The file is uploaded now (during add-to-cart) so the attachment ID
        // survives any payment gateway redirect (PayPal, etc.).
        if ( 'business_membership' === $product_type && ! empty( $_FILES['business_logo']['name'] ) ) {
            $logo_id = self::handle_logo_upload();
            if ( is_wp_error( $logo_id ) ) {
                wp_send_json_error( [
                    'message' => $logo_id->get_error_message(),
                ] );
            }
            $_POST['sd_logo_attachment_id'] = $logo_id;
        }

        // Build cart item data.
        $cart_item_data = self::build_cart_item_data( $_POST, $product_type );

        // Find variation if variable product.
        $variation_id = 0;
        $variation = [];

        if ( $product->is_type( 'variable' ) ) {
            $variation_data = self::find_variation( $product, $_POST, $product_type );

            if ( is_wp_error( $variation_data ) ) {
                wp_send_json_error( [
                    'message' => $variation_data->get_error_message(),
                ] );
            }

            $variation_id = $variation_data['variation_id'];
            $variation = $variation_data['variation'];
        }

        // Clear existing donations from cart if configured.
        if ( apply_filters( 'starter_shelter_clear_cart_before_donation', false ) ) {
            WC()->cart->empty_cart();
        }

        // Add to cart.
        $cart_item_key = WC()->cart->add_to_cart(
            $product_id,
            1,
            $variation_id,
            $variation,
            $cart_item_data
        );

        if ( ! $cart_item_key ) {
            wp_send_json_error( [
                'message' => __( 'Could not add to cart. Please try again.', 'shelterkit-donations' ),
            ] );
        }

        wp_send_json_success( [
            'message'      => __( 'Added to cart successfully.', 'shelterkit-donations' ),
            'cart_url'     => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'cart_count'   => WC()->cart->get_cart_contents_count(),
            'cart_total'   => WC()->cart->get_cart_total(),
        ] );
    }

    /**
     * Get product ID for a donation type.
     *
     * @since 2.0.0
     *
     * @param string $type Product type (donation, membership, business_membership, memorial).
     * @return int Product ID or 0.
     */
    private static function get_product_id_for_type( string $type ): int {
        $option_keys = [
            'donation'            => 'sd_donation_product_id',
            'membership'          => 'sd_membership_product_id',
            'business_membership' => 'sd_business_membership_product_id',
            'memorial'            => 'sd_memorial_product_id',
        ];

        $option_key = $option_keys[ $type ] ?? $option_keys['donation'];

        return (int) get_option( $option_key, 0 );
    }

    /**
     * Build cart item data from POST data.
     *
     * @since 2.0.0
     *
     * @param array  $post_data   POST data.
     * @param string $product_type Product type.
     * @return array Cart item data.
     */
    private static function build_cart_item_data( array $post_data, string $product_type ): array {
        $data = [
            'sd_product_type' => $product_type,
            'sd_custom_price' => floatval( $post_data['amount'] ?? 0 ),
        ];

        // Common fields.
        if ( ! empty( $post_data['allocation'] ) ) {
            $data['sd_allocation'] = sanitize_key( $post_data['allocation'] );
        }

        if ( ! empty( $post_data['campaign_id'] ) ) {
            $data['sd_campaign_id'] = absint( $post_data['campaign_id'] );
        }

        if ( ! empty( $post_data['is_anonymous'] ) ) {
            $data['sd_is_anonymous'] = true;
        }

        if ( ! empty( $post_data['donor_name'] ) ) {
            $data['sd_donor_name'] = sanitize_text_field( $post_data['donor_name'] );
        }

        // Dedication fields.
        if ( ! empty( $post_data['dedication_enabled'] ) ) {
            $data['sd_dedication_enabled'] = true;

            if ( ! empty( $post_data['dedication_type'] ) ) {
                $data['sd_dedication_type'] = sanitize_key( $post_data['dedication_type'] );
            }

            if ( ! empty( $post_data['honoree_name'] ) ) {
                $data['sd_honoree_name'] = sanitize_text_field( $post_data['honoree_name'] );
            }

            if ( ! empty( $post_data['honoree_type'] ) ) {
                $data['sd_honoree_type'] = sanitize_key( $post_data['honoree_type'] );
            }

            if ( ! empty( $post_data['tribute_message'] ) ) {
                $data['sd_tribute_message'] = sanitize_textarea_field( $post_data['tribute_message'] );
            }

            // Family notification fields. POST/cart-data/item-meta keys
            // now use the canonical `notify_family_*` shape that matches
            // the sd_memorial entity declarations — closes the CC-2
            // audit divergence where cart wrote `_sd_family_name` while
            // the entity used `_sd_notify_family_name` for the same data.
            if ( ! empty( $post_data['notify_family_enabled'] ) ) {
                $data['sd_notify_family_enabled'] = true;

                if ( ! empty( $post_data['notify_family_name'] ) ) {
                    $data['sd_notify_family_name'] = sanitize_text_field( $post_data['notify_family_name'] );
                }
                if ( ! empty( $post_data['notify_family_email'] ) ) {
                    $data['sd_notify_family_email'] = sanitize_email( $post_data['notify_family_email'] );
                }
                if ( ! empty( $post_data['notify_family_address'] ) ) {
                    $data['sd_notify_family_address'] = sanitize_textarea_field( $post_data['notify_family_address'] );
                }
                if ( ! empty( $post_data['notify_family_send_card'] ) ) {
                    $data['sd_notify_family_send_card'] = true;
                }
            }
        }

        // Membership-specific fields.
        if ( in_array( $product_type, [ 'membership', 'business_membership' ], true ) ) {
            if ( ! empty( $post_data['tier'] ) ) {
                $data['sd_membership_tier'] = sanitize_key( $post_data['tier'] );
            }
            if ( ! empty( $post_data['business_name'] ) ) {
                $data['sd_business_name'] = sanitize_text_field( $post_data['business_name'] );
            }
            if ( ! empty( $post_data['sd_logo_attachment_id'] ) ) {
                $data['sd_logo_attachment_id'] = absint( $post_data['sd_logo_attachment_id'] );
            }
        }

        /**
         * Filters cart item data for shelter donations.
         *
         * @since 2.0.0
         *
         * @param array  $data         Cart item data.
         * @param array  $post_data    Original POST data.
         * @param string $product_type Product type.
         */
        return apply_filters( 'starter_shelter_cart_item_data', $data, $post_data, $product_type );
    }

    /**
     * Find the appropriate variation for a variable product.
     *
     * @since 2.0.0
     *
     * @param \WC_Product_Variable $product      The variable product.
     * @param array                $post_data    POST data.
     * @param string               $product_type Product type.
     * @return array|WP_Error Variation data or error.
     */
    private static function find_variation( \WC_Product_Variable $product, array $post_data, string $product_type ) {
        $attribute_key = self::get_attribute_key_for_type( $product_type );
        $attribute_value = self::get_attribute_value_from_post( $post_data, $product_type );

        if ( ! $attribute_value ) {
            // If no specific attribute needed, get the first available variation.
            $variations = $product->get_available_variations();

            if ( empty( $variations ) ) {
                return new WP_Error( 'no_variations', __( 'No variations available for this product.', 'shelterkit-donations' ) );
            }

            return [
                'variation_id' => $variations[0]['variation_id'],
                'variation'    => [],
            ];
        }

        // Find variation matching the attribute.
        $data_store = \WC_Data_Store::load( 'product' );

        // Try different attribute formats.
        $attribute_formats = [
            $attribute_key => $attribute_value,
            'attribute_' . $attribute_key => $attribute_value,
            'attribute_pa_' . sanitize_title( $attribute_key ) => $attribute_value,
            sanitize_title( $attribute_key ) => $attribute_value,
        ];

        foreach ( $attribute_formats as $attr_name => $attr_value ) {
            $variation_id = $data_store->find_matching_product_variation( $product, [ $attr_name => $attr_value ] );

            if ( $variation_id ) {
                return [
                    'variation_id' => $variation_id,
                    'variation'    => [ $attr_name => $attr_value ],
                ];
            }
        }

        // Try matching by variation attribute value directly.
        foreach ( $product->get_available_variations() as $variation ) {
            foreach ( $variation['attributes'] as $attr_name => $attr_val ) {
                // Normalize for comparison.
                $normalized_attr = strtolower( trim( $attr_val ) );
                $normalized_search = strtolower( trim( $attribute_value ) );

                if ( $normalized_attr === $normalized_search ||
                     sanitize_title( $attr_val ) === sanitize_title( $attribute_value ) ) {
                    return [
                        'variation_id' => $variation['variation_id'],
                        'variation'    => [ $attr_name => $attr_val ],
                    ];
                }
            }
        }

        // Fallback: use first variation.
        $variations = $product->get_available_variations();

        if ( ! empty( $variations ) ) {
            return [
                'variation_id' => $variations[0]['variation_id'],
                'variation'    => [],
            ];
        }

        return new WP_Error( 'variation_not_found', __( 'Could not find a matching product variation.', 'shelterkit-donations' ) );
    }

    /**
     * Get the attribute key for a product type.
     *
     * @since 2.0.0
     *
     * @param string $product_type Product type.
     * @return string Attribute key.
     */
    private static function get_attribute_key_for_type( string $product_type ): string {
        $keys = [
            'donation'            => 'preferred-allocation',
            'membership'          => 'membership-level',
            'business_membership' => 'membership-level',
            'memorial'            => 'in-memoriam-type',
        ];

        return $keys[ $product_type ] ?? 'preferred-allocation';
    }

    /**
     * Get attribute value from POST data based on product type.
     *
     * @since 2.0.0
     *
     * @param array  $post_data    POST data.
     * @param string $product_type Product type.
     * @return string Attribute value.
     */
    private static function get_attribute_value_from_post( array $post_data, string $product_type ): string {
        switch ( $product_type ) {
            case 'donation':
                return sanitize_text_field( $post_data['allocation'] ?? 'general-fund' );

            case 'membership':
            case 'business_membership':
                return sanitize_text_field( $post_data['tier'] ?? '' );

            case 'memorial':
                return sanitize_text_field( $post_data['honoree_type'] ?? 'person' );

            default:
                return '';
        }
    }

    /**
     * Add custom data to cart item.
     *
     * @since 2.0.0
     *
     * @param array $cart_item_data Cart item data.
     * @param int   $product_id     Product ID.
     * @param int   $variation_id   Variation ID.
     * @return array Modified cart item data.
     */
    public static function add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
        // This is called for standard add-to-cart; our AJAX handler adds data directly.
        // Handle URL parameter add-to-cart for backwards compatibility.

        if ( isset( $_REQUEST['sd_allocation'] ) ) {
            $cart_item_data['sd_allocation'] = sanitize_key( $_REQUEST['sd_allocation'] );
        }

        if ( isset( $_REQUEST['sd_amount'] ) ) {
            $amount = floatval( $_REQUEST['sd_amount'] );
            // Reject negative or zero amounts.
            if ( $amount > 0 ) {
                $cart_item_data['sd_custom_price'] = $amount;
            }
        }

        if ( isset( $_REQUEST['sd_campaign'] ) ) {
            $cart_item_data['sd_campaign_id'] = absint( $_REQUEST['sd_campaign'] );
        }

        if ( isset( $_REQUEST['sd_anonymous'] ) ) {
            $cart_item_data['sd_is_anonymous'] = true;
        }

        return $cart_item_data;
    }

    /**
     * Display custom cart item data.
     *
     * @since 2.0.0
     *
     * @param array $item_data Cart item display data.
     * @param array $cart_item Cart item.
     * @return array Modified display data.
     */
    public static function display_cart_item_data( array $item_data, array $cart_item ): array {
        // Allocation.
        if ( ! empty( $cart_item['sd_allocation'] ) ) {
            $allocations = \Starter_Shelter\Core\Config::get_item( 'settings', 'allocations', [] );
            $allocation_label = $allocations[ $cart_item['sd_allocation'] ] ?? ucwords( str_replace( '-', ' ', $cart_item['sd_allocation'] ) );

            $item_data[] = [
                'key'   => __( 'Allocation', 'shelterkit-donations' ),
                'value' => $allocation_label,
            ];
        }

        // Campaign.
        if ( ! empty( $cart_item['sd_campaign_id'] ) ) {
            $campaign = get_term( $cart_item['sd_campaign_id'], 'sd_campaign' );
            if ( $campaign && ! is_wp_error( $campaign ) ) {
                $item_data[] = [
                    'key'   => __( 'Campaign', 'shelterkit-donations' ),
                    'value' => $campaign->name,
                ];
            }
        }

        // Dedication.
        if ( ! empty( $cart_item['sd_dedication_enabled'] ) ) {
            $dedication_type = $cart_item['sd_dedication_type'] ?? 'honor';
            $type_labels = [
                'honor'  => __( 'In Honor Of', 'shelterkit-donations' ),
                'memory' => __( 'In Memory Of', 'shelterkit-donations' ),
            ];

            if ( ! empty( $cart_item['sd_honoree_name'] ) ) {
                $item_data[] = [
                    'key'   => $type_labels[ $dedication_type ] ?? __( 'Dedication', 'shelterkit-donations' ),
                    'value' => $cart_item['sd_honoree_name'],
                ];
            }

            if ( ! empty( $cart_item['sd_honoree_type'] ) ) {
                $honoree_types = [
                    'person' => __( 'Person', 'shelterkit-donations' ),
                    'pet'    => __( 'Pet', 'shelterkit-donations' ),
                ];
                $item_data[] = [
                    'key'   => __( 'Honoree Type', 'shelterkit-donations' ),
                    'value' => $honoree_types[ $cart_item['sd_honoree_type'] ] ?? $cart_item['sd_honoree_type'],
                ];
            }

            if ( ! empty( $cart_item['sd_tribute_message'] ) ) {
                $item_data[] = [
                    'key'   => __( 'Tribute Message', 'shelterkit-donations' ),
                    'value' => wp_trim_words( $cart_item['sd_tribute_message'], 20 ),
                ];
            }
        }

        // Donor name.
        if ( ! empty( $cart_item['sd_donor_name'] ) ) {
            $item_data[] = [
                'key'   => __( 'Donor Name', 'shelterkit-donations' ),
                'value' => $cart_item['sd_donor_name'],
            ];
        }

        // Anonymous.
        if ( ! empty( $cart_item['sd_is_anonymous'] ) ) {
            $item_data[] = [
                'key'   => __( 'Anonymous', 'shelterkit-donations' ),
                'value' => __( 'Yes', 'shelterkit-donations' ),
            ];
        }

        // Membership tier.
        if ( ! empty( $cart_item['sd_membership_tier'] ) ) {
            $item_data[] = [
                'key'   => __( 'Membership Level', 'shelterkit-donations' ),
                'value' => ucwords( str_replace( '-', ' ', $cart_item['sd_membership_tier'] ) ),
            ];
        }

        // Business name.
        if ( ! empty( $cart_item['sd_business_name'] ) ) {
            $item_data[] = [
                'key'   => __( 'Business Name', 'shelterkit-donations' ),
                'value' => $cart_item['sd_business_name'],
            ];
        }

        // Business logo.
        if ( ! empty( $cart_item['sd_logo_attachment_id'] ) ) {
            $logo_url = wp_get_attachment_image_url( $cart_item['sd_logo_attachment_id'], 'thumbnail' );
            if ( $logo_url ) {
                $item_data[] = [
                    'key'     => __( 'Business Logo', 'shelterkit-donations' ),
                    'value'   => __( 'Uploaded (pending review)', 'shelterkit-donations' ),
                    'display' => '<img src="' . esc_url( $logo_url ) . '" alt="" style="max-width:60px;max-height:40px;vertical-align:middle;"> '
                        . esc_html__( 'Pending review', 'shelterkit-donations' ),
                ];
            }
        }

        return $item_data;
    }

    /**
     * Save cart item data to order item.
     *
     * @since 2.0.0
     *
     * @param \WC_Order_Item_Product $item          Order item.
     * @param string                 $cart_item_key Cart item key.
     * @param array                  $values        Cart item values.
     * @param \WC_Order              $order         Order.
     */
    public static function save_cart_item_to_order( $item, $cart_item_key, $values, $order ): void {
        $meta_keys = [
            'sd_product_type',
            'sd_donor_name',
            'sd_allocation',
            'sd_campaign_id',
            'sd_is_anonymous',
            'sd_dedication_enabled',
            'sd_dedication_type',
            'sd_honoree_name',
            'sd_honoree_type',
            'sd_tribute_message',
            'sd_notify_family_enabled',
            'sd_notify_family_name',
            'sd_notify_family_email',
            'sd_notify_family_address',
            'sd_notify_family_send_card',
            'sd_membership_tier',
            'sd_business_name',
            'sd_logo_attachment_id',
        ];

        foreach ( $meta_keys as $key ) {
            if ( isset( $values[ $key ] ) ) {
                $item->add_meta_data( '_' . $key, $values[ $key ], true );
            }
        }
    }

    /**
     * Set custom price for donation items.
     *
     * @since 2.0.0
     *
     * @param \WC_Cart $cart Cart object.
     */
    public static function set_custom_price( $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['sd_custom_price'] ) && $cart_item['sd_custom_price'] > 0 ) {
                $cart_item['data']->set_price( $cart_item['sd_custom_price'] );
            }
        }
    }

    /**
     * Handle business logo file upload.
     *
     * Creates a WordPress media attachment from the uploaded file.
     * Called during add-to-cart so the attachment ID (not the file)
     * travels through the cart → order pipeline. This survives
     * payment gateway redirects (PayPal Smart Buttons, etc.) that
     * would otherwise lose file data from the original request.
     *
     * The logo starts with status 'pending' and goes through the
     * admin moderation flow (Logo_Moderation class).
     *
     * @since 2.1.0
     *
     * @return int|\WP_Error Attachment ID or error.
     */
    private static function handle_logo_upload() {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Validate file type via WP's server-side check rather than the
        // client-supplied $_FILES[..]['type'] (trivially spoofable).
        // SVG intentionally excluded — SVGs can contain embedded JavaScript
        // and are a known XSS vector. Only raster formats are safe for
        // user-uploaded content served inline.
        $file_check = wp_check_filetype_and_ext(
            // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- private, reachable only from ajax_add_to_cart(), which verifies the nonce and rate-limits first. $_FILES entries go to wp_check_filetype_and_ext() and media_handle_upload(), which is what validates an upload; sanitize_text_field() on a tmp path would corrupt it.
            $_FILES['business_logo']['tmp_name'] ?? '',
            $_FILES['business_logo']['name'] ?? '',
            [
                'png'      => 'image/png',
                'jpg|jpeg' => 'image/jpeg',
            ]
        );
        if ( empty( $file_check['ext'] ) || empty( $file_check['type'] ) ) {
            return new \WP_Error(
                'invalid_file_type',
                __( 'Logo must be a PNG or JPG file.', 'shelterkit-donations' )
            );
        }

        // Validate file size (2MB).
        if ( ( $_FILES['business_logo']['size'] ?? 0 ) > 2 * 1024 * 1024 ) {
            // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return new \WP_Error(
                'file_too_large',
                __( 'Logo must be under 2MB.', 'shelterkit-donations' )
            );
        }

        // Use WordPress media handling to upload and create attachment.
        $attachment_id = media_handle_upload( 'business_logo', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return new \WP_Error(
                'upload_failed',
                __( 'Logo upload failed. Please try again.', 'shelterkit-donations' )
            );
        }

        // Mark as pending review.
        update_post_meta( $attachment_id, '_sd_logo_status', 'pending' );

        return $attachment_id;
    }

    /**
     * Validate add to cart.
     *
     * @since 2.0.0
     *
     * @param bool  $passed     Validation passed.
     * @param int   $product_id Product ID.
     * @param int   $quantity   Quantity.
     * @param int   $variation_id Variation ID.
     * @param array $variations Variations.
     * @return bool Validation result.
     */
    public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ): bool {
        // Check if this is a shelter product.
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return $passed;
        }

        $sku = $product->get_sku();
        $config = Product_Mapper::find_by_sku( $sku );

        if ( ! $config ) {
            return $passed;
        }

        // Validate custom amount if provided.
        if ( isset( $_REQUEST['sd_amount'] ) ) {
            $amount = floatval( $_REQUEST['sd_amount'] );

            if ( $amount < 1 ) {
                wc_add_notice( __( 'Please enter a valid donation amount.', 'shelterkit-donations' ), 'error' );
                return false;
            }

            $max_amount = apply_filters( 'starter_shelter_max_donation_amount', 100000 );

            if ( $amount > $max_amount ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: maximum amount */
                        __( 'The maximum donation amount is %s.', 'shelterkit-donations' ),
                        wc_price( $max_amount )
                    ),
                    'error'
                );
                return false;
            }
        }

        return $passed;
    }
}
