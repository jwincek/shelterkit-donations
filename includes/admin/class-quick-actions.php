<?php
/**
 * Admin Quick Actions - Handles admin row actions and bulk operations.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Admin\Import_Export\CSV_Exporter;
use Starter_Shelter\Core\Entity_Hydrator;
use Starter_Shelter\Core\Family_Notification;
use Starter_Shelter\Emails\Email_Factory;
use Starter_Shelter\Helpers;

/**
 * Handles quick actions from CPT list tables.
 *
 * @since 1.0.0
 */
class Quick_Actions {

    /**
     * Initialize quick actions.
     */
    public static function init(): void {
        // Admin post handlers for row actions.
        add_action( 'admin_post_skdon_send_reminder', [ self::class, 'handle_send_reminder' ] );
        add_action( 'admin_post_skdon_extend_membership', [ self::class, 'handle_extend_membership' ] );
        add_action( 'admin_post_skdon_notify_family', [ self::class, 'handle_notify_family' ] );
        add_action( 'admin_post_skdon_send_statement', [ self::class, 'handle_send_statement' ] );
        add_action( 'admin_post_skdon_resend_receipt', [ self::class, 'handle_resend_receipt' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_skdon_view_receipt', [ self::class, 'ajax_view_receipt' ] );

        // Bulk action handlers.
        add_filter( 'bulk_actions-edit-skdon_membership', [ self::class, 'register_membership_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-skdon_membership', [ self::class, 'handle_membership_bulk_actions' ], 10, 3 );

        add_filter( 'bulk_actions-edit-skdon_memorial', [ self::class, 'register_memorial_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-skdon_memorial', [ self::class, 'handle_memorial_bulk_actions' ], 10, 3 );

        add_filter( 'bulk_actions-edit-skdon_donation', [ self::class, 'register_donation_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-skdon_donation', [ self::class, 'handle_donation_bulk_actions' ], 10, 3 );

        // Admin notices for action results.
        add_action( 'admin_notices', [ self::class, 'display_action_notices' ] );

        // Add filters for CPT list tables.
        add_action( 'restrict_manage_posts', [ self::class, 'add_list_filters' ] );
        add_action( 'pre_get_posts', [ self::class, 'apply_list_filters' ] );
    }

    /**
     * Thirty days on from a membership's end date, or from today if it has
     * passed. Counted in the site's time zone: the end date is a local day
     * (#62).
     *
     * @param string $end_date The stored end date, or ''.
     */
    private static function thirty_days_on( string $end_date ): string {
        $today = Helpers\local_date( 'today' );
        $end   = Helpers\local_date( $end_date );
        $base  = $end && $end > $today ? $end : $today;
        return $base->modify( '+30 days' )->format( 'Y-m-d' );
    }

    /**
     * Handle sending renewal reminder.
     */
    public static function handle_send_reminder(): void {
        $membership_id = absint( $_GET['id'] ?? 0 );

        check_admin_referer( 'sd_send_reminder_' . $membership_id );

        if ( ! current_user_can( 'manage_options' ) || ! $membership_id ) {
            wp_die( esc_html__( 'Invalid request.', 'shelterkit-donations' ) );
        }

        $membership = Entity_Hydrator::get( 'skdon_membership', $membership_id );
        if ( ! $membership ) {
            wp_die( esc_html__( 'Membership not found.', 'shelterkit-donations' ) );
        }

        $donor_id = $membership['donor_id'] ?? 0;

        // Send the renewal reminder email directly; this is a manual
        // re-trigger, not the cron-driven `starter_shelter_membership_expiring`
        // broadcast event.
        Email_Factory::trigger_email( 'membership-renewal', [
            'membership_id' => $membership_id,
            'donor_id'      => $donor_id,
        ] );

        // Track that reminder was sent.
        update_post_meta( $membership_id, '_skdon_reminder_sent_date', current_time( 'mysql' ) );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'skdon_membership',
            'sd_action' => 'reminder_sent',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handle extending membership by 30 days.
     */
    public static function handle_extend_membership(): void {
        $membership_id = absint( $_GET['id'] ?? 0 );

        check_admin_referer( 'sd_extend_' . $membership_id );

        if ( ! current_user_can( 'manage_options' ) || ! $membership_id ) {
            wp_die( esc_html__( 'Invalid request.', 'shelterkit-donations' ) );
        }

        $end_date = get_post_meta( $membership_id, '_skdon_end_date', true );

        if ( $end_date ) {
            // Extend from current end date or today, whichever is later.
            $new_end_date = self::thirty_days_on( (string) $end_date );
        } else {
            $new_end_date = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
        }

        update_post_meta( $membership_id, '_skdon_end_date', $new_end_date );

        do_action( 'starter_shelter_membership_extended', $membership_id, $new_end_date );

        // Add note.
        $note = sprintf(
            /* translators: 1: new end date, 2: admin user */
            __( 'Membership extended to %1$s by %2$s', 'shelterkit-donations' ),
            Helpers\format_date( $new_end_date ),
            wp_get_current_user()->display_name
        );
        add_post_meta( $membership_id, '_skdon_admin_notes', [
            'date' => current_time( 'mysql' ),
            'note' => $note,
            'user' => get_current_user_id(),
        ] );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'skdon_membership',
            'sd_action' => 'extended',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Notify Family, on one memorial: email the family now.
     *
     * Until #58 this fired a hook no email listened to and recorded the
     * family as notified anyway. It now sends the email, and the memorial
     * counts as notified only once the email has gone.
     */
    public static function handle_notify_family(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ID names the nonce checked next.
        $memorial_id = isset( $_GET['id'] ) ? max( 0, (int) $_GET['id'] ) : 0;

        check_admin_referer( 'sd_notify_family_' . $memorial_id );

        if ( ! current_user_can( 'manage_options' ) || ! $memorial_id || 'skdon_memorial' !== get_post_type( $memorial_id ) ) {
            wp_die( esc_html__( 'Invalid request.', 'shelterkit-donations' ) );
        }

        $sent = Family_Notification::send( $memorial_id );

        wp_safe_redirect( add_query_arg(
            is_wp_error( $sent )
                ? [ 'post_type' => 'skdon_memorial', 'sd_action' => 'family_not_notified', 'sd_count' => 1, 'skdon_reason' => $sent->get_error_code() ]
                : [ 'post_type' => 'skdon_memorial', 'sd_action' => 'family_notified', 'sd_count' => 1 ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /**
     * Email the families of the memorials given that asked to be told and
     * haven't been yet.
     *
     * @param int[] $memorial_ids Memorial post IDs.
     * @return array{sent: int, failed: int} How many were emailed, and how many couldn't be.
     */
    public static function notify_families( array $memorial_ids ): array {
        $sent   = 0;
        $failed = 0;
        foreach ( $memorial_ids as $memorial_id ) {
            $memorial_id = (int) $memorial_id;
            $notify      = Helpers\get_memorial_notify_family( $memorial_id );
            if ( ! $notify['enabled'] || '' === $notify['email'] || get_post_meta( $memorial_id, '_skdon_family_notified_date', true ) ) {
                continue;
            }
            true === Family_Notification::send( $memorial_id ) ? ++$sent : ++$failed;
        }
        return [ 'sent' => $sent, 'failed' => $failed ];
    }

    /**
     * Handle sending annual statement to donor.
     */
    public static function handle_send_statement(): void {
        $donor_id = absint( $_GET['id'] ?? 0 );

        check_admin_referer( 'sd_send_statement_' . $donor_id );

        if ( ! current_user_can( 'manage_options' ) || ! $donor_id ) {
            wp_die( esc_html__( 'Invalid request.', 'shelterkit-donations' ) );
        }

        $donor = Entity_Hydrator::get( 'skdon_donor', $donor_id );
        if ( ! $donor ) {
            wp_die( esc_html__( 'Donor not found.', 'shelterkit-donations' ) );
        }

        // Compute the annual giving summary via the shelter-reports ability.
        // The email's `donor-annual-summary` declares this shape in its
        // trigger_args; firing with a matching summary lets placeholders
        // resolve cleanly at runtime.
        $year     = (int) wp_date( 'Y' );
        $summary  = [
            'grand_formatted' => Helpers\format_currency( 0 ),
            'donations'       => [ 'count' => 0, 'formatted' => Helpers\format_currency( 0 ) ],
        ];

        $ability = wp_get_ability( 'shelter-reports/annual-summary' );
        if ( $ability ) {
            $result = $ability->execute( [ 'donor_id' => $donor_id, 'year' => $year ] );
            if ( is_array( $result ) ) {
                $summary = $result;
            }
        }

        do_action( 'starter_shelter_annual_summary', $donor_id, $year, $summary );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'skdon_donor',
            'sd_action' => 'statement_sent',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handle resending donation receipt.
     */
    public static function handle_resend_receipt(): void {
        $donation_id = absint( $_GET['id'] ?? 0 );

        check_admin_referer( 'sd_resend_receipt_' . $donation_id );

        if ( ! current_user_can( 'manage_options' ) || ! $donation_id ) {
            wp_die( esc_html__( 'Invalid request.', 'shelterkit-donations' ) );
        }

        $donation = Entity_Hydrator::get( 'skdon_donation', $donation_id );
        if ( ! $donation ) {
            wp_die( esc_html__( 'Donation not found.', 'shelterkit-donations' ) );
        }

        $donor_id = $donation['donor_id'] ?? 0;

        // Re-send the receipt directly via the email factory rather than
        // firing the normal `starter_shelter_donation_created` event (which
        // would mislead other listeners about a new donation).
        Email_Factory::trigger_email( 'donation-receipt', [
            'donation_id' => $donation_id,
            'donor_id'    => $donor_id,
            'input'       => $donation,
        ] );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'skdon_donation',
            'sd_action' => 'receipt_sent',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * AJAX handler for viewing donation receipt.
     */
    public static function ajax_view_receipt(): void {
        $donation_id = absint( $_GET['id'] ?? 0 );

        check_ajax_referer( 'sd_view_receipt_' . $donation_id, '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) || ! $donation_id ) {
            wp_die( esc_html__( 'Invalid request.', 'shelterkit-donations' ) );
        }

        $donation = Entity_Hydrator::get( 'skdon_donation', $donation_id );
        if ( ! $donation ) {
            wp_die( esc_html__( 'Donation not found.', 'shelterkit-donations' ) );
        }

        $donor = $donation['donor_id'] ? Entity_Hydrator::get( 'skdon_donor', $donation['donor_id'] ) : null;

        // Render receipt preview.
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php esc_html_e( 'Donation Receipt', 'shelterkit-donations' ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; }
                .receipt-header { text-align: center; margin-bottom: 30px; }
                .receipt-header h1 { margin: 0 0 10px; font-size: 24px; }
                .receipt-details { background: #f9fafb; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                .receipt-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb; }
                .receipt-row:last-child { border-bottom: none; }
                .receipt-amount { font-size: 32px; font-weight: bold; color: #059669; text-align: center; margin: 20px 0; }
                .receipt-footer { text-align: center; color: #6b7280; font-size: 14px; margin-top: 30px; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <h1><?php echo esc_html( \Starter_Shelter\Helpers\starter_shelter_org_name() ); ?></h1>
                <p><?php esc_html_e( 'Donation Receipt', 'shelterkit-donations' ); ?></p>
            </div>

            <div class="receipt-amount">
                <?php echo esc_html( Helpers\format_currency( $donation['amount'] ?? 0 ) ); ?>
            </div>

            <div class="receipt-details">
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Receipt #', 'shelterkit-donations' ); ?></span>
                    <strong><?php echo esc_html( $donation_id ); ?></strong>
                </div>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Date', 'shelterkit-donations' ); ?></span>
                    <strong><?php echo esc_html( Helpers\format_date( $donation['donation_date'] ?? '' ) ); ?></strong>
                </div>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Donor', 'shelterkit-donations' ); ?></span>
                    <strong>
                        <?php
                        if ( $donation['is_anonymous'] ?? false ) {
                            esc_html_e( 'Anonymous', 'shelterkit-donations' );
                        } else {
                            echo esc_html( $donor['display_name'] ?? $donor['first_name'] . ' ' . $donor['last_name'] ?? 'N/A' );
                        }
                        ?>
                    </strong>
                </div>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Allocation', 'shelterkit-donations' ); ?></span>
                    <strong><?php echo esc_html( Helpers\get_allocation_label( $donation['allocation'] ?? '' ) ); ?></strong>
                </div>
                <?php if ( ! empty( $donation['dedication'] ) ) : ?>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Dedication', 'shelterkit-donations' ); ?></span>
                    <strong><?php echo esc_html( $donation['dedication'] ); ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <div class="receipt-footer">
                <?php
                $ein = \Starter_Shelter\Helpers\starter_shelter_tax_id();
                if ( $ein ) {
                    printf(
                        /* translators: %s: EIN number */
                        esc_html__( 'Tax ID (EIN): %s', 'shelterkit-donations' ),
                        esc_html( $ein )
                    );
                    echo '<br>';
                }
                ?>
                <?php esc_html_e( 'Thank you for your generous support!', 'shelterkit-donations' ); ?>
                <br><br>
                <button onclick="window.print();" style="padding: 10px 20px; cursor: pointer;">
                    <?php esc_html_e( 'Print Receipt', 'shelterkit-donations' ); ?>
                </button>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Bulk-action value for Export Selected. Markup, not storage: it keeps its
     * sd_ name (see the prefix guard test).
     *
     * @var string
     */
    private const EXPORT_SELECTED = 'sd_export_selected';

    /**
     * Offer Export Selected only to users who may export: the list screens
     * are open to anyone who can edit posts, the Export page is not (#31).
     *
     * @param array<string, string> $actions Bulk actions.
     * @return array<string, string>
     */
    private static function add_export_selected( array $actions ): array {
        if ( current_user_can( 'manage_options' ) ) {
            $actions[ self::EXPORT_SELECTED ] = __( 'Export Selected', 'shelterkit-donations' );
        }
        return $actions;
    }

    /**
     * Stream the selected records as the same CSV the Export page produces,
     * limited to those records (#31). Runs before the list screen sends any
     * output, so the download headers can still be set; the exporter exits.
     *
     * @param string $entity_type  import-export.json entity type.
     * @param int[]  $post_ids     Selected records.
     * @param string $redirect_url Where to go when the user may not export.
     * @return string
     */
    private static function export_selected( string $entity_type, array $post_ids, string $redirect_url ): string {
        if ( ! current_user_can( 'manage_options' ) || ! $post_ids ) {
            return $redirect_url;
        }
        CSV_Exporter::export( $entity_type, [ 'ids' => $post_ids ] );
        return $redirect_url; // Not reached: export() exits.
    }

    /**
     * Register bulk actions for memberships.
     */
    public static function register_membership_bulk_actions( array $actions ): array {
        $actions['sd_send_reminders'] = __( 'Send Renewal Reminders', 'shelterkit-donations' );
        $actions['sd_extend_30_days'] = __( 'Extend 30 Days', 'shelterkit-donations' );
        return self::add_export_selected( $actions );
    }

    /**
     * Handle bulk actions for memberships.
     */
    public static function handle_membership_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
        if ( self::EXPORT_SELECTED === $action ) {
            return self::export_selected( 'memberships', $post_ids, $redirect_url );
        }
        if ( ! in_array( $action, [ 'sd_send_reminders', 'sd_extend_30_days' ], true ) ) {
            return $redirect_url;
        }

        $count = 0;

        switch ( $action ) {
            case 'sd_send_reminders':
                foreach ( $post_ids as $membership_id ) {
                    $membership = Entity_Hydrator::get( 'skdon_membership', $membership_id );
                    if ( $membership ) {
                        Email_Factory::trigger_email( 'membership-renewal', [
                            'membership_id' => $membership_id,
                            'donor_id'      => $membership['donor_id'] ?? 0,
                        ] );
                        update_post_meta( $membership_id, '_skdon_reminder_sent_date', current_time( 'mysql' ) );
                        ++$count;
                    }
                }
                return add_query_arg( [ 'sd_action' => 'reminders_sent', 'sd_count' => $count ], $redirect_url );

            case 'sd_extend_30_days':
                foreach ( $post_ids as $membership_id ) {
                    $end_date = get_post_meta( $membership_id, '_skdon_end_date', true );
                    $new_end_date = self::thirty_days_on( (string) $end_date );
                    update_post_meta( $membership_id, '_skdon_end_date', $new_end_date );
                    do_action( 'starter_shelter_membership_extended', $membership_id, $new_end_date );
                    ++$count;
                }
                return add_query_arg( [ 'sd_action' => 'memberships_extended', 'sd_count' => $count ], $redirect_url );
        }

        return $redirect_url;
    }

    /**
     * Register bulk actions for memorials.
     */
    public static function register_memorial_bulk_actions( array $actions ): array {
        $actions['sd_notify_families'] = __( 'Send Family Notifications', 'shelterkit-donations' );
        return self::add_export_selected( $actions );
    }

    /**
     * Handle bulk actions for memorials.
     */
    public static function handle_memorial_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
        if ( self::EXPORT_SELECTED === $action ) {
            return self::export_selected( 'memorials', $post_ids, $redirect_url );
        }
        if ( 'sd_notify_families' !== $action ) {
            return $redirect_url;
        }

        $result = self::notify_families( $post_ids );

        return add_query_arg( [ 'sd_action' => 'families_notified', 'sd_count' => $result['sent'], 'skdon_failed' => $result['failed'] ], $redirect_url );
    }

    /**
     * Register bulk actions for donations.
     */
    public static function register_donation_bulk_actions( array $actions ): array {
        $actions['sd_resend_receipts'] = __( 'Resend Receipts', 'shelterkit-donations' );
        return self::add_export_selected( $actions );
    }

    /**
     * Handle bulk actions for donations.
     */
    public static function handle_donation_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
        if ( self::EXPORT_SELECTED === $action ) {
            return self::export_selected( 'donations', $post_ids, $redirect_url );
        }
        if ( 'sd_resend_receipts' !== $action ) {
            return $redirect_url;
        }

        $count = 0;

        foreach ( $post_ids as $donation_id ) {
            $donation = Entity_Hydrator::get( 'skdon_donation', $donation_id );
            if ( $donation ) {
                Email_Factory::trigger_email( 'donation-receipt', [
                    'donation_id' => $donation_id,
                    'donor_id'    => $donation['donor_id'] ?? 0,
                    'input'       => $donation,
                ] );
                ++$count;
            }
        }

        return add_query_arg( [ 'sd_action' => 'receipts_sent', 'sd_count' => $count ], $redirect_url );
    }

    /**
     * Display admin notices for completed actions.
     */
    public static function display_action_notices(): void {
        $action = sanitize_key( $_GET['sd_action'] ?? '' );
        $count = absint( $_GET['sd_count'] ?? 0 );

        // Notify Family, when the email couldn't be sent (#58).
        $failed = 'family_not_notified' === $action ? 1 : absint( $_GET['skdon_failed'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count shown in a notice, after a redirect from a checked action.
        if ( $failed && in_array( $action, [ 'family_not_notified', 'families_notified' ], true ) ) {
            $reasons = [
                'no_family_email' => __( 'The memorial has no family email address.', 'shelterkit-donations' ),
                'email_disabled'  => __( 'The Memorial Family Notification email is turned off under WooCommerce → Settings → Emails.', 'shelterkit-donations' ),
            ];
            $reason  = $reasons[ sanitize_key( $_GET['skdon_reason'] ?? '' ) ] ?? __( 'Check that the Memorial Family Notification email is turned on under WooCommerce → Settings → Emails, and that the site can send email.', 'shelterkit-donations' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks a message to show, after a redirect from a checked action.
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
                esc_html( sprintf(
                    /* translators: %d: number of families. */
                    _n( 'The family email couldn\'t be sent for %d memorial.', 'The family email couldn\'t be sent for %d memorials.', $failed, 'shelterkit-donations' ),
                    $failed
                ) ),
                esc_html( $reason )
            );
            if ( 'family_not_notified' === $action ) {
                return;
            }
        }

        if ( ! $action || ! $count ) {
            return;
        }

        $messages = [
            /* translators: %d: number of renewal reminders sent. */
            'reminder_sent'       => _n( '%d renewal reminder sent.', '%d renewal reminders sent.', $count, 'shelterkit-donations' ),
            /* translators: %d: number of renewal reminders sent. */
            'reminders_sent'      => _n( '%d renewal reminder sent.', '%d renewal reminders sent.', $count, 'shelterkit-donations' ),
            /* translators: %d: number of memberships extended. */
            'extended'            => _n( '%d membership extended by 30 days.', '%d memberships extended by 30 days.', $count, 'shelterkit-donations' ),
            /* translators: %d: number of memberships extended. */
            'memberships_extended' => _n( '%d membership extended.', '%d memberships extended.', $count, 'shelterkit-donations' ),
            'family_notified'     => __( 'Family notification sent.', 'shelterkit-donations' ),
            /* translators: %d: number of family notifications sent. */
            'families_notified'   => _n( '%d family notification sent.', '%d family notifications sent.', $count, 'shelterkit-donations' ),
            'statement_sent'      => __( 'Annual statement sent.', 'shelterkit-donations' ),
            'receipt_sent'        => __( 'Donation receipt sent.', 'shelterkit-donations' ),
            /* translators: %d: number of receipts sent. */
            'receipts_sent'       => _n( '%d receipt sent.', '%d receipts sent.', $count, 'shelterkit-donations' ),
        ];

        if ( isset( $messages[ $action ] ) ) {
            $message = sprintf( $messages[ $action ], $count );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }

    /**
     * Add filter dropdowns to CPT list tables.
     */
    public static function add_list_filters( string $post_type ): void {
        switch ( $post_type ) {
            case 'skdon_membership':
                self::render_membership_filters();
                break;
            case 'skdon_memorial':
                self::render_memorial_filters();
                break;
            case 'skdon_donation':
                self::render_donation_filters();
                break;
            case 'skdon_donor':
                self::render_donor_filters();
                break;
        }
    }

    /**
     * Render membership list filters.
     */
    private static function render_membership_filters(): void {
        $status = sanitize_key( $_GET['membership_status'] ?? '' );
        $tier = sanitize_key( $_GET['membership_tier'] ?? '' );
        $type = sanitize_key( $_GET['membership_type'] ?? '' );

        ?>
        <select name="membership_status">
            <option value=""><?php esc_html_e( 'All Statuses', 'shelterkit-donations' ); ?></option>
            <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'shelterkit-donations' ); ?></option>
            <option value="expiring" <?php selected( $status, 'expiring' ); ?>><?php esc_html_e( 'Expiring Soon (30 days)', 'shelterkit-donations' ); ?></option>
            <option value="expired" <?php selected( $status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'shelterkit-donations' ); ?></option>
        </select>

        <select name="membership_type">
            <option value=""><?php esc_html_e( 'All Types', 'shelterkit-donations' ); ?></option>
            <option value="individual" <?php selected( $type, 'individual' ); ?>><?php esc_html_e( 'Individual', 'shelterkit-donations' ); ?></option>
            <option value="family" <?php selected( $type, 'family' ); ?>><?php esc_html_e( 'Family', 'shelterkit-donations' ); ?></option>
            <option value="business" <?php selected( $type, 'business' ); ?>><?php esc_html_e( 'Business', 'shelterkit-donations' ); ?></option>
        </select>
        <?php
    }

    /**
     * Render memorial list filters.
     */
    private static function render_memorial_filters(): void {
        $type = sanitize_key( $_GET['memorial_type'] ?? '' );
        $notify = sanitize_key( $_GET['notify_status'] ?? '' );

        ?>
        <select name="memorial_type">
            <option value=""><?php esc_html_e( 'All Types', 'shelterkit-donations' ); ?></option>
            <option value="human" <?php selected( $type, 'human' ); ?>><?php esc_html_e( 'Person', 'shelterkit-donations' ); ?></option>
            <option value="pet" <?php selected( $type, 'pet' ); ?>><?php esc_html_e( 'Pet', 'shelterkit-donations' ); ?></option>
            <option value="honor" <?php selected( $type, 'honor' ); ?>><?php esc_html_e( 'In Honor Of', 'shelterkit-donations' ); ?></option>
        </select>

        <select name="notify_status">
            <option value=""><?php esc_html_e( 'All Notifications', 'shelterkit-donations' ); ?></option>
            <option value="pending" <?php selected( $notify, 'pending' ); ?>><?php esc_html_e( 'Pending', 'shelterkit-donations' ); ?></option>
            <option value="sent" <?php selected( $notify, 'sent' ); ?>><?php esc_html_e( 'Sent', 'shelterkit-donations' ); ?></option>
            <option value="not_requested" <?php selected( $notify, 'not_requested' ); ?>><?php esc_html_e( 'Not Requested', 'shelterkit-donations' ); ?></option>
        </select>
        <?php
    }

    /**
     * Render donation list filters.
     */
    private static function render_donation_filters(): void {
        $allocation = sanitize_key( $_GET['allocation'] ?? '' );
        // Retired funds stay filterable: their past gifts are still listed.
        $allocations = \Starter_Shelter\Core\Allocations::known();

        ?>
        <select name="allocation">
            <option value=""><?php esc_html_e( 'All Allocations', 'shelterkit-donations' ); ?></option>
            <?php foreach ( $allocations as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $allocation, $value ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render donor list filters.
     */
    private static function render_donor_filters(): void {
        $level = sanitize_key( $_GET['donor_level'] ?? '' );
        $has_membership = sanitize_key( $_GET['has_membership'] ?? '' );

        ?>
        <select name="donor_level">
            <option value=""><?php esc_html_e( 'All Levels', 'shelterkit-donations' ); ?></option>
            <option value="new" <?php selected( $level, 'new' ); ?>><?php esc_html_e( 'New', 'shelterkit-donations' ); ?></option>
            <option value="bronze" <?php selected( $level, 'bronze' ); ?>><?php esc_html_e( 'Bronze', 'shelterkit-donations' ); ?></option>
            <option value="silver" <?php selected( $level, 'silver' ); ?>><?php esc_html_e( 'Silver', 'shelterkit-donations' ); ?></option>
            <option value="gold" <?php selected( $level, 'gold' ); ?>><?php esc_html_e( 'Gold', 'shelterkit-donations' ); ?></option>
            <option value="platinum" <?php selected( $level, 'platinum' ); ?>><?php esc_html_e( 'Platinum', 'shelterkit-donations' ); ?></option>
        </select>

        <select name="has_membership">
            <option value=""><?php esc_html_e( 'All Donors', 'shelterkit-donations' ); ?></option>
            <option value="yes" <?php selected( $has_membership, 'yes' ); ?>><?php esc_html_e( 'With Membership', 'shelterkit-donations' ); ?></option>
            <option value="no" <?php selected( $has_membership, 'no' ); ?>><?php esc_html_e( 'Without Membership', 'shelterkit-donations' ); ?></option>
        </select>
        <?php
    }

    /**
     * Apply list filters to query.
     */
    public static function apply_list_filters( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        switch ( $post_type ) {
            case 'skdon_membership':
                self::apply_membership_filters( $query );
                break;
            case 'skdon_memorial':
                self::apply_memorial_filters( $query );
                break;
            case 'skdon_donation':
                self::apply_donation_filters( $query );
                break;
            case 'skdon_donor':
                self::apply_donor_filters( $query );
                break;
        }
    }

    /**
     * Apply membership filters.
     */
    private static function apply_membership_filters( \WP_Query $query ): void {
        $meta_query = $query->get( 'meta_query' ) ?: [];

        // Status filter.
        $status = sanitize_key( $_GET['membership_status'] ?? '' );
        if ( $status ) {
            $today = wp_date( 'Y-m-d' );
            $in_30_days = wp_date( 'Y-m-d', strtotime( '+30 days' ) );

            switch ( $status ) {
                case 'active':
                    $meta_query[] = [ 'key' => '_skdon_end_date', 'value' => $today, 'compare' => '>=' ];
                    break;
                case 'expiring':
                    $meta_query[] = [ 'key' => '_skdon_end_date', 'value' => [ $today, $in_30_days ], 'compare' => 'BETWEEN' ];
                    break;
                case 'expired':
                    $meta_query[] = [ 'key' => '_skdon_end_date', 'value' => $today, 'compare' => '<' ];
                    break;
            }
        }

        // Type filter.
        $type = sanitize_key( $_GET['membership_type'] ?? '' );
        if ( $type ) {
            $meta_query[] = [ 'key' => '_skdon_membership_type', 'value' => $type ];
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Apply memorial filters.
     */
    private static function apply_memorial_filters( \WP_Query $query ): void {
        $meta_query = $query->get( 'meta_query' ) ?: [];

        // Type filter.
        $type = sanitize_key( $_GET['memorial_type'] ?? '' );
        if ( $type ) {
            $meta_query[] = [ 'key' => '_skdon_memorial_type', 'value' => $type ];
        }

        // Notification status filter.
        $notify = sanitize_key( $_GET['notify_status'] ?? '' );
        if ( $notify ) {
            switch ( $notify ) {
                case 'pending':
                    $meta_query[] = [ 'key' => '_skdon_notify_family_enabled', 'value' => '1' ];
                    $meta_query[] = [
                        'relation' => 'OR',
                        [ 'key' => '_skdon_family_notified_date', 'compare' => 'NOT EXISTS' ],
                        [ 'key' => '_skdon_family_notified_date', 'value' => '' ],
                    ];
                    break;
                case 'sent':
                    $meta_query[] = [ 'key' => '_skdon_family_notified_date', 'compare' => 'EXISTS' ];
                    $meta_query[] = [ 'key' => '_skdon_family_notified_date', 'value' => '', 'compare' => '!=' ];
                    break;
                case 'not_requested':
                    $meta_query[] = [
                        'relation' => 'OR',
                        [ 'key' => '_skdon_notify_family_enabled', 'compare' => 'NOT EXISTS' ],
                        [ 'key' => '_skdon_notify_family_enabled', 'value' => '0' ],
                    ];
                    break;
            }
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Apply donation filters.
     */
    private static function apply_donation_filters( \WP_Query $query ): void {
        $allocation = sanitize_key( $_GET['allocation'] ?? '' );

        if ( $allocation ) {
            $meta_query = $query->get( 'meta_query' ) ?: [];
            $meta_query[] = [ 'key' => '_skdon_allocation', 'value' => $allocation ];
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Apply donor filters.
     */
    private static function apply_donor_filters( \WP_Query $query ): void {
        $meta_query = $query->get( 'meta_query' ) ?: [];

        // Level filter.
        $level = sanitize_key( $_GET['donor_level'] ?? '' );
        if ( $level ) {
            $meta_query[] = [ 'key' => '_skdon_donor_level', 'value' => $level ];
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }
}
