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
        'shelterkit-donations-block-bindings',
        STARTER_SHELTER_URL . 'assets/js/block-bindings.js',
        [ 'wp-blocks' ],
        filemtime( STARTER_SHELTER_PATH . 'assets/js/block-bindings.js' ),
        true
    );

    // Each block's editor-script dependencies are declared in its
    // blocks/<block>/edit.asset.php, which core reads when it registers the
    // block. That replaced a runtime patch here that looked handles up by
    // name: the 3.0.0 rename changed those names but not the ones core
    // derives from the frozen block names, so the patch matched nothing and
    // every edit.js ran before wp.element existed (#17).

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

    // NOTE: skdon_memorial intentionally uses the classic editor + meta
    // boxes (Meta_Boxes, manifest-driven) rather than the block editor.
    // assets/js/memorial-panel.js implements an alternative
    // PluginDocumentSettingPanel UX but is currently un-enqueued — see
    // the header note in that file for why it was parked.

    // Add inline script to window before any block scripts load
    add_action( 'admin_print_scripts', function () use ( $data ) {
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
        'taxonomy'   => 'skdon_campaign',
        'hide_empty' => false,
    ] );

    if ( is_wp_error( $campaigns ) ) {
        return [];
    }

    $options = [
        [ 'value' => 0, 'label' => __( '— Select Campaign —', 'shelterkit-donations' ) ],
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
    $allocations = \Starter_Shelter\Core\Allocations::active();

    if ( empty( $allocations ) ) {
        $allocations = [
            'general-fund'      => __( 'General Fund', 'shelterkit-donations' ),
            'medical-care'      => __( 'Medical Care', 'shelterkit-donations' ),
            'food-supplies'     => __( 'Food & Supplies', 'shelterkit-donations' ),
            'facility'          => __( 'Facility Improvements', 'shelterkit-donations' ),
            'rescue-operations' => __( 'Rescue Operations', 'shelterkit-donations' ),
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
        WHERE post_type = 'skdon_memorial' AND post_status = 'publish'
        ORDER BY YEAR(post_date) DESC
    " );

    $options = [
        [ 'value' => '', 'label' => __( 'All Years', 'shelterkit-donations' ) ],
    ];

    foreach ( $years as $year ) {
        $options[] = [
            'value' => $year,
            'label' => $year,
        ];
    }

    return $options;
}
