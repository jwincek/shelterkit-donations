<?php
/**
 * My Account Integration - Custom endpoints for donor dashboard.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Manages WooCommerce My Account integration for donors.
 *
 * @since 1.0.0
 */
class My_Account {

    /**
     * Custom endpoint slugs.
     *
     * @since 1.0.0
     * @var array
     */
    private const ENDPOINTS = [
        'donor-dashboard'  => 'donor-dashboard',
        'giving-history'   => 'giving-history',
        'my-memberships'   => 'my-memberships',
        'my-memorials'     => 'my-memorials',
        'annual-statement' => 'annual-statement',
    ];

    /**
     * Initialize My Account integration.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'init', [ self::class, 'register_endpoints' ] );
        add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_items' ] );
        add_filter( 'woocommerce_account_menu_item_classes', [ self::class, 'highlight_my_giving' ], 10, 2 );

        foreach ( self::ENDPOINTS as $endpoint => $slug ) {
            add_action( "woocommerce_account_{$slug}_endpoint", [ self::class, 'render_' . str_replace( '-', '_', $endpoint ) ] );
        }

        add_filter( 'the_title', [ self::class, 'endpoint_titles' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
        add_action( 'template_redirect', [ self::class, 'handle_account_actions' ] );
        add_action( 'template_redirect', [ self::class, 'maybe_render_print_statement' ] );
    }

    /**
     * Render a standalone, print-optimized contribution statement when the
     * annual-statement endpoint is requested with ?print=1, then stop.
     *
     * This bypasses the theme/account chrome so a member can "Save as PDF"
     * from their browser — a dependency-free receipt. The donor is resolved
     * from the current session, so a member only ever prints their own.
     *
     * @since 2.2.0
     */
    public static function maybe_render_print_statement(): void {
        if ( empty( $_GET['print'] ) || ! is_user_logged_in() || ! is_account_page() ) {
            return;
        }

        global $wp_query;
        if ( ! isset( $wp_query->query_vars['annual-statement'] ) ) {
            return;
        }

        $donor_id = self::get_current_donor_id();
        if ( ! $donor_id ) {
            return;
        }

        $year    = isset( $_GET['year'] ) ? absint( wp_unslash( $_GET['year'] ) ) : self::default_statement_year( $donor_id );
        $summary = self::execute_ability(
            'shelter-reports/annual-summary',
            [ 'donor_id' => $donor_id, 'year' => $year ],
            static fn ( $i ) => \Starter_Shelter\Abilities\Reports\annual_summary( $i )
        );

        if ( ! is_array( $summary ) ) {
            return; // Error/unavailable — fall through to the normal page.
        }

        // Fully assembled, individually-escaped document.
        echo self::build_print_statement_html( $summary, $year ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Build the standalone print-statement HTML document.
     *
     * Separated from the handler (no echo/exit) so it's directly testable.
     * Organization details come from the WooCommerce store settings; the
     * tax/deductibility note is filterable so a site can add its EIN or
     * 501(c)(3) language without overriding the whole document.
     *
     * @since 2.2.0
     *
     * @param array $summary shelter-reports/annual-summary output.
     * @param int   $year    Tax year.
     * @return string Full HTML document.
     */
    public static function build_print_statement_html( array $summary, int $year ): string {
        $org_name = get_bloginfo( 'name' );
        $org_address = implode( ', ', array_filter( [
            (string) get_option( 'woocommerce_store_address' ),
            (string) get_option( 'woocommerce_store_address_2' ),
            trim( (string) get_option( 'woocommerce_store_city' ) . ' ' . (string) get_option( 'woocommerce_store_postcode' ) ),
        ] ) );

        $tax_note = (string) apply_filters(
            'starter_shelter_receipt_tax_note',
            __( 'No goods or services were provided in exchange for these contributions. Please retain this statement for your tax records.', 'starter-shelter' ),
            $summary,
            $year
        );

        // Build itemized rows across all three contribution types.
        $rows = [];
        foreach ( $summary['donations']['items'] ?? [] as $d ) {
            $rows[] = [
                'date'  => (string) ( $d['donation_date'] ?? '' ),
                'desc'  => Helpers\get_allocation_label( (string) ( $d['allocation'] ?? 'general-fund' ) ),
                'amount' => (string) ( $d['amount_formatted'] ?? Helpers\format_currency( (float) ( $d['amount'] ?? 0 ) ) ),
            ];
        }
        foreach ( $summary['memorials']['items'] ?? [] as $m ) {
            $rows[] = [
                'date'  => (string) ( $m['donation_date'] ?? '' ),
                'desc'  => sprintf( /* translators: %s: honoree name */ __( 'Memorial tribute — %s', 'starter-shelter' ), (string) ( $m['honoree_name'] ?? '' ) ),
                'amount' => (string) ( $m['amount_formatted'] ?? Helpers\format_currency( (float) ( $m['amount'] ?? 0 ) ) ),
            ];
        }
        foreach ( $summary['memberships']['items'] ?? [] as $m ) {
            $rows[] = [
                'date'  => (string) ( $m['start_date'] ?? '' ),
                'desc'  => sprintf( /* translators: %s: tier */ __( 'Membership — %s', 'starter-shelter' ), (string) ( $m['tier_label'] ?? $m['tier'] ?? '' ) ),
                'amount' => (string) ( $m['amount_formatted'] ?? Helpers\format_currency( (float) ( $m['amount'] ?? 0 ) ) ),
            ];
        }
        usort( $rows, static fn ( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

        ob_start();
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( sprintf( __( '%1$s Contribution Statement %2$d', 'starter-shelter' ), $org_name, $year ) ); ?></title>
    <style>
        body { font-family: Georgia, "Times New Roman", serif; color: #222; max-width: 720px; margin: 32px auto; padding: 0 24px; line-height: 1.5; }
        .receipt-org { font-size: 1.4rem; font-weight: bold; margin: 0; }
        .receipt-org-address { color: #555; margin: 2px 0 24px; }
        h1 { font-size: 1.2rem; border-bottom: 2px solid #222; padding-bottom: 6px; }
        .receipt-meta { margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; }
        td.amount, th.amount { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 2px solid #222; border-bottom: none; }
        .tax-note { margin-top: 24px; font-size: 0.9rem; color: #333; }
        .generated { color: #777; font-size: 0.8rem; margin-top: 24px; }
        .no-print { margin-top: 24px; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body onload="window.print()">
    <p class="receipt-org"><?php echo esc_html( $org_name ); ?></p>
    <?php if ( '' !== $org_address ) : ?>
    <p class="receipt-org-address"><?php echo esc_html( $org_address ); ?></p>
    <?php endif; ?>

    <h1><?php echo esc_html( sprintf( __( 'Charitable Contribution Statement — %d', 'starter-shelter' ), $year ) ); ?></h1>

    <div class="receipt-meta">
        <strong><?php echo esc_html( (string) ( $summary['donor']['name'] ?? '' ) ); ?></strong><br>
        <?php if ( ! empty( $summary['donor']['address'] ) ) : ?>
        <?php echo esc_html( (string) $summary['donor']['address'] ); ?><br>
        <?php endif; ?>
        <?php if ( ! empty( $summary['donor']['email'] ) ) : ?>
        <?php echo esc_html( (string) $summary['donor']['email'] ); ?>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php esc_html_e( 'Date', 'starter-shelter' ); ?></th>
                <th><?php esc_html_e( 'Description', 'starter-shelter' ); ?></th>
                <th class="amount"><?php esc_html_e( 'Amount', 'starter-shelter' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="3"><?php esc_html_e( 'No contributions recorded for this year.', 'starter-shelter' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( Helpers\format_date( $row['date'] ) ); ?></td>
                    <td><?php echo esc_html( $row['desc'] ); ?></td>
                    <td class="amount"><?php echo esc_html( $row['amount'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2"><?php esc_html_e( 'Total contributions', 'starter-shelter' ); ?></td>
                <td class="amount"><?php echo esc_html( (string) ( $summary['grand_formatted'] ?? '' ) ); ?></td>
            </tr>
        </tfoot>
    </table>

    <p class="tax-note"><?php echo esc_html( $tax_note ); ?></p>
    <p class="generated"><?php echo esc_html( sprintf( __( 'Issued %s', 'starter-shelter' ), Helpers\format_date( (string) ( $summary['generated_date'] ?? wp_date( 'Y-m-d' ) ) ) ) ); ?></p>

    <p class="no-print"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'starter-shelter' ); ?></button></p>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Process self-service POST actions from the account pages (PRG).
     *
     * Currently handles the public-recognition preference. Verifies the
     * nonce, resolves the current user's own donor (so a user can only
     * change their own record), applies the change, sets a notice, and
     * redirects to avoid resubmission.
     *
     * @since 2.2.0
     */
    public static function handle_account_actions(): void {
        if ( empty( $_POST['sd_account_action'] ) || ! is_user_logged_in() ) {
            return;
        }

        $action   = sanitize_key( wp_unslash( $_POST['sd_account_action'] ) );
        $donor_id = self::get_current_donor_id();
        if ( ! $donor_id ) {
            return;
        }

        switch ( $action ) {
            case 'recognition':
                self::handle_recognition( $donor_id );
                break;
            case 'cancel_membership':
                self::handle_cancel_membership( $donor_id );
                break;
            case 'toggle_auto_renew':
                self::handle_toggle_auto_renew( $donor_id );
                break;
            case 'update_profile':
                self::handle_update_profile( $donor_id );
                break;
        }
    }

    /**
     * Update the current user's own donor profile (name, phone) and mailing
     * address via the owner-scoped donor abilities, then redirect (PRG).
     *
     * @param int $donor_id Current user's donor ID.
     */
    private static function handle_update_profile( int $donor_id ): void {
        if ( ! self::verify_action_nonce( 'sd_profile_nonce', 'sd_profile_action' ) ) {
            return;
        }

        $field = static fn ( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

        $profile = self::execute_ability(
            'shelter-donors/update-profile',
            [
                'donor_id'   => $donor_id,
                'first_name' => $field( 'first_name' ),
                'last_name'  => $field( 'last_name' ),
                'phone'      => $field( 'phone' ),
            ],
            static fn ( $i ) => \Starter_Shelter\Abilities\Donors\update_profile( $i )
        );

        $address = self::execute_ability(
            'shelter-donors/update-address',
            [
                'donor_id' => $donor_id,
                'source'   => 'myaccount',
                'address'  => [
                    'address_1' => $field( 'address_1' ),
                    'address_2' => $field( 'address_2' ),
                    'city'      => $field( 'city' ),
                    'state'     => $field( 'state' ),
                    'postcode'  => $field( 'postcode' ),
                    'country'   => $field( 'country' ) ?: 'US',
                ],
            ],
            static fn ( $i ) => \Starter_Shelter\Abilities\Donors\update_address( $i )
        );

        if ( is_wp_error( $profile ) || is_wp_error( $address ) ) {
            $error = is_wp_error( $profile ) ? $profile : $address;
            wc_add_notice( $error->get_error_message(), 'error' );
        } else {
            wc_add_notice( __( 'Your contact details have been updated.', 'starter-shelter' ) );
        }

        wp_safe_redirect( wc_get_account_endpoint_url( 'donor-dashboard' ) );
        exit;
    }

    /**
     * Run an ability by name, falling back to a direct callback when the
     * Abilities API isn't available. Returns the result or WP_Error.
     *
     * @param string   $name     Ability name.
     * @param array    $input    Ability input.
     * @param callable $fallback Receives $input, returns array|WP_Error.
     * @return mixed
     */
    private static function execute_ability( string $name, array $input, callable $fallback ) {
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
        return $ability ? $ability->execute( $input ) : $fallback( $input );
    }

    /**
     * Save the public-recognition preference, then redirect (PRG).
     *
     * @param int $donor_id Current user's donor ID.
     */
    private static function handle_recognition( int $donor_id ): void {
        if ( ! self::verify_action_nonce( 'sd_recognition_nonce', 'sd_recognition' ) ) {
            return;
        }

        $list_publicly = ! empty( $_POST['sd_list_publicly'] );
        self::set_recognition_preference( $donor_id, $list_publicly );

        wc_add_notice(
            $list_publicly
                ? __( 'Saved. Your name will be listed on the public members wall.', 'starter-shelter' )
                : __( 'Saved. Your name will be hidden from the public members wall.', 'starter-shelter' )
        );

        self::redirect_to_memberships();
    }

    /**
     * Cancel one of the current user's own memberships via the ability.
     *
     * @param int $donor_id Current user's donor ID.
     */
    private static function handle_cancel_membership( int $donor_id ): void {
        if ( ! self::verify_action_nonce( 'sd_membership_nonce', 'sd_membership_action' ) ) {
            return;
        }

        $membership_id = isset( $_POST['membership_id'] ) ? absint( wp_unslash( $_POST['membership_id'] ) ) : 0;

        if ( ! self::current_user_owns_membership( $membership_id, $donor_id ) ) {
            wc_add_notice( __( 'That membership could not be found on your account.', 'starter-shelter' ), 'error' );
            self::redirect_to_memberships();
            return;
        }

        $input  = [ 'membership_id' => $membership_id, 'reason' => __( 'Cancelled by member from My Account.', 'starter-shelter' ) ];
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'shelter-memberships/cancel' ) : null;
        $result  = $ability ? $ability->execute( $input ) : \Starter_Shelter\Abilities\Memberships\cancel( $input );

        if ( is_wp_error( $result ) ) {
            wc_add_notice( $result->get_error_message(), 'error' );
        } else {
            wc_add_notice( __( 'Your membership has been cancelled.', 'starter-shelter' ) );
        }

        self::redirect_to_memberships();
    }

    /**
     * Toggle auto-renew on one of the current user's own memberships.
     *
     * @param int $donor_id Current user's donor ID.
     */
    private static function handle_toggle_auto_renew( int $donor_id ): void {
        if ( ! self::verify_action_nonce( 'sd_membership_nonce', 'sd_membership_action' ) ) {
            return;
        }

        $membership_id = isset( $_POST['membership_id'] ) ? absint( wp_unslash( $_POST['membership_id'] ) ) : 0;

        if ( ! self::current_user_owns_membership( $membership_id, $donor_id ) ) {
            wc_add_notice( __( 'That membership could not be found on your account.', 'starter-shelter' ), 'error' );
            self::redirect_to_memberships();
            return;
        }

        $on = ! empty( $_POST['auto_renew'] );
        update_post_meta( $membership_id, '_sd_auto_renew', $on );

        wc_add_notice(
            $on
                ? __( 'Automatic renewal is now on for this membership.', 'starter-shelter' )
                : __( 'Automatic renewal is now off for this membership.', 'starter-shelter' )
        );

        self::redirect_to_memberships();
    }

    /**
     * Whether a membership belongs to the given donor (ownership guard for
     * the self-service handlers). WooCommerce-free for direct testing.
     *
     * @param int $membership_id Membership post ID.
     * @param int $donor_id      Donor post ID (the current user's).
     * @return bool
     */
    public static function current_user_owns_membership( int $membership_id, int $donor_id ): bool {
        if ( ! $membership_id || ! $donor_id ) {
            return false;
        }
        return (int) get_post_meta( $membership_id, '_sd_donor_id', true ) === $donor_id;
    }

    /**
     * Verify a POST nonce, adding an error notice on failure.
     */
    private static function verify_action_nonce( string $field, string $action ): bool {
        $nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wc_add_notice( __( 'Security check failed. Please try again.', 'starter-shelter' ), 'error' );
            return false;
        }
        return true;
    }

    /**
     * Redirect back to My Memberships (post/redirect/get) and stop.
     */
    private static function redirect_to_memberships(): void {
        wp_safe_redirect( wc_get_account_endpoint_url( 'my-memberships' ) );
        exit;
    }

    /**
     * Apply a donor's public-recognition preference to their memberships.
     *
     * Opt-out model: unchecking public listing sets `is_anonymous` on every
     * one of the donor's memberships, which the recognition-wall ability
     * honors. WooCommerce-free so it can be unit-exercised directly.
     *
     * @since 2.2.0
     *
     * @param int  $donor_id      The donor post ID.
     * @param bool $list_publicly Whether to list the member publicly.
     * @return int Number of memberships updated.
     */
    public static function set_recognition_preference( int $donor_id, bool $list_publicly ): int {
        if ( ! $donor_id ) {
            return 0;
        }

        $anonymous   = ! $list_publicly;
        $memberships = Query::for( 'sd_membership' )
            ->where( 'donor_id', $donor_id )
            ->get();

        $count = 0;
        foreach ( $memberships as $membership ) {
            $membership_id = (int) ( $membership['id'] ?? 0 );
            if ( $membership_id ) {
                update_post_meta( $membership_id, '_sd_is_anonymous', $anonymous );
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Whether the donor is currently listed publicly (no active membership
     * has opted out). Drives the checkbox's initial state.
     *
     * @since 2.2.0
     *
     * @param int $donor_id The donor post ID.
     * @return bool
     */
    public static function is_listed_publicly( int $donor_id ): bool {
        if ( ! $donor_id ) {
            return false;
        }

        $memberships = Query::for( 'sd_membership' )
            ->where( 'donor_id', $donor_id )
            ->whereCompare( 'end_date', wp_date( 'Y-m-d' ), '>=', 'DATE' )
            ->get();

        foreach ( $memberships as $membership ) {
            if ( ! empty( $membership['is_anonymous'] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register custom endpoints.
     *
     * @since 1.0.0
     */
    public static function register_endpoints(): void {
        foreach ( self::ENDPOINTS as $slug ) {
            add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES );
        }
    }

    /**
     * Add custom query vars.
     *
     * @since 1.0.0
     *
     * @param array $vars Query vars.
     * @return array Modified query vars.
     */
    public static function add_query_vars( array $vars ): array {
        foreach ( self::ENDPOINTS as $slug ) {
            $vars[] = $slug;
        }
        return $vars;
    }

    /**
     * Add menu items to My Account navigation.
     *
     * @since 1.0.0
     *
     * @param array $items Menu items.
     * @return array Modified menu items.
     */
    public static function add_menu_items( array $items ): array {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            return $items;
        }

        // Consolidate the donor area under a single top-level item; the
        // sub-sections (history, memberships, memorials, statement) are
        // reached via the in-page sub-nav (render_account_nav). Keeps the
        // WooCommerce account menu uncluttered.
        $new_items = [];

        foreach ( $items as $key => $label ) {
            if ( 'customer-logout' === $key ) {
                $new_items['donor-dashboard'] = __( 'My Giving', 'starter-shelter' );
            }
            $new_items[ $key ] = $label;
        }

        return $new_items;
    }

    /**
     * Set endpoint titles.
     *
     * @since 1.0.0
     *
     * @param string $title The page title.
     * @param int    $id    The post ID.
     * @return string Modified title.
     */
    public static function endpoint_titles( string $title, int $id ): string {
        global $wp_query;

        if ( ! is_main_query() || ! in_the_loop() || ! is_account_page() ) {
            return $title;
        }

        $endpoint_titles = [
            'donor-dashboard'  => __( 'Donor Dashboard', 'starter-shelter' ),
            'giving-history'   => __( 'Giving History', 'starter-shelter' ),
            'my-memberships'   => __( 'My Memberships', 'starter-shelter' ),
            'my-memorials'     => __( 'My Memorials', 'starter-shelter' ),
            'annual-statement' => __( 'Annual Statement', 'starter-shelter' ),
        ];

        foreach ( $endpoint_titles as $endpoint => $endpoint_title ) {
            if ( isset( $wp_query->query_vars[ $endpoint ] ) ) {
                return $endpoint_title;
            }
        }

        return $title;
    }

    /**
     * Get current user's donor ID.
     *
     * @since 1.0.0
     *
     * @return int|null Donor ID or null.
     */
    public static function get_current_donor_id(): ?int {
        $user_id = get_current_user_id();
        
        if ( ! $user_id ) {
            return null;
        }

        $donors = get_posts( [
            'post_type'      => 'sd_donor',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_user_id',
                    'value' => $user_id,
                ],
            ],
            'fields'         => 'ids',
        ] );

        if ( ! empty( $donors ) ) {
            return $donors[0];
        }

        $user = get_user_by( 'id', $user_id );
        
        if ( $user ) {
            $donors = get_posts( [
                'post_type'      => 'sd_donor',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => '_sd_email',
                        'value' => $user->user_email,
                    ],
                ],
                'fields'         => 'ids',
            ] );

            if ( ! empty( $donors ) ) {
                update_post_meta( $donors[0], '_sd_user_id', $user_id );
                return $donors[0];
            }
        }

        return null;
    }

    /**
     * Render donor dashboard endpoint.
     *
     * @since 1.0.0
     */
    public static function render_donor_dashboard(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );

        $recent_donations = Query::for( 'sd_donation' )
            ->where( 'donor_id', $donor_id )
            ->orderBy( 'donation_date', 'DESC' )
            ->paginate( 1, 5 );

        $membership = Query::for( 'sd_membership' )
            ->where( 'donor_id', $donor_id )
            ->whereCompare( 'end_date', wp_date( 'Y-m-d' ), '>=', 'DATE' )
            ->orderBy( 'end_date', 'DESC' )
            ->first();

        $stats = [
            'lifetime_giving' => $donor['lifetime_giving'] ?? 0,
            'donor_level'     => $donor['donor_level'] ?? 'new',
            'donation_count'  => Query::for( 'sd_donation' )->where( 'donor_id', $donor_id )->count(),
            'memorial_count'  => Query::for( 'sd_memorial' )->where( 'donor_id', $donor_id )->count(),
        ];

        self::render_template( 'donor-dashboard', compact( 'donor', 'recent_donations', 'membership', 'stats' ) );
    }

    /**
     * Render giving history endpoint.
     *
     * @since 1.0.0
     */
    public static function render_giving_history(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $paged = isset( $_GET['history-page'] ) ? absint( $_GET['history-page'] ) : 1;
        $year = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : null;

        $donations = Query::for( 'sd_donation' )
            ->where( 'donor_id', $donor_id )
            ->whereYear( 'donation_date', $year )
            ->orderBy( 'donation_date', 'DESC' )
            ->paginate( $paged, 10 );

        $years = self::get_donation_years( $donor_id );

        self::render_template( 'giving-history', [
            'donations'    => $donations,
            'years'        => $years,
            'current_year' => $year,
        ] );
    }

    /**
     * Render memberships endpoint.
     *
     * @since 1.0.0
     */
    public static function render_my_memberships(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $memberships = Query::for( 'sd_membership' )
            ->where( 'donor_id', $donor_id )
            ->orderBy( 'end_date', 'DESC' )
            ->get();

        $active = array_filter( $memberships, fn( $m ) => $m['is_active'] ?? false );
        $expired = array_filter( $memberships, fn( $m ) => ! ( $m['is_active'] ?? false ) );

        self::render_template( 'my-memberships', [
            'active_memberships'  => $active,
            'expired_memberships' => $expired,
            'list_publicly'       => self::is_listed_publicly( $donor_id ),
        ] );
    }

    /**
     * Render memorials endpoint.
     *
     * @since 1.0.0
     */
    public static function render_my_memorials(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $paged = isset( $_GET['memorial-page'] ) ? absint( $_GET['memorial-page'] ) : 1;

        $memorials = Query::for( 'sd_memorial' )
            ->where( 'donor_id', $donor_id )
            ->orderBy( 'donation_date', 'DESC' )
            ->paginate( $paged, 12 );

        self::render_template( 'my-memorials', [ 'memorials' => $memorials ] );
    }

    /**
     * Render annual statement endpoint.
     *
     * @since 1.0.0
     */
    public static function render_annual_statement(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $year = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : self::default_statement_year( $donor_id );

        $ability = wp_get_ability( 'shelter-reports/annual-summary' );
        
        if ( ! $ability ) {
            echo '<p>' . esc_html__( 'Statement generation is temporarily unavailable.', 'starter-shelter' ) . '</p>';
            return;
        }

        $summary = $ability->execute( [ 'donor_id' => $donor_id, 'year' => $year ] );

        if ( is_wp_error( $summary ) ) {
            echo '<p>' . esc_html( $summary->get_error_message() ) . '</p>';
            return;
        }

        $years = self::get_donation_years( $donor_id );

        // Keep the selector and the rendered statement in sync: an explicit
        // ?year for a year with no contributions still shows as selected.
        if ( ! in_array( $year, $years, true ) ) {
            $years[] = $year;
            rsort( $years );
        }

        self::render_template( 'annual-statement', [
            'summary'   => $summary,
            'year'      => $year,
            'years'     => $years,
            'print_url' => add_query_arg( [ 'print' => '1', 'year' => $year ] ),
        ] );
    }

    /**
     * Get years that have donations for a donor.
     *
     * @since 1.0.0
     *
     * @param int $donor_id The donor ID.
     * @return array Array of years.
     */
    private static function get_donation_years( int $donor_id ): array {
        global $wpdb;

        $years = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT YEAR(pm.meta_value) as year
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donation_date'
            INNER JOIN {$wpdb->postmeta} pd ON p.ID = pd.post_id AND pd.meta_key = '_sd_donor_id' AND pd.meta_value = %d
            WHERE p.post_type IN ('sd_donation', 'sd_memorial', 'sd_membership')
            AND p.post_status = 'publish'
            ORDER BY year DESC",
            $donor_id
        ) );

        return array_map( 'intval', $years );
    }

    /**
     * Resolve the default statement year for a donor.
     *
     * Prefers the prior, completed tax year (the usual target for a charitable
     * contribution statement). If that year has no contributions, falls back to
     * the most recent year that does, so the year selector and the rendered
     * statement always agree instead of silently showing different years.
     *
     * @since 1.0.0
     *
     * @param int $donor_id The donor ID.
     * @return int The default year.
     */
    private static function default_statement_year( int $donor_id ): int {
        $prior_year = (int) wp_date( 'Y' ) - 1;
        $years      = self::get_donation_years( $donor_id );

        if ( empty( $years ) || in_array( $prior_year, $years, true ) ) {
            return $prior_year;
        }

        return (int) $years[0]; // Most recent year with contributions (DESC).
    }

    /**
     * Render a template.
     *
     * @since 1.0.0
     *
     * @param string $template The template name.
     * @param array  $args     Template arguments.
     */
    private static function render_template( string $template, array $args = [] ): void {
        $template_path = locate_template( "starter-shelter/myaccount/{$template}.php" );

        if ( ! $template_path ) {
            $template_path = STARTER_SHELTER_PATH . "templates/myaccount/{$template}.php";
        }

        if ( ! file_exists( $template_path ) ) {
            return;
        }

        // The template name matches its account endpoint slug, so it doubles
        // as the active sub-nav tab.
        self::render_account_nav( $template );

        extract( $args ); // phpcs:ignore
        include $template_path;
    }

    /**
     * Render the donor-area sub-navigation (the in-page tab bar that
     * replaces the per-section top-level account menu items).
     *
     * @since 2.2.0
     *
     * @param string $current Current endpoint slug (active tab).
     */
    private static function render_account_nav( string $current ): void {
        $items = [
            'donor-dashboard'  => __( 'Overview', 'starter-shelter' ),
            'giving-history'   => __( 'Giving History', 'starter-shelter' ),
            'my-memberships'   => __( 'Memberships', 'starter-shelter' ),
            'my-memorials'     => __( 'Memorials', 'starter-shelter' ),
            'annual-statement' => __( 'Annual Statement', 'starter-shelter' ),
        ];

        echo '<nav class="sd-account-subnav" aria-label="' . esc_attr__( 'Donor account sections', 'starter-shelter' ) . '">';
        foreach ( $items as $slug => $label ) {
            $is_current = ( $slug === $current );
            printf(
                '<a href="%s" class="sd-account-subnav__link%s"%s>%s</a>',
                esc_url( wc_get_account_endpoint_url( $slug ) ),
                $is_current ? ' is-active' : '',
                $is_current ? ' aria-current="page"' : '',
                esc_html( $label )
            );
        }
        echo '</nav>';
    }

    /**
     * Keep the single "My Giving" menu item highlighted while the member is
     * on any of its sub-sections (WooCommerce only marks the exact endpoint).
     *
     * @since 2.2.0
     *
     * @param string[] $classes  Menu item CSS classes.
     * @param string   $endpoint The menu item's endpoint.
     * @return string[]
     */
    public static function highlight_my_giving( array $classes, string $endpoint ): array {
        if ( 'donor-dashboard' !== $endpoint ) {
            return $classes;
        }

        global $wp_query;
        foreach ( [ 'giving-history', 'my-memberships', 'my-memorials', 'annual-statement' ] as $slug ) {
            if ( isset( $wp_query->query_vars[ $slug ] ) ) {
                $classes[] = 'is-active';
                break;
            }
        }

        return $classes;
    }

    /**
     * Render message when user has no donor profile.
     *
     * @since 1.0.0
     */
    private static function render_no_donor_message(): void {
        $donation_url = Helpers\get_donation_page_url();
        ?>
        <div class="sd-no-donor">
            <p><?php esc_html_e( 'You don\'t have a donor profile yet. Make your first donation to get started!', 'starter-shelter' ); ?></p>
            <?php if ( $donation_url ) : ?>
            <a href="<?php echo esc_url( $donation_url ); ?>" class="button"><?php esc_html_e( 'Donate Now', 'starter-shelter' ); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue My Account styles.
     *
     * @since 1.0.0
     */
    public static function enqueue_styles(): void {
        if ( ! is_account_page() ) {
            return;
        }

        wp_add_inline_style( 'woocommerce-general', self::get_styles() );
    }

    /**
     * Get inline styles.
     *
     * @since 1.0.0
     *
     * @return string CSS code.
     */
    private static function get_styles(): string {
        return '
/* Donor area sub-navigation */
.sd-account-subnav { display: flex; flex-wrap: wrap; gap: 2px; border-bottom: 1px solid #e0e0e0; margin: 0 0 24px; }
.sd-account-subnav__link { padding: 8px 14px; text-decoration: none; color: #555; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.sd-account-subnav__link:hover { color: #1e1e1e; }
.sd-account-subnav__link.is-active { color: #1e1e1e; font-weight: 600; border-bottom-color: var(--wp--preset--color--primary, #0073aa); }

/* Dashboard stats — clickable cards */
.sd-dashboard-stats { display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap; }
.sd-stat { flex: 1; min-width: 120px; background: #f8f8f8; padding: 20px; text-align: center; border-radius: 4px; }
.sd-stat-link { text-decoration: none; color: inherit; transition: all 0.2s ease; display: block; }
.sd-stat-link:hover { background: #eef; transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.sd-stat-value { display: block; font-size: 1.8em; font-weight: bold; color: #1e1e1e; }
.sd-stat-label { display: block; color: #666; margin-top: 5px; }

/* Public recognition preference */
.sd-recognition-pref { background: #f8f8f8; padding: 20px; margin: 0 0 24px; border-radius: 4px; }
.sd-recognition-pref h3 { margin-top: 0; }
.sd-recognition-toggle { display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; }
.sd-recognition-help { color: #666; margin: 6px 0 12px; font-size: 0.9em; }

/* Membership self-service actions (renew / auto-renew / cancel) */
.sd-membership-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 10px; }
.sd-membership-actions form { margin: 0; }
.sd-auto-renew-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; cursor: pointer; }
.sd-cancel-btn { color: #b32d2e; }

/* Contact details edit form */
.sd-profile-edit { background: #f8f8f8; padding: 20px; margin: 20px 0; border-radius: 4px; }
.sd-profile-edit h3 { margin-top: 0; }
.sd-profile-form { display: flex; flex-wrap: wrap; gap: 12px 16px; }
.sd-field { display: flex; flex-direction: column; flex: 1 1 100%; margin: 0; }
.sd-field--half { flex: 1 1 calc(50% - 16px); }
.sd-field--actions { flex-basis: 100%; }
.sd-field label { font-weight: 600; font-size: 0.9em; margin-bottom: 4px; }
.sd-field input { width: 100%; }

/* Membership status */
.sd-membership-status, .sd-recent-donations { background: #f8f8f8; padding: 20px; margin: 20px 0; border-radius: 4px; }
.sd-membership-status.sd-expiring { background: #fff8e5; border: 1px solid #f0c33c; }
.sd-expiry-warning { color: #996800; font-weight: 600; }
.sd-renew-btn { margin-top: 10px; }

/* Membership cards */
.sd-membership-card { display: flex; justify-content: space-between; align-items: center; background: #f8f8f8; padding: 20px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #2271b1; }
.sd-membership-card.sd-active { border-left-color: #00a32a; }
.sd-membership-card.sd-expired { border-left-color: #c3c4c7; opacity: 0.8; }
.sd-membership-card.sd-expiring { border-left-color: #dba617; background: #fff8e5; }
.sd-membership-info h4 { margin: 0 0 4px; }
.sd-membership-info p { margin: 0; }
.sd-membership-amount { color: #666; font-size: 0.9em; margin-top: 4px !important; }

/* Memorial cards */
.sd-memorials-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
.sd-memorial-card { background: #f8f8f8; border-radius: 8px; overflow: hidden; transition: box-shadow 0.2s ease; }
.sd-memorial-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
.sd-memorial-photo img { width: 100%; height: 160px; object-fit: cover; }
.sd-memorial-content { padding: 16px; }
.sd-memorial-type-badge { display: inline-block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #8b5a5a; background: #f5ebe9; padding: 2px 8px; border-radius: 3px; margin-bottom: 8px; }
.sd-memorial-content h4 { margin: 0 0 8px; font-size: 1.1em; }
.sd-memorial-excerpt { color: #555; font-style: italic; font-size: 0.9em; margin: 0 0 8px; }
.sd-memorial-meta { display: flex; justify-content: space-between; font-size: 0.85em; color: #888; margin-bottom: 8px; }
.sd-memorial-link { font-size: 0.9em; }

/* Giving history */
.sd-page-total td { border-top: 2px solid #333 !important; }
.sd-year-filter { margin-bottom: 20px; }

/* Pagination */
.sd-pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #ddd; }
.sd-page-link { text-decoration: none; }
.sd-page-info { color: #666; font-size: 0.9em; }

/* Statement */
.sd-statement-content { background: #fff; border: 1px solid #ddd; padding: 40px; margin-top: 20px; }
.sd-statement-summary { width: 100%; margin: 20px 0; border-collapse: collapse; }
.sd-statement-summary td { padding: 10px; border-bottom: 1px solid #ddd; }
.sd-statement-summary .sd-total td { border-top: 2px solid #333; font-size: 1.1em; }
.sd-statement-header { display: flex; justify-content: space-between; align-items: center; }

/* No donor */
.sd-no-donor { text-align: center; padding: 40px; background: #f8f8f8; border-radius: 4px; }
';
    }

    /**
     * Flush rewrite rules on activation.
     *
     * @since 1.0.0
     */
    public static function flush_rewrite_rules(): void {
        self::register_endpoints();
        flush_rewrite_rules();
    }
}
