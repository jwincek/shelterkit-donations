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
    private const RUN_TYPES = [ 'import_started', 'import_finished', 'tool_started', 'tool_finished' ];

    /**
     * The list's default WHERE: entries outside any run, plus each run's own
     * entry. A run's other entries show when the run is opened.
     */
    private const COLLAPSE_RUNS = "(run_id IS NULL OR event_type IN ('import_started', 'import_finished', 'tool_started', 'tool_finished'))";

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
        add_action( 'add_meta_boxes', [ self::class, 'register_history_box' ] );
        add_action( 'admin_post_skdon_export_activity_log', [ self::class, 'handle_export' ] );
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
        // A site's first save adds the option rather than updating it.
        add_action( 'add_option_starter_shelter_options', [ self::class, 'log_settings_first_saved' ], 10, 2 );

        // Manual admin actions.
        add_action( 'starter_shelter_membership_extended', [ self::class, 'log_membership_extended' ], 10, 2 );
        add_action( 'starter_shelter_family_notified', [ self::class, 'log_family_notified' ], 10, 2 );
        add_action( 'starter_shelter_membership_cancelled', [ self::class, 'log_membership_cancelled' ], 10, 3 );
        add_action( 'starter_shelter_gift_refunded', [ self::class, 'log_gift_refunded' ], 10, 4 );

        // Settings kept as Config overrides, and the fund list (#36).
        add_action( 'starter_shelter_config_override_saved', [ self::class, 'log_config_changed' ], 10, 4 );
        add_action( 'starter_shelter_funds_saved', [ self::class, 'log_funds_changed' ], 10, 4 );

        // Gift and donor records: edits, deletions, trash (#36).
        Record_Edit_Tracker::init();
        add_action( 'before_delete_post', [ self::class, 'log_record_deleted' ], 10, 2 );
        add_action( 'trashed_post', [ self::class, 'log_record_trashed' ] );
        add_action( 'untrashed_post', [ self::class, 'log_record_untrashed' ] );
        add_action( 'starter_shelter_donor_profile_updated', [ self::class, 'log_donor_profile_updated' ], 10, 2 );
        add_action( 'starter_shelter_donor_address_updated', [ self::class, 'log_donor_address_updated' ], 10, 3 );

        // Events inside runs: one order of a Legacy Order Sync, one reminder.
        add_action( 'starter_shelter_legacy_order_synced', [ self::class, 'log_legacy_order_synced' ], 10, 3 );
        add_action( 'starter_shelter_renewal_reminder_sent', [ self::class, 'log_renewal_reminder_sent' ], 10, 3 );

        // Cleanup old logs.
        add_action( 'skdon_cleanup_activity_log', [ self::class, 'cleanup_old_logs' ] );

        if ( ! wp_next_scheduled( 'skdon_cleanup_activity_log' ) ) {
            // A day out, not now: a site that has just updated gets a day to
            // choose how long to keep its log before the first cleanup (#38).
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'skdon_cleanup_activity_log' );
        }

        // Deleting one import's entries (#38).
        add_action( 'admin_post_skdon_delete_log_run', [ self::class, 'handle_delete_run' ] );
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

        // This entry says what happened to its record, so the edit tracker
        // doesn't also write a generic "edited" entry for it (#36).
        if ( $object_id && in_array( $event_type, Record_Edit_Tracker::DESCRIBING_EVENTS, true ) ) {
            Record_Edit_Tracker::describe( $object_id );
        }
    }

    /**
     * Write an entry attached to a given run, whatever run is current. The
     * edit tracker writes changes it noted during a run after the fact.
     *
     * @param string|null $run_id      Run ID, or null for none.
     * @param string      $event_type  Event type.
     * @param string      $category    Event category.
     * @param array       $data        The event's data.
     * @param string|null $object_type Related object type.
     * @param int|null    $object_id   Related object ID.
     */
    public static function record_in_run( ?string $run_id, string $event_type, string $category, array $data = [], ?string $object_type = null, ?int $object_id = null ): void {
        $current   = self::$run;
        self::$run = null;
        if ( $run_id ) {
            $anchor    = self::run_anchor( $run_id );
            self::$run = $anchor ? [ 'id' => $run_id, 'source' => (string) ( $anchor->source ?: 'tool' ) ] : null;
        }
        try {
            self::record( $event_type, $category, $data, $object_type, $object_id );
        } finally {
            self::$run = $current;
        }
    }

    /**
     * The current run's ID, if entries are being attached to one.
     *
     * @return string|null
     */
    public static function current_run(): ?string {
        return self::$run['id'] ?? null;
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
     * An import (source "import") is an import_started entry; anything else,
     * an admin tool or a scheduled task, is tool_started (#36).
     *
     * @param string $kind   What runs: csv_import, legacy_order_sync, purge, backfill and so on.
     * @param array  $data   What the run covers: entity type, file name, filters.
     * @param string $source The source its entries carry.
     * @return string The run ID, to resume the run in a later request.
     */
    public static function begin_run( string $kind, array $data = [], string $source = 'import' ): string {
        $run_id    = wp_generate_uuid4();
        $source    = sanitize_key( $source ) ?: 'import';
        self::$run = [
            'id'     => $run_id,
            'source' => $source,
        ];
        self::record( 'import' === $source ? 'import_started' : 'tool_started', 'admin', array_merge( [ 'kind' => $kind ], $data ) );
        return $run_id;
    }

    /**
     * For a tool that runs in batches, one request each: rejoin the run the
     * browser sent back, or start one on the first batch.
     *
     * @param string $run_id The run ID the request carried, or ''.
     * @param string $kind   The tool, for a new run.
     * @param array  $data   What it covers, for a new run.
     * @param string $source Source, for a new run.
     * @return string The run ID, to send back to the browser.
     */
    public static function join_run( string $run_id, string $kind, array $data = [], string $source = 'tool' ): string {
        return ( '' !== $run_id && self::resume_run( $run_id ) ) ? $run_id : self::begin_run( $kind, $data, $source );
    }

    /**
     * The end of one batch: add its counts, and finish the run if it was the last.
     *
     * @param string            $run_id   The run.
     * @param array<string,int> $counts   This batch's counts.
     * @param bool              $finished Whether the whole run is done.
     */
    public static function end_batch( string $run_id, array $counts, bool $finished ): void {
        self::add_to_run( $run_id, $counts );
        if ( $finished ) {
            self::finish_run( $run_id );
        } else {
            self::leave_run();
        }
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
        if ( self::$run ) {
            Record_Edit_Tracker::flush( self::$run['id'] );
        }
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
     * @param array  $details Other data learned by the end, such as a tool's notes.
     */
    public static function finish_run( string $run_id, array $summary = [], array $details = [] ): void {
        Record_Edit_Tracker::flush( $run_id );
        $anchor = self::run_anchor( $run_id );
        if ( $anchor ) {
            $data                = array_merge( json_decode( (string) $anchor->meta, true ) ?: [], $details );
            $data['totals']      = array_merge( (array) ( $data['totals'] ?? [] ), array_map( 'intval', $summary ) );
            $data['finished_at'] = current_time( 'mysql' );
            self::update_anchor( (int) $anchor->id, $data, str_replace( '_started', '_finished', (string) $anchor->event_type ) );
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
            "SELECT id, event_type, source, meta FROM $table WHERE run_id = %s AND event_type IN ('import_started', 'import_finished', 'tool_started', 'tool_finished') ORDER BY id ASC LIMIT 1",
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

        $current = Settings::get_defaults();
        $changes = [];
        $removed = [];
        foreach ( $all_keys as $key ) {
            // A key that is no longer a setting at all (#49): say it went,
            // rather than "old value → (empty)".
            if ( array_key_exists( $key, $old_arr ) && ! array_key_exists( $key, $new_arr ) && ! array_key_exists( $key, $current ) ) {
                $removed[] = (string) $key;
                continue;
            }
            $old = $old_arr[ $key ] ?? null;
            $new = $new_arr[ $key ] ?? null;
            // As text, so 30 and "30" (a default and the form's value) agree,
            // and so do a missing value and an empty one.
            if ( ( self::scalar_text( $old ) ?? '' ) !== ( self::scalar_text( $new ) ?? '' ) ) {
                // Old → new, except for anything that looks like a credential.
                $changes[ $key ] = preg_match( '/key|secret|token|password/i', (string) $key )
                    ? null
                    : [ self::scalar_text( $old ), self::scalar_text( $new ) ];
            }
        }

        if ( $changes || $removed ) {
            self::record( 'settings_changed', 'admin', array_filter( [
                'changed_fields' => array_keys( $changes ),
                'changes'        => $changes,
                'removed_fields' => $removed,
            ] ) );
        }
    }

    /**
     * Log a site's first settings save, against the defaults it replaces.
     *
     * @param string $option The option name.
     * @param mixed  $value  The settings saved.
     */
    public static function log_settings_first_saved( $option, $value ): void {
        // Only the settings saved: a default the form doesn't send isn't changed.
        self::log_settings_changed( array_intersect_key( Settings::get_defaults(), (array) $value ), $value );
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

    /**
     * Log a Config override saved: the fund list, membership tiers, pet
     * species, donor levels (#36). Funds have their own entry.
     *
     * @param string $name Config file name.
     * @param string $key  Overridden key.
     * @param mixed  $old  Value before, or null.
     * @param mixed  $new  Value after, or null when the override was removed.
     */
    public static function log_config_changed( string $name, string $key, $old, $new ): void {
        if ( 'settings' === $name && in_array( $key, [ 'allocations', 'retired_allocations', 'allocation_aliases' ], true ) ) {
            return; // log_funds_changed() says it better.
        }
        $changes = self::diff( (array) ( $old ?? [] ), (array) ( $new ?? [] ) );
        if ( $changes ) {
            self::record( 'config_changed', 'admin', [
                'config'  => $name . '.' . $key,
                'changes' => $changes,
                'reset'   => null === $new,
            ] );
        }
    }

    /**
     * Log funds added, retired, restored or renamed (#36).
     *
     * @param array<string,string> $old_active  Active funds before, slug => label.
     * @param array<string,string> $active      Active funds after.
     * @param array<string,string> $old_retired Retired funds before.
     * @param array<string,string> $retired     Retired funds after.
     */
    public static function log_funds_changed( array $old_active, array $active, array $old_retired, array $retired ): void {
        $data = [
            'added'    => array_diff_key( $active, $old_active, $old_retired ),
            'retired'  => array_intersect_key( $old_active, array_diff_key( $retired, $old_retired ) ),
            'restored' => array_intersect_key( $active, $old_retired ),
            'renamed'  => [],
        ];
        foreach ( array_intersect_key( $active, $old_active ) as $slug => $label ) {
            if ( $label !== $old_active[ $slug ] ) {
                $data['renamed'][ $slug ] = [ $old_active[ $slug ], $label ];
            }
        }
        if ( array_filter( $data ) ) {
            self::record( 'funds_changed', 'admin', $data );
        }
    }

    /**
     * Log a donation, membership, memorial or donor being deleted for good.
     * The record is gone afterwards, so the entry keeps what identified it.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    The post.
     */
    public static function log_record_deleted( $post_id, $post ): void {
        if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Record_Edit_Tracker::POST_TYPES, true ) || 'auto-draft' === $post->post_status ) {
            return;
        }
        $meta = static fn ( string $key ): string => (string) get_post_meta( (int) $post_id, '_skdon_' . $key, true );
        $data = [ 'status' => $post->post_status ];
        if ( 'skdon_donor' !== $post->post_type ) {
            // A donor's details are personal data: the entry keeps only the ID.
            $data += [
                'donor_id'     => (int) $meta( 'donor_id' ),
                'amount'       => (float) $meta( 'amount' ),
                'allocation'   => $meta( 'allocation' ),
                'tier'         => $meta( 'tier' ),
                'honoree_name' => $meta( 'honoree_name' ),
            ];
        }
        self::record( 'record_deleted', self::record_category( $post->post_type ), array_filter( $data ), $post->post_type, (int) $post_id );
    }

    /**
     * Log a record moved to the trash.
     *
     * @param int $post_id Post ID.
     */
    public static function log_record_trashed( $post_id ): void {
        $type = (string) get_post_type( (int) $post_id );
        if ( in_array( $type, Record_Edit_Tracker::POST_TYPES, true ) ) {
            self::record( 'record_trashed', self::record_category( $type ), [], $type, (int) $post_id );
        }
    }

    /**
     * Log a record restored from the trash.
     *
     * @param int $post_id Post ID.
     */
    public static function log_record_untrashed( $post_id ): void {
        $type = (string) get_post_type( (int) $post_id );
        if ( in_array( $type, Record_Edit_Tracker::POST_TYPES, true ) ) {
            self::record( 'record_untrashed', self::record_category( $type ), [], $type, (int) $post_id );
        }
    }

    /**
     * Log a donor's profile updated (in My Account, or by an admin). Which
     * fields, not their values: they are personal data.
     *
     * @param int   $donor_id Donor ID.
     * @param array $input    The submitted fields.
     */
    public static function log_donor_profile_updated( int $donor_id, array $input ): void {
        $fields = array_values( array_diff( array_keys( $input ), [ 'donor_id', 'id' ] ) );
        self::record( 'donor_profile_updated', 'admin', [ 'donor_id' => $donor_id, 'fields' => $fields ], 'skdon_donor', $donor_id );
    }

    /**
     * Log a donor's address updated, and from where.
     *
     * @param int    $donor_id Donor ID.
     * @param array  $address  The new address (not stored).
     * @param string $source   Where it came from (checkout, profile, import…).
     */
    public static function log_donor_address_updated( int $donor_id, array $address, string $source ): void {
        self::record( 'donor_address_updated', 'admin', [ 'donor_id' => $donor_id, 'from' => $source ], 'skdon_donor', $donor_id );
    }

    /**
     * Log one order of a Legacy Order Sync (attached to the sync's run).
     *
     * @param int   $order_id     The order.
     * @param array $created      Counts of records created.
     * @param array $item_results Each line's result.
     */
    public static function log_legacy_order_synced( int $order_id, array $created, array $item_results ): void {
        self::record( 'legacy_order_synced', 'system', [
            'created' => array_map( 'intval', array_filter( $created, 'is_numeric' ) ),
            'lines'   => count( $item_results ),
        ], 'shop_order', $order_id );
    }

    /**
     * Log a renewal reminder (attached to the day's reminder run).
     *
     * @param int   $membership_id The membership.
     * @param int   $donor_id      Its donor.
     * @param array $membership    The membership's data.
     */
    public static function log_renewal_reminder_sent( int $membership_id, int $donor_id, array $membership ): void {
        self::record( 'renewal_reminder_sent', 'membership', [
            'donor_id' => $donor_id,
            'end_date' => (string) ( $membership['end_date'] ?? '' ),
        ], 'skdon_membership', $membership_id );
    }

    /**
     * The log category for a record type.
     *
     * @param string $post_type Post type.
     */
    private static function record_category( string $post_type ): string {
        return [ 'skdon_membership' => 'membership', 'skdon_donor' => 'admin' ][ $post_type ] ?? 'donation';
    }

    /**
     * Old → new for every value that differs between two nested arrays,
     * keyed by dotted path. At most 50.
     *
     * @param array $old Before.
     * @param array $new After.
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    private static function diff( array $old, array $new ): array {
        $flatten = static function ( array $array, string $prefix = '' ) use ( &$flatten ): array {
            $out = [];
            foreach ( $array as $key => $value ) {
                $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
                $out += is_array( $value ) && $value ? $flatten( $value, $path ) : [ $path => is_scalar( $value ) || null === $value ? $value : wp_json_encode( $value ) ];
            }
            return $out;
        };
        $before  = $flatten( $old );
        $after   = $flatten( $new );
        $changes = [];
        foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $path ) {
            $a = array_key_exists( $path, $before ) ? self::scalar_text( $before[ $path ] ) : null;
            $b = array_key_exists( $path, $after ) ? self::scalar_text( $after[ $path ] ) : null;
            if ( $a !== $b ) {
                $changes[ $path ] = [ $a, $b ];
            }
        }
        return array_slice( $changes, 0, 50, true );
    }

    /**
     * A setting's value as the log keeps it.
     *
     * @param mixed $value A value.
     * @return string|null
     */
    private static function scalar_text( $value ): ?string {
        if ( null === $value ) {
            return null;
        }
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        $text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
        return mb_strlen( $text ) > 100 ? mb_substr( $text, 0, 99 ) . '…' : $text;
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
                return sprintf( __( '%1$s email sent to %2$s', 'shelterkit-donations' ), ucfirst( str_replace( '_', ' ', $text( 'email_type' ) ) ), $text( 'recipient' ) ?: __( '(removed)', 'shelterkit-donations' ) );

            case 'log_cleaned':
                /* translators: %d: number of activity log entries deleted. */
                return sprintf( _n( 'Cleanup deleted %d entry past its retention period', 'Cleanup deleted %d entries past their retention period', (int) ( $d['deleted'] ?? 0 ), 'shelterkit-donations' ), (int) ( $d['deleted'] ?? 0 ) );

            case 'log_entries_deleted':
                $run = self::run_labels( [ $text( 'run' ) ] )[ $text( 'run' ) ] ?? __( 'an import', 'shelterkit-donations' );
                /* translators: 1: number of entries; 2: the import, e.g. "CSV import of gifts.csv: 120 rows, 120 created". */
                return sprintf( _n( 'Deleted %1$d entry of %2$s', 'Deleted %1$d entries of %2$s', (int) ( $d['deleted'] ?? 0 ), 'shelterkit-donations' ), (int) ( $d['deleted'] ?? 0 ), $run );

            case 'settings_changed':
                $parts = [];
                if ( ! empty( $d['changes'] ) ) {
                    $parts[] = self::render_changes( (array) $d['changes'] );
                } elseif ( ! empty( $d['changed_fields'] ) ) {
                    $parts[] = implode( ', ', array_map( 'strval', (array) $d['changed_fields'] ) );
                }
                if ( ! empty( $d['removed_fields'] ) ) {
                    /* translators: %s: comma-separated list of setting keys that no longer exist. */
                    $parts[] = sprintf( __( 'removed %s', 'shelterkit-donations' ), implode( ', ', array_map( 'strval', (array) $d['removed_fields'] ) ) );
                }
                /* translators: %s: the changes, e.g. "renewal_reminder_days: 30 → 14". */
                return sprintf( __( 'Settings updated: %s', 'shelterkit-donations' ), implode( '; ', $parts ) );

            case 'config_changed':
                return ! empty( $d['reset'] )
                    /* translators: %s: name of a setting group, e.g. Membership tiers. */
                    ? sprintf( __( '%s reset to the defaults', 'shelterkit-donations' ), self::config_label( $text( 'config' ) ) )
                    /* translators: 1: name of a setting group, e.g. Membership tiers; 2: the changes. */
                    : sprintf( __( '%1$s changed: %2$s', 'shelterkit-donations' ), self::config_label( $text( 'config' ) ), self::render_changes( (array) ( $d['changes'] ?? [] ) ) );

            case 'funds_changed':
                return self::render_funds( $d );

            case 'record_edited':
                /* translators: 1: record type, e.g. Membership; 2: the changes, e.g. "tier: single → family". */
                return sprintf( __( '%1$s edited: %2$s', 'shelterkit-donations' ), self::type_label( (string) $row->object_type ), self::render_changes( (array) ( $d['changes'] ?? [] ) ) );

            case 'record_deleted':
                return self::render_deleted( (string) $row->object_type, $d, $donor );

            case 'record_trashed':
                /* translators: %s: record type, e.g. Donation. */
                return sprintf( __( '%s moved to the trash', 'shelterkit-donations' ), self::type_label( (string) $row->object_type ) );

            case 'record_untrashed':
                /* translators: %s: record type, e.g. Donation. */
                return sprintf( __( '%s restored from the trash', 'shelterkit-donations' ), self::type_label( (string) $row->object_type ) );

            case 'donor_profile_updated':
                /* translators: 1: donor's name; 2: comma-separated list of the fields changed. */
                return sprintf( __( 'Profile updated for %1$s: %2$s', 'shelterkit-donations' ), $donor(), implode( ', ', array_map( 'strval', (array) ( $d['fields'] ?? [] ) ) ) );

            case 'donor_address_updated':
                /* translators: 1: donor's name; 2: where the address came from (checkout, profile, import…). */
                return sprintf( __( 'Address updated for %1$s (from %2$s)', 'shelterkit-donations' ), $donor(), $text( 'from' ) );

            case 'legacy_order_synced':
                /* translators: 1: order number; 2: number of lines on it. */
                return sprintf( _n( 'Order #%1$d synced: %2$d line', 'Order #%1$d synced: %2$d lines', (int) ( $d['lines'] ?? 0 ), 'shelterkit-donations' ), (int) $row->object_id, (int) ( $d['lines'] ?? 0 ) );

            case 'renewal_reminder_sent':
                /* translators: 1: donor's name; 2: membership end date. */
                return sprintf( __( 'Renewal reminder sent to %1$s (membership ends %2$s)', 'shelterkit-donations' ), $donor(), $text( 'end_date' ) );

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
                return sprintf( __( 'Family notification sent for memorial of %1$s to %2$s', 'shelterkit-donations' ), $text( 'honoree_name' ), $text( 'family_email' ) ?: __( '(removed)', 'shelterkit-donations' ) );

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

            case 'tool_started':
            case 'tool_finished':
                return self::render_tool( 'tool_finished' === $row->event_type, $d );
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

        // Filters, shared with the CSV export (#39).
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only admin screen behind current_user_can(); the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
        $filters         = self::request_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
        $category_filter = $filters['category'];
        $date_filter     = $filters['date'];
        $search          = $filters['s'];
        $source_filter   = $filters['source'];
        $run_filter      = $filters['run'];
        $object_filter   = $filters['object'];

        // Pagination.
        $per_page = 50;
        $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
        $offset = ( $current_page - 1 ) * $per_page;

        [ $where, $params ] = self::filter_sql( $filters );

        // Get total count.
        $count_query = "SELECT COUNT(*) FROM $table_name WHERE $where";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $table_name is $wpdb->prefix . self::TABLE_NAME (constant); $where is a static SQL skeleton whose only dynamic values use %s placeholders bound via $wpdb->prepare( $count_query, $params ). The no-filter branch passes a fully literal query.
        $total_items = $params ? $wpdb->get_var( $wpdb->prepare( $count_query, $params ) ) : $wpdb->get_var( $count_query );

        // Get logs.
        $query = "SELECT * FROM $table_name WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $table_name is $wpdb->prefix . self::TABLE_NAME (constant); $where is a static SQL skeleton whose only dynamic values use %s/%d placeholders bound via $wpdb->prepare( $query, $params ).
        $logs = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        // Filter dropdown uses the closed enumeration — no scan needed.
        $categories = self::KNOWN_CATEGORIES;

        // An opened import (not a tool) with entries of its own can have them deleted (#38).
        $run_anchor     = $run_filter ? self::run_anchor( $run_filter ) : null;
        $can_delete_run = $run_anchor && str_starts_with( (string) $run_anchor->event_type, 'import_' ) && (int) $total_items > 1;

        // A record's history shows which run each entry came from.
        $run_labels = $object_filter ? self::run_labels( wp_list_pluck( (array) $logs, 'run_id' ) ) : [];

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
            <?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect. ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php
                $deleted_count = absint( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect.
                /* translators: %d: number of activity log entries deleted. */
                echo esc_html( sprintf( _n( 'Deleted %d entry. The deletion is recorded in the log.', 'Deleted %d entries. The deletion is recorded in the log.', $deleted_count, 'shelterkit-donations' ), $deleted_count ) );
                ?>
            </p></div>
            <?php endif; ?>

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
                    <?php if ( $object_filter ) : ?>
                    <input type="hidden" name="object" value="<?php echo esc_attr( $object_filter[0] . ':' . $object_filter[1] ); ?>" />
                    <?php endif; ?>

                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'shelterkit-donations' ); ?>" />

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'shelterkit-donations' ); ?></button>
                </form>

                <!-- The current view as CSV, every page (#39). -->
                <form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sd-log-export">
                    <input type="hidden" name="action" value="skdon_export_activity_log" />
                    <?php wp_nonce_field( 'skdon_export_activity_log', '_wpnonce', false ); ?>
                    <?php
                    $export_args = [
                        'category' => $category_filter,
                        'date'     => $date_filter,
                        'source'   => $source_filter,
                        's'        => $search,
                        'run'      => $run_filter,
                        'object'   => $object_filter ? $object_filter[0] . ':' . $object_filter[1] : '',
                    ];
                    foreach ( array_filter( $export_args ) as $name => $value ) :
                        ?>
                    <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
                    <?php endforeach; ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'shelterkit-donations' ); ?></button>
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
                    <?php if ( $can_delete_run ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sd-log-delete-run" onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Delete this import\'s entries? Its own entry stays, and the deletion is recorded.', 'shelterkit-donations' ) ) ); ?> );">
                        <input type="hidden" name="action" value="skdon_delete_log_run" />
                        <input type="hidden" name="run" value="<?php echo esc_attr( $run_filter ); ?>" />
                        <?php wp_nonce_field( 'skdon_delete_log_run_' . $run_filter ); ?>
                        <button type="submit" class="button-link button-link-delete">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %d: number of entries. */
                                _n( 'Delete its %d entry', 'Delete its %d entries', (int) $total_items - 1, 'shelterkit-donations' ),
                                (int) $total_items - 1
                            ) );
                            ?>
                        </button>
                    </form>
                    <?php endif; ?>
                <?php elseif ( $object_filter ) : ?>
                <span>
                    <?php
                    /* translators: 1: record type, e.g. Membership; 2: its ID. */
                    echo esc_html( sprintf( __( 'Showing the history of %1$s #%2$d.', 'shelterkit-donations' ), self::type_label( $object_filter[0] ), $object_filter[1] ) );
                    ?>
                    <a href="<?php echo esc_url( remove_query_arg( [ 'object', 'paged' ] ) ); ?>"><?php esc_html_e( 'Show all activity', 'shelterkit-donations' ); ?></a>
                </span>
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
                            <?php self::print_links( $log ); ?>
                            <?php if ( isset( $run_labels[ $log->run_id ] ) && ! in_array( $log->event_type, self::RUN_TYPES, true ) ) : ?>
                            <br><span class="description">
                                <?php
                                /* translators: %s: the import or tool run the entry belongs to. */
                                echo esc_html( sprintf( __( 'Part of: %s', 'shelterkit-donations' ), $run_labels[ $log->run_id ] ) );
                                ?>
                            </span>
                            <?php endif; ?>
                            <?php if ( ! empty( $run_sizes[ $log->run_id ] ) && in_array( $log->event_type, self::RUN_TYPES, true ) ) : ?>
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
     * "tier: single → family; amount: 10 → 50". A change kept without its
     * values (a donor's details, a credential) shows its name only.
     *
     * @param array<string, array{0: ?string, 1: ?string}|null> $changes Field => [ old, new ].
     * @return string
     */
    private static function render_changes( array $changes ): string {
        $empty = __( '(empty)', 'shelterkit-donations' );
        $parts = [];
        foreach ( $changes as $field => $pair ) {
            if ( ! is_array( $pair ) ) {
                $parts[] = (string) $field;
                continue;
            }
            $old     = null === $pair[0] || '' === $pair[0] ? $empty : (string) $pair[0];
            $new     = null === $pair[1] || '' === $pair[1] ? $empty : (string) $pair[1];
            /* translators: 1: field name; 2: old value; 3: new value. */
            $parts[] = sprintf( __( '%1$s: %2$s → %3$s', 'shelterkit-donations' ), $field, $old, $new );
        }
        return implode( '; ', $parts );
    }

    /**
     * A Config override's name.
     *
     * @param string $config name.key.
     */
    private static function config_label( string $config ): string {
        $labels = [
            'tiers.tiers'           => __( 'Membership tiers', 'shelterkit-donations' ),
            'settings.pet_species'  => __( 'Pet species', 'shelterkit-donations' ),
            'settings.donor_levels' => __( 'Donor levels', 'shelterkit-donations' ),
        ];
        return $labels[ $config ] ?? $config;
    }

    /**
     * "Funds changed: added Medical Care; retired General Fund".
     *
     * @param array $d The entry's data.
     */
    private static function render_funds( array $d ): string {
        $list  = static fn ( $funds ): string => implode( ', ', array_map( 'strval', (array) $funds ) );
        $parts = [];
        if ( ! empty( $d['added'] ) ) {
            /* translators: %s: fund names. */
            $parts[] = sprintf( __( 'added %s', 'shelterkit-donations' ), $list( $d['added'] ) );
        }
        if ( ! empty( $d['retired'] ) ) {
            /* translators: %s: fund names. */
            $parts[] = sprintf( __( 'retired %s', 'shelterkit-donations' ), $list( $d['retired'] ) );
        }
        if ( ! empty( $d['restored'] ) ) {
            /* translators: %s: fund names. */
            $parts[] = sprintf( __( 'restored %s', 'shelterkit-donations' ), $list( $d['restored'] ) );
        }
        foreach ( (array) ( $d['renamed'] ?? [] ) as $pair ) {
            /* translators: 1: old fund name; 2: new fund name. */
            $parts[] = sprintf( __( 'renamed %1$s to %2$s', 'shelterkit-donations' ), (string) ( $pair[0] ?? '' ), (string) ( $pair[1] ?? '' ) );
        }
        /* translators: %s: the changes, e.g. "added Medical Care; retired General Fund". */
        return sprintf( __( 'Funds changed: %s', 'shelterkit-donations' ), implode( '; ', $parts ) );
    }

    /**
     * A record type's name: Donation, Membership, Memorial, Donor.
     *
     * @param string $post_type Post type.
     */
    private static function type_label( string $post_type ): string {
        $labels = [
            'skdon_donation'   => __( 'Donation', 'shelterkit-donations' ),
            'skdon_membership' => __( 'Membership', 'shelterkit-donations' ),
            'skdon_memorial'   => __( 'Memorial', 'shelterkit-donations' ),
            'skdon_donor'      => __( 'Donor', 'shelterkit-donations' ),
        ];
        return $labels[ $post_type ] ?? $post_type;
    }

    /**
     * "Deleted donation: $50.00 from Pat Donor".
     *
     * @param string   $post_type Post type.
     * @param array    $d         The entry's data.
     * @param callable $donor     Returns the donor's name.
     */
    private static function render_deleted( string $post_type, array $d, callable $donor ): string {
        $type = self::type_label( $post_type );
        if ( 'skdon_donor' === $post_type || empty( $d['donor_id'] ) ) {
            /* translators: %s: record type, e.g. Donor. */
            return sprintf( __( '%s deleted', 'shelterkit-donations' ), $type );
        }
        /* translators: 1: record type, e.g. Donation; 2: amount; 3: donor's name. */
        return sprintf( __( '%1$s deleted: %2$s from %3$s', 'shelterkit-donations' ), $type, Helpers\format_currency( (float) ( $d['amount'] ?? 0 ) ), $donor() );
    }

    /**
     * The sentence for an admin tool's or a scheduled task's run.
     *
     * @param bool  $finished Whether it finished.
     * @param array $d        The run's data.
     * @return string
     */
    private static function render_tool( bool $finished, array $d ): string {
        $t     = array_map( 'intval', (array) ( $d['totals'] ?? [] ) );
        $n     = static fn ( string $key ): int => $t[ $key ] ?? 0;
        $kind  = (string) ( $d['kind'] ?? '' );
        $names = [
            'backfill'          => __( 'Backfill & Repair', 'shelterkit-donations' ),
            'purge'             => __( 'Purge', 'shelterkit-donations' ),
            'recalc_donors'     => __( 'Recalculate donor totals', 'shelterkit-donations' ),
            'reset_sync_flags'  => __( 'Reset Sync Flags', 'shelterkit-donations' ),
            'merge_repair'      => __( 'Repair merged records', 'shelterkit-donations' ),
            'apply_refunds'     => __( 'Apply past refunds', 'shelterkit-donations' ),
            'assign_refund'     => __( 'Match a refund', 'shelterkit-donations' ),
            'product_repair'    => __( 'Product Sync repair', 'shelterkit-donations' ),
            'reprocess_order'   => __( 'Reprocess order', 'shelterkit-donations' ),
            'renewal_reminders' => __( 'Renewal reminders', 'shelterkit-donations' ),
        ];
        $name = $names[ $kind ] ?? $kind;

        if ( ! $finished ) {
            /* translators: %s: tool name, e.g. Purge. */
            return sprintf( __( '%s started and has not finished', 'shelterkit-donations' ), $name );
        }
        if ( ! empty( $d['error'] ) ) {
            /* translators: 1: tool name; 2: error message. */
            return sprintf( __( '%1$s failed: %2$s', 'shelterkit-donations' ), $name, (string) $d['error'] );
        }

        switch ( $kind ) {
            case 'backfill':
                /* translators: 1: number of fields filled in; 2: number of records checked. */
                return sprintf( __( 'Backfill & Repair: %1$d fields filled in across %2$d records', 'shelterkit-donations' ), $n( 'fixed' ), $n( 'records' ) );
            case 'purge':
                /* translators: 1: number of records; 2: record type, e.g. Memorial. */
                return sprintf( __( 'Purged %1$d records: %2$s', 'shelterkit-donations' ), $n( 'deleted' ), self::type_label( (string) ( $d['post_type'] ?? '' ) ) );
            case 'recalc_donors':
                /* translators: 1: number of donors; 2: number whose totals changed. */
                return sprintf( __( 'Recalculated donor totals: %1$d donors, %2$d changed', 'shelterkit-donations' ), $n( 'processed' ), $n( 'updated' ) );
            case 'reset_sync_flags':
                /* translators: %d: number of orders. */
                return sprintf( _n( 'Reset Legacy Order Sync flags on %d order', 'Reset Legacy Order Sync flags on %d orders', $n( 'orders' ), 'shelterkit-donations' ), $n( 'orders' ) );
            case 'merge_repair':
                /* translators: 1: number of records repaired; 2: number that failed. */
                return sprintf( __( 'Repaired records Legacy Order Sync merged: %1$d repaired, %2$d failed', 'shelterkit-donations' ), $n( 'repaired' ), $n( 'failed' ) );
            case 'apply_refunds':
                /* translators: 1: orders checked; 2: gifts changed; 3: refunds left to match. */
                return sprintf( __( 'Applied past refunds: %1$d orders checked, %2$d gifts changed, %3$d refunds to match', 'shelterkit-donations' ), $n( 'orders' ), $n( 'changed' ), $n( 'review' ) );
            case 'assign_refund':
                return (int) ( $d['record'] ?? 0 )
                    /* translators: 1: refund ID; 2: order number; 3: record ID. */
                    ? sprintf( __( 'Refund #%1$d on order #%2$d matched to #%3$d', 'shelterkit-donations' ), (int) ( $d['refund_id'] ?? 0 ), (int) ( $d['order_id'] ?? 0 ), (int) $d['record'] )
                    /* translators: 1: refund ID; 2: order number. */
                    : sprintf( __( 'Refund #%1$d on order #%2$d marked as returning no gift', 'shelterkit-donations' ), (int) ( $d['refund_id'] ?? 0 ), (int) ( $d['order_id'] ?? 0 ) );
            case 'product_repair':
                /* translators: 1: products repaired; 2: products skipped. */
                return sprintf( __( 'Product Sync repair: %1$d repaired, %2$d skipped', 'shelterkit-donations' ), $n( 'repaired' ), $n( 'skipped' ) );
            case 'reprocess_order':
                /* translators: 1: order number; 2: lines recorded now; 3: lines already recorded; 4: lines not recorded. */
                return sprintf( __( 'Reprocessed order #%1$d: %2$d recorded, %3$d already recorded, %4$d not recorded', 'shelterkit-donations' ), (int) ( $d['order_id'] ?? 0 ), $n( 'recorded' ), $n( 'already' ), $n( 'failed' ) );
            case 'renewal_reminders':
                /* translators: 1: reminders sent; 2: memberships skipped; 3: the end date they share. */
                return sprintf( __( 'Renewal reminders: %1$d sent, %2$d skipped (memberships ending %3$s)', 'shelterkit-donations' ), $n( 'sent' ), $n( 'skipped' ), (string) ( $d['end_date'] ?? '' ) );
        }

        /* translators: %s: tool name. */
        return sprintf( __( '%s finished', 'shelterkit-donations' ), $name );
    }

    // ─────────────────────────────────────────────────────────────
    // Filters, links, record history and CSV export (#39)
    // ─────────────────────────────────────────────────────────────

    /**
     * The list's filters, read from a request.
     *
     * @param array $q Request parameters ($_GET).
     * @return array{category: string, date: string, s: string, source: string, run: string, object: array{0: string, 1: int}|null}
     */
    public static function request_filters( array $q ): array {
        $run    = sanitize_text_field( wp_unslash( (string) ( $q['run'] ?? '' ) ) );
        $object = sanitize_text_field( wp_unslash( (string) ( $q['object'] ?? '' ) ) );
        $object = preg_match( '/^([a-z_]+):(\d+)$/', $object, $m )
            && ( in_array( $m[1], Record_Edit_Tracker::POST_TYPES, true ) || 'shop_order' === $m[1] )
            ? [ $m[1], (int) $m[2] ]
            : null;

        return [
            'category' => sanitize_key( (string) ( $q['category'] ?? '' ) ),
            'date'     => sanitize_key( (string) ( $q['date'] ?? '' ) ),
            's'        => sanitize_text_field( wp_unslash( (string) ( $q['s'] ?? '' ) ) ),
            'source'   => sanitize_key( (string) ( $q['source'] ?? '' ) ),
            'run'      => wp_is_uuid( $run, 4 ) ? $run : '',
            'object'   => $object,
        ];
    }

    /**
     * WHERE clause and parameters for a set of filters. A run shows as its
     * one entry unless it is opened, or unless one record's history is shown,
     * which includes what runs did to it.
     *
     * @param array $f From request_filters().
     * @return array{0: string, 1: array}
     */
    public static function filter_sql( array $f ): array {
        global $wpdb;

        if ( $f['run'] ) {
            $where  = 'run_id = %s';
            $params = [ $f['run'] ];
        } elseif ( $f['object'] ) {
            [ $where, $params ] = self::object_sql( $f['object'][0], $f['object'][1] );
        } else {
            $where  = self::COLLAPSE_RUNS;
            $params = [];
        }

        if ( $f['source'] ) {
            $where   .= ' AND source = %s';
            $params[] = $f['source'];
        }
        if ( $f['category'] ) {
            $where   .= ' AND event_category = %s';
            $params[] = $f['category'];
        }
        $dates = [
            'today' => ' AND DATE(created_at) = CURDATE()',
            'week'  => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
        ];
        $where .= $dates[ $f['date'] ] ?? '';

        if ( $f['s'] ) {
            // Entries since #37 keep their data, not a sentence, and name a
            // donor by ID: match the data, and the IDs of donors so named.
            $like     = '%' . $wpdb->esc_like( $f['s'] ) . '%';
            $clauses  = [ 'message LIKE %s', 'meta LIKE %s' ];
            $params[] = $like;
            $params[] = $like;
            foreach ( self::donor_ids_named( $f['s'] ) as $donor_id ) {
                [ $clause, $donor_params ] = self::data_names( 'donor_id', $donor_id );
                $clauses[]                 = $clause;
                $params                    = array_merge( $params, $donor_params );
            }
            $where .= ' AND (' . implode( ' OR ', $clauses ) . ')';
        }

        return [ $where, $params ];
    }

    /**
     * Entries about one record: those whose object it is, and for a donor
     * the entries naming them (their gifts), for an order those naming it.
     *
     * @param string $type Post type, or shop_order.
     * @param int    $id   ID.
     * @return array{0: string, 1: array}
     */
    private static function object_sql( string $type, int $id ): array {
        $clauses = [ '(object_type = %s AND object_id = %d)' ];
        $params  = [ $type, $id ];
        $key     = [ 'skdon_donor' => 'donor_id', 'shop_order' => 'order_id' ][ $type ] ?? '';
        if ( $key ) {
            [ $clause, $more ] = self::data_names( $key, $id );
            $clauses[]         = $clause;
            $params            = array_merge( $params, $more );
        }
        return [ '(' . implode( ' OR ', $clauses ) . ')', $params ];
    }

    /**
     * A clause matching entries whose data has `"$key":$id`, as JSON writes it.
     *
     * @param string $key Data key.
     * @param int    $id  Its value.
     * @return array{0: string, 1: array}
     */
    private static function data_names( string $key, int $id ): array {
        global $wpdb;
        return [
            '(meta LIKE %s OR meta LIKE %s)',
            [
                '%' . $wpdb->esc_like( '"' . $key . '":' . $id . ',' ) . '%',
                '%' . $wpdb->esc_like( '"' . $key . '":' . $id . '}' ) . '%',
            ],
        ];
    }

    /**
     * Links for an entry: the record it is about (while it exists) and the
     * order behind it.
     *
     * @param object $row A row of the log table.
     * @return array<int, array{0: string, 1: string}> [ label, URL ], URL '' when gone.
     */
    public static function entry_links( object $row ): array {
        $links = [];
        $type  = (string) $row->object_type;
        $id    = (int) $row->object_id;
        $data  = json_decode( (string) $row->meta, true ) ?: [];

        if ( $id && in_array( $type, Record_Edit_Tracker::POST_TYPES, true ) ) {
            $links[] = [ '#' . $id, get_post( $id ) ? (string) get_edit_post_link( $id, 'url' ) : '' ];
        }

        $order = 'shop_order' === $type ? $id : (int) ( $data['order_id'] ?? 0 );
        if ( ! $order && $id && in_array( $type, [ 'skdon_donation', 'skdon_membership', 'skdon_memorial' ], true ) ) {
            $order = (int) get_post_meta( $id, '_skdon_wc_order_id', true );
        }
        if ( $order > 0 ) {
            /* translators: %d: WooCommerce order number. */
            $links[] = [ sprintf( __( 'order #%d', 'shelterkit-donations' ), $order ), self::order_url( $order ) ];
        }

        return $links;
    }

    /**
     * An order's edit screen, whichever storage WooCommerce uses.
     *
     * @param int $order_id Order ID.
     * @return string URL, or '' without WooCommerce.
     */
    private static function order_url( int $order_id ): string {
        if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
            return (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
        }
        return function_exists( 'wc_get_order' ) ? admin_url( 'post.php?post=' . $order_id . '&action=edit' ) : '';
    }

    /**
     * Print an entry's links.
     *
     * @param object $row A row of the log table.
     */
    private static function print_links( object $row ): void {
        foreach ( self::entry_links( $row ) as [ $label, $url ] ) {
            if ( '' === $url ) {
                echo ' <span class="sd-log-link">' . esc_html( $label ) . '</span>';
            } else {
                echo ' <a href="' . esc_url( $url ) . '" class="sd-log-link">' . esc_html( $label ) . '</a>';
            }
        }
    }

    /**
     * The sentence of each run's own entry, by run ID.
     *
     * @param string[] $run_ids Run IDs.
     * @return array<string, string>
     */
    private static function run_labels( array $run_ids ): array {
        global $wpdb;
        $run_ids = array_values( array_unique( array_filter( $run_ids ) ) );
        if ( ! $run_ids ) {
            return [];
        }
        $table        = $wpdb->prefix . self::TABLE_NAME;
        $placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%s' ) );
        $labels       = [];
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the table name is $wpdb->prefix plus a constant; $placeholders is a generated %s list, one per run ID passed.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE run_id IN ($placeholders) AND event_type IN ('import_started', 'import_finished', 'tool_started', 'tool_finished')",
            $run_ids
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        foreach ( (array) $rows as $row ) {
            $labels[ $row->run_id ] = self::render_message( $row );
        }
        return $labels;
    }

    /**
     * The History panel on donation, membership, memorial and donor screens.
     */
    public static function register_history_box(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return; // The log itself is for administrators.
        }
        foreach ( Record_Edit_Tracker::POST_TYPES as $type ) {
            add_meta_box( 'skdon-activity-history', __( 'History', 'shelterkit-donations' ), [ self::class, 'render_history_box' ], $type, 'normal', 'low' );
        }
    }

    /**
     * The record's latest entries, including what imports and tools did to it.
     *
     * @param \WP_Post $post The record.
     */
    public static function render_history_box( $post ): void {
        global $wpdb;
        if ( ! $post instanceof \WP_Post || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $table              = $wpdb->prefix . self::TABLE_NAME;
        [ $where, $params ] = self::object_sql( $post->post_type, $post->ID );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the table name is $wpdb->prefix plus a constant; $where is built from fixed clauses whose %s/%d placeholders take $params.
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC LIMIT 20", $params ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        if ( ! $rows ) {
            echo '<p>' . esc_html__( 'No activity recorded for this record yet.', 'shelterkit-donations' ) . '</p>';
            return;
        }

        $runs = self::run_labels( wp_list_pluck( $rows, 'run_id' ) );
        echo '<table class="widefat striped sd-history"><tbody>';
        foreach ( $rows as $row ) {
            $ts = (int) mysql2date( 'U', $row->created_at );
            echo '<tr><td style="white-space:nowrap">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) . '</td><td>';
            echo esc_html( self::render_message( $row ) );
            self::print_links( $row );
            if ( $row->run_id && isset( $runs[ $row->run_id ] ) && ! in_array( $row->event_type, self::RUN_TYPES, true ) ) {
                $url = add_query_arg( [ 'page' => self::PAGE_SLUG, 'run' => $row->run_id ], admin_url( 'admin.php' ) );
                /* translators: %s: the import or tool run the entry belongs to. */
                echo '<br><span class="description">' . esc_html( sprintf( __( 'Part of: %s', 'shelterkit-donations' ), $runs[ $row->run_id ] ) ) . '</span> <a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'shelterkit-donations' ) . '</a>';
            }
            echo '</td><td style="white-space:nowrap">' . esc_html( self::user_label( $row ) ) . '<br><span class="description">' . esc_html( $row->source ? self::source_label( (string) $row->source ) : '' ) . '</span></td></tr>';
        }
        echo '</tbody></table>';

        $all = add_query_arg( [ 'page' => self::PAGE_SLUG, 'object' => $post->post_type . ':' . $post->ID ], admin_url( 'admin.php' ) );
        echo '<p><a href="' . esc_url( $all ) . '">' . esc_html( sprintf(
            /* translators: %d: number of activity log entries. */
            _n( 'View its %d entry in the Activity Log', 'View all %d entries in the Activity Log', $total, 'shelterkit-donations' ),
            $total
        ) ) . '</a></p>';
    }

    /**
     * Who wrote an entry: a user's name, or System.
     *
     * @param object $row A row of the log table.
     */
    private static function user_label( object $row ): string {
        if ( ! $row->user_id ) {
            return __( 'System', 'shelterkit-donations' );
        }
        $user = get_user_by( 'id', (int) $row->user_id );
        return $user ? $user->display_name : __( 'Unknown', 'shelterkit-donations' );
    }

    /**
     * Download the current view as CSV: same filters, every page.
     */
    public static function handle_export(): void {
        check_admin_referer( 'skdon_export_activity_log' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shelterkit-donations' ), 403 );
        }

        $filters = self::request_filters( $_GET );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=activity-log-' . wp_date( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.
        self::write_csv( $out, $filters );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download.
        exit;
    }

    /**
     * Write the entries matching some filters as CSV, newest first, a chunk
     * at a time. Every text cell goes through fputcsv_safe(), so a donor
     * name or message that starts with "=" is not run as a formula.
     *
     * @param resource $handle  Where to write.
     * @param array    $filters From request_filters().
     */
    public static function write_csv( $handle, array $filters ): void {
        global $wpdb;
        $table              = $wpdb->prefix . self::TABLE_NAME;
        [ $where, $params ] = self::filter_sql( $filters );

        Helpers\fputcsv_safe( $handle, [
            __( 'Date', 'shelterkit-donations' ),
            __( 'Category', 'shelterkit-donations' ),
            __( 'Source', 'shelterkit-donations' ),
            __( 'Activity', 'shelterkit-donations' ),
            __( 'Record', 'shelterkit-donations' ),
            __( 'Order', 'shelterkit-donations' ),
            __( 'User', 'shelterkit-donations' ),
            __( 'Part of', 'shelterkit-donations' ),
            __( 'Event', 'shelterkit-donations' ),
            __( 'Data', 'shelterkit-donations' ),
        ] );

        $offset = 0;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the table name is $wpdb->prefix plus a constant; $where is built from fixed clauses with every value bound.
            $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC LIMIT 500 OFFSET %d", array_merge( $params, [ $offset ] ) ) );
            $runs = self::run_labels( wp_list_pluck( $rows, 'run_id' ) );
            foreach ( $rows as $row ) {
                $order = '';
                foreach ( self::entry_links( $row ) as [ $label ] ) {
                    if ( ! str_starts_with( $label, '#' ) ) {
                        $order = $label;
                    }
                }
                Helpers\fputcsv_safe( $handle, [
                    $row->created_at,
                    $row->event_category,
                    $row->source ? self::source_label( (string) $row->source ) : '',
                    self::render_message( $row ),
                    $row->object_id ? self::type_label( (string) $row->object_type ) . ' #' . $row->object_id : '',
                    $order,
                    self::user_label( $row ),
                    in_array( $row->event_type, self::RUN_TYPES, true ) ? '' : ( $runs[ $row->run_id ] ?? '' ),
                    $row->event_type,
                    (string) $row->meta,
                ] );
            }
            $offset += 500;
            $more    = 500 === count( $rows );
        } while ( $more );
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
     * How long entries of one kind are kept, in days, or null for forever.
     *
     * Settings → General → Data Management sets both (#38). The filters can
     * still override them; a filtered value of 0 or less is ignored, where it
     * used to delete every entry.
     *
     * @param string $kind 'routine' or 'admin'.
     * @return int|null
     */
    public static function retention_days( string $kind ): ?int {
        $admin   = 'admin' === $kind;
        $setting = Settings::get( $admin ? 'log_retention_admin_days' : 'log_retention_days', $admin ? 730 : 90 );
        $days    = 'forever' === $setting ? null : max( 30, (int) $setting );

        /**
         * Filters how many days routine activity log entries are kept.
         *
         * @param int|null $days Days, or null to keep them forever.
         */
        $filtered = $admin
            ? apply_filters( 'starter_shelter_activity_log_admin_retention_days', $days )
            : apply_filters( 'starter_shelter_activity_log_retention_days', $days );

        if ( null === $filtered || 'forever' === $filtered ) {
            return null;
        }
        return is_numeric( $filtered ) && (int) $filtered > 0 ? (int) $filtered : $days;
    }

    /**
     * Delete entries past their retention period, once a day.
     *
     * Admin actions (the admin category, and every entry an admin tool's run
     * wrote) follow the admin period; everything else the routine one. A
     * cleanup that deletes anything says so in the log.
     */
    public static function cleanup_old_logs(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NAME;
        $routine = self::retention_days( 'routine' );
        $admin   = self::retention_days( 'admin' );
        $deleted = 0;

        // An entry belongs to a tool's run when its run's own entry is a tool's.
        $join = "LEFT JOIN $table tool ON tool.run_id = l.run_id AND tool.event_type IN ('tool_started', 'tool_finished')";

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is $wpdb->prefix plus a constant; the cutoff is bound.
        if ( null !== $routine ) {
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE l FROM $table l $join WHERE l.created_at < %s AND l.event_category <> 'admin' AND tool.id IS NULL",
                self::cutoff( $routine )
            ) );
        }
        if ( null !== $admin ) {
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE l FROM $table l $join WHERE l.created_at < %s AND ( l.event_category = 'admin' OR tool.id IS NOT NULL )",
                self::cutoff( $admin )
            ) );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( $deleted > 0 ) {
            self::record( 'log_cleaned', 'system', [
                'deleted'      => $deleted,
                'routine_days' => $routine,
                'admin_days'   => $admin,
            ] );
        }
    }

    /**
     * The moment entries older than some days were written before, in the
     * site's time, which is how created_at is stored.
     *
     * @param int $days Days.
     * @return string Y-m-d H:i:s.
     */
    private static function cutoff( int $days ): string {
        return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
    }

    /**
     * Delete one import's entries, keeping the import's own entry and noting
     * the deletion in the log (#38). Only imports: a tool's entries are the
     * record of what an admin did, and don't go before their time.
     */
    public static function handle_delete_run(): void {
        $run_id = sanitize_text_field( wp_unslash( $_POST['run'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line, against this run.
        check_admin_referer( 'skdon_delete_log_run_' . $run_id );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'shelterkit-donations' ), 403 );
        }

        $result = self::delete_run_entries( $run_id );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), 400 );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'deleted' => $result ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Delete the entries one import wrote, except its own.
     *
     * @param string $run_id The import's run.
     * @return int|\WP_Error How many were deleted.
     */
    public static function delete_run_entries( string $run_id ) {
        global $wpdb;
        $anchor = wp_is_uuid( $run_id, 4 ) ? self::run_anchor( $run_id ) : null;
        if ( ! $anchor ) {
            return new \WP_Error( 'no_run', __( 'That import was not found in the activity log.', 'shelterkit-donations' ) );
        }
        if ( ! str_starts_with( (string) $anchor->event_type, 'import_' ) ) {
            return new \WP_Error( 'not_an_import', __( 'Only an import\'s entries can be deleted. A tool\'s entries are the record of what an admin did.', 'shelterkit-donations' ) );
        }

        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME . ' WHERE run_id = %s AND id <> %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name is $wpdb->prefix plus a constant.
            $run_id,
            (int) $anchor->id
        ) );

        $data                    = json_decode( (string) $anchor->meta, true ) ?: [];
        $data['entries_deleted'] = (int) ( $data['entries_deleted'] ?? 0 ) + $deleted;
        self::update_anchor( (int) $anchor->id, $data, (string) $anchor->event_type );

        // Not part of the run: the deletion is its own admin action.
        self::record_in_run( null, 'log_entries_deleted', 'admin', [ 'run' => $run_id, 'deleted' => $deleted ] );

        return $deleted;
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
            .sd-log-delete-run { display: inline; margin-left: 8px; }
            .sd-log-filters { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
            
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
