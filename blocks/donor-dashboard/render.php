<?php
/**
 * Donor Dashboard Block - Server-side render.
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 2.0.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Core\Entity_Hydrator;
use Starter_Shelter\Core\Query;
use Starter_Shelter\Helpers;
use Starter_Shelter\Blocks;

// Block attributes.
$show_stats        = $attributes['showStats'] ?? true;
$show_recent_gifts = $attributes['showRecentGifts'] ?? true;
$show_membership   = $attributes['showMembership'] ?? true;
$show_donor_level  = $attributes['showDonorLevel'] ?? true;
$recent_count      = $attributes['recentGiftsCount'] ?? 5;
$layout            = $attributes['layout'] ?? 'cards';
$guest_message     = $attributes['guestMessage'] ?: __( 'Please log in to view your donor dashboard.', 'shelterkit-donations' );

// Check if user is logged in.
$is_logged_in = is_user_logged_in();
$donor_id = Blocks\get_current_user_donor_id();

// Get donor data if logged in.
$donor = null;
$stats = null;
$recent_gifts = [];
$membership = null;

if ( $donor_id ) {
    $donor = Entity_Hydrator::get( 'skdon_donor', $donor_id );

    // Gift count and first gift, from the records. Nothing ever wrote the
    // _skdon_donation_count or _skdon_first_gift_date this used to read, so
    // it always said 0 gifts and never showed "Donor Since" (#42). Query
    // counts published records only, like lifetime giving.
    $gift_count = 0;
    $first_gift = '';
    foreach ( [ 'skdon_donation' => 'donation_date', 'skdon_membership' => 'start_date', 'skdon_memorial' => 'donation_date' ] as $type => $date_field ) {
        $gift_count += Query::for( $type )->where( 'donor_id', $donor_id )->count();
        $earliest    = Query::for( $type )->where( 'donor_id', $donor_id )->orderBy( $date_field, 'ASC', 'DATE' )->first();
        $date        = (string) ( $earliest[ $date_field ] ?? '' );
        if ( '' !== $date && ( '' === $first_gift || $date < $first_gift ) ) {
            $first_gift = $date;
        }
    }

    $stats = [
        'lifetime_giving' => (float) get_post_meta( $donor_id, '_skdon_lifetime_giving', true ),
        'donation_count'  => $gift_count,
        'first_gift_date' => $first_gift,
    ];

    // Get recent donations.
    $recent_query = new WP_Query( [
        'post_type'      => 'skdon_donation',
        'posts_per_page' => $recent_count,
        'meta_query'     => [
            [
                'key'   => '_skdon_donor_id',
                'value' => $donor_id,
            ],
        ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $recent_gifts = array_map( function ( $post ) {
        return [
            'id'         => $post->ID,
            'amount'     => (float) get_post_meta( $post->ID, '_skdon_amount', true ),
            'date'       => get_the_date( get_option( 'date_format' ), $post ),
            'allocation' => get_post_meta( $post->ID, '_skdon_allocation', true ),
            'type'       => get_post_meta( $post->ID, '_skdon_donation_type', true ) ?: 'general',
        ];
    }, $recent_query->posts );

    // Get membership status.
    if ( $show_membership ) {
        $membership_query = new WP_Query( [
            'post_type'      => 'skdon_membership',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_skdon_donor_id',
                    'value' => $donor_id,
                ],
                [
                    'key'     => '_skdon_end_date',
                    'value'   => wp_date( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ] );

        if ( $membership_query->have_posts() ) {
            $mem_post = $membership_query->posts[0];
            $membership = [
                'id'        => $mem_post->ID,
                'tier'      => get_post_meta( $mem_post->ID, '_skdon_tier', true ),
                'type'      => get_post_meta( $mem_post->ID, '_skdon_membership_type', true ),
                'start'     => get_post_meta( $mem_post->ID, '_skdon_start_date', true ),
                'end'       => get_post_meta( $mem_post->ID, '_skdon_end_date', true ),
                'is_active' => true,
            ];
        }
    }
}

// Wrapper attributes.
$classes = [
    'sd-donor-dashboard',
    "sd-layout--{$layout}",
    ! $is_logged_in ? 'is-guest' : '',
];

// Rendered in full here, with no script (#42). The client store this block
// used to load re-fetched everything from routes that don't exist, its
// start-up callback didn't exist either, and its defaults overrode this
// render's data, so the dashboard stayed on "Loading…" for good. Nothing on
// it changes after the page loads.
$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', array_filter( $classes ) ),
] );
?>

<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup from get_block_wrapper_attributes(). ?>>

    <?php if ( ! $is_logged_in ) : ?>
    <!-- Guest State -->
    <div class="sd-dashboard-guest">
        <svg class="sd-guest-icon" viewBox="0 0 24 24" width="48" height="48" aria-hidden="true">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z" fill="currentColor"/>
        </svg>
        <p class="sd-guest-message"><?php echo esc_html( $guest_message ); ?></p>
        <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="sd-login-button wp-element-button">
            <?php esc_html_e( 'Log In', 'shelterkit-donations' ); ?>
        </a>
    </div>

    <?php else : ?>
    <!-- Dashboard Content -->
    <div class="sd-dashboard-content">
        
        <!-- Header -->
        <header class="sd-dashboard-header">
            <div class="sd-donor-greeting">
                <h2>
                    <?php
                    printf(
                        /* translators: %s: donor name */
                        esc_html__( 'Welcome back, %s!', 'shelterkit-donations' ),
                        '<span>' . esc_html( $donor['display_name'] ?? __( 'Friend', 'shelterkit-donations' ) ) . '</span>'
                    );
                    ?>
                </h2>
                
                <?php if ( $show_donor_level && isset( $donor['donor_level'] ) ) : ?>
                <span class="sd-donor-level">
                    <?php
                    $levels = [
                        'new' => __( 'New Donor', 'shelterkit-donations' ),
                        'bronze' => __( 'Bronze', 'shelterkit-donations' ),
                        'silver' => __( 'Silver', 'shelterkit-donations' ),
                        'gold' => __( 'Gold', 'shelterkit-donations' ),
                        'platinum' => __( 'Platinum', 'shelterkit-donations' ),
                    ];
                    echo esc_html( $levels[ $donor['donor_level'] ] ?? __( 'Donor', 'shelterkit-donations' ) );
                    ?>
                </span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ( $show_stats && $stats ) : ?>
        <!-- Stats Grid -->
        <section class="sd-dashboard-stats">
            <h3 class="sd-section-title"><?php esc_html_e( 'Your Impact', 'shelterkit-donations' ); ?></h3>
            
            <div class="sd-stats-grid">
                <div class="sd-stat-card">
                    <span class="sd-stat-value">
                        <?php echo esc_html( Helpers\format_currency( $stats['lifetime_giving'] ) ); ?>
                    </span>
                    <span class="sd-stat-label"><?php esc_html_e( 'Lifetime Giving', 'shelterkit-donations' ); ?></span>
                </div>
                
                <div class="sd-stat-card">
                    <span class="sd-stat-value">
                        <?php echo esc_html( number_format_i18n( $stats['donation_count'] ) ); ?>
                    </span>
                    <span class="sd-stat-label"><?php esc_html_e( 'Total Gifts', 'shelterkit-donations' ); ?></span>
                </div>
                
                <?php if ( $stats['first_gift_date'] ) : ?>
                <div class="sd-stat-card">
                    <span class="sd-stat-value">
                        <?php echo esc_html( wp_date( 'M Y', strtotime( $stats['first_gift_date'] ) ) ); ?>
                    </span>
                    <span class="sd-stat-label"><?php esc_html_e( 'Donor Since', 'shelterkit-donations' ); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( $show_membership ) : ?>
        <!-- Membership Status -->
        <section class="sd-dashboard-membership">
            <h3 class="sd-section-title"><?php esc_html_e( 'Membership', 'shelterkit-donations' ); ?></h3>
            
            <?php if ( $membership ) : ?>
            <div class="sd-membership-card is-active">
                <div class="sd-membership-badge">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" fill="currentColor"/>
                    </svg>
                    <span>
                        <?php echo esc_html( ucfirst( $membership['tier'] ?? '' ) ); ?>
                    </span>
                </div>
                <div class="sd-membership-details">
                    <p class="sd-membership-status"><?php esc_html_e( 'Active Member', 'shelterkit-donations' ); ?></p>
                    <p class="sd-membership-expires">
                        <?php
                        printf(
                            /* translators: %s: expiration date */
                            esc_html__( 'Renews: %s', 'shelterkit-donations' ),
                            esc_html( wp_date( get_option( 'date_format' ), strtotime( $membership['end'] ) ) )
                        );
                        ?>
                    </p>
                </div>
            </div>
            <?php else : ?>
            <div class="sd-membership-card is-inactive">
                <p><?php esc_html_e( 'You are not currently a member.', 'shelterkit-donations' ); ?></p>
                <a href="<?php echo esc_url( home_url( '/membership/' ) ); ?>" class="sd-join-button wp-element-button">
                    <?php esc_html_e( 'Become a Member', 'shelterkit-donations' ); ?>
                </a>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ( $show_recent_gifts && ! empty( $recent_gifts ) ) : ?>
        <!-- Recent Gifts -->
        <section class="sd-dashboard-gifts">
            <h3 class="sd-section-title"><?php esc_html_e( 'Recent Gifts', 'shelterkit-donations' ); ?></h3>
            
            <ul class="sd-gifts-list">
                <?php foreach ( $recent_gifts as $gift ) : ?>
                <li class="sd-gift-item">
                    <span class="sd-gift-amount"><?php echo esc_html( Helpers\format_currency( $gift['amount'] ) ); ?></span>
                    <span class="sd-gift-type"><?php echo esc_html( ucfirst( $gift['type'] ) ); ?></span>
                    <span class="sd-gift-date"><?php echo esc_html( $gift['date'] ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <?php // My Account's gift history. "donations" isn't an endpoint, so this link was a 404 (#64). ?>
            <p class="sd-view-all">
                <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'giving-history' ) ); ?>">
                    <?php esc_html_e( 'View All Gifts →', 'shelterkit-donations' ); ?>
                </a>
            </p>
        </section>
        <?php endif; ?>

        <!-- Quick Actions -->
        <?php // Class names as the stylesheet has them; the old ones matched no rule (#64). ?>
        <section class="sd-quick-actions">
            <?php $donation_url = Helpers\get_donation_page_url(); ?>
            <?php if ( $donation_url ) : ?>
            <a href="<?php echo esc_url( $donation_url ); ?>" class="sd-action-button sd-primary wp-element-button">
                <?php esc_html_e( 'Make a Gift', 'shelterkit-donations' ); ?>
            </a>
            <?php endif; ?>
            <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>" class="sd-action-button sd-secondary">
                <?php esc_html_e( 'Update Profile', 'shelterkit-donations' ); ?>
            </a>
        </section>
    </div>
    <?php endif; ?>
</div>
