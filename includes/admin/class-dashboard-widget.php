<?php
/**
 * Dashboard Widget - Enhanced stats overview with action items.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Entity_Hydrator;
use Starter_Shelter\Helpers;

/**
 * Adds a dashboard widget with shelter donation statistics and action items.
 *
 * @since 1.0.0
 */
class Dashboard_Widget {

    /**
     * Widget ID.
     *
     * @var string
     */
    private const WIDGET_ID = 'sd_dashboard_widget';

    /**
     * Initialize the dashboard widget.
     */
    public static function init(): void {
        add_action( 'wp_dashboard_setup', [ self::class, 'register_widget' ] );
        add_action( 'wp_ajax_sd_dashboard_refresh', [ self::class, 'ajax_refresh' ] );

        // Invalidate cached stats + action items when shelter records change.
        // Mirrors class-menu's hook list so the widget's action-items count
        // doesn't stay stale for up to 15 min after a logo approval or a
        // family notification (audit §4.7).
        foreach ( [ 'sd_donation', 'sd_membership', 'sd_memorial', 'sd_donor' ] as $cpt ) {
            add_action( "save_post_{$cpt}", [ self::class, 'invalidate_cache' ] );
        }
        add_action( 'starter_shelter_logo_approved', [ self::class, 'invalidate_cache' ] );
        add_action( 'starter_shelter_logo_rejected', [ self::class, 'invalidate_cache' ] );
        add_action( 'starter_shelter_memorial_family_notification', [ self::class, 'invalidate_cache' ] );
    }

    /**
     * Clear all cached dashboard stats.
     *
     * @since 2.1.0
     */
    public static function invalidate_cache(): void {
        foreach ( [ 'today', 'week', 'month', 'year' ] as $period ) {
            delete_transient( 'sd_dashboard_stats_v2_' . $period );
        }
        delete_transient( 'sd_dashboard_action_items' );
    }

    /**
     * Register the dashboard widget.
     */
    public static function register_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __( 'Shelter Donations Overview', 'starter-shelter' ),
            [ self::class, 'render_widget' ],
            [ self::class, 'render_widget_config' ],
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render the dashboard widget.
     */
    /**
     * Cache TTL for dashboard stats (15 minutes).
     */
    private const CACHE_TTL = 900;

    /**
     * Number of items shown in the widget's "Recent Activity" feed.
     */
    private const RECENT_ACTIVITY_COUNT = 5;

    public static function render_widget(): void {
        $period = get_user_option( 'sd_dashboard_period' ) ?: 'month';

        // Try cached stats first. The v2 suffix is bumped whenever the
        // ability's return shape changes so old transients don't serve
        // pre-deploy data through the post-deploy renderer.
        $cache_key = 'sd_dashboard_stats_v2_' . $period;
        $stats = get_transient( $cache_key );

        if ( false === $stats ) {
            $ability = wp_get_ability( 'shelter-reports/dashboard-stats' );

            if ( ! $ability ) {
                echo '<p>' . esc_html__( 'Unable to load statistics.', 'starter-shelter' ) . '</p>';
                return;
            }

            $stats = $ability->execute( [ 'period' => $period ] );

            if ( is_wp_error( $stats ) ) {
                echo '<p class="error">' . esc_html( $stats->get_error_message() ) . '</p>';
                return;
            }

            set_transient( $cache_key, $stats, self::CACHE_TTL );
        }

        // Get action items (cached separately).
        $action_items = self::get_action_items();

        self::render_styles();
        ?>

        <!-- Period Tabs -->
        <div class="sd-widget-tabs">
            <?php
            $periods = [
                'today'  => __( 'Today', 'starter-shelter' ),
                'week'   => __( 'Week', 'starter-shelter' ),
                'month'  => __( 'Month', 'starter-shelter' ),
                'year'   => __( 'Year', 'starter-shelter' ),
            ];
            foreach ( $periods as $key => $label ) :
            ?>
            <button type="button" 
                    class="sd-tab <?php echo $period === $key ? 'active' : ''; ?>" 
                    data-period="<?php echo esc_attr( $key ); ?>">
                <?php echo esc_html( $label ); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Stats Grid -->
        <div class="sd-widget-stats" id="sd-widget-stats">
            <?php echo self::render_stats_grid( $stats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>

        <?php if ( ! empty( $action_items ) ) : ?>
        <!-- Action Items -->
        <div class="sd-widget-actions">
            <h4>
                <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                <?php esc_html_e( 'Action Required', 'starter-shelter' ); ?>
            </h4>
            <ul>
                <?php foreach ( $action_items as $item ) : ?>
                <li>
                    <a href="<?php echo esc_url( $item['url'] ); ?>">
                        <span class="sd-action-count"><?php echo esc_html( $item['count'] ); ?></span>
                        <?php echo esc_html( $item['label'] ); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Recent Activity -->
        <div class="sd-widget-recent">
            <h4><?php esc_html_e( 'Recent Activity', 'starter-shelter' ); ?></h4>
            <?php 
            $recent = self::get_recent_activity( self::RECENT_ACTIVITY_COUNT );
            if ( ! empty( $recent ) ) :
            ?>
            <ul>
                <?php foreach ( $recent as $activity ) : ?>
                <li>
                    <span class="sd-activity-icon"><?php echo esc_html( $activity['icon'] ); ?></span>
                    <span class="sd-activity-text">
                        <?php echo wp_kses_post( $activity['text'] ); ?>
                        <span class="sd-activity-time"><?php echo esc_html( $activity['time'] ); ?></span>
                    </span>
                    <span class="sd-activity-amount"><?php echo esc_html( $activity['amount'] ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else : ?>
            <p class="sd-no-activity"><?php esc_html_e( 'No recent activity.', 'starter-shelter' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Footer Links -->
        <div class="sd-widget-footer">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=starter-shelter-reports' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'View Reports', 'starter-shelter' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_donation' ) ); ?>" class="button">
                <?php esc_html_e( 'All Donations', 'starter-shelter' ); ?>
            </a>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.sd-widget-tabs .sd-tab').on('click', function() {
                var period = $(this).data('period');
                var $tabs = $('.sd-widget-tabs .sd-tab');
                var $stats = $('#sd-widget-stats');
                
                $tabs.removeClass('active');
                $(this).addClass('active');
                $stats.css('opacity', '0.5');
                
                $.post(ajaxurl, {
                    action: 'sd_dashboard_refresh',
                    period: period,
                    nonce: '<?php echo wp_create_nonce( 'sd_dashboard_refresh' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $stats.html(response.data.html).css('opacity', '1');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render widget configuration form.
     */
    public static function render_widget_config(): void {
        // WP core's dashboard widget plumbing nonce-verifies the POST
        // before calling this callback, so the update_user_option below
        // is currently safe. Belt-and-braces: also verify the
        // 'edit-dashboard' nonce ourselves so a hypothetical future core
        // refactor that removed the outer check wouldn't open a (small)
        // self-CSRF on the user's own dashboard preference.
        $nonce_ok = isset( $_POST['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edit-dashboard' );

        if ( isset( $_POST['sd_dashboard_period'] ) && $nonce_ok ) {
            update_user_option( get_current_user_id(), 'sd_dashboard_period', sanitize_key( $_POST['sd_dashboard_period'] ) );
        }

        $period = get_user_option( 'sd_dashboard_period' ) ?: 'month';
        ?>
        <p>
            <label for="sd_dashboard_period"><?php esc_html_e( 'Default period:', 'starter-shelter' ); ?></label>
            <select name="sd_dashboard_period" id="sd_dashboard_period">
                <option value="today" <?php selected( $period, 'today' ); ?>><?php esc_html_e( 'Today', 'starter-shelter' ); ?></option>
                <option value="week" <?php selected( $period, 'week' ); ?>><?php esc_html_e( 'This Week', 'starter-shelter' ); ?></option>
                <option value="month" <?php selected( $period, 'month' ); ?>><?php esc_html_e( 'This Month', 'starter-shelter' ); ?></option>
                <option value="year" <?php selected( $period, 'year' ); ?>><?php esc_html_e( 'This Year', 'starter-shelter' ); ?></option>
            </select>
        </p>
        <?php
    }

    /**
     * AJAX handler for refreshing stats.
     */
    public static function ajax_refresh(): void {
        check_ajax_referer( 'sd_dashboard_refresh', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $period = sanitize_key( $_POST['period'] ?? 'month' );

        // Save preference.
        update_user_option( get_current_user_id(), 'sd_dashboard_period', $period );

        // Use cached stats (cache key version must match render_widget).
        $cache_key = 'sd_dashboard_stats_v2_' . $period;
        $stats = get_transient( $cache_key );

        if ( false === $stats ) {
            $ability = wp_get_ability( 'shelter-reports/dashboard-stats' );
            if ( ! $ability ) {
                wp_send_json_error();
            }

            $stats = $ability->execute( [ 'period' => $period ] );
            if ( is_wp_error( $stats ) ) {
                wp_send_json_error();
            }

            set_transient( $cache_key, $stats, self::CACHE_TTL );
        }

        wp_send_json_success( [ 'html' => self::render_stats_grid( $stats ) ] );
    }

    /**
     * Get action items that need attention.
     *
     * @return array Action items.
     */
    private static function get_action_items(): array {
        $cached = get_transient( 'sd_dashboard_action_items' );
        if ( false !== $cached ) {
            return $cached;
        }

        // Single source of truth lives in the ability (same call the
        // menu badge uses). Flatten each item into the widget's display
        // shape: { count, label, url }. The ability returns singular and
        // plural label variants; _n() picks the right one for each count.
        $items = [];
        if ( function_exists( 'wp_get_ability' ) ) {
            $ability = wp_get_ability( 'shelter-reports/action-items' );
            if ( $ability ) {
                $result = $ability->execute( [] );
                if ( is_array( $result ) ) {
                    foreach ( $result['items'] ?? [] as $item ) {
                        $count = (int) ( $item['count'] ?? 0 );
                        $items[] = [
                            'count' => $count,
                            'label' => 1 === $count
                                ? ( $item['label'] ?? '' )
                                : ( $item['label_plural'] ?? $item['label'] ?? '' ),
                            'url'   => $item['url'] ?? '#',
                        ];
                    }
                }
            }
        }

        set_transient( 'sd_dashboard_action_items', $items, self::CACHE_TTL );
        return $items;
    }

    /**
     * Get recent activity for the widget.
     *
     * @param int $count Number of items.
     * @return array Recent activity.
     */
    private static function get_recent_activity( int $count = self::RECENT_ACTIVITY_COUNT ): array {
        // Pull the structured feed from the ability — the data layer is
        // now CPT-agnostic structured items, no SQL here. Display
        // formatting (icons, sprintf, time-ago) stays in this view layer.
        if ( ! function_exists( 'wp_get_ability' ) ) {
            return [];
        }

        $ability = wp_get_ability( 'shelter-reports/recent-activity' );
        if ( ! $ability ) {
            return [];
        }

        $result = $ability->execute( [ 'limit' => $count ] );
        if ( ! is_array( $result ) ) {
            return [];
        }

        $activity = [];
        foreach ( $result['items'] ?? [] as $row ) {
            $donor_name = __( 'Someone', 'starter-shelter' );
            if ( ! empty( $row['is_anonymous'] ) ) {
                $donor_name = __( 'Anonymous', 'starter-shelter' );
            } elseif ( ! empty( $row['donor_name'] ) ) {
                $donor_name = $row['donor_name'];
            }

            $amount = Helpers\format_currency( (float) ( $row['amount'] ?? 0 ) );
            // created_ts is a UTC epoch from the ability — directly
            // comparable with time(). Falling back to strtotime(post_date)
            // would mix server-PHP-TZ with UTC.
            $ts     = (int) ( $row['created_ts'] ?? time() );
            $time   = human_time_diff( $ts, time() ) . ' ' . __( 'ago', 'starter-shelter' );

            switch ( $row['type'] ?? '' ) {
                case 'sd_donation':
                    $activity[] = [
                        'icon'   => '💰',
                        /* translators: %s: donor name */
                        'text'   => sprintf( '<strong>%s</strong> ' . esc_html__( 'donated', 'starter-shelter' ), esc_html( $donor_name ) ),
                        'amount' => $amount,
                        'time'   => $time,
                    ];
                    break;

                case 'sd_membership':
                    $tier_label = ucfirst( $row['tier'] ?? '' );
                    $activity[] = [
                        'icon'   => '🏅',
                        /* translators: 1: donor name 2: tier label */
                        'text'   => sprintf( '<strong>%1$s</strong> ' . esc_html__( 'joined as', 'starter-shelter' ) . ' %2$s', esc_html( $donor_name ), esc_html( $tier_label ) ),
                        'amount' => $amount,
                        'time'   => $time,
                    ];
                    break;

                case 'sd_memorial':
                    $honoree = $row['honoree_name'] ?? __( 'someone special', 'starter-shelter' );
                    $activity[] = [
                        'icon'   => '❤️',
                        /* translators: %s: honoree name */
                        'text'   => sprintf( esc_html__( 'Memorial for', 'starter-shelter' ) . ' <strong>%s</strong>', esc_html( $honoree ) ),
                        'amount' => $amount,
                        'time'   => $time,
                    ];
                    break;
            }
        }

        return $activity;
    }

    /**
     * Render the 5-card stats grid as an HTML string.
     *
     * Single source of truth for the widget body — both the initial
     * render_widget() and the ajax_refresh() response build the inner
     * grid from this. Each card has the same shape (icon + value +
     * label) so the cards are produced from a small declarative table
     * rather than five hand-written blocks.
     *
     * @since 1.1.3
     *
     * @param array $stats Output of `shelter-reports/dashboard-stats`.
     * @return string Escaped HTML.
     */
    private static function render_stats_grid( array $stats ): string {
        $donation_stats   = $stats['donations'] ?? [];
        $membership_stats = $stats['memberships'] ?? [];
        $memorial_stats   = $stats['memorials'] ?? [];

        $cards = [
            [ 'class' => 'sd-stat-primary', 'icon' => '💰', 'value' => Helpers\format_currency( (float) ( $donation_stats['total'] ?? 0 ) ),         'label' => __( 'Donations', 'starter-shelter' ) ],
            [ 'class' => '',                'icon' => '📝', 'value' => number_format( (int)   ( $donation_stats['count'] ?? 0 ) ),                   'label' => __( 'Transactions', 'starter-shelter' ) ],
            [ 'class' => '',                'icon' => '👥', 'value' => number_format( (int)   ( $donation_stats['unique_donors'] ?? 0 ) ),           'label' => __( 'Donors', 'starter-shelter' ) ],
            [ 'class' => '',                'icon' => '🏅', 'value' => number_format( (int)   ( $membership_stats['new'] ?? 0 ) ),                   'label' => __( 'New Members', 'starter-shelter' ) ],
            [ 'class' => '',                'icon' => '❤️', 'value' => number_format( (int)   ( $memorial_stats['total'] ?? 0 ) ),                   'label' => __( 'Memorials', 'starter-shelter' ) ],
        ];

        ob_start();
        foreach ( $cards as $card ) {
            $class = 'sd-widget-stat' . ( '' !== $card['class'] ? ' ' . $card['class'] : '' );
            ?>
            <div class="<?php echo esc_attr( $class ); ?>">
                <span class="sd-stat-icon"><?php echo esc_html( $card['icon'] ); ?></span>
                <div class="sd-stat-content">
                    <span class="sd-widget-stat-value"><?php echo esc_html( $card['value'] ); ?></span>
                    <span class="sd-widget-stat-label"><?php echo esc_html( $card['label'] ); ?></span>
                </div>
            </div>
            <?php
        }
        return (string) ob_get_clean();
    }

    /**
     * Render inline styles for the widget.
     */
    private static function render_styles(): void {
        ?>
        <style>
            .sd-widget-tabs {
                display: flex;
                gap: 5px;
                margin-bottom: 15px;
                border-bottom: 1px solid #c3c4c7;
                padding-bottom: 10px;
            }
            .sd-widget-tabs .sd-tab {
                padding: 5px 12px;
                border: none;
                background: #f0f0f1;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
                transition: all 0.2s;
            }
            .sd-widget-tabs .sd-tab:hover { background: #dcdcde; }
            .sd-widget-tabs .sd-tab.active { background: #2271b1; color: #fff; }

            .sd-widget-stats {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-bottom: 15px;
                transition: opacity 0.2s;
            }
            .sd-widget-stats .sd-widget-stat:nth-child(5) {
                grid-column: 1 / -1;
            }
            .sd-widget-stat {
                display: flex;
                align-items: center;
                gap: 10px;
                background: #f6f7f7;
                padding: 12px;
                border-radius: 4px;
            }
            .sd-widget-stat.sd-stat-primary {
                background: linear-gradient(135deg, #059669 0%, #047857 100%);
                color: #fff;
            }
            .sd-widget-stat.sd-stat-primary .sd-widget-stat-label { color: rgba(255,255,255,0.8); }
            .sd-stat-icon { font-size: 24px; }
            .sd-stat-content { flex: 1; }
            .sd-widget-stat-value {
                font-size: 20px;
                font-weight: 600;
                display: block;
                line-height: 1.2;
            }
            .sd-widget-stat-label {
                font-size: 11px;
                color: #646970;
                text-transform: uppercase;
            }

            .sd-widget-actions {
                background: #fff8e5;
                border: 1px solid #f0c33c;
                border-radius: 4px;
                padding: 12px;
                margin-bottom: 15px;
            }
            .sd-widget-actions h4 {
                margin: 0 0 8px;
                font-size: 13px;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            .sd-widget-actions ul { margin: 0; padding: 0; list-style: none; }
            .sd-widget-actions li { margin: 5px 0; }
            .sd-widget-actions a { text-decoration: none; color: #1d2327; }
            .sd-widget-actions a:hover { color: #2271b1; }
            .sd-action-count {
                display: inline-block;
                background: #d63638;
                color: #fff;
                font-size: 11px;
                font-weight: 600;
                padding: 2px 6px;
                border-radius: 10px;
                margin-right: 5px;
            }

            .sd-widget-recent { margin-bottom: 15px; }
            .sd-widget-recent h4 { margin: 0 0 10px; font-size: 13px; }
            .sd-widget-recent ul { margin: 0; padding: 0; list-style: none; }
            .sd-widget-recent li {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .sd-widget-recent li:last-child { border-bottom: none; }
            .sd-activity-icon { font-size: 16px; }
            .sd-activity-text { flex: 1; }
            .sd-activity-time { display: block; font-size: 11px; color: #888; }
            .sd-activity-amount { font-weight: 600; color: #059669; }
            .sd-no-activity { color: #888; font-style: italic; margin: 0; }

            .sd-widget-footer {
                display: flex;
                gap: 10px;
                padding-top: 15px;
                border-top: 1px solid #c3c4c7;
            }
        </style>
        <?php
    }
}
