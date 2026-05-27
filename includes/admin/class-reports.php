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

        $active_tab = sanitize_key( $_GET['tab'] ?? 'donations' );
        $period     = sanitize_key( $_GET['period'] ?? 'month' );

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
                switch ( $active_tab ) {
                    case 'memberships':
                        self::render_memberships_report( $period );
                        break;
                    case 'memorials':
                        self::render_memorials_report( $period );
                        break;
                    case 'campaigns':
                        self::render_campaigns_report( $period );
                        break;
                    default:
                        self::render_donations_report( $period );
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
    private static function render_donations_report( string $period ): void {
        $stats = self::fetch_stats(
            'shelter-donations/get-stats',
            [ 'period' => $period ],
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
        self::render_donation_trend_chart( $period );
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
        <?php
    }

    /**
     * Render memberships report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_memberships_report( string $period ): void {
        $stats = self::fetch_stats(
            'shelter-reports/dashboard-stats',
            [ 'period' => $period ],
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
        <?php
    }

    /**
     * Render memorials report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_memorials_report( string $period ): void {
        $stats = self::fetch_stats(
            'shelter-reports/dashboard-stats',
            [ 'period' => $period ],
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
        // Get active campaigns.
        $campaigns = get_terms( [
            'taxonomy'   => 'sd_campaign',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $campaigns ) || empty( $campaigns ) ) {
            echo '<p>' . esc_html__( 'No campaigns found.', 'starter-shelter' ) . '</p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Campaign', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Goal', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Raised', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Progress', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $campaigns as $campaign ) :
                    $goal = (float) get_term_meta( $campaign->term_id, '_sd_goal', true );

                    // Calculate raised amount filtered by period.
                    $raised = self::get_campaign_raised( $campaign->term_id, $period );
                    $percent = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $campaign->name ); ?></strong></td>
                    <td><?php echo esc_html( '$' . number_format( $goal, 2 ) ); ?></td>
                    <td><?php echo esc_html( '$' . number_format( $raised, 2 ) ); ?></td>
                    <td>
                        <div class="sd-progress-bar">
                            <div class="sd-progress-fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
                        </div>
                        <span><?php echo esc_html( number_format( $percent, 1 ) ); ?>%</span>
                    </td>
                    <td><?php echo esc_html( $campaign->count ); ?></td>
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
    private static function render_donation_trend_chart( string $period ): void {
        $ability = function_exists( 'wp_get_ability' )
            ? wp_get_ability( 'shelter-reports/donation-trend' )
            : null;
        if ( ! $ability ) {
            return;
        }

        $data = $ability->execute( [ 'period' => $period ] );
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
     * Get the total raised for a campaign, filtered by period.
     *
     * @since 2.1.0
     *
     * @param int    $campaign_id Campaign term ID.
     * @param string $period      Reporting period.
     * @return float Total raised.
     */
    private static function get_campaign_raised( int $campaign_id, string $period ): float {
        $query = \Starter_Shelter\Core\Query::for( 'sd_donation' )
            ->whereInTaxonomy( 'sd_campaign', $campaign_id );

        if ( 'all_time' !== $period ) {
            $range = \Starter_Shelter\Helpers\get_date_range_for_period( $period );
            if ( ! empty( $range['start'] ) && ! empty( $range['end'] ) ) {
                $query->whereDateBetween( 'donation_date', $range['start'], $range['end'] );
            }
        }

        return $query->sum( 'amount' );
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

        $report = sanitize_key( $_GET['report'] ?? 'donations' );
        $period = sanitize_key( $_GET['period'] ?? 'month' );

        $filename = 'shelter-' . $report . '-' . $period . '-' . wp_date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        switch ( $report ) {
            case 'campaign':
                // Per-campaign export (link rendered with campaign_id in
                // the campaigns table). Distinct from the top-bar
                // Export CSV button which doesn't carry a campaign_id.
                self::export_campaign_report( $output );
                break;
            case 'memberships':
                self::export_memberships_report( $output, $period );
                break;
            case 'memorials':
                self::export_memorials_report( $output, $period );
                break;
            case 'donations':
            default:
                self::export_donations_report( $output, $period );
                break;
        }

        fclose( $output );
        exit;
    }

    /**
     * Export donations report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     * @param string   $period Report period.
     */
    private static function export_donations_report( $output, string $period ): void {
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
        
        $result = $ability->execute( [
            'date_from' => $date_range['start'],
            'date_to'   => $date_range['end'],
            'per_page'  => 1000,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        foreach ( $result['items'] ?? [] as $donation ) {
            fputcsv( $output, [
                $donation['date_formatted'] ?? '',
                $donation['donor']['full_name'] ?? '',
                $donation['donor']['email'] ?? '',
                $donation['amount'] ?? 0,
                $donation['allocation_label'] ?? '',
                $donation['campaign_name'] ?? '',
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
    private static function export_memberships_report( $output, string $period ): void {
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

        $result = $ability->execute( [
            'status'   => 'all',
            'per_page' => 1000,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        foreach ( $result['items'] ?? [] as $membership ) {
            fputcsv( $output, [
                $membership['donor']['full_name'] ?? '',
                $membership['donor']['email'] ?? '',
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
    private static function export_memorials_report( $output, string $period ): void {
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
        $result = $ability->execute( [
            'type'     => 'all',
            'per_page' => 1000,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        foreach ( $result['items'] ?? [] as $memorial ) {
            fputcsv( $output, [
                $memorial['honoree_name'] ?? '',
                $memorial['memorial_type'] ?? '',
                $memorial['donor']['full_name'] ?? '',
                $memorial['donor']['email'] ?? '',
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

        $result = $ability->execute( [
            'campaign_id'       => $campaign_id,
            'include_donations' => true,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        // Campaign summary.
        fputcsv( $output, [ __( 'Campaign Report', 'starter-shelter' ), $result['campaign']['name'] ?? '' ] );
        fputcsv( $output, [ __( 'Goal', 'starter-shelter' ), $result['campaign']['goal'] ?? 0 ] );
        fputcsv( $output, [ __( 'Raised', 'starter-shelter' ), $result['progress']['total_raised'] ?? 0 ] );
        fputcsv( $output, [ __( 'Progress', 'starter-shelter' ), ( $result['progress']['percent_of_goal'] ?? 0 ) . '%' ] );
        fputcsv( $output, [] );

        // Donations.
        fputcsv( $output, [
            __( 'Date', 'starter-shelter' ),
            __( 'Donor', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
        ] );

        foreach ( $result['donations'] ?? [] as $donation ) {
            fputcsv( $output, [
                $donation['date_formatted'] ?? '',
                $donation['donor_name'] ?? '',
                $donation['amount'] ?? 0,
            ] );
        }
    }
}
