<?php
/**
 * Admin Menu - Registers the top-level Shelter Donations admin menu.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

/**
 * Handles the main admin menu structure.
 *
 * @since 1.0.0
 */
class Menu {

    /**
     * Menu slug.
     *
     * @since 1.0.0
     * @var string
     */
    public const MENU_SLUG = 'starter-shelter';

    /**
     * Initialize the admin menu.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ], 5 );
        add_action( 'admin_menu', [ self::class, 'add_pending_badge' ], 99 );

        // Invalidate badge cache when relevant records change.
        add_action( 'save_post_sd_membership', [ self::class, 'invalidate_pending_cache' ] );
        add_action( 'save_post_sd_memorial', [ self::class, 'invalidate_pending_cache' ] );
        add_action( 'starter_shelter_logo_approved', [ self::class, 'invalidate_pending_cache' ] );
        add_action( 'starter_shelter_logo_rejected', [ self::class, 'invalidate_pending_cache' ] );
        add_action( 'starter_shelter_memorial_family_notification', [ self::class, 'invalidate_pending_cache' ] );
    }

    /**
     * Register the top-level admin menu.
     *
     * @since 1.0.0
     */
    public static function register_menu(): void {
        add_menu_page(
            __( 'Shelter Donations', 'starter-shelter' ),
            __( 'Shelter Donations', 'starter-shelter' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_dashboard_page' ],
            'dashicons-heart',
            26
        );

        // Add "Dashboard" as first submenu (replaces the duplicate parent link).
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'starter-shelter' ),
            __( 'Dashboard', 'starter-shelter' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_dashboard_page' ]
        );
    }

    /**
     * Render the main dashboard page.
     *
     * @since 1.0.0
     */
    public static function render_dashboard_page(): void {
        // Get stats using the dashboard-stats ability.
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'shelter-reports/dashboard-stats' ) : null;
        
        $stats = null;
        if ( $ability ) {
            $stats = $ability->execute( [ 'period' => 'month' ] );
            if ( is_wp_error( $stats ) ) {
                $stats = null;
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Shelter Donations', 'starter-shelter' ); ?></h1>
            
            <div class="sd-admin-dashboard">
                <?php if ( $stats ) : ?>
                <div class="sd-dashboard-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    
                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid #00a32a;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Donations This Month', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( '$' . number_format( $stats['donations']['total'] ?? 0, 2 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php 
                            printf(
                                /* translators: %d: donation count */
                                esc_html__( '%d donations', 'starter-shelter' ),
                                $stats['donations']['count'] ?? 0
                            );
                            ?>
                        </p>
                    </div>

                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Active Members', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( number_format( $stats['memberships']['active'] ?? 0 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php 
                            printf(
                                /* translators: %d: number of new records this month */
                                esc_html__( '%d new this month', 'starter-shelter' ),
                                $stats['memberships']['new'] ?? 0
                            );
                            ?>
                        </p>
                    </div>

                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo ( $stats['memberships']['expiring_soon'] ?? 0 ) > 0 ? '#dba617' : '#c3c4c7'; ?>;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Expiring Soon', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( number_format( $stats['memberships']['expiring_soon'] ?? 0 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php esc_html_e( 'within 30 days', 'starter-shelter' ); ?>
                        </p>
                    </div>

                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid #8c6db8;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Total Donors', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( number_format( $stats['donors']['total'] ?? 0 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php 
                            printf(
                                /* translators: %d: number of new records this month */
                                esc_html__( '%d new this month', 'starter-shelter' ),
                                $stats['donors']['new'] ?? 0
                            );
                            ?>
                        </p>
                    </div>

                </div>
                <?php else : ?>
                <p><?php esc_html_e( 'Welcome to Shelter Donations! Statistics will appear here once you start receiving donations.', 'starter-shelter' ); ?></p>
                <?php endif; ?>

                <div class="sd-quick-links" style="margin-top: 30px;">
                    <h2><?php esc_html_e( 'Quick Links', 'starter-shelter' ); ?></h2>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_donation' ) ); ?>" class="button">
                            <?php esc_html_e( 'View Donations', 'starter-shelter' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_membership' ) ); ?>" class="button">
                            <?php esc_html_e( 'View Memberships', 'starter-shelter' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_memorial' ) ); ?>" class="button">
                            <?php esc_html_e( 'View Memorials', 'starter-shelter' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=starter-shelter-reports' ) ); ?>" class="button button-primary">
                            <?php esc_html_e( 'View Reports', 'starter-shelter' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add a pending action count badge to the menu item.
     *
     * Shows the total number of items needing attention (pending logos,
     * expiring memberships, pending family notifications) as a red
     * bubble on the Shelter Donations menu — same pattern as
     * WooCommerce's order count badge.
     *
     * @since 2.1.0
     */
    public static function add_pending_badge(): void {
        global $menu;

        $count = self::get_pending_count();
        if ( $count < 1 ) {
            return;
        }

        $badge = sprintf(
            ' <span class="awaiting-mod sd-pending-badge">%d</span>',
            $count
        );

        foreach ( $menu as &$item ) {
            if ( isset( $item[2] ) && self::MENU_SLUG === $item[2] ) {
                $item[0] .= $badge;
                break;
            }
        }
    }

    /**
     * Get the total number of pending action items.
     *
     * Uses a short transient to avoid running 3 queries on every
     * admin page load.
     *
     * @since 2.1.0
     *
     * @return int Total pending count.
     */
    private static function get_pending_count(): int {
        $cached = get_transient( 'sd_menu_pending_count' );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        // Single source of truth — same ability the dashboard widget
        // consumes for its action list. We just sum the counts here.
        $count = 0;
        if ( function_exists( 'wp_get_ability' ) ) {
            $ability = wp_get_ability( 'shelter-reports/action-items' );
            if ( $ability ) {
                $result = $ability->execute( [] );
                if ( is_array( $result ) ) {
                    foreach ( $result['items'] ?? [] as $item ) {
                        $count += (int) ( $item['count'] ?? 0 );
                    }
                }
            }
        }

        set_transient( 'sd_menu_pending_count', $count, 600 );

        return $count;
    }

    /**
     * Clear the pending count cache.
     *
     * Called when actions are taken that change the pending count
     * (logo moderation, family notification sent, membership renewed).
     *
     * @since 2.1.0
     */
    public static function invalidate_pending_cache(): void {
        delete_transient( 'sd_menu_pending_count' );
    }

    /**
     * Get the menu slug.
     *
     * @since 1.0.0
     *
     * @return string The menu slug.
     */
    public static function get_menu_slug(): string {
        return self::MENU_SLUG;
    }
}
