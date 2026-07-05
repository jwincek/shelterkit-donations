<?php
/**
 * Admin Meta Boxes - Auto-generated from entity schema.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\{ Config, Entity_Hydrator, Field_Manifest };
use Starter_Shelter\Helpers;

/**
 * Handles auto-generated meta boxes for shelter CPTs.
 *
 * @since 1.0.0
 */
class Meta_Boxes {

    /**
     * Meta box configurations by post type.
     *
     * @var array
     */
    private static array $meta_boxes = [];

    /**
     * Initialize meta boxes.
     */
    public static function init(): void {
        self::$meta_boxes = self::get_meta_box_config();

        foreach ( array_keys( self::$meta_boxes ) as $post_type ) {
            add_action( "add_meta_boxes_{$post_type}", [ self::class, 'register_meta_boxes' ] );
            add_action( "save_post_{$post_type}", [ self::class, 'save_meta_boxes' ], 10, 2 );
        }

        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    /**
     * Get meta box configuration for all post types.
     *
     * Manifest-owned entities (config/manifests/<entity>.php with a
     * `meta_boxes` block) replace the corresponding hard-coded entry.
     * Unmigrated entities continue to use the hard-coded definition
     * below; entities migrate one at a time.
     *
     * @return array Meta box configurations.
     */
    private static function get_meta_box_config(): array {
        $config = self::get_hard_coded_meta_box_config();

        foreach ( Field_Manifest::get_meta_boxes() as $post_type => $cfg ) {
            $config[ $post_type ] = $cfg;
        }

        return $config;
    }

    /**
     * Legacy hard-coded meta-box config. Each entry is migrated into
     * its entity manifest (`config/manifests/<entity>.php` `meta_boxes`
     * block) one at a time; this method shrinks as that happens.
     *
     * @return array Hard-coded meta box configurations for unmigrated
     *               entities only.
     */
    private static function get_hard_coded_meta_box_config(): array {
        return [
        ];
    }

    /**
     * Register meta boxes for a post type.
     */
    public static function register_meta_boxes( \WP_Post $post ): void {
        $post_type = $post->post_type;
        if ( ! isset( self::$meta_boxes[ $post_type ] ) ) {
            return;
        }

        foreach ( self::$meta_boxes[ $post_type ]['boxes'] as $box_id => $box ) {
            add_meta_box(
                'sd_' . $box_id,
                $box['title'],
                [ self::class, 'render_meta_box' ],
                $post_type,
                $box['context'] ?? 'normal',
                $box['priority'] ?? 'default',
                [ 'box_id' => $box_id, 'fields' => $box['fields'], 'show_when' => $box['show_when'] ?? null ]
            );
        }
    }

    /**
     * Render a meta box.
     */
    public static function render_meta_box( \WP_Post $post, array $meta_box ): void {
        $args = $meta_box['args'];
        $entity = Entity_Hydrator::get( $post->post_type, $post->ID );

        wp_nonce_field( 'sd_meta_box_' . $args['box_id'], 'sd_meta_box_' . $args['box_id'] . '_nonce' );

        $wrapper_attrs = $args['show_when'] ? ' data-show-when="' . esc_attr( wp_json_encode( $args['show_when'] ) ) . '"' : '';

        echo '<div class="sd-meta-box"' . $wrapper_attrs . '><table class="form-table sd-meta-fields">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $wrapper_attrs built from esc_attr() above; rest is static markup.
        foreach ( $args['fields'] as $field_id => $field ) {
            self::render_field( $field_id, $field, $entity, $post );
        }
        echo '</table></div>';
    }

    /**
     * Render a single field.
     */
    private static function render_field( string $field_id, array $field, array $entity, \WP_Post $post ): void {
        $type = $field['type'];
        $label = $field['label'] ?? '';
        $required = $field['required'] ?? false;
        $readonly = $field['readonly'] ?? false;
        $show_when = $field['show_when'] ?? null;

        $value = $entity[ $field_id ] ?? get_post_meta( $post->ID, '_sd_' . $field_id, true );

        $row_attrs = $show_when ? ' data-show-when="' . esc_attr( wp_json_encode( $show_when ) ) . '" style="display:none;"' : '';

        echo '<tr' . $row_attrs . '><th scope="row"><label for="sd_' . esc_attr( $field_id ) . '">' . esc_html( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $row_attrs built from esc_attr() above; other interpolations escaped inline.
        if ( $required ) echo ' <span class="required">*</span>';
        echo '</label></th><td>';

        switch ( $type ) {
            case 'text':
            case 'email':
            case 'tel':
            case 'url':
                $input_type = $type === 'url' ? 'url' : $type;
                printf( '<input type="%s" id="sd_%s" name="sd_%s" value="%s" class="regular-text" %s />', esc_attr( $input_type ), esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ), $readonly ? 'readonly' : '' );
                break;

            case 'currency':
                echo '<div class="sd-currency-input"><span class="sd-currency-symbol">$</span>';
                printf( '<input type="number" id="sd_%s" name="sd_%s" value="%s" class="regular-text" min="0" step="0.01" /></div>', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ) );
                break;

            case 'textarea':
                printf( '<textarea id="sd_%s" name="sd_%s" rows="%d" class="large-text">%s</textarea>', esc_attr( $field_id ), esc_attr( $field_id ), (int) ( $field['rows'] ?? 5 ), esc_textarea( $value ) );
                break;

            case 'select':
                $options = is_string( $field['options'] ) ? Config::get_item( 'settings', $field['options'], [] ) : $field['options'];
                echo '<select id="sd_' . esc_attr( $field_id ) . '" name="sd_' . esc_attr( $field_id ) . '">';
                echo '<option value="">' . esc_html__( '— Select —', 'shelter-donations' ) . '</option>';
                foreach ( $options as $opt_val => $opt_label ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $opt_val ), selected( $value, $opt_val, false ), esc_html( $opt_label ) );
                }
                echo '</select>';
                break;

            case 'tier_select':
                $type_val = $entity['membership_type'] ?? 'individual';
                $tiers_config = Config::get( 'tiers' );
                $all_tiers = $tiers_config['tiers'] ?? [];
                echo '<select id="sd_' . esc_attr( $field_id ) . '" name="sd_' . esc_attr( $field_id ) . '" class="sd-tier-select" data-tier-select>';
                echo '<option value="">' . esc_html__( '— Select Tier —', 'shelter-donations' ) . '</option>';
                foreach ( $all_tiers as $tier_type => $tiers ) {
                    foreach ( $tiers as $slug => $data ) {
                        $hidden = $tier_type !== $type_val ? ' style="display:none;"' : '';
                        $price = $data['amount'] ?? $data['price'] ?? 0;
                        printf( '<option value="%s" data-type="%s" %s%s>%s (%s)</option>', esc_attr( $slug ), esc_attr( $tier_type ), selected( $value, $slug, false ), $hidden, esc_html( $data['label'] ?? ucfirst( $slug ) ), esc_html( Helpers\format_currency( $price ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden is a static literal style attribute; all dynamic args escaped.
                    }
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf( '<input type="checkbox" id="sd_%s" name="sd_%s" value="1" %s />', esc_attr( $field_id ), esc_attr( $field_id ), checked( $value, true, false ) );
                break;

            case 'date':
                printf( '<input type="date" id="sd_%s" name="sd_%s" value="%s" />', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ? wp_date( 'Y-m-d', strtotime( $value ) ) : '' ) );
                break;

            case 'datetime':
                if ( ( $field['default'] ?? '' ) === 'now' && ! $value ) $value = current_time( 'mysql' );
                printf( '<input type="datetime-local" id="sd_%s" name="sd_%s" value="%s" />', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ? wp_date( 'Y-m-d\TH:i', strtotime( $value ) ) : '' ) );
                break;

            case 'image':
                $img_url = $value ? wp_get_attachment_image_url( $value, 'thumbnail' ) : '';
                echo '<div class="sd-image-upload"><div class="sd-image-preview">';
                if ( $img_url ) echo '<img src="' . esc_url( $img_url ) . '" />';
                echo '</div>';
                printf( '<input type="hidden" id="sd_%s" name="sd_%s" value="%s" />', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ) );
                echo '<button type="button" class="button sd-upload-image">' . esc_html__( 'Select Image', 'shelter-donations' ) . '</button>';
                echo '<button type="button" class="button sd-remove-image"' . ( ! $value ? ' style="display:none;"' : '' ) . '>' . esc_html__( 'Remove', 'shelter-donations' ) . '</button></div>';
                break;

            case 'post_select':
                $selected_title = $value ? get_the_title( $value ) : '';
                printf( '<select id="sd_%s" name="sd_%s" class="sd-post-select" data-post-type="%s">', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $field['post_type'] ?? 'post' ) );
                if ( $value && $selected_title ) printf( '<option value="%s" selected>%s</option>', esc_attr( $value ), esc_html( $selected_title ) );
                echo '</select>';
                break;

            case 'user_select':
                wp_dropdown_users( [ 'name' => 'sd_' . $field_id, 'id' => 'sd_' . $field_id, 'selected' => $value, 'show_option_none' => __( '— No User —', 'shelter-donations' ), 'option_none_value' => 0 ] );
                break;

            case 'order_link':
                if ( $value ) printf( '<a href="%s" class="button">%s #%d</a>', esc_url( admin_url( 'post.php?post=' . $value . '&action=edit' ) ), esc_html__( 'View Order', 'shelter-donations' ), (int) $value );
                else echo '<span class="description">' . esc_html__( 'Not linked to an order', 'shelter-donations' ) . '</span>';
                break;

            case 'currency_display':
                echo '<strong class="sd-currency-display">' . esc_html( Helpers\format_currency( $value ?: 0 ) ) . '</strong>';
                break;

            case 'number_display':
                echo '<strong>' . esc_html( number_format( (int) $value ) ) . '</strong>';
                break;

            case 'date_display':
            case 'datetime_display':
                echo $value ? esc_html( Helpers\format_date( $value ) ) : '—';
                break;

            case 'level_badge':
                $levels = [ 'new' => 'New', 'bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold', 'platinum' => 'Platinum' ];
                $class = $value ? 'sd-level--' . $value : '';
                echo '<span class="sd-level-badge ' . esc_attr( $class ) . '">' . esc_html( $levels[ $value ] ?? 'New' ) . '</span>';
                break;

            case 'status_badge':
                $statuses = [ 'pending' => [ 'Pending Review', 'sd-badge--warning' ], 'approved' => [ 'Approved', 'sd-badge--success' ], 'rejected' => [ 'Rejected', 'sd-badge--error' ] ];
                $logo_id = $entity['logo_attachment_id'] ?? 0;
                if ( ! $logo_id ) { echo '<span class="sd-badge sd-badge--muted">No Logo</span>'; break; }
                $status = $value ?: 'pending';
                $info = $statuses[ $status ] ?? $statuses['pending'];
                echo '<span class="sd-badge ' . esc_attr( $info[1] ) . '">' . esc_html( $info[0] ) . '</span>';
                break;
        }

        if ( ! empty( $field['description'] ) ) echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
        echo '</td></tr>';
    }

    /**
     * Save meta box data.
     */
    public static function save_meta_boxes( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $post_type = $post->post_type;
        if ( ! isset( self::$meta_boxes[ $post_type ] ) ) return;

        foreach ( self::$meta_boxes[ $post_type ]['boxes'] as $box_id => $box ) {
            $nonce_key = 'sd_meta_box_' . $box_id . '_nonce';
            if ( ! isset( $_POST[ $nonce_key ] ) || ! wp_verify_nonce( $_POST[ $nonce_key ], 'sd_meta_box_' . $box_id ) ) continue;

            foreach ( $box['fields'] as $field_id => $field ) {
                if ( ! empty( $field['readonly'] ) ) continue;

                $key = 'sd_' . $field_id;
                $meta_key = '_sd_' . $field_id;

                if ( isset( $_POST[ $key ] ) ) {
                    $value = self::sanitize_field( $_POST[ $key ], $field['type'] );
                    update_post_meta( $post_id, $meta_key, $value );
                } elseif ( 'checkbox' === $field['type'] ) {
                    update_post_meta( $post_id, $meta_key, 0 );
                }
            }

            // composite_save: after the flat per-field writes, assemble
            // those values into a single object-meta key. Closes the
            // admin-vs-abilities address-storage divergence — see
            // sd_donor manifest's address box for the canonical use.
            if ( ! empty( $box['composite_save'] ) ) {
                self::apply_composite_save( $post_id, $box['composite_save'] );
            }
        }
    }

    /**
     * Apply a composite_save directive: read the just-saved flat meta
     * keys, project them into the object-meta shape declared by
     * `field_map`, merge into any existing object value at `meta_key`,
     * and write. Preserves object keys not covered by field_map so
     * data written by other paths (e.g., the update-address ability's
     * `country` field) isn't clobbered by admin saves.
     */
    private static function apply_composite_save( int $post_id, array $cs ): void {
        $target_meta = $cs['meta_key']  ?? '';
        $field_map   = $cs['field_map'] ?? [];

        if ( '' === $target_meta || empty( $field_map ) ) {
            return;
        }

        $existing = get_post_meta( $post_id, $target_meta, true );
        $object   = is_array( $existing ) ? $existing : [];

        foreach ( $field_map as $field_id => $object_key ) {
            $flat_value = get_post_meta( $post_id, '_sd_' . $field_id, true );
            if ( '' === $flat_value || null === $flat_value ) {
                unset( $object[ $object_key ] );
            } else {
                $object[ $object_key ] = $flat_value;
            }
        }

        update_post_meta( $post_id, $target_meta, $object );
    }

    /**
     * Sanitize a field value based on type.
     */
    private static function sanitize_field( $value, string $type ) {
        return match ( $type ) {
            'email'                          => sanitize_email( $value ),
            'url'                            => esc_url_raw( $value ),
            'number', 'currency'             => floatval( $value ),
            'checkbox'                       => ! empty( $value ) ? 1 : 0,
            'textarea'                       => sanitize_textarea_field( $value ),
            'post_select', 'user_select', 'image' => absint( $value ),
            default                          => sanitize_text_field( $value ),
        };
    }

    /**
     * Enqueue admin assets for meta boxes.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) return;

        $screen = get_current_screen();
        if ( ! $screen || ! isset( self::$meta_boxes[ $screen->post_type ] ) ) return;

        wp_enqueue_media();

        // Use WooCommerce's bundled select2 (the plugin requires WC) instead of
        // a CDN — loading scripts/styles from external services is disallowed.
        // selectWoo is WC's select2 fork and still exposes $.fn.select2().
        $select2_handle = wp_script_is( 'selectWoo', 'registered' ) ? 'selectWoo'
            : ( wp_script_is( 'select2', 'registered' ) ? 'select2' : '' );
        if ( $select2_handle ) {
            wp_enqueue_script( $select2_handle );
            if ( wp_style_is( 'select2', 'registered' ) ) {
                wp_enqueue_style( 'select2' );
            }
        }

        $deps = array_values( array_filter( [ 'jquery', $select2_handle ] ) );
        wp_enqueue_script( 'sd-meta-boxes', STARTER_SHELTER_URL . 'assets/js/admin-meta-boxes.js', $deps, STARTER_SHELTER_VERSION, true );
        wp_localize_script( 'sd-meta-boxes', 'sdMetaBoxes', [ 'restUrl' => rest_url( 'wp/v2/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'selectImage' => __( 'Select Image', 'shelter-donations' ), 'useImage' => __( 'Use this image', 'shelter-donations' ) ] );

        wp_add_inline_style( 'wp-admin', '
            /* Meta box table layout */
            .sd-meta-box .form-table th { width: 150px; }
            .sd-meta-box .required { color: #dc3545; }
            
            /* Sidebar meta boxes - prevent overflow */
            #side-sortables .sd-meta-box .form-table,
            #side-sortables .sd-meta-box .form-table tbody,
            #side-sortables .sd-meta-box .form-table tr,
            #side-sortables .sd-meta-box .form-table th,
            #side-sortables .sd-meta-box .form-table td {
                display: block;
                width: 100%;
            }
            #side-sortables .sd-meta-box .form-table th {
                padding-bottom: 5px;
                font-weight: 600;
            }
            #side-sortables .sd-meta-box .form-table td {
                padding-bottom: 15px;
            }
            
            /* Force Select2 to respect container width in sidebars */
            #side-sortables .sd-meta-box .select2-container {
                width: 100% !important;
                max-width: 100% !important;
            }
            #side-sortables .sd-meta-box .sd-post-select,
            #side-sortables .sd-meta-box select {
                width: 100% !important;
                max-width: 100% !important;
            }
            #side-sortables .sd-meta-box input[type="text"],
            #side-sortables .sd-meta-box input[type="number"],
            #side-sortables .sd-meta-box input[type="date"],
            #side-sortables .sd-meta-box input[type="email"] {
                width: 100%;
                max-width: 100%;
            }
            #side-sortables .sd-meta-box .sd-currency-input {
                max-width: 100%;
            }
            #side-sortables .sd-meta-box .sd-currency-input input {
                flex: 1;
                min-width: 0;
            }
            
            /* Currency input */
            .sd-currency-input { display: flex; align-items: center; max-width: 200px; }
            .sd-currency-symbol { padding: 0 8px; background: #f0f0f1; border: 1px solid #8c8f94; border-right: 0; line-height: 28px; border-radius: 4px 0 0 4px; }
            .sd-currency-input input { border-radius: 0 4px 4px 0; max-width: 150px; }
            .sd-currency-display { color: #059669; font-size: 18px; }
            
            /* Image upload */
            .sd-image-upload { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
            .sd-image-preview { width: 100px; height: 100px; border: 1px dashed #ccc; border-radius: 4px; overflow: hidden; }
            .sd-image-preview img { width: 100%; height: 100%; object-fit: cover; }
            
            /* Post select - main area */
            .sd-post-select { min-width: 300px; }
            
            /* Tier select */
            .sd-tier-select { min-width: 250px; }
            
            /* Badges */
            .sd-level-badge, .sd-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; }
            .sd-level--bronze { background: #fef3c7; color: #92400e; }
            .sd-level--silver { background: #e5e7eb; color: #374151; }
            .sd-level--gold { background: #fef08a; color: #854d0e; }
            .sd-level--platinum { background: #e0e7ff; color: #3730a3; }
            .sd-badge--success { background: #d1fae5; color: #065f46; }
            .sd-badge--warning { background: #fef3c7; color: #92400e; }
            .sd-badge--error { background: #fee2e2; color: #991b1b; }
            .sd-badge--muted { background: #f3f4f6; color: #6b7280; }
        ' );
    }
}
