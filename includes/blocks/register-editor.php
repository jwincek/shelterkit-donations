<?php
/**
 * Block Editor Scripts - Provides localized data for block editor scripts.
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Register editor assets and localize data for all shelter blocks.
 *
 * @since 2.0.0
 */
function register_editor_assets(): void {
    // Register block binding sources on the client side.
    // The names must match the PHP register_block_bindings_source() calls.
    wp_enqueue_script(
        'shelter-donations-block-bindings',
        STARTER_SHELTER_URL . 'assets/js/block-bindings.js',
        [ 'wp-blocks' ],
        filemtime( STARTER_SHELTER_PATH . 'assets/js/block-bindings.js' ),
        true
    );

    // Block editor scripts auto-registered from block.json "editorScript"
    // lack dependency metadata (no .asset.php from build step). We need to
    // add wp-server-side-render explicitly since every edit.js uses SSR.
    $block_handles = [
        'shelter-donations-donation-form-editor-script',
        'shelter-donations-memorial-form-editor-script',
        'shelter-donations-membership-form-editor-script',
        'shelter-donations-memorial-wall-editor-script',
        'shelter-donations-campaign-progress-editor-script',
        'shelter-donations-campaign-card-editor-script',
        'shelter-donations-donor-dashboard-editor-script',
        'shelter-donations-contribution-tabs-editor-script',
    ];

    // All edit.js files use wp.serverSideRender, wp.blockEditor,
    // wp.components, wp.element, wp.i18n, and wp.blocks.
    $required_deps = [
        'wp-blocks',
        'wp-element',
        'wp-components',
        'wp-block-editor',
        'wp-i18n',
        'wp-server-side-render',
    ];

    foreach ( $block_handles as $handle ) {
        $script = wp_scripts()->query( $handle );
        if ( $script ) {
            $script->deps = array_unique( array_merge( $script->deps, $required_deps ) );
        }
    }

    // Localize data for each block's editor script
    $data = [
        'campaigns'   => get_campaigns_for_editor(),
        'allocations' => get_allocations_for_editor(),
        'years'       => get_memorial_years(),
        'tiers'       => [
            'individual' => \Starter_Shelter\Core\Config::get_item( 'tiers', 'individual', [] ),
            'business'   => \Starter_Shelter\Core\Config::get_item( 'tiers', 'business', [] ),
        ],
    ];

    // NOTE: sd_memorial intentionally uses the classic editor + meta
    // boxes (Meta_Boxes, manifest-driven) rather than the block editor.
    // assets/js/memorial-panel.js implements an alternative
    // PluginDocumentSettingPanel UX but is currently un-enqueued — see
    // the header note in that file for why it was parked.

    // Add inline script to window before any block scripts load
    add_action( 'admin_print_scripts', function() use ( $data ) {
        echo '<script>window.shelterDonationsBlocks = ' . wp_json_encode( $data ) . ';</script>';
    }, 5 );
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\register_editor_assets' );

/**
 * Get campaigns for editor dropdown.
 *
 * @since 2.0.0
 *
 * @return array Campaign options.
 */
function get_campaigns_for_editor(): array {
    $campaigns = get_terms( [
        'taxonomy'   => 'sd_campaign',
        'hide_empty' => false,
    ] );

    if ( is_wp_error( $campaigns ) ) {
        return [];
    }

    $options = [
        [ 'value' => 0, 'label' => __( '— Select Campaign —', 'shelter-donations' ) ],
    ];

    foreach ( $campaigns as $campaign ) {
        $options[] = [
            'value' => $campaign->term_id,
            'label' => $campaign->name,
        ];
    }

    return $options;
}

/**
 * Get allocations for editor dropdown.
 *
 * @since 2.0.0
 *
 * @return array Allocation options.
 */
function get_allocations_for_editor(): array {
    $allocations = \Starter_Shelter\Core\Config::get_item( 'settings', 'allocations', [] );

    if ( empty( $allocations ) ) {
        $allocations = [
            'general-fund'      => __( 'General Fund', 'shelter-donations' ),
            'medical-care'      => __( 'Medical Care', 'shelter-donations' ),
            'food-supplies'     => __( 'Food & Supplies', 'shelter-donations' ),
            'facility'          => __( 'Facility Improvements', 'shelter-donations' ),
            'rescue-operations' => __( 'Rescue Operations', 'shelter-donations' ),
        ];
    }

    return array_map(
        fn( $key, $label ) => [ 'value' => $key, 'label' => $label ],
        array_keys( $allocations ),
        array_values( $allocations )
    );
}

/**
 * Get memorial years for editor dropdown.
 *
 * @since 2.0.0
 *
 * @return array Year options.
 */
function get_memorial_years(): array {
    global $wpdb;

    $years = $wpdb->get_col( "
        SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts}
        WHERE post_type = 'sd_memorial' AND post_status = 'publish'
        ORDER BY YEAR(post_date) DESC
    " );

    $options = [
        [ 'value' => '', 'label' => __( 'All Years', 'shelter-donations' ) ],
    ];

    foreach ( $years as $year ) {
        $options[] = [
            'value' => $year,
            'label' => $year,
        ];
    }

    return $options;
}
