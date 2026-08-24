<?php
/**
 * Uninstall cleanup routines.
 *
 * Loaded by uninstall.php (where the plugin's normal bootstrap is NOT
 * available) and by the test suite. Relies only on WordPress core functions
 * and $wpdb — never on the plugin's classes, constants, autoloader, or its
 * CPTs/taxonomies being registered.
 *
 * Data is preserved by default; a site only purges when an admin has enabled
 * the "Delete all data on uninstall" setting. WooCommerce products (referenced
 * by orders) and media-library attachments (shared) are always preserved.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Run uninstall cleanup across the install (network-aware), honoring each
 * site's own opt-in.
 */
function starter_shelter_run_uninstall(): void {
    if ( is_multisite() ) {
        foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            starter_shelter_maybe_purge_current_site();
            restore_current_blog();
        }
        return;
    }

    starter_shelter_maybe_purge_current_site();
}

/**
 * Purge the current site's plugin data only when the admin opted in.
 */
function starter_shelter_maybe_purge_current_site(): void {
    $options = get_option( 'starter_shelter_options', [] );

    if ( empty( $options['delete_data_on_uninstall'] ) ) {
        return; // Preserve by default.
    }

    starter_shelter_purge_site_data();
}

/**
 * Delete all plugin-owned data for the current site.
 */
function starter_shelter_purge_site_data(): void {
    global $wpdb;

    // 1. CPT posts + their meta and term relationships. The post_type strings
    // work even though the CPTs aren't registered at uninstall time.
    $ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type IN ('sd_donation','sd_membership','sd_memorial','sd_donor')"
    );
    foreach ( $ids as $id ) {
        wp_delete_post( (int) $id, true );
    }

    // 2. Taxonomy terms (taxonomies aren't registered at uninstall, so operate
    // on the tables directly).
    foreach ( [ 'sd_campaign', 'sd_memorial_year', 'sd_donation_allocation' ] as $taxonomy ) {
        starter_shelter_delete_taxonomy_terms( $taxonomy );
    }

    // 3. Activity-log table. Guarded by a (non-DDL) existence check so we don't
    // issue a DROP — which implicitly commits — when there's nothing to drop.
    $table = $wpdb->prefix . 'sd_activity_log';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
        $wpdb->query( "DROP TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    // 4. Options: the settings blob plus every sd_-prefixed option (cached
    // config, product-id pointers, sync bookkeeping). `\_` escapes the LIKE
    // wildcard to match a literal underscore.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name = 'starter_shelter_options' OR option_name LIKE 'sd\\_%'"
    );

    // 5. Candle-list user meta.
    $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => '_sd_candles' ] );

    // 6. Plugin transients (and their timeout twins).
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_sd\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_sd\\_%'"
    );
}

/**
 * Delete every term in a taxonomy via direct SQL, leaving a shared term row
 * intact if another taxonomy still references it.
 *
 * @param string $taxonomy Taxonomy slug.
 */
function starter_shelter_delete_taxonomy_terms( string $taxonomy ): void {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
        $taxonomy
    ) );
    if ( empty( $rows ) ) {
        return;
    }

    foreach ( $rows as $row ) {
        $tt_id   = (int) $row->term_taxonomy_id;
        $term_id = (int) $row->term_id;

        $wpdb->delete( $wpdb->term_relationships, [ 'term_taxonomy_id' => $tt_id ] );
        $wpdb->delete( $wpdb->term_taxonomy, [ 'term_taxonomy_id' => $tt_id ] );

        // Only remove the term itself if no other taxonomy still uses it.
        $still_used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
            $term_id
        ) );
        if ( 0 === $still_used ) {
            $wpdb->delete( $wpdb->terms, [ 'term_id' => $term_id ] );
            $wpdb->delete( $wpdb->termmeta, [ 'term_id' => $term_id ] );
        }
    }
}
