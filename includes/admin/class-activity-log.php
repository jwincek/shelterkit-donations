<?php
/**
 * Admin Activity Log - Tracks important events for auditing.
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
 * Handles activity logging and display.
 *
 * @since 1.0.0
 */
class Activity_Log {

    /**
     * Database table name (without prefix).
     *
     * @var string
     */
    private const TABLE_NAME = 'sd_activity_log';

    /**
     * Page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'shelter-donations-activity';

    /**
     * Closed enumeration of category keys emitted by log_X methods.
     *
     * Used by the filter dropdown on the activity-log page instead of a
     * `SELECT DISTINCT event_category` against the log table — that
     * scan is unnecessary because the producer set is small, finite,
     * and known at compile time. Keep this in sync with the
     * get_category_icon() map.
     */
    private const KNOWN_CATEGORIES = [ 'admin', 'donation', 'email', 'membership', 'system' ];

    /**
     * Submenu page hook from add_submenu_page().
     *
     * Cached so enqueue_assets() can match against the actual hook WP
     * derives (from sanitize_title(menu_title), NOT the parent slug —
     * see wp-admin/includes/plugin.php::get_plugin_page_hookname).
     */
    private static string $page_hook = '';

    /**
     * Initialize activity log.
     */
    public static function init(): void {
        // Create table on activation.
        register_activation_hook( STARTER_SHELTER_FILE, [ self::class, 'create_table' ] );

        // Add admin page.
        add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

        // Log events.
        add_action( 'starter_shelter_donation_created', [ self::class, 'log_donation_created' ], 10, 3 );
        add_action( 'starter_shelter_membership_created', [ self::class, 'log_membership_created' ], 10, 3 );
        add_action( 'starter_shelter_memorial_created', [ self::class, 'log_memorial_created' ], 10, 3 );
        add_action( 'starter_shelter_membership_renewed', [ self::class, 'log_membership_renewed' ], 10, 3 );
        add_action( 'starter_shelter_logo_approved', [ self::class, 'log_logo_approved' ], 10, 3 );
        add_action( 'starter_shelter_logo_rejected', [ self::class, 'log_logo_rejected' ], 10, 3 );
        add_action( 'starter_shelter_order_processed', [ self::class, 'log_order_processed' ], 10, 3 );
        
        // Email events.
        add_action( 'starter_shelter_email_sent', [ self::class, 'log_email_sent' ], 10, 4 );
        
        // Settings changes.
        add_action( 'update_option_starter_shelter_options', [ self::class, 'log_settings_changed' ], 10, 2 );

        // Manual admin actions.
        add_action( 'starter_shelter_membership_extended', [ self::class, 'log_membership_extended' ], 10, 2 );
        add_action( 'starter_shelter_family_notified', [ self::class, 'log_family_notified' ], 10, 2 );
        add_action( 'starter_shelter_membership_cancelled', [ self::class, 'log_membership_cancelled' ], 10, 3 );

        // Cleanup old logs.
        add_action( 'sd_cleanup_activity_log', [ self::class, 'cleanup_old_logs' ] );
        
        if ( ! wp_next_scheduled( 'sd_cleanup_activity_log' ) ) {
            wp_schedule_event( time(), 'daily', 'sd_cleanup_activity_log' );
        }
    }

    /**
     * Create the activity log table.
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_category varchar(30) NOT NULL,
            message text NOT NULL,
            object_type varchar(30) DEFAULT NULL,
            object_id bigint(20) unsigned DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            meta longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY event_category (event_category),
            KEY object_type_id (object_type, object_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Add activity log page to admin menu.
     */
    public static function add_menu_page(): void {
        self::$page_hook = (string) add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Activity Log', 'shelter-donations' ),
            __( 'Activity Log', 'shelter-donations' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    /**
     * Enqueue admin assets.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( '' === self::$page_hook || $hook !== self::$page_hook ) {
            return;
        }

        wp_add_inline_style( 'wp-admin', self::get_inline_styles() );
    }

    /**
     * Log an activity.
     *
     * @param string      $event_type    Event type identifier.
     * @param string      $category      Event category (donation, membership, email, admin, system).
     * @param string      $message       Human-readable message.
     * @param string|null $object_type   Related object type (post type).
     * @param int|null    $object_id     Related object ID.
     * @param array       $meta          Additional metadata.
     */
    public static function log(
        string $event_type,
        string $category,
        string $message,
        ?string $object_type = null,
        ?int $object_id = null,
        array $meta = []
    ): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->insert(
            $table_name,
            [
                'event_type'     => $event_type,
                'event_category' => $category,
                'message'        => $message,
                'object_type'    => $object_type,
                'object_id'      => $object_id,
                'user_id'        => get_current_user_id() ?: null,
                'ip_address'     => self::get_client_ip(),
                'meta'           => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Log donation created.
     */
    public static function log_donation_created( int $donation_id, int $donor_id, array $data ): void {
        $amount = Helpers\format_currency( $data['amount'] ?? 0 );
        $donor_name = self::get_donor_name( $donor_id );

        self::log(
            'donation_created',
            'donation',
            /* translators: 1: donor's display name; 2: formatted donation amount. */
            sprintf( __( '%1$s donated %2$s', 'shelter-donations' ), $donor_name, $amount ),
            'sd_donation',
            $donation_id,
            [
                'donor_id'   => $donor_id,
                'amount'     => $data['amount'] ?? 0,
                'allocation' => $data['allocation'] ?? '',
            ]
        );
    }

    /**
     * Log membership created.
     */
    public static function log_membership_created( int $membership_id, int $donor_id, array $data ): void {
        $donor_name = self::get_donor_name( $donor_id );
        $tier = $data['tier'] ?? '';
        $type = $data['membership_type'] ?? 'individual';

        self::log(
            'membership_created',
            'membership',
            /* translators: 1: donor's display name; 2: membership tier name; 3: membership type (e.g. individual, business). */
            sprintf( __( '%1$s joined as %2$s %3$s member', 'shelter-donations' ), $donor_name, ucfirst( $tier ), $type ),
            'sd_membership',
            $membership_id,
            [
                'donor_id' => $donor_id,
                'tier'     => $tier,
                'type'     => $type,
                'amount'   => $data['amount'] ?? 0,
            ]
        );
    }

    /**
     * Log memorial created.
     */
    public static function log_memorial_created( int $memorial_id, int $donor_id, array $data ): void {
        $donor_name = self::get_donor_name( $donor_id );
        $honoree = $data['honoree_name'] ?? __( 'Unknown', 'shelter-donations' );

        self::log(
            'memorial_created',
            'donation',
            /* translators: 1: donor's display name; 2: honoree's name the memorial is for. */
            sprintf( __( '%1$s created memorial for %2$s', 'shelter-donations' ), $donor_name, $honoree ),
            'sd_memorial',
            $memorial_id,
            [
                'donor_id'     => $donor_id,
                'honoree_name' => $honoree,
                'type'         => $data['memorial_type'] ?? '',
                'amount'       => $data['amount'] ?? 0,
            ]
        );
    }

    /**
     * Log membership renewed.
     */
    public static function log_membership_renewed( int $membership_id, int $donor_id, array $data ): void {
        $donor_name = self::get_donor_name( $donor_id );

        self::log(
            'membership_renewed',
            'membership',
            /* translators: %s: donor's display name. */
            sprintf( __( '%s renewed membership', 'shelter-donations' ), $donor_name ),
            'sd_membership',
            $membership_id,
            [
                'donor_id' => $donor_id,
                'new_end_date' => $data['new_end_date'] ?? '',
            ]
        );
    }

    /**
     * Log logo approved.
     */
    public static function log_logo_approved( int $membership_id, int $donor_id, array $data ): void {
        $business_name = $data['business_name'] ?? __( 'Unknown Business', 'shelter-donations' );
        $admin_user = wp_get_current_user();

        self::log(
            'logo_approved',
            'admin',
            /* translators: 1: business name whose logo was approved; 2: display name of the admin who approved it. */
            sprintf( __( 'Logo approved for %1$s by %2$s', 'shelter-donations' ), $business_name, $admin_user->display_name ),
            'sd_membership',
            $membership_id,
            [
                'business_name' => $business_name,
                'approved_by'   => get_current_user_id(),
            ]
        );
    }

    /**
     * Log logo rejected.
     */
    public static function log_logo_rejected( int $membership_id, int $donor_id, array $data ): void {
        $business_name = $data['membership']['business_name'] ?? __( 'Unknown Business', 'shelter-donations' );
        $reason = $data['reason'] ?? '';
        $admin_user = wp_get_current_user();

        self::log(
            'logo_rejected',
            'admin',
            /* translators: 1: business name whose logo was rejected; 2: display name of the admin who rejected it; 3: rejection reason. */
            sprintf( __( 'Logo rejected for %1$s by %2$s: %3$s', 'shelter-donations' ), $business_name, $admin_user->display_name, $reason ),
            'sd_membership',
            $membership_id,
            [
                'business_name' => $business_name,
                'rejected_by'   => get_current_user_id(),
                'reason'        => $reason,
            ]
        );
    }

    /**
     * Log order processed.
     */
    public static function log_order_processed( int $order_id, array $results, bool $has_errors ): void {
        $status = $has_errors ? __( 'with errors', 'shelter-donations' ) : __( 'successfully', 'shelter-donations' );

        self::log(
            'order_processed',
            'system',
            /* translators: 1: WooCommerce order ID; 2: processing outcome (e.g. successfully, with errors). */
            sprintf( __( 'Order #%1$d processed %2$s', 'shelter-donations' ), $order_id, $status ),
            'shop_order',
            $order_id,
            [
                'has_errors'   => $has_errors,
                'items_count'  => count( $results ),
            ]
        );
    }

    /**
     * Log email sent.
     */
    public static function log_email_sent( string $email_type, string $recipient, int $object_id, array $data ): void {
        self::log(
            'email_sent',
            'email',
            /* translators: 1: email type label (e.g. Receipt, Renewal Reminder); 2: recipient email address. */
            sprintf( __( '%1$s email sent to %2$s', 'shelter-donations' ), ucfirst( str_replace( '_', ' ', $email_type ) ), $recipient ),
            $data['object_type'] ?? null,
            $object_id,
            [
                'email_type' => $email_type,
                'recipient'  => $recipient,
            ]
        );
    }

    /**
     * Log settings changed.
     */
    public static function log_settings_changed( $old_value, $new_value ): void {
        $admin_user = wp_get_current_user();

        // Walk the union of old + new keys so a setting that was removed
        // (key in old but not new) is also captured as changed. Previously
        // we iterated only $new_value, missing removals — uncommon with
        // the Options API but worth doing right.
        $old_arr  = is_array( $old_value ) ? $old_value : [];
        $new_arr  = is_array( $new_value ) ? $new_value : [];
        $all_keys = array_unique( array_merge( array_keys( $old_arr ), array_keys( $new_arr ) ) );

        $changed = [];
        foreach ( $all_keys as $key ) {
            $old_v = $old_arr[ $key ] ?? null;
            $new_v = $new_arr[ $key ] ?? null;
            if ( $old_v !== $new_v ) {
                $changed[] = $key;
            }
        }

        if ( ! empty( $changed ) ) {
            self::log(
                'settings_changed',
                'admin',
                /* translators: 1: display name of the admin who changed settings; 2: comma-separated list of changed setting keys. */
            sprintf( __( 'Settings updated by %1$s: %2$s', 'shelter-donations' ), $admin_user->display_name, implode( ', ', $changed ) ),
                null,
                null,
                [
                    'changed_fields' => $changed,
                    'changed_by'     => get_current_user_id(),
                ]
            );
        }
    }

    /**
     * Log membership extended.
     */
    public static function log_membership_extended( int $membership_id, string $new_end_date ): void {
        $admin_user = wp_get_current_user();
        $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
        $donor_name = $membership['donor_id'] ? self::get_donor_name( $membership['donor_id'] ) : __( 'Unknown', 'shelter-donations' );

        self::log(
            'membership_extended',
            'admin',
            /* translators: 1: donor's display name; 2: new membership end date; 3: display name of the admin who extended it. */
            sprintf( __( 'Membership for %1$s extended to %2$s by %3$s', 'shelter-donations' ), $donor_name, $new_end_date, $admin_user->display_name ),
            'sd_membership',
            $membership_id,
            [
                'new_end_date' => $new_end_date,
                'extended_by'  => get_current_user_id(),
            ]
        );
    }

    /**
     * Log membership cancelled.
     */
    public static function log_membership_cancelled( int $membership_id, array $membership, string $reason ): void {
        $donor_id   = (int) ( $membership['donor_id'] ?? 0 );
        $donor_name = $donor_id ? self::get_donor_name( $donor_id ) : __( 'Unknown', 'shelter-donations' );

        self::log(
            'membership_cancelled',
            'membership',
            $reason
                /* translators: 1: donor's display name; 2: cancellation reason. */
                ? sprintf( __( '%1$s cancelled membership: %2$s', 'shelter-donations' ), $donor_name, $reason )
                /* translators: %s: donor's display name. */
                : sprintf( __( '%s cancelled membership', 'shelter-donations' ), $donor_name ),
            'sd_membership',
            $membership_id,
            [
                'donor_id' => $donor_id,
                'reason'   => $reason,
                'tier'     => $membership['tier'] ?? '',
            ]
        );
    }

    /**
     * Log family notified.
     */
    public static function log_family_notified( int $memorial_id, string $family_email ): void {
        $memorial = Entity_Hydrator::get( 'sd_memorial', $memorial_id );
        $honoree = $memorial['honoree_name'] ?? __( 'Unknown', 'shelter-donations' );

        self::log(
            'family_notified',
            'email',
            /* translators: 1: honoree's name the memorial is for; 2: family member's email address notified. */
            sprintf( __( 'Family notification sent for memorial of %1$s to %2$s', 'shelter-donations' ), $honoree, $family_email ),
            'sd_memorial',
            $memorial_id,
            [
                'family_email' => $family_email,
                'honoree_name' => $honoree,
            ]
        );
    }

    /**
     * Render the activity log page.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            self::create_table();
        }

        // Filters.
        $category_filter = sanitize_key( $_GET['category'] ?? '' );
        $date_filter = sanitize_key( $_GET['date'] ?? '' );
        $search = sanitize_text_field( $_GET['s'] ?? '' );

        // Pagination.
        $per_page = 50;
        $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset = ( $current_page - 1 ) * $per_page;

        // Build query.
        $where = '1=1';
        $params = [];

        if ( $category_filter ) {
            $where .= ' AND event_category = %s';
            $params[] = $category_filter;
        }

        if ( $date_filter ) {
            switch ( $date_filter ) {
                case 'today':
                    $where .= ' AND DATE(created_at) = CURDATE()';
                    break;
                case 'week':
                    $where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                    break;
                case 'month':
                    $where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                    break;
            }
        }

        if ( $search ) {
            $where .= ' AND message LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        // Get total count.
        $count_query = "SELECT COUNT(*) FROM $table_name WHERE $where";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $table_name is $wpdb->prefix . self::TABLE_NAME (constant); $where is a static SQL skeleton whose only dynamic values use %s placeholders bound via $wpdb->prepare( $count_query, $params ). The no-filter branch passes a fully literal query.
        $total_items = $params ? $wpdb->get_var( $wpdb->prepare( $count_query, $params ) ) : $wpdb->get_var( $count_query );

        // Get logs.
        $query = "SELECT * FROM $table_name WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $table_name is $wpdb->prefix . self::TABLE_NAME (constant); $where is a static SQL skeleton whose only dynamic values use %s/%d placeholders bound via $wpdb->prepare( $query, $params ).
        $logs = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        // Filter dropdown uses the closed enumeration — no scan needed.
        $categories = self::KNOWN_CATEGORIES;

        ?>
        <div class="wrap sd-activity-log">
            <h1><?php esc_html_e( 'Activity Log', 'shelter-donations' ); ?></h1>

            <!-- Filters -->
            <div class="sd-log-filters">
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
                    
                    <select name="category">
                        <option value=""><?php esc_html_e( 'All Categories', 'shelter-donations' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
                            <?php echo esc_html( ucfirst( $cat ) ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="date">
                        <option value=""><?php esc_html_e( 'All Time', 'shelter-donations' ); ?></option>
                        <option value="today" <?php selected( $date_filter, 'today' ); ?>><?php esc_html_e( 'Today', 'shelter-donations' ); ?></option>
                        <option value="week" <?php selected( $date_filter, 'week' ); ?>><?php esc_html_e( 'Last 7 Days', 'shelter-donations' ); ?></option>
                        <option value="month" <?php selected( $date_filter, 'month' ); ?>><?php esc_html_e( 'Last 30 Days', 'shelter-donations' ); ?></option>
                    </select>

                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'shelter-donations' ); ?>" />

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'shelter-donations' ); ?></button>
                </form>
            </div>

            <!-- Stats summary -->
            <div class="sd-log-stats">
                <?php
                $today_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = CURDATE()" );
                $week_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
                ?>
                <span><strong><?php echo esc_html( $today_count ); ?></strong> <?php esc_html_e( 'today', 'shelter-donations' ); ?></span>
                <span><strong><?php echo esc_html( $week_count ); ?></strong> <?php esc_html_e( 'this week', 'shelter-donations' ); ?></span>
                <span><strong><?php echo esc_html( $total_items ); ?></strong> <?php esc_html_e( 'total', 'shelter-donations' ); ?></span>
            </div>

            <!-- Log table -->
            <?php if ( empty( $logs ) ) : ?>
            <div class="sd-empty-state">
                <span class="dashicons dashicons-list-view"></span>
                <p><?php esc_html_e( 'No activity logged yet.', 'shelter-donations' ); ?></p>
            </div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped sd-log-table">
                <thead>
                    <tr>
                        <th class="column-time"><?php esc_html_e( 'Time', 'shelter-donations' ); ?></th>
                        <th class="column-category"><?php esc_html_e( 'Category', 'shelter-donations' ); ?></th>
                        <th class="column-message"><?php esc_html_e( 'Activity', 'shelter-donations' ); ?></th>
                        <th class="column-user"><?php esc_html_e( 'User', 'shelter-donations' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) :
                        // created_at is stored as the WP site's local time
                        // (`current_time('mysql')`). mysql2date with format
                        // 'U' interprets the string in the WP timezone and
                        // returns a UTC epoch — safe to hand to wp_date,
                        // which then formats in the WP timezone again.
                        // Using strtotime() here would apply the server
                        // PHP timezone offset, which can differ from WP's.
                        $log_ts = (int) mysql2date( 'U', $log->created_at );
                        ?>
                    <tr>
                        <td class="column-time">
                            <span class="sd-log-date"><?php echo esc_html( wp_date( 'M j', $log_ts ) ); ?></span>
                            <span class="sd-log-time"><?php echo esc_html( wp_date( 'g:i a', $log_ts ) ); ?></span>
                        </td>
                        <td class="column-category">
                            <span class="sd-category-badge sd-category-<?php echo esc_attr( $log->event_category ); ?>">
                                <?php echo esc_html( self::get_category_icon( $log->event_category ) ); ?>
                                <?php echo esc_html( ucfirst( $log->event_category ) ); ?>
                            </span>
                        </td>
                        <td class="column-message">
                            <?php echo esc_html( $log->message ); ?>
                            <?php if ( $log->object_type && $log->object_id ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $log->object_id ) ); ?>" class="sd-log-link">
                                #<?php echo esc_html( $log->object_id ); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="column-user">
                            <?php
                            if ( $log->user_id ) {
                                $user = get_user_by( 'id', $log->user_id );
                                echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'shelter-donations' );
                            } else {
                                echo '<span class="sd-system-user">' . esc_html__( 'System', 'shelter-donations' ) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php
            $total_pages = ceil( $total_items / $per_page );
            if ( $total_pages > 1 ) :
                $page_links = paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $total_pages,
                    'current'   => $current_page,
                ] );
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo $page_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns pre-escaped anchor markup. ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get category icon.
     */
    private static function get_category_icon( string $category ): string {
        $icons = [
            'donation'   => '💰',
            'membership' => '🏅',
            'email'      => '✉️',
            'admin'      => '👤',
            'system'     => '⚙️',
        ];

        return $icons[ $category ] ?? '📋';
    }

    /**
     * Get donor name by ID.
     */
    private static function get_donor_name( int $donor_id ): string {
        $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
        
        if ( ! $donor ) {
            return __( 'Unknown Donor', 'shelter-donations' );
        }

        return $donor['display_name'] ?? trim( ( $donor['first_name'] ?? '' ) . ' ' . ( $donor['last_name'] ?? '' ) ) ?: __( 'Unknown Donor', 'shelter-donations' );
    }

    /**
     * Get the client IP for audit log entries.
     *
     * Defaults to REMOTE_ADDR only. Forwarded-header values
     * (X-Forwarded-For, X-Real-IP, CF-Connecting-IP) are NOT trusted
     * because any HTTP client can set them — honoring them would let
     * an attacker write arbitrary IPs into the audit log and defeat
     * its forensic purpose.
     *
     * Sites that sit behind a known reverse proxy or CDN (Cloudflare,
     * nginx, a load balancer) can hook `starter_shelter_activity_log_client_ip`
     * to extract the real client IP from $_SERVER themselves. Validate
     * before returning. Example:
     *
     *     add_filter( 'starter_shelter_activity_log_client_ip',
     *         function ( $ip ) {
     *             $trusted = [ '203.0.113.10' ]; // your proxy
     *             if ( ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', $trusted, true ) ) {
     *                 return $ip;
     *             }
     *             $forwarded = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' )[0] );
     *             return filter_var( $forwarded, FILTER_VALIDATE_IP ) ?: $ip;
     *         }
     *     );
     *
     * @since 2.1.0
     * @since 1.1.3 Stopped trusting forwarded headers by default.
     *
     * @return string|null Validated IP, or null if none available.
     */
    private static function get_client_ip(): ?string {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        $ip          = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : null;

        /**
         * Filters the resolved client IP for activity log entries.
         *
         * See {@see Activity_Log::get_client_ip()} for the threat model and
         * the recommended pattern for trusting a known proxy.
         *
         * @param string|null $ip Default-resolved IP (REMOTE_ADDR or null).
         */
        $ip = apply_filters( 'starter_shelter_activity_log_client_ip', $ip );

        return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
    }

    /**
     * Cleanup old log entries (older than 90 days).
     */
    public static function cleanup_old_logs(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $days_to_keep = apply_filters( 'starter_shelter_activity_log_retention_days', 90 );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_to_keep
        ) );
    }

    /**
     * Get inline styles.
     */
    private static function get_inline_styles(): string {
        return '
            .sd-log-filters {
                background: #fff;
                padding: 15px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .sd-log-filters form {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .sd-log-filters select,
            .sd-log-filters input[type="search"] {
                min-width: 150px;
            }
            
            .sd-log-stats {
                margin-bottom: 15px;
                display: flex;
                gap: 20px;
            }
            .sd-log-stats span {
                color: #646970;
            }
            
            .sd-empty-state {
                text-align: center;
                padding: 60px 20px;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .sd-empty-state .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #c3c4c7;
            }
            
            .sd-log-table .column-time { width: 100px; }
            .sd-log-table .column-category { width: 120px; }
            .sd-log-table .column-user { width: 150px; }
            
            .sd-log-date {
                display: block;
                font-weight: 600;
            }
            .sd-log-time {
                color: #646970;
                font-size: 12px;
            }
            
            .sd-category-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .sd-category-donation { background: #d1fae5; color: #065f46; }
            .sd-category-membership { background: #dbeafe; color: #1e40af; }
            .sd-category-email { background: #fef3c7; color: #92400e; }
            .sd-category-admin { background: #ede9fe; color: #5b21b6; }
            .sd-category-system { background: #f3f4f6; color: #374151; }
            
            .sd-log-link {
                margin-left: 5px;
                color: #2271b1;
                text-decoration: none;
            }
            
            .sd-system-user {
                color: #888;
                font-style: italic;
            }
        ';
    }
}
