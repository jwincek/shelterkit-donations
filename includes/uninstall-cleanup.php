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

// The lists of every stored name, old and new (#30). Self-contained: it needs
// nothing from the plugin's bootstrap.
require_once __DIR__ . '/core/class-prefix-migration.php';

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
    // Scheduled events are not data, so they go whatever the preserve setting.
    // Deactivation normally clears them first; this catches a site where it
    // did not. The plugin is not loaded here, so the hooks are named directly.
    foreach ( \Starter_Shelter\Core\Prefix_Migration::CRON_HOOKS as $hook ) {
        wp_clear_scheduled_hook( $hook );
        wp_clear_scheduled_hook( \Starter_Shelter\Core\Prefix_Migration::new_name( $hook ) );
    }
    wp_clear_scheduled_hook( 'starter_shelter_renewal_reminders' );

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
    $prefix = \Starter_Shelter\Core\Prefix_Migration::class;

    // Old names as well as new: a site can be uninstalled before the plugin
    // ever loaded the release that moved its data (#30). Nothing here matches
    // by `LIKE 'sd\_%'`, which also deleted other plugins' options.
    $both = static fn ( array $old ): array => array_merge( $old, array_map( [ $prefix, 'new_name' ], $old ) );

    // 1. CPT posts + their meta and term relationships. The post_type strings
    // work even though the CPTs aren't registered at uninstall time.
    $types = $both( $prefix::POST_TYPES );
    $ids   = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a generated %s placeholder list.
        $types
    ) );
    foreach ( $ids as $id ) {
        wp_delete_post( (int) $id, true );
    }

    // 2. Taxonomy terms (taxonomies aren't registered at uninstall, so operate
    // on the tables directly).
    foreach ( $both( $prefix::TAXONOMIES ) as $taxonomy ) {
        starter_shelter_delete_taxonomy_terms( $taxonomy );
    }

    // 3. Activity-log table. DROP TABLE IF EXISTS needs no existence check,
    // and unlike SHOW TABLES it also sees the TEMPORARY tables tests create.
    foreach ( $both( [ $prefix::TABLE ] ) as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- the site prefix plus a constant.
    }

    // 4. Options: the settings blob plus every option the plugin owns, by name.
    $options = array_merge( [ 'starter_shelter_options' ], $prefix::all_option_names() );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $options ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a generated %s placeholder list.
        $options
    ) );

    // 5. User meta: the candle list and per-user options.
    $user_keys = $both( [ '_sd_candles' ] );
    foreach ( $prefix::USER_OPTIONS as $option ) {
        $user_keys[] = $wpdb->get_blog_prefix() . $option;
        $user_keys[] = $wpdb->get_blog_prefix() . $prefix::new_name( $option );
    }
    foreach ( $user_keys as $key ) {
        $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $key ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall.
    }

    // 6. Plugin transients (and their timeout twins): the fixed names, then
    // the ones that carry an id after a long, specific prefix.
    $transients = [];
    foreach ( $both( $prefix::TRANSIENTS ) as $name ) {
        $transients[] = '_transient_' . $name;
        $transients[] = '_transient_timeout_' . $name;
    }
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $transients ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a generated %s placeholder list.
        $transients
    ) );
    foreach ( $both( $prefix::TRANSIENT_PREFIXES ) as $name ) {
        foreach ( [ '_transient_', '_transient_timeout_' ] as $kind ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $kind . $name ) . '%'
            ) );
        }
    }
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
