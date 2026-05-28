<?php
/**
 * Admin Reports - Reports dashboard page.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Config;

/**
 * Handles the admin reports page with donation and membership statistics.
 *
 * @since 1.0.0
 */
class Reports {

    /**
     * Page slug.
     *
     * @since 1.0.0
     * @var string
     */
    private const PAGE_SLUG = 'starter-shelter-reports';

    /**
     * Submenu page hook name returned by add_submenu_page().
     *
     * Cached because the admin_enqueue_scripts callback receives the
     * hook name to filter on. Building the expected hook by hand is
     * fragile — WP derives it from the parent's sanitize_title(menu_title)
     * (see wp-admin/includes/plugin.php::get_plugin_page_hookname).
     *
     * @since 1.1.3
     */
    private static string $page_hook = '';

    /**
     * Initialize reports page.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_reports_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_sd_export_report', [ self::class, 'handle_export' ] );
    }

    /**
     * Add reports page to admin menu.
     *
     * @since 1.0.0
     */
    public static function add_reports_page(): void {
        self::$page_hook = (string) add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Shelter Donations Reports', 'starter-shelter' ),
            __( 'Reports', 'starter-shelter' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_reports_page' ]
        );
    }

    /**
     * Enqueue admin assets for reports page.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_assets( string $hook ): void {
        // Compare against the hook returned by add_submenu_page (cached
        // in self::$page_hook). WP derives the hook from the parent's
        // sanitize_title(menu_title), NOT the parent slug — so building
        // the expected name from MENU_SLUG would silently mismatch.
        if ( '' === self::$page_hook || $hook !== self::$page_hook ) {
            return;
        }

        wp_enqueue_style(
            'sd-reports',
            STARTER_SHELTER_URL . 'assets/css/admin-reports.css',
            [],
            STARTER_SHELTER_VERSION
        );

        wp_enqueue_script(
            'sd-reports',
            STARTER_SHELTER_URL . 'assets/js/admin-reports.js',
            [ 'jquery', 'wp-api-fetch' ],
            STARTER_SHELTER_VERSION,
            true
        );

        // Nonce action must match the one check_ajax_referer expects in
        // handle_export() — otherwise the Export CSV button silently fails
        // via wp_die() when the user clicks it.
        wp_localize_script( 'sd-reports', 'sdReports', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sd_export_report' ),
        ] );
    }

    /**
     * Render the reports page.
     *
     * @since 1.0.0
     */
    public static function render_reports_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab  = sanitize_key( $_GET['tab'] ?? 'donations' );
        $period      = sanitize_key( $_GET['period'] ?? 'month' );
        $campaign_id = absint( $_GET['campaign_id'] ?? 0 );

        // Validate campaign_id against the taxonomy — drop garbage IDs
        // before they reach ability inputs (defense-in-depth; the list
        // abilities also call whereInTaxonomy which would no-op).
        if ( $campaign_id > 0 ) {
            $campaign_term = get_term( $campaign_id, 'sd_campaign' );
            if ( ! $campaign_term || is_wp_error( $campaign_term ) ) {
                $campaign_id = 0;
            }
        }

        $campaign_terms = get_terms( [
            'taxonomy'   => 'sd_campaign',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );
        if ( is_wp_error( $campaign_terms ) ) {
            $campaign_terms = [];
        }

        ?>
        <div class="wrap sd-reports">
            <h1><?php esc_html_e( 'Shelter Donations Reports', 'starter-shelter' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'donations' ) ); ?>" 
                   class="nav-tab <?php echo 'donations' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Donations', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'memberships' ) ); ?>" 
                   class="nav-tab <?php echo 'memberships' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Memberships', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'memorials' ) ); ?>" 
                   class="nav-tab <?php echo 'memorials' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Memorials', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'campaigns' ) ); ?>" 
                   class="nav-tab <?php echo 'campaigns' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Campaigns', 'starter-shelter' ); ?>
                </a>
            </nav>

            <div class="sd-reports-filters">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>" />
                    
                    <select name="period" id="sd-period-filter" onchange="document.getElementById('sd-custom-range').style.display = this.value === 'custom' ? 'inline-flex' : 'none';">
                        <option value="today" <?php selected( $period, 'today' ); ?>>
                            <?php esc_html_e( 'Today', 'starter-shelter' ); ?>
                        </option>
                        <option value="week" <?php selected( $period, 'week' ); ?>>
                            <?php esc_html_e( 'This Week', 'starter-shelter' ); ?>
                        </option>
                        <option value="month" <?php selected( $period, 'month' ); ?>>
                            <?php esc_html_e( 'This Month', 'starter-shelter' ); ?>
                        </option>
                        <option value="quarter" <?php selected( $period, 'quarter' ); ?>>
                            <?php esc_html_e( 'This Quarter', 'starter-shelter' ); ?>
                        </option>
                        <option value="year" <?php selected( $period, 'year' ); ?>>
                            <?php esc_html_e( 'This Year', 'starter-shelter' ); ?>
                        </option>
                        <option value="fiscal_year" <?php selected( $period, 'fiscal_year' ); ?>>
                            <?php esc_html_e( 'Fiscal Year', 'starter-shelter' ); ?>
                        </option>
                        <option value="all_time" <?php selected( $period, 'all_time' ); ?>>
                            <?php esc_html_e( 'All Time', 'starter-shelter' ); ?>
                        </option>
                        <option value="custom" <?php selected( $period, 'custom' ); ?>>
                            <?php esc_html_e( 'Custom Range', 'starter-shelter' ); ?>
                        </option>
                    </select>

                    <span id="sd-custom-range" style="display: <?php echo 'custom' === $period ? 'inline-flex' : 'none'; ?>; gap: 5px; align-items: center;">
                        <input type="date" name="date_from" value="<?php echo esc_attr( sanitize_text_field( $_GET['date_from'] ?? '' ) ); ?>" />
                        <span>—</span>
                        <input type="date" name="date_to" value="<?php echo esc_attr( sanitize_text_field( $_GET['date_to'] ?? '' ) ); ?>" />
                    </span>

                    <?php if ( 'campaigns' !== $active_tab && ! empty( $campaign_terms ) ) : ?>
                    <select name="campaign_id" id="sd-campaign-filter">
                        <option value="0"><?php esc_html_e( 'All Campaigns', 'starter-shelter' ); ?></option>
                        <?php foreach ( $campaign_terms as $term ) : ?>
                            <option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $campaign_id, $term->term_id ); ?>>
                                <?php echo esc_html( $term->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <button type="submit" class="button">
                        <?php esc_html_e( 'Filter', 'starter-shelter' ); ?>
                    </button>
                    
                    <?php if ( 'campaigns' !== $active_tab ) : // Campaigns tab has per-row export links in the table; the top button has no campaign_id to send. ?>
                    <button type="button" class="button sd-export-btn" data-tab="<?php echo esc_attr( $active_tab ); ?>">
                        <?php esc_html_e( 'Export CSV', 'starter-shelter' ); ?>
                    </button>
                    <?php endif; ?>
                </form>
            </div>

            <div class="sd-reports-content">
                <?php
                if ( $campaign_id > 0 && isset( $campaign_term ) && $campaign_term ) {
                    printf(
                        '<p class="description"><em>%s</em></p>',
                        esc_html( sprintf(
                            /* translators: %s: campaign name. */
                            __( 'Filtered by campaign: %s. Donor totals (lifetime / new) are not campaign-scoped — they reflect the period only.', 'starter-shelter' ),
                            $campaign_term->name
                        ) )
                    );
                }
                switch ( $active_tab ) {
                    case 'memberships':
                        self::render_memberships_report( $period, $campaign_id );
                        break;
                    case 'memorials':
                        self::render_memorials_report( $period, $campaign_id );
                        break;
                    case 'campaigns':
                        self::render_campaigns_report( $period );
                        break;
                    default:
                        self::render_donations_report( $period, $campaign_id );
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Resolve a stats ability and execute it, echoing inline error
     * markup on failure. Returns null when the caller should bail.
     *
     * Eliminates the eight-line "get ability, check null, execute,
     * check WP_Error" prelude that the three report-tab renderers
     * had been hand-rolling.
     *
     * @since 1.1.3
     *
     * @param string $ability_id    Ability identifier (e.g. 'shelter-reports/dashboard-stats').
     * @param array  $args          Input array passed to execute().
     * @param string $error_message Localized "Unable to load X statistics." string
     *                              shown when the ability is unregistered.
     * @return array|null The ability's result, or null on failure.
     */
    private static function fetch_stats( string $ability_id, array $args, string $error_message ): ?array {
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_id ) : null;
        if ( ! $ability ) {
            echo '<p>' . esc_html( $error_message ) . '</p>';
            return null;
        }

        $stats = $ability->execute( $args );
        if ( is_wp_error( $stats ) ) {
            echo '<p class="error">' . esc_html( $stats->get_error_message() ) . '</p>';
            return null;
        }

        return is_array( $stats ) ? $stats : null;
    }

    /**
     * Render donations report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_donations_report( string $period, int $campaign_id = 0 ): void {
        $args = [ 'period' => $period ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $stats = self::fetch_stats(
            'shelter-donations/get-stats',
            $args,
            __( 'Unable to load donation statistics.', 'starter-shelter' )
        );
        if ( ! $stats ) {
            return;
        }
        ?>
        <div class="sd-stats-cards">
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( $stats['total_formatted'] ?? '$0.00' ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Total Donations', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $stats['donation_count'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Number of Donations', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $stats['donor_count'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Unique Donors', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( '$' . number_format( $stats['average_amount'] ?? 0, 2 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Average Donation', 'starter-shelter' ); ?></span>
            </div>
        </div>

        <?php
        // Donation trend chart.
        self::render_donation_trend_chart( $period, $campaign_id );
        ?>

        <?php if ( ! empty( $stats['by_allocation'] ) ) : ?>
        <h3><?php esc_html_e( 'By Allocation', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Allocation', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Count', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $stats['by_allocation'] as $allocation => $data ) : ?>
                <tr>
                    <td><?php echo esc_html( \Starter_Shelter\Helpers\get_allocation_label( $allocation ) ); ?></td>
                    <td><?php echo esc_html( number_format( $data['count'] ) ); ?></td>
                    <td><?php echo esc_html( '$' . number_format( $data['total'], 2 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php self::render_donations_table( $period, $campaign_id ); ?>
        <?php
    }

    /**
     * Render a "recent donations" table for the Donations tab.
     *
     * Calls shelter-donations/list (per_page=20, ordered newest first)
     * and renders a wp-list-table. Each row links to the post edit
     * screen; a "View all" link below points to the donations CPT list.
     *
     * @since 1.1.3
     */
    private static function render_donations_table( string $period, int $campaign_id = 0 ): void {
        $range = \Starter_Shelter\Helpers\get_date_range_for_period( $period );
        $args  = [
            'date_from' => $range['start'],
            'date_to'   => $range['end'],
            'per_page'  => 20,
        ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $result = self::fetch_stats(
            'shelter-donations/list',
            $args,
            __( 'Unable to load recent donations.', 'starter-shelter' )
        );
        $items = $result['items'] ?? [];
        if ( empty( $items ) ) {
            return;
        }

        $donor_map = self::batch_donor_info( array_column( $items, 'donor_id' ) );
        $total     = (int) ( $result['total'] ?? count( $items ) );
        ?>
        <h3><?php esc_html_e( 'Recent Donations', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Donor', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Amount', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Allocation', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Campaign', 'starter-shelter' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $donation ) :
                    $donor_id     = (int) ( $donation['donor_id'] ?? 0 );
                    $donor        = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
                    $display_name = $donation['is_anonymous']
                        ? __( 'Anonymous', 'starter-shelter' )
                        : ( $donation['donor_name'] ?: $donor['name'] ?: __( 'Unknown', 'starter-shelter' ) );
                    $campaigns    = is_array( $donation['campaign'] ?? null )
                        ? implode( ', ', array_filter( array_map( static fn( $c ) => is_array( $c ) ? ( $c['name'] ?? '' ) : '', $donation['campaign'] ) ) )
                        : '';
                    $edit_url     = (int) ( $donation['id'] ?? 0 ) > 0 ? get_edit_post_link( (int) $donation['id'] ) : '';
                ?>
                <tr>
                    <td><?php echo esc_html( $donation['date_formatted'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $display_name ); ?></td>
                    <td><?php echo esc_html( $donation['amount_formatted'] ?? '$' . number_format( (float) ( $donation['amount'] ?? 0 ), 2 ) ); ?></td>
                    <td><?php echo esc_html( $donation['allocation_label'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $campaigns ); ?></td>
                    <td>
                        <?php if ( $edit_url ) : ?>
                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'View', 'starter-shelter' ); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( $total > count( $items ) ) : ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_donation' ) ); ?>">
                <?php
                printf(
                    /* translators: %d: total donation count */
                    esc_html__( 'View all %d donations in the admin list', 'starter-shelter' ),
                    $total
                );
                ?>
            </a>
        </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render memberships report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_memberships_report( string $period, int $campaign_id = 0 ): void {
        $args = [ 'period' => $period ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $stats = self::fetch_stats(
            'shelter-reports/dashboard-stats',
            $args,
            __( 'Unable to load membership statistics.', 'starter-shelter' )
        );
        if ( ! $stats ) {
            return;
        }
        $membership_stats = $stats['memberships'] ?? [];

        ?>
        <div class="sd-stats-cards">
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $membership_stats['active'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Active Memberships', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $membership_stats['new'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'New This Period', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card sd-stat-warning">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $membership_stats['expiring_soon'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Expiring Soon', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( '$' . number_format( $membership_stats['revenue'] ?? 0, 2 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Membership Revenue', 'starter-shelter' ); ?></span>
            </div>
        </div>

        <?php
        // Retention metric.
        self::render_retention_metric();
        ?>

        <?php if ( ! empty( $membership_stats['by_tier'] ) ) : ?>
        <h3><?php esc_html_e( 'By Tier', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Tier', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Active', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Revenue', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $membership_stats['by_tier'] as $tier => $data ) : ?>
                <tr>
                    <td><?php echo esc_html( \Starter_Shelter\Helpers\get_tier_label( $tier ) ); ?></td>
                    <td><?php echo esc_html( number_format( $data['count'] ) ); ?></td>
                    <td><?php echo esc_html( '$' . number_format( $data['revenue'], 2 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php self::render_memberships_table( $campaign_id ); ?>
        <?php
    }

    /**
     * Render a "recent memberships" table for the Memberships tab.
     *
     * Period filter doesn't apply (the membership list ability has no
     * date-range input — memberships are filtered by status). Showing
     * 20 most recent regardless of period.
     *
     * @since 1.1.3
     */
    private static function render_memberships_table( int $campaign_id = 0 ): void {
        $args = [
            'status'   => 'all',
            'per_page' => 20,
        ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $result = self::fetch_stats(
            'shelter-memberships/list',
            $args,
            __( 'Unable to load recent memberships.', 'starter-shelter' )
        );
        $items = $result['items'] ?? [];
        if ( empty( $items ) ) {
            return;
        }

        $donor_map = self::batch_donor_info( array_column( $items, 'donor_id' ) );
        $total     = (int) ( $result['total'] ?? count( $items ) );
        ?>
        <h3><?php esc_html_e( 'Recent Memberships', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Member', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Tier', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Start', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'End', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'starter-shelter' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $membership ) :
                    $donor_id = (int) ( $membership['donor_id'] ?? 0 );
                    $donor    = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
                    $edit_url = (int) ( $membership['id'] ?? 0 ) > 0 ? get_edit_post_link( (int) $membership['id'] ) : '';
                ?>
                <tr>
                    <td><?php echo esc_html( $donor['name'] ?: __( 'Unknown', 'starter-shelter' ) ); ?></td>
                    <td><?php echo esc_html( $membership['tier_label'] ?? '' ); ?></td>
                    <td><?php echo esc_html( ucfirst( $membership['membership_type'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( $membership['start_date'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $membership['end_date'] ?? '' ); ?></td>
                    <td><?php echo ! empty( $membership['is_active'] ) ? esc_html__( 'Active', 'starter-shelter' ) : esc_html__( 'Expired', 'starter-shelter' ); ?></td>
                    <td>
                        <?php if ( $edit_url ) : ?>
                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'View', 'starter-shelter' ); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( $total > count( $items ) ) : ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_membership' ) ); ?>">
                <?php
                printf(
                    /* translators: %d: total membership count */
                    esc_html__( 'View all %d memberships in the admin list', 'starter-shelter' ),
                    $total
                );
                ?>
            </a>
        </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render memorials report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_memorials_report( string $period, int $campaign_id = 0 ): void {
        $args = [ 'period' => $period ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $stats = self::fetch_stats(
            'shelter-reports/dashboard-stats',
            $args,
            __( 'Unable to load memorial statistics.', 'starter-shelter' )
        );
        if ( ! $stats ) {
            return;
        }
        $memorial_stats = $stats['memorials'] ?? [];

        ?>
        <div class="sd-stats-cards">
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $memorial_stats['total'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Total Memorials', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $memorial_stats['new'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'New This Period', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( '$' . number_format( $memorial_stats['revenue'] ?? 0, 2 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Memorial Donations', 'starter-shelter' ); ?></span>
            </div>
        </div>

        <?php self::render_memorials_table( $campaign_id ); ?>
        <?php
    }

    /**
     * Render a "recent memorials" table for the Memorials tab.
     *
     * The memorial list ability filters by year, not period range —
     * showing 20 most recent regardless of period for now. (A later
     * pass could push period awareness down into the ability.)
     *
     * @since 1.1.3
     */
    private static function render_memorials_table( int $campaign_id = 0 ): void {
        $args = [
            'type'     => 'all',
            'per_page' => 20,
        ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $result = self::fetch_stats(
            'shelter-memorials/list',
            $args,
            __( 'Unable to load recent memorials.', 'starter-shelter' )
        );
        $items = $result['items'] ?? [];
        if ( empty( $items ) ) {
            return;
        }

        $donor_map = self::batch_donor_info( array_column( $items, 'donor_id' ) );
        $total     = (int) ( $result['total'] ?? count( $items ) );
        ?>
        <h3><?php esc_html_e( 'Recent Memorials', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Honoree', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Donor', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Amount', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Family Notified', 'starter-shelter' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $memorial ) :
                    $donor_id  = (int) ( $memorial['donor_id'] ?? 0 );
                    $donor     = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
                    $donor_lbl = $memorial['is_anonymous']
                        ? __( 'Anonymous', 'starter-shelter' )
                        : ( $memorial['donor_display_name'] ?? $memorial['donor_name'] ?? $donor['name'] ?? __( 'Unknown', 'starter-shelter' ) );
                    $edit_url  = (int) ( $memorial['id'] ?? 0 ) > 0 ? get_edit_post_link( (int) $memorial['id'] ) : '';
                    $notified  = ! empty( $memorial['family_notified_date'] )
                        ? esc_html( $memorial['family_notified_date'] )
                        : ( ! empty( $memorial['notify_family_enabled'] )
                            ? '<em>' . esc_html__( 'Pending', 'starter-shelter' ) . '</em>'
                            : '—' );
                ?>
                <tr>
                    <td><?php echo esc_html( $memorial['honoree_name'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $memorial['memorial_type_label'] ?? $memorial['memorial_type'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $donor_lbl ); ?></td>
                    <td><?php echo esc_html( $memorial['date_formatted'] ?? $memorial['donation_date'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $memorial['amount_formatted'] ?? '$' . number_format( (float) ( $memorial['amount'] ?? 0 ), 2 ) ); ?></td>
                    <td><?php echo wp_kses_post( $notified ); ?></td>
                    <td>
                        <?php if ( $edit_url ) : ?>
                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'View', 'starter-shelter' ); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( $total > count( $items ) ) : ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_memorial' ) ); ?>">
                <?php
                printf(
                    /* translators: %d: total memorial count */
                    esc_html__( 'View all %d memorials in the admin list', 'starter-shelter' ),
                    $total
                );
                ?>
            </a>
        </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render campaigns report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_campaigns_report( string $period ): void {
        $campaigns = get_terms( [
            'taxonomy'   => 'sd_campaign',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );

        if ( is_wp_error( $campaigns ) || empty( $campaigns ) ) {
            echo '<p>' . esc_html__( 'No campaigns found.', 'starter-shelter' ) . '</p>';
            return;
        }

        // Type-aware progress comes from the single-source ability also
        // consumed by campaign-card. One ability call per campaign — fine
        // for typical campaign counts (<50). If this grows, the cleanup
        // is a batch ability `shelter-reports/campaigns-progress` or a
        // get_term_meta prime + computation here.
        $progress_ability = function_exists( 'wp_get_ability' )
            ? wp_get_ability( 'shelter-reports/campaign-progress' )
            : null;
        ?>
        <p class="description">
            <em><?php esc_html_e( 'Campaigns show lifetime progress — the period selector above does not affect this tab.', 'starter-shelter' ); ?></em>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Campaign', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Goal', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Progress', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Ends', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $campaigns as $campaign ) :
                    // Fall back to a synthetic row if the ability isn't
                    // registered, so admins still see something useful.
                    $data = $progress_ability
                        ? $progress_ability->execute( [ 'campaign_id' => $campaign->term_id ] )
                        : null;
                    if ( is_wp_error( $data ) || ! is_array( $data ) ) {
                        $data = [
                            'goal_unit'         => 'currency',
                            'goal_formatted'    => '—',
                            'raised_formatted'  => '—',
                            'member_count'      => 0,
                            'progress'          => 0,
                            'end_date'          => (string) get_term_meta( $campaign->term_id, '_sd_end_date', true ),
                            'is_active'         => true,
                        ];
                    }

                    $is_member_drive = 'members' === ( $data['goal_unit'] ?? 'currency' );
                    $is_active       = (bool) ( $data['is_active'] ?? true );
                    $percent         = (float) ( $data['progress'] ?? 0 );
                    $end_date        = (string) ( $data['end_date'] ?? '' );

                    if ( $is_member_drive ) {
                        $progress_text = sprintf(
                            /* translators: 1: members joined, 2: goal formatted (e.g. "100 members"). */
                            __( '%1$d / %2$s', 'starter-shelter' ),
                            (int) ( $data['member_count'] ?? 0 ),
                            $data['goal_formatted'] ?? ''
                        );
                    } else {
                        $progress_text = sprintf(
                            /* translators: 1: dollars raised, 2: dollars goal. */
                            __( '%1$s / %2$s', 'starter-shelter' ),
                            $data['raised_formatted'] ?? '$0',
                            $data['goal_formatted']   ?? '$0'
                        );
                    }
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $campaign->name ); ?></strong></td>
                    <td>
                        <span class="sd-pill <?php echo $is_member_drive ? 'sd-pill--membership' : 'sd-pill--donation'; ?>">
                            <?php echo esc_html( $is_member_drive
                                ? __( 'Membership', 'starter-shelter' )
                                : __( 'Donation', 'starter-shelter' )
                            ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( $data['goal_formatted'] ?? '—' ); ?></td>
                    <td>
                        <span class="sd-campaign-progress-text">
                            <?php echo esc_html( $progress_text ); ?>
                            (<?php echo esc_html( number_format( $percent, 1 ) ); ?>%)
                        </span>
                        <div class="sd-progress-bar">
                            <div class="sd-progress-fill" style="width: <?php echo esc_attr( min( 100, $percent ) ); ?>%;"></div>
                        </div>
                    </td>
                    <td>
                        <?php echo $end_date
                            ? esc_html( \Starter_Shelter\Helpers\format_date( $end_date ) )
                            : '—'; ?>
                    </td>
                    <td>
                        <span class="sd-pill <?php echo $is_active ? 'sd-pill--active' : 'sd-pill--ended'; ?>">
                            <?php echo esc_html( $is_active
                                ? __( 'Active', 'starter-shelter' )
                                : __( 'Ended', 'starter-shelter' )
                            ); ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( [
                            'action'      => 'sd_export_report',
                            'report'      => 'campaign',
                            'campaign_id' => $campaign->term_id,
                            '_wpnonce'    => wp_create_nonce( 'sd_export_report' ),
                        ], admin_url( 'admin-ajax.php' ) ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'Export', 'starter-shelter' ); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render an inline SVG bar chart of donation totals over time.
     *
     * Groups donations by day (short periods), week, or month and
     * renders a simple bar chart. No external JS library needed.
     *
     * @since 2.1.0
     *
     * @param string $period The reporting period.
     */
    private static function render_donation_trend_chart( string $period, int $campaign_id = 0 ): void {
        $ability = function_exists( 'wp_get_ability' )
            ? wp_get_ability( 'shelter-reports/donation-trend' )
            : null;
        if ( ! $ability ) {
            return;
        }

        $args = [ 'period' => $period ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $data = $ability->execute( $args );
        if ( is_wp_error( $data ) || ! is_array( $data ) ) {
            return;
        }

        $buckets = $data['buckets'] ?? [];
        if ( empty( $buckets ) ) {
            return;
        }

        // Pick the date label format from the bucket type — 'day'/'week'
        // get a short month-day label, 'month' gets month-year.
        $label_format = 'month' === ( $data['bucket'] ?? 'day' ) ? 'M Y' : 'M j';

        $max_total = max( array_map( static fn( $b ) => (float) $b['total'], $buckets ) );
        $bar_count = count( $buckets );
        $chart_w   = 600;
        $chart_h   = 200;
        $bar_gap   = 4;
        $bar_w     = max( 8, min( 40, (int) ( ( $chart_w - ( $bar_count * $bar_gap ) ) / $bar_count ) ) );
        $total_w   = $bar_count * ( $bar_w + $bar_gap );

        ?>
        <div class="sd-trend-chart" style="margin: 20px 0;">
            <h3><?php esc_html_e( 'Donation Trend', 'starter-shelter' ); ?></h3>
            <div style="overflow-x: auto;">
                <svg viewBox="0 0 <?php echo $total_w; ?> <?php echo $chart_h + 30; ?>" width="<?php echo min( $total_w, $chart_w ); ?>" style="font-family: -apple-system, BlinkMacSystemFont, sans-serif;">
                    <!-- Grid lines -->
                    <?php for ( $i = 0; $i <= 4; $i++ ) :
                        $y = $chart_h - ( $chart_h * $i / 4 );
                    ?>
                    <line x1="0" y1="<?php echo $y; ?>" x2="<?php echo $total_w; ?>" y2="<?php echo $y; ?>" stroke="#e0e0e0" stroke-width="0.5" />
                    <?php endfor; ?>

                    <!-- Bars -->
                    <?php foreach ( $buckets as $i => $bucket ) :
                        $bar_h = $max_total > 0 ? ( (float) $bucket['total'] / $max_total ) * $chart_h : 0;
                        $x = $i * ( $bar_w + $bar_gap );
                        $y = $chart_h - $bar_h;
                        $label = wp_date( $label_format, strtotime( $bucket['period_start'] ) );
                    ?>
                    <g>
                        <rect x="<?php echo $x; ?>" y="<?php echo $y; ?>" width="<?php echo $bar_w; ?>" height="<?php echo $bar_h; ?>"
                            fill="#059669" rx="2" opacity="0.85">
                            <title><?php echo esc_attr( $label . ': $' . number_format( (float) $bucket['total'], 2 ) . ' (' . $bucket['count'] . ' donations)' ); ?></title>
                        </rect>
                        <?php if ( $bar_count <= 15 ) : ?>
                        <text x="<?php echo $x + $bar_w / 2; ?>" y="<?php echo $chart_h + 14; ?>" text-anchor="middle" fill="#666" font-size="9">
                            <?php echo esc_html( $label ); ?>
                        </text>
                        <?php elseif ( $i % 3 === 0 ) : ?>
                        <text x="<?php echo $x + $bar_w / 2; ?>" y="<?php echo $chart_h + 14; ?>" text-anchor="middle" fill="#666" font-size="8">
                            <?php echo esc_html( $label ); ?>
                        </text>
                        <?php endif; ?>
                    </g>
                    <?php endforeach; ?>
                </svg>
            </div>
        </div>
        <?php
    }

    /**
     * Render membership retention metric.
     *
     * Shows the percentage of memberships that have been renewed
     * (have more than one order associated, or have a renewal date).
     *
     * @since 2.1.0
     */
    private static function render_retention_metric(): void {
        $ability = function_exists( 'wp_get_ability' )
            ? wp_get_ability( 'shelter-reports/membership-retention' )
            : null;
        if ( ! $ability ) {
            return;
        }

        $stats = $ability->execute( [] );
        if ( is_wp_error( $stats ) || ! is_array( $stats ) ) {
            return;
        }

        $retention_rate = (float) ( $stats['retention_rate'] ?? 0 );
        $renewed_count  = (int) ( $stats['renewed_count'] ?? 0 );
        $expired_count  = (int) ( $stats['expired_count'] ?? 0 );

        $rate_color = $retention_rate >= 70 ? '#059669' : ( $retention_rate >= 40 ? '#d97706' : '#dc2626' );

        ?>
        <div class="sd-retention-metric" style="margin: 20px 0; padding: 15px 20px; background: #f6f7f7; border-radius: 4px; display: flex; align-items: center; gap: 20px;">
            <div style="text-align: center;">
                <span style="font-size: 32px; font-weight: 700; color: <?php echo esc_attr( $rate_color ); ?>;"><?php echo esc_html( $retention_rate ); ?>%</span>
                <br>
                <span style="font-size: 11px; text-transform: uppercase; color: #646970;"><?php esc_html_e( 'Renewal Rate', 'starter-shelter' ); ?></span>
            </div>
            <div style="font-size: 13px; color: #50575e;">
                <?php printf(
                    esc_html__( '%1$d of %2$d expired memberships renewed in the last 12 months.', 'starter-shelter' ),
                    $renewed_count,
                    $expired_count
                ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle CSV export via AJAX.
     *
     * @since 1.0.0
     */
    public static function handle_export(): void {
        check_ajax_referer( 'sd_export_report', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $report      = sanitize_key( $_GET['report'] ?? 'donations' );
        $period      = sanitize_key( $_GET['period'] ?? 'month' );
        $campaign_id = absint( $_GET['campaign_id'] ?? 0 );

        $filename_suffix = $campaign_id > 0 ? '-campaign-' . $campaign_id : '';
        $filename        = 'shelter-' . $report . '-' . $period . $filename_suffix . '-' . wp_date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        switch ( $report ) {
            case 'campaign':
                // Per-campaign export (link rendered with campaign_id in
                // the campaigns table). Distinct from the top-bar
                // Export CSV button which now also threads campaign_id
                // when the filter is set.
                self::export_campaign_report( $output );
                break;
            case 'memberships':
                self::export_memberships_report( $output, $period, $campaign_id );
                break;
            case 'memorials':
                self::export_memorials_report( $output, $period, $campaign_id );
                break;
            case 'donations':
            default:
                self::export_donations_report( $output, $period, $campaign_id );
                break;
        }

        fclose( $output );
        exit;
    }

    /**
     * Resolve donor display name + email for a set of donor IDs in one shot.
     *
     * The list abilities (shelter-donations / shelter-memberships /
     * shelter-memorials) return `donor_id` per row but not the donor's
     * display fields. Rather than fetch each donor individually inside
     * the export loop (N+1 queries), batch-hydrate via Query.
     *
     * @since 1.1.3
     *
     * @param int[] $donor_ids List of donor post IDs (duplicates / 0s allowed).
     * @return array<int, array{name:string, email:string}> Keyed by donor ID.
     */
    private static function batch_donor_info( array $donor_ids ): array {
        $ids = array_filter( array_unique( array_map( 'intval', $donor_ids ) ) );
        if ( empty( $ids ) ) {
            return [];
        }

        $donors = \Starter_Shelter\Core\Query::for( 'sd_donor' )
            ->withArgs( [ 'post__in' => $ids ] )
            ->get( count( $ids ) );

        $map = [];
        foreach ( $donors as $donor ) {
            $name = $donor['display_name'] ?? '';
            if ( '' === $name ) {
                $name = $donor['full_name'] ?? '';
            }
            $map[ (int) $donor['id'] ] = [
                'name'  => (string) $name,
                'email' => (string) ( $donor['email'] ?? '' ),
            ];
        }
        return $map;
    }

    /**
     * Export donations report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     * @param string   $period Report period.
     */
    private static function export_donations_report( $output, string $period, int $campaign_id = 0 ): void {
        // CSV headers.
        fputcsv( $output, [
            __( 'Date', 'starter-shelter' ),
            __( 'Donor', 'starter-shelter' ),
            __( 'Email', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
            __( 'Allocation', 'starter-shelter' ),
            __( 'Campaign', 'starter-shelter' ),
            __( 'Anonymous', 'starter-shelter' ),
        ] );

        // Use the list ability.
        $ability = wp_get_ability( 'shelter-donations/list' );
        if ( ! $ability ) {
            return;
        }

        $date_range = \Starter_Shelter\Helpers\get_date_range_for_period( $period );

        // Paginate through results — the shelter-donations/list schema
        // caps per_page at 100, so a single call with per_page=1000 (as
        // an earlier version of this code did) errors silently and
        // produces a header-only CSV. Loop until total_pages is reached.
        $per_page    = 100;
        $page        = 1;
        $total_pages = 1;
        $all_items   = [];

        do {
            $args = [
                'date_from' => $date_range['start'],
                'date_to'   => $date_range['end'],
                'page'      => $page,
                'per_page'  => $per_page,
            ];
            if ( $campaign_id > 0 ) {
                $args['campaign_id'] = $campaign_id;
            }
            $result = $ability->execute( $args );

            if ( is_wp_error( $result ) ) {
                return;
            }

            $items       = $result['items'] ?? [];
            $all_items   = array_merge( $all_items, $items );
            $total_pages = (int) ( $result['total_pages'] ?? 1 );
            $page++;
        } while ( $page <= $total_pages );

        // Batch-resolve donor display + email (the list ability returns
        // only donor_id, not the donor entity).
        $donor_map = self::batch_donor_info( array_column( $all_items, 'donor_id' ) );

        foreach ( $all_items as $donation ) {
            $donor_id = (int) ( $donation['donor_id'] ?? 0 );
            $donor    = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];

            // Campaign is a taxonomy relation — array of { id, name, slug }.
            // Flatten to a comma-separated list of names for the CSV column.
            $campaign_names = '';
            if ( ! empty( $donation['campaign'] ) && is_array( $donation['campaign'] ) ) {
                $campaign_names = implode(
                    ', ',
                    array_filter( array_map( static fn( $c ) => is_array( $c ) ? ( $c['name'] ?? '' ) : '', $donation['campaign'] ) )
                );
            }

            fputcsv( $output, [
                $donation['date_formatted'] ?? '',
                $donation['donor_name'] ?? $donor['name'],
                $donor['email'],
                $donation['amount'] ?? 0,
                $donation['allocation_label'] ?? '',
                $campaign_names,
                $donation['is_anonymous'] ? __( 'Yes', 'starter-shelter' ) : __( 'No', 'starter-shelter' ),
            ] );
        }
    }

    /**
     * Export memberships report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     * @param string   $period Report period.
     */
    private static function export_memberships_report( $output, string $period, int $campaign_id = 0 ): void {
        fputcsv( $output, [
            __( 'Member', 'starter-shelter' ),
            __( 'Email', 'starter-shelter' ),
            __( 'Tier', 'starter-shelter' ),
            __( 'Type', 'starter-shelter' ),
            __( 'Start Date', 'starter-shelter' ),
            __( 'End Date', 'starter-shelter' ),
            __( 'Status', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
        ] );

        $ability = wp_get_ability( 'shelter-memberships/list' );
        if ( ! $ability ) {
            return;
        }

        $args = [
            'status'   => 'all',
            'per_page' => 1000,
        ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $result = $ability->execute( $args );

        if ( is_wp_error( $result ) ) {
            return;
        }

        $items     = $result['items'] ?? [];
        $donor_map = self::batch_donor_info( array_column( $items, 'donor_id' ) );

        foreach ( $items as $membership ) {
            $donor_id = (int) ( $membership['donor_id'] ?? 0 );
            $donor    = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
            fputcsv( $output, [
                $donor['name'],
                $donor['email'],
                $membership['tier_label'] ?? '',
                $membership['membership_type'] ?? '',
                $membership['start_date'] ?? '',
                $membership['end_date'] ?? '',
                $membership['is_active'] ? __( 'Active', 'starter-shelter' ) : __( 'Expired', 'starter-shelter' ),
                $membership['amount'] ?? 0,
            ] );
        }
    }

    /**
     * Export memorials report to CSV.
     *
     * @since 1.1.3
     *
     * @param resource $output File handle.
     * @param string   $period Report period.
     */
    private static function export_memorials_report( $output, string $period, int $campaign_id = 0 ): void {
        fputcsv( $output, [
            __( 'Honoree', 'starter-shelter' ),
            __( 'Type', 'starter-shelter' ),
            __( 'Donor', 'starter-shelter' ),
            __( 'Email', 'starter-shelter' ),
            __( 'Date', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
            __( 'Family Notified', 'starter-shelter' ),
        ] );

        $ability = wp_get_ability( 'shelter-memorials/list' );
        if ( ! $ability ) {
            return;
        }

        // Period scope is intentional but the list ability filters by
        // year, not period range. For now, pull all and let the user
        // filter in their spreadsheet — the per-period semantics are
        // already reflected in the dashboard stats view.
        $args = [
            'type'     => 'all',
            'per_page' => 1000,
        ];
        if ( $campaign_id > 0 ) {
            $args['campaign_id'] = $campaign_id;
        }
        $result = $ability->execute( $args );

        if ( is_wp_error( $result ) ) {
            return;
        }

        $items     = $result['items'] ?? [];
        $donor_map = self::batch_donor_info( array_column( $items, 'donor_id' ) );

        foreach ( $items as $memorial ) {
            $donor_id = (int) ( $memorial['donor_id'] ?? 0 );
            $donor    = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
            fputcsv( $output, [
                $memorial['honoree_name'] ?? '',
                $memorial['memorial_type'] ?? '',
                $memorial['donor_display_name'] ?? $memorial['donor_name'] ?? $donor['name'],
                $donor['email'],
                $memorial['donation_date'] ?? '',
                $memorial['amount'] ?? 0,
                ! empty( $memorial['family_notified_date'] ) ? $memorial['family_notified_date'] : '',
            ] );
        }
    }

    /**
     * Export campaign report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     */
    private static function export_campaign_report( $output ): void {
        $campaign_id = absint( $_GET['campaign_id'] ?? 0 );

        if ( ! $campaign_id ) {
            return;
        }

        $ability = wp_get_ability( 'shelter-reports/campaign-report' );
        if ( ! $ability ) {
            return;
        }

        $result = $ability->execute( [ 'campaign_id' => $campaign_id ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        $campaign = $result['campaign'] ?? [];
        $progress = $result['progress'] ?? [];
        $type     = (string) ( $campaign['type'] ?? 'donation_drive' );

        // Summary block — header rows shared between types, then a type-
        // specific "Raised" or "Joined" line.
        fputcsv( $output, [ __( 'Campaign Report', 'starter-shelter' ), $campaign['name'] ?? '' ] );
        fputcsv( $output, [ __( 'Type', 'starter-shelter' ), 'donation_drive' === $type
            ? __( 'Donation Drive', 'starter-shelter' )
            : __( 'Membership Drive', 'starter-shelter' )
        ] );
        fputcsv( $output, [ __( 'Goal', 'starter-shelter' ), $campaign['goal_formatted'] ?? '' ] );

        if ( 'donation_drive' === $type ) {
            fputcsv( $output, [ __( 'Raised', 'starter-shelter' ), $progress['total_formatted'] ?? '' ] );
        } else {
            fputcsv( $output, [ __( 'Joined', 'starter-shelter' ), $progress['member_count'] ?? 0 ] );
        }
        fputcsv( $output, [ __( 'Progress', 'starter-shelter' ), ( $progress['percent_of_goal'] ?? 0 ) . '%' ] );
        fputcsv( $output, [] );

        if ( 'donation_drive' === $type ) {
            $donations = $result['donations'] ?? [];
            $donor_map = self::batch_donor_info( array_column( $donations, 'donor_id' ) );

            fputcsv( $output, [
                __( 'Date', 'starter-shelter' ),
                __( 'Donor', 'starter-shelter' ),
                __( 'Email', 'starter-shelter' ),
                __( 'Amount', 'starter-shelter' ),
                __( 'Allocation', 'starter-shelter' ),
            ] );

            foreach ( $donations as $donation ) {
                $donor_id = (int) ( $donation['donor_id'] ?? 0 );
                $donor    = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
                fputcsv( $output, [
                    $donation['date_formatted']    ?? '',
                    $donation['donor_name']        ?? $donor['name'],
                    $donor['email'],
                    $donation['amount']            ?? 0,
                    $donation['allocation_label'] ?? '',
                ] );
            }
            return;
        }

        // membership_drive: parallel CSV layout matching the Memberships
        // tab export (Member / Email / Tier / Type / Start / End / Status / Amount).
        $memberships = $result['memberships'] ?? [];
        $donor_map   = self::batch_donor_info( array_column( $memberships, 'donor_id' ) );

        fputcsv( $output, [
            __( 'Member', 'starter-shelter' ),
            __( 'Email', 'starter-shelter' ),
            __( 'Tier', 'starter-shelter' ),
            __( 'Type', 'starter-shelter' ),
            __( 'Start Date', 'starter-shelter' ),
            __( 'End Date', 'starter-shelter' ),
            __( 'Status', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
        ] );

        foreach ( $memberships as $membership ) {
            $donor_id = (int) ( $membership['donor_id'] ?? 0 );
            $donor    = $donor_map[ $donor_id ] ?? [ 'name' => '', 'email' => '' ];
            fputcsv( $output, [
                $donor['name'],
                $donor['email'],
                $membership['tier_label']      ?? '',
                $membership['membership_type'] ?? '',
                $membership['start_date']      ?? '',
                $membership['end_date']        ?? '',
                ! empty( $membership['is_active'] )
                    ? __( 'Active', 'starter-shelter' )
                    : __( 'Expired', 'starter-shelter' ),
                $membership['amount'] ?? 0,
            ] );
        }
    }
}
