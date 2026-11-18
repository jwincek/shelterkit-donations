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
    private const TABLE_NAME = 'skdon_activity_log';

    /**
     * Schema version, recorded once the table has been created.
     *
     * Bump it when the CREATE TABLE statement changes, and every site
     * re-runs dbDelta() on its next request.
     *
     * @var string
     */
    private const DB_VERSION = '2';

    /**
     * Option holding the schema version this site's table was built at.
     * Listed in Core\Prefix_Migration::OPTIONS, so uninstall removes it with the table.
     *
     * @var string
     */
    private const DB_VERSION_OPTION = 'skdon_activity_log_db_version';

    /**
     * Page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'shelterkit-donations-activity';

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
     * Where an entry can come from (#37). The screen's Source filter offers
     * these; the starter_shelter_activity_log_source filter may add others.
     */
    public const SOURCES = [ 'checkout', 'webhook', 'admin', 'import', 'tool', 'cron', 'cli', 'rest', 'site' ];

    /**
     * Event types that stand for a whole run in the list.
     */
    private const RUN_TYPES = [ 'import_started', 'import_finished' ];

    /**
     * The list's default WHERE: entries outside any run, plus each run's own
     * entry. A run's other entries show when the run is opened.
     */
    private const COLLAPSE_RUNS = "(run_id IS NULL OR event_type IN ('import_started', 'import_finished'))";

    /**
     * The run entries are being attached to, if any.
     *
     * @var array{id: string, source: string}|null
     */
    private static ?array $run = null;

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
        // Activator::activate() creates the table on a fresh install. This
        // covers sites that were activated before it did: a
        // register_activation_hook() call used to sit here, but init() runs
        // on plugins_loaded, which has already fired by the time a plugin is
        // activated, so it never ran. Those sites only got the table when
        // someone opened this screen, and every event before that was lost
        // (#22). One autoloaded option read per request.
        self::maybe_create_table();

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
        add_action( 'starter_shelter_gift_refunded', [ self::class, 'log_gift_refunded' ], 10, 4 );

        // Cleanup old logs.
        add_action( 'skdon_cleanup_activity_log', [ self::class, 'cleanup_old_logs' ] );

        if ( ! wp_next_scheduled( 'skdon_cleanup_activity_log' ) ) {
            wp_schedule_event( time(), 'daily', 'skdon_cleanup_activity_log' );
        }
    }

    /**
     * Create the table if this site's schema is older than DB_VERSION.
     */
    public static function maybe_create_table(): void {
        if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
            return;
        }

        self::create_table();
    }

    /**
     * Create or update the activity log table, and record the schema version.
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        // No IF NOT EXISTS: dbDelta() reads the table name from the word after
        // CREATE TABLE, and handles an existing table itself by altering it.
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_category varchar(30) NOT NULL,
            message text NOT NULL,
            object_type varchar(30) DEFAULT NULL,
            object_id bigint(20) unsigned DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            meta longtext DEFAULT NULL,
            source varchar(20) DEFAULT NULL,
            run_id char(36) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY event_category (event_category),
            KEY object_type_id (object_type, object_id),
            KEY user_id (user_id),
            KEY source (source),
            KEY run_id (run_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Remove the cleanup event. Called on deactivation; uninstall clears it
     * by name too, since the plugin is not loaded then.
     */
    public static function unschedule_cleanup(): void {
        wp_clear_scheduled_hook( 'skdon_cleanup_activity_log' );
    }

    /**
     * Add activity log page to admin menu.
     */
    public static function add_menu_page(): void {
        self::$page_hook = (string) add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Activity Log', 'shelterkit-donations' ),
            __( 'Activity Log', 'shelterkit-donations' ),
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
     * Write an entry whose text is stored as written.
     *
     * Kept for entries that bring their own sentence. The plugin's own events
     * use record(), which stores data and leaves the sentence to the screen.
     *
     * @param string      $event_type  Event type identifier.
     * @param string      $category    Event category (donation, membership, email, admin, system).
     * @param string      $message     Human-readable message, or '' to build it when shown.
     * @param string|null $object_type Related object type (post type).
     * @param int|null    $object_id   Related object ID.
     * @param array       $meta        The event's data.
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
                // Only wp-admin requests record an address. Until #22 the log
                // heard nothing else, so this is exactly what it collected
                // before; events a donor triggers (checkout, a payment
                // webhook) now reach the log without storing the donor's IP.
                'ip_address'     => is_admin() ? self::get_client_ip() : null,
                'meta'           => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
                'source'         => self::current_source(),
                'run_id'         => self::$run['id'] ?? null,
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Write an entry as data. Its sentence is built when the log is shown,
     * in the viewer's language, with the donor's name as it is then (#37).
     *
     * @param string      $event_type  Event type; render_message() knows how to word it.
     * @param string      $category    Event category.
     * @param array       $data        The event's data. Refer to a donor by donor_id, not name.
     * @param string|null $object_type Related object type (post type).
     * @param int|null    $object_id   Related object ID.
     */
    public static function record(
        string $event_type,
        string $category,
        array $data = [],
        ?string $object_type = null,
        ?int $object_id = null
    ): void {
        self::log( $event_type, $category, '', $object_type, $object_id, $data );
    }

    // ─────────────────────────────────────────────────────────────
    // Runs: an import or a tool that writes many entries
    // ─────────────────────────────────────────────────────────────

    /**
     * Start a run. Writes the run's one visible entry, and attaches every
     * entry written until finish_run() or leave_run() to it, so the log shows
     * an import as one line instead of one line per record (#37).
     *
     * @param string $kind   csv_import, legacy_memorial_import or legacy_order_sync.
     * @param array  $data   What the run covers: entity type, file name, filters.
     * @param string $source The source its entries carry.
     * @return string The run ID, to resume the run in a later request.
     */
    public static function begin_run( string $kind, array $data = [], string $source = 'import' ): string {
        $run_id    = wp_generate_uuid4();
        self::$run = [
            'id'     => $run_id,
            'source' => sanitize_key( $source ) ?: 'import',
        ];
        self::record( 'import_started', 'admin', array_merge( [ 'kind' => $kind ], $data ) );
        return $run_id;
    }

    /**
     * Rejoin a run in a later request, such as the next batch of an import.
     *
     * @param string $run_id From begin_run().
     * @return bool Whether the run exists. If not, entries are written unattached.
     */
    public static function resume_run( string $run_id ): bool {
        $anchor = wp_is_uuid( $run_id, 4 ) ? self::run_anchor( $run_id ) : null;
        if ( ! $anchor ) {
            return false;
        }
        self::$run = [
            'id'     => $run_id,
            'source' => (string) ( $anchor->source ?: 'import' ),
        ];
        return true;
    }

    /**
     * Stop attaching entries to the current run without finishing it: the
     * end of one batch.
     */
    public static function leave_run(): void {
        self::$run = null;
    }

    /**
     * Add one batch's counts to a run's running totals.
     *
     * @param string            $run_id From begin_run().
     * @param array<string,int> $counts Counts to add.
     */
    public static function add_to_run( string $run_id, array $counts ): void {
        $anchor = self::run_anchor( $run_id );
        if ( ! $anchor ) {
            return;
        }
        $data   = json_decode( (string) $anchor->meta, true ) ?: [];
        $totals = (array) ( $data['totals'] ?? [] );
        foreach ( $counts as $key => $count ) {
            $totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) $count;
        }
        $data['totals'] = $totals;
        self::update_anchor( (int) $anchor->id, $data, (string) $anchor->event_type );
    }

    /**
     * Finish a run: its visible entry becomes the summary.
     *
     * @param string $run_id  From begin_run().
     * @param array  $summary Final counts (rows, created, updated, skipped, errors…).
     */
    public static function finish_run( string $run_id, array $summary = [] ): void {
        $anchor = self::run_anchor( $run_id );
        if ( $anchor ) {
            $data           = json_decode( (string) $anchor->meta, true ) ?: [];
            $data['totals'] = array_merge( (array) ( $data['totals'] ?? [] ), array_map( 'intval', $summary ) );
            $data['finished_at'] = current_time( 'mysql' );
            self::update_anchor( (int) $anchor->id, $data, 'import_finished' );
        }
        self::$run = null;
    }

    /**
     * The run's visible entry.
     *
     * @param string $run_id Run ID.
     * @return object|null
     */
    private static function run_anchor( string $run_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $row   = $wpdb->get_row( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is $wpdb->prefix plus a constant.
            "SELECT id, event_type, source, meta FROM $table WHERE run_id = %s AND event_type IN ('import_started', 'import_finished') ORDER BY id ASC LIMIT 1",
            $run_id
        ) );
        return $row ?: null;
    }

    /**
     * Rewrite a run's visible entry.
     *
     * @param int    $id         Entry ID.
     * @param array  $data       Its data.
     * @param string $event_type Its event type.
     */
    private static function update_anchor( int $id, array $data, string $event_type ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE_NAME,
            [ 'meta' => wp_json_encode( $data ), 'event_type' => $event_type ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Source: where an entry came from
    // ─────────────────────────────────────────────────────────────

    /**
     * Where the current request comes from, recorded on every entry (#37).
     *
     * @return string One of SOURCES, or what the filter returns.
     */
    public static function current_source(): string {
        if ( self::$run ) {
            $source = self::$run['source'];
        } elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
            $source = 'cli';
        } elseif ( wp_doing_cron() ) {
            $source = 'cron';
        } elseif ( self::is_webhook_request() ) {
            $source = 'webhook';
        } elseif ( self::is_checkout_request() ) {
            $source = 'checkout';
        } elseif ( is_admin() && ( ! wp_doing_ajax() || str_starts_with( (string) wp_get_raw_referer(), admin_url() ) ) ) {
            // admin-ajax.php counts as admin only when an admin screen sent it;
            // the front end's AJAX goes through it too.
            $source = 'admin';
        } elseif ( '' !== self::rest_route() ) {
            $source = 'rest';
        } else {
            $source = 'site';
        }

        /**
         * Filters the source recorded on an activity log entry.
         *
         * @param string $source checkout, webhook, admin, import, tool, cron, cli, rest or site.
         */
        $source = sanitize_key( (string) apply_filters( 'starter_shelter_activity_log_source', $source ) );
        return '' !== $source ? substr( $source, 0, 20 ) : 'site';
    }

    /**
     * A payment provider's callback: WooCommerce's legacy wc-api endpoint, or
     * a REST route with "webhook" in it (WooPayments, PayPal Payments, Stripe).
     */
    private static function is_webhook_request(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which endpoint this request is; acts on nothing.
        return isset( $_GET['wc-api'] ) || str_contains( self::rest_route(), 'webhook' );
    }

    /**
     * The customer placing an order: classic or block checkout.
     */
    private static function is_checkout_request(): bool {
        if ( did_action( 'woocommerce_checkout_order_processed' ) || did_action( 'woocommerce_store_api_checkout_order_processed' ) ) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which endpoint this request is; acts on nothing.
        if ( isset( $_GET['wc-ajax'] ) && 'checkout' === sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) ) {
            return true;
        }
        return (bool) preg_match( '#^/wc/store(/v\d+)?/checkout#', self::rest_route() );
    }

    /**
     * The REST route being served, or ''.
     */
    private static function rest_route(): string {
        if ( ! wp_is_serving_rest_request() ) {
            return '';
        }
        global $wp;
        return (string) ( $wp->query_vars['rest_route'] ?? '' );
    }

    // ─────────────────────────────────────────────────────────────
    // Event writers. Data only: render_message() words them.
    // ─────────────────────────────────────────────────────────────

    /**
     * Log donation created.
     */
    public static function log_donation_created( int $donation_id, int $donor_id, array $data ): void {
        self::record( 'donation_created', 'donation', [
            'donor_id'   => $donor_id,
            'amount'     => $data['amount'] ?? 0,
            'allocation' => $data['allocation'] ?? '',
        ], 'skdon_donation', $donation_id );
    }

    /**
     * Log membership created.
     */
    public static function log_membership_created( int $membership_id, int $donor_id, array $data ): void {
        self::record( 'membership_created', 'membership', [
            'donor_id' => $donor_id,
            'tier'     => $data['tier'] ?? '',
            'type'     => $data['membership_type'] ?? 'individual',
            'amount'   => $data['amount'] ?? 0,
        ], 'skdon_membership', $membership_id );
    }

    /**
     * Log memorial created.
     */
    public static function log_memorial_created( int $memorial_id, int $donor_id, array $data ): void {
        self::record( 'memorial_created', 'donation', [
            'donor_id'     => $donor_id,
            'honoree_name' => $data['honoree_name'] ?? '',
            'type'         => $data['memorial_type'] ?? '',
            'amount'       => $data['amount'] ?? 0,
        ], 'skdon_memorial', $memorial_id );
    }

    /**
     * Log membership renewed.
     */
    public static function log_membership_renewed( int $membership_id, int $donor_id, array $data ): void {
        self::record( 'membership_renewed', 'membership', [
            'donor_id'     => $donor_id,
            'new_end_date' => $data['new_end_date'] ?? '',
        ], 'skdon_membership', $membership_id );
    }

    /**
     * Log logo approved.
     */
    public static function log_logo_approved( int $membership_id, int $donor_id, array $data ): void {
        self::record( 'logo_approved', 'admin', [
            'donor_id'      => $donor_id,
            'business_name' => $data['business_name'] ?? '',
        ], 'skdon_membership', $membership_id );
    }

    /**
     * Log logo rejected.
     */
    public static function log_logo_rejected( int $membership_id, int $donor_id, array $data ): void {
        self::record( 'logo_rejected', 'admin', [
            'donor_id'      => $donor_id,
            'business_name' => $data['membership']['business_name'] ?? '',
            'reason'        => $data['reason'] ?? '',
        ], 'skdon_membership', $membership_id );
    }

    /**
     * Log order processed.
     */
    public static function log_order_processed( int $order_id, array $results, bool $has_errors ): void {
        self::record( 'order_processed', 'system', [
            'has_errors'  => $has_errors,
            'items_count' => count( $results ),
        ], 'shop_order', $order_id );
    }

    /**
     * Log email sent.
     */
    public static function log_email_sent( string $email_type, string $recipient, int $object_id, array $data ): void {
        self::record( 'email_sent', 'email', [
            'email_type' => $email_type,
            'recipient'  => $recipient,
        ], $data['object_type'] ?? null, $object_id );
    }

    /**
     * Log settings changed.
     */
    public static function log_settings_changed( $old_value, $new_value ): void {
        // Walk the union of old + new keys so a setting that was removed
        // (key in old but not new) is also captured as changed.
        $old_arr  = is_array( $old_value ) ? $old_value : [];
        $new_arr  = is_array( $new_value ) ? $new_value : [];
        $all_keys = array_unique( array_merge( array_keys( $old_arr ), array_keys( $new_arr ) ) );

        $changed = [];
        foreach ( $all_keys as $key ) {
            if ( ( $old_arr[ $key ] ?? null ) !== ( $new_arr[ $key ] ?? null ) ) {
                $changed[] = $key;
            }
        }

        if ( $changed ) {
            self::record( 'settings_changed', 'admin', [ 'changed_fields' => $changed ] );
        }
    }

    /**
     * Log membership extended.
     */
    public static function log_membership_extended( int $membership_id, string $new_end_date ): void {
        self::record( 'membership_extended', 'admin', [
            'donor_id'     => (int) get_post_meta( $membership_id, '_skdon_donor_id', true ),
            'new_end_date' => $new_end_date,
        ], 'skdon_membership', $membership_id );
    }

    /**
     * Log membership cancelled.
     */
    public static function log_membership_cancelled( int $membership_id, array $membership, string $reason ): void {
        self::record( 'membership_cancelled', 'membership', [
            'donor_id' => (int) ( $membership['donor_id'] ?? 0 ),
            'reason'   => $reason,
            'tier'     => $membership['tier'] ?? '',
        ], 'skdon_membership', $membership_id );
    }

    /**
     * Log a gift refunded in part or in full, or a refund undone (#34).
     *
     * @param int   $record   The donation, membership or memorial.
     * @param int   $order_id Its order.
     * @param float $refunded Refunded now, in total.
     * @param float $original The gift's amount before any refund.
     */
    public static function log_gift_refunded( int $record, int $order_id, float $refunded, float $original ): void {
        $type = (string) get_post_type( $record );

        if ( $refunded <= 0 ) {
            $event = 'gift_refund_undone';
        } elseif ( $refunded + 0.005 >= $original ) {
            $event = 'gift_refunded';
        } else {
            $event = 'gift_partly_refunded';
        }

        self::record( $event, 'skdon_membership' === $type ? 'membership' : 'donation', [
            'donor_id' => (int) get_post_meta( $record, '_skdon_donor_id', true ),
            'order_id' => $order_id,
            'refunded' => $refunded,
            'original' => $original,
        ], $type, $record );
    }

    /**
     * Log family notified.
     */
    public static function log_family_notified( int $memorial_id, string $family_email ): void {
        self::record( 'family_notified', 'email', [
            'family_email' => $family_email,
            'honoree_name' => (string) get_post_meta( $memorial_id, '_skdon_honoree_name', true ),
        ], 'skdon_memorial', $memorial_id );
    }

    // ─────────────────────────────────────────────────────────────
    // Rendering
    // ─────────────────────────────────────────────────────────────

    /**
     * The sentence for an entry, in the viewer's language.
     *
     * Entries written before #37 stored their sentence; it is shown as
     * written. Later entries store data and are worded here, so a donor's
     * name is looked up now rather than kept in the log's text.
     *
     * @param object $row A row of the log table.
     * @return string Plain text.
     */
    public static function render_message( object $row ): string {
        if ( '' !== (string) ( $row->message ?? '' ) ) {
            return (string) $row->message;
        }

        $d     = json_decode( (string) ( $row->meta ?? '' ), true ) ?: [];
        $donor = static fn (): string => self::get_donor_name( (int) ( $d['donor_id'] ?? 0 ) );
        $money = static fn ( string $key ): string => Helpers\format_currency( (float) ( $d[ $key ] ?? 0 ) );
        $text  = static fn ( string $key ): string => (string) ( $d[ $key ] ?? '' );

        switch ( (string) $row->event_type ) {
            case 'donation_created':
                /* translators: 1: donor's name; 2: amount. */
                return sprintf( __( '%1$s donated %2$s', 'shelterkit-donations' ), $donor(), $money( 'amount' ) );

            case 'membership_created':
                /* translators: 1: donor's name; 2: membership tier; 3: membership type (individual, business). */
                return sprintf( __( '%1$s joined as %2$s %3$s member', 'shelterkit-donations' ), $donor(), ucfirst( $text( 'tier' ) ), $text( 'type' ) );

            case 'memorial_created':
                /* translators: 1: donor's name; 2: the name the memorial honours. */
                return sprintf( __( '%1$s created memorial for %2$s', 'shelterkit-donations' ), $donor(), $text( 'honoree_name' ) );

            case 'membership_renewed':
                /* translators: %s: donor's name. */
                return sprintf( __( '%s renewed membership', 'shelterkit-donations' ), $donor() );

            case 'logo_approved':
                /* translators: %s: business name. */
                return sprintf( __( 'Logo approved for %s', 'shelterkit-donations' ), $text( 'business_name' ) ?: __( 'Unknown Business', 'shelterkit-donations' ) );

            case 'logo_rejected':
                /* translators: 1: business name; 2: reason. */
                return sprintf( __( 'Logo rejected for %1$s: %2$s', 'shelterkit-donations' ), $text( 'business_name' ) ?: __( 'Unknown Business', 'shelterkit-donations' ), $text( 'reason' ) );

            case 'order_processed':
                return ! empty( $d['has_errors'] )
                    /* translators: %d: WooCommerce order ID. */
                    ? sprintf( __( 'Order #%d processed with errors', 'shelterkit-donations' ), (int) $row->object_id )
                    /* translators: %d: WooCommerce order ID. */
                    : sprintf( __( 'Order #%d processed successfully', 'shelterkit-donations' ), (int) $row->object_id );

            case 'email_sent':
                /* translators: 1: email type (e.g. Receipt, Renewal reminder); 2: recipient's email address. */
                return sprintf( __( '%1$s email sent to %2$s', 'shelterkit-donations' ), ucfirst( str_replace( '_', ' ', $text( 'email_type' ) ) ), $text( 'recipient' ) );

            case 'settings_changed':
                /* translators: %s: comma-separated list of changed setting keys. */
                return sprintf( __( 'Settings updated: %s', 'shelterkit-donations' ), implode( ', ', array_map( 'strval', (array) ( $d['changed_fields'] ?? [] ) ) ) );

            case 'membership_extended':
                /* translators: 1: donor's name; 2: new end date. */
                return sprintf( __( 'Membership for %1$s extended to %2$s', 'shelterkit-donations' ), $donor(), $text( 'new_end_date' ) );

            case 'membership_cancelled':
                return '' !== $text( 'reason' )
                    /* translators: 1: donor's name; 2: cancellation reason. */
                    ? sprintf( __( '%1$s cancelled membership: %2$s', 'shelterkit-donations' ), $donor(), $text( 'reason' ) )
                    /* translators: %s: donor's name. */
                    : sprintf( __( '%s cancelled membership', 'shelterkit-donations' ), $donor() );

            case 'family_notified':
                /* translators: 1: the name the memorial honours; 2: family member's email address. */
                return sprintf( __( 'Family notification sent for memorial of %1$s to %2$s', 'shelterkit-donations' ), $text( 'honoree_name' ), $text( 'family_email' ) );

            case 'gift_refunded':
                /* translators: 1: donor's name; 2: gift amount; 3: order number. */
                return sprintf( __( 'Refunded: %1$s\'s gift of %2$s (order #%3$d)', 'shelterkit-donations' ), $donor(), $money( 'original' ), (int) ( $d['order_id'] ?? 0 ) );

            case 'gift_partly_refunded':
                /* translators: 1: amount refunded; 2: donor's name; 3: gift amount; 4: order number. */
                return sprintf( __( 'Partly refunded: %1$s of %2$s\'s gift of %3$s (order #%4$d)', 'shelterkit-donations' ), $money( 'refunded' ), $donor(), $money( 'original' ), (int) ( $d['order_id'] ?? 0 ) );

            case 'gift_refund_undone':
                /* translators: 1: donor's name; 2: gift amount; 3: order number. */
                return sprintf( __( 'Refund undone: %1$s\'s gift of %2$s counts again (order #%3$d)', 'shelterkit-donations' ), $donor(), $money( 'original' ), (int) ( $d['order_id'] ?? 0 ) );

            case 'import_started':
            case 'import_finished':
                return self::render_run( (string) $row->event_type, $d );
        }

        /* translators: %s: event type identifier. */
        return sprintf( __( 'Event: %s', 'shelterkit-donations' ), (string) $row->event_type );
    }

    /**
     * The sentence for a run's entry.
     *
     * @param string $event_type import_started or import_finished.
     * @param array  $d          The run's data.
     * @return string
     */
    private static function render_run( string $event_type, array $d ): string {
        $kinds = [
            'csv_import'             => __( 'CSV import', 'shelterkit-donations' ),
            'legacy_memorial_import' => __( 'Legacy memorial import', 'shelterkit-donations' ),
            'legacy_order_sync'      => __( 'Legacy Order Sync', 'shelterkit-donations' ),
        ];
        $kind = $kinds[ $d['kind'] ?? '' ] ?? __( 'Import', 'shelterkit-donations' );

        $what = $kind;
        if ( ! empty( $d['file'] ) ) {
            /* translators: 1: kind of import (e.g. CSV import); 2: file name. */
            $what = sprintf( __( '%1$s of %2$s', 'shelterkit-donations' ), $kind, (string) $d['file'] );
        }

        if ( 'import_started' === $event_type ) {
            /* translators: %s: the import, e.g. "CSV import of donations.csv". */
            return sprintf( __( '%s started and has not finished', 'shelterkit-donations' ), $what );
        }

        $t = (array) ( $d['totals'] ?? [] );

        $labels = [
            /* translators: %d: number of rows in the file. */
            'rows'        => _n_noop( '%d row', '%d rows', 'shelterkit-donations' ),
            /* translators: %d: number of orders read. */
            'orders'      => _n_noop( '%d order', '%d orders', 'shelterkit-donations' ),
            /* translators: %d: number of records created. */
            'created'     => _n_noop( '%d created', '%d created', 'shelterkit-donations' ),
            /* translators: %d: number of records updated. */
            'updated'     => _n_noop( '%d updated', '%d updated', 'shelterkit-donations' ),
            /* translators: %d: number of rows skipped. */
            'skipped'     => _n_noop( '%d skipped', '%d skipped', 'shelterkit-donations' ),
            /* translators: %d: number of duplicate rows. */
            'duplicates'  => _n_noop( '%d duplicate', '%d duplicates', 'shelterkit-donations' ),
            /* translators: %d: number of rows or orders that failed. */
            'errors'      => _n_noop( '%d failed', '%d failed', 'shelterkit-donations' ),
            /* translators: %d: number of donations created. */
            'donations'   => _n_noop( '%d donation', '%d donations', 'shelterkit-donations' ),
            /* translators: %d: number of memberships created. */
            'memberships' => _n_noop( '%d membership', '%d memberships', 'shelterkit-donations' ),
            /* translators: %d: number of memorials created. */
            'memorials'   => _n_noop( '%d memorial', '%d memorials', 'shelterkit-donations' ),
            /* translators: %d: number of donors created. */
            'donors'      => _n_noop( '%d new donor', '%d new donors', 'shelterkit-donations' ),
        ];
        $parts = [];
        foreach ( $labels as $key => $noop ) {
            $n = (int) ( $t[ $key ] ?? 0 );
            if ( $n > 0 ) {
                $parts[] = sprintf( translate_nooped_plural( $noop, $n, 'shelterkit-donations' ), $n );
            }
        }

        return $parts
            /* translators: 1: the import, e.g. "CSV import of donations.csv"; 2: its counts, e.g. "120 rows, 100 created, 20 skipped". */
            ? sprintf( __( '%1$s: %2$s', 'shelterkit-donations' ), $what, implode( wp_get_list_item_separator(), $parts ) )
            /* translators: %s: the import, e.g. "CSV import of donations.csv". */
            : sprintf( __( '%s finished with nothing to do', 'shelterkit-donations' ), $what );
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
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            self::create_table();
        }

        // Filters.
        $category_filter = sanitize_key( $_GET['category'] ?? '' );
        $date_filter = sanitize_key( $_GET['date'] ?? '' );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only admin screen behind current_user_can(); the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $source_filter = sanitize_key( $_GET['source'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter, like the ones above.
        $run_filter    = sanitize_text_field( wp_unslash( $_GET['run'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter, like the ones above.
        $run_filter    = wp_is_uuid( $run_filter, 4 ) ? $run_filter : '';

        // Pagination.
        $per_page = 50;
        $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset = ( $current_page - 1 ) * $per_page;

        // Build query. A run shows as its one entry unless it is opened (#37).
        $where  = $run_filter ? 'run_id = %s' : self::COLLAPSE_RUNS;
        $params = $run_filter ? [ $run_filter ] : [];

        if ( $source_filter ) {
            $where .= ' AND source = %s';
            $params[] = $source_filter;
        }

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
            // Entries since #37 keep their data, not a sentence, and name a
            // donor by ID: match the data, and the IDs of donors so named.
            $like      = '%' . $wpdb->esc_like( $search ) . '%';
            $clauses   = [ 'message LIKE %s', 'meta LIKE %s' ];
            $params[]  = $like;
            $params[]  = $like;
            foreach ( self::donor_ids_named( $search ) as $donor_id ) {
                $clauses[] = 'meta LIKE %s';
                $params[]  = '%' . $wpdb->esc_like( '"donor_id":' . $donor_id . ',' ) . '%';
                $clauses[] = 'meta LIKE %s';
                $params[]  = '%' . $wpdb->esc_like( '"donor_id":' . $donor_id . '}' ) . '%';
            }
            $where .= ' AND (' . implode( ' OR ', $clauses ) . ')';
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

        // How many entries each run on this page holds, for its link.
        $run_sizes = [];
        $run_ids   = array_values( array_unique( array_filter( wp_list_pluck( (array) $logs, 'run_id' ) ) ) );
        if ( $run_ids && ! $run_filter ) {
            $placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a generated %s list, one per run ID passed.
            foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT run_id, COUNT(*) AS n FROM $table_name WHERE run_id IN ($placeholders) GROUP BY run_id", $run_ids ) ) as $size ) {
                $run_sizes[ $size->run_id ] = (int) $size->n - 1; // Not counting the run's own entry.
            }
        }

        ?>
        <div class="wrap sd-activity-log">
            <h1><?php esc_html_e( 'Activity Log', 'shelterkit-donations' ); ?></h1>

            <!-- Filters -->
            <div class="sd-log-filters">
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
                    
                    <select name="category">
                        <option value=""><?php esc_html_e( 'All Categories', 'shelterkit-donations' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
                            <?php echo esc_html( ucfirst( $cat ) ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="date">
                        <option value=""><?php esc_html_e( 'All Time', 'shelterkit-donations' ); ?></option>
                        <option value="today" <?php selected( $date_filter, 'today' ); ?>><?php esc_html_e( 'Today', 'shelterkit-donations' ); ?></option>
                        <option value="week" <?php selected( $date_filter, 'week' ); ?>><?php esc_html_e( 'Last 7 Days', 'shelterkit-donations' ); ?></option>
                        <option value="month" <?php selected( $date_filter, 'month' ); ?>><?php esc_html_e( 'Last 30 Days', 'shelterkit-donations' ); ?></option>
                    </select>

                    <select name="source">
                        <option value=""><?php esc_html_e( 'All Sources', 'shelterkit-donations' ); ?></option>
                        <?php foreach ( self::SOURCES as $source ) : ?>
                        <option value="<?php echo esc_attr( $source ); ?>" <?php selected( $source_filter, $source ); ?>><?php echo esc_html( self::source_label( $source ) ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ( $run_filter ) : ?>
                    <input type="hidden" name="run" value="<?php echo esc_attr( $run_filter ); ?>" />
                    <?php endif; ?>

                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'shelterkit-donations' ); ?>" />

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'shelterkit-donations' ); ?></button>
                </form>
            </div>

            <!-- Stats summary -->
            <div class="sd-log-stats">
                <?php
                $collapse    = self::COLLAPSE_RUNS;
                $today_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE $collapse AND DATE(created_at) = CURDATE()" );
                $week_count  = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE $collapse AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
                ?>
                <span><strong><?php echo esc_html( $today_count ); ?></strong> <?php esc_html_e( 'today', 'shelterkit-donations' ); ?></span>
                <span><strong><?php echo esc_html( $week_count ); ?></strong> <?php esc_html_e( 'this week', 'shelterkit-donations' ); ?></span>
                <span><strong><?php echo esc_html( $total_items ); ?></strong> <?php esc_html_e( 'total', 'shelterkit-donations' ); ?></span>
                <?php if ( $run_filter ) : ?>
                <span><?php esc_html_e( 'Showing one import\'s entries.', 'shelterkit-donations' ); ?> <a href="<?php echo esc_url( remove_query_arg( [ 'run', 'paged' ] ) ); ?>"><?php esc_html_e( 'Show all activity', 'shelterkit-donations' ); ?></a></span>
                <?php endif; ?>
            </div>

            <!-- Log table -->
            <?php if ( empty( $logs ) ) : ?>
            <div class="sd-empty-state">
                <span class="dashicons dashicons-list-view"></span>
                <p><?php esc_html_e( 'No activity logged yet.', 'shelterkit-donations' ); ?></p>
            </div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped sd-log-table">
                <thead>
                    <tr>
                        <th class="column-time"><?php esc_html_e( 'Time', 'shelterkit-donations' ); ?></th>
                        <th class="column-category"><?php esc_html_e( 'Category', 'shelterkit-donations' ); ?></th>
                        <th class="column-message"><?php esc_html_e( 'Activity', 'shelterkit-donations' ); ?></th>
                        <th class="column-source"><?php esc_html_e( 'Source', 'shelterkit-donations' ); ?></th>
                        <th class="column-user"><?php esc_html_e( 'User', 'shelterkit-donations' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $logs as $log ) :
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
                            <?php echo esc_html( self::render_message( $log ) ); ?>
                            <?php if ( $log->object_type && $log->object_id ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $log->object_id ) ); ?>" class="sd-log-link">
                                #<?php echo esc_html( $log->object_id ); ?>
                            </a>
                            <?php endif; ?>
                            <?php if ( ! empty( $run_sizes[ $log->run_id ] ) ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'run', $log->run_id, remove_query_arg( 'paged' ) ) ); ?>" class="sd-log-link">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: %d: number of log entries the import wrote. */
                                    _n( 'View its %d entry', 'View its %d entries', $run_sizes[ $log->run_id ], 'shelterkit-donations' ),
                                    $run_sizes[ $log->run_id ]
                                ) );
                                ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="column-source"><?php echo esc_html( $log->source ? self::source_label( (string) $log->source ) : '—' ); ?></td>
                        <td class="column-user">
                            <?php
                            if ( $log->user_id ) {
                                $user = get_user_by( 'id', $log->user_id );
                                echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'shelterkit-donations' );
                            } else {
                                echo '<span class="sd-system-user">' . esc_html__( 'System', 'shelterkit-donations' ) . '</span>';
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
     * A source's label.
     *
     * @param string $source A source key.
     * @return string
     */
    private static function source_label( string $source ): string {
        $labels = [
            'checkout' => __( 'Checkout', 'shelterkit-donations' ),
            'webhook'  => __( 'Payment webhook', 'shelterkit-donations' ),
            'admin'    => __( 'Admin', 'shelterkit-donations' ),
            'import'   => __( 'Import', 'shelterkit-donations' ),
            'tool'     => __( 'Admin tool', 'shelterkit-donations' ),
            'cron'     => __( 'Scheduled task', 'shelterkit-donations' ),
            'cli'      => __( 'WP-CLI', 'shelterkit-donations' ),
            'rest'     => __( 'REST API', 'shelterkit-donations' ),
            'site'     => __( 'Website', 'shelterkit-donations' ),
        ];
        return $labels[ $source ] ?? $source;
    }

    /**
     * IDs of donors whose name contains a search term, for searching entries
     * that name a donor by ID.
     *
     * @param string $search Search term.
     * @return int[]
     */
    private static function donor_ids_named( string $search ): array {
        return array_map( 'intval', get_posts( [
            'post_type'   => 'skdon_donor',
            'post_status' => 'any',
            's'           => $search,
            'numberposts' => 50,
            'fields'      => 'ids',
        ] ) );
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
        $donor = Entity_Hydrator::get( 'skdon_donor', $donor_id );

        if ( ! $donor ) {
            return __( 'Unknown Donor', 'shelterkit-donations' );
        }

        return $donor['display_name'] ?? trim( ( $donor['first_name'] ?? '' ) . ' ' . ( $donor['last_name'] ?? '' ) ) ?: __( 'Unknown Donor', 'shelterkit-donations' );
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
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REMOTE_ADDR is recorded in an audit log as the connection address; it is stored and compared, never rendered.
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
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
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
            .sd-log-table .column-source { width: 130px; }
            
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
