<?php
/**
 * My Account — Donor Dashboard template.
 *
 * Override from a theme at `shelter-donations/myaccount/donor-dashboard.php`.
 * Runs in the plugin's WooCommerce namespace so `Helpers\…` resolves; a
 * theme override should keep the namespace + use lines (or use fully
 * qualified function names). WordPress core functions resolve via the
 * global fallback.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 *
 * @var array      $donor            Hydrated donor entity.
 * @var array      $recent_donations Paginated recent donations.
 * @var array|null $membership       Current active membership (or null).
 * @var array      $stats            Dashboard stats (lifetime_giving, donor_level, donation_count, memorial_count).
 */

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;

$membership_url = wc_get_account_endpoint_url( 'my-memberships' );
$giving_url     = wc_get_account_endpoint_url( 'giving-history' );
$memorials_url  = wc_get_account_endpoint_url( 'my-memorials' );

// Membership expiry warning.
$days_remaining = null;
$is_expiring    = false;
if ( $membership && ! empty( $membership['end_date'] ) ) {
    $days_remaining = (int) ( ( strtotime( $membership['end_date'] ) - time() ) / DAY_IN_SECONDS );
    $is_expiring = $days_remaining >= 0 && $days_remaining <= 30;
}
?>
<div class="sd-donor-dashboard">
    <div class="sd-dashboard-header">
        <h2><?php echo esc_html( sprintf( /* translators: %s: donor first name */ __( 'Welcome, %s!', 'shelterkit-donations' ), $donor['first_name'] ?? __( 'Friend', 'shelterkit-donations' ) ) ); ?></h2>
        <p class="sd-donor-level"><?php echo esc_html( sprintf( /* translators: %s: donor level label */ __( 'Donor Level: %s', 'shelterkit-donations' ), Helpers\get_donor_level_label( $stats['donor_level'] ) ) ); ?></p>
    </div>

    <div class="sd-dashboard-stats">
        <a href="<?php echo esc_url( $giving_url ); ?>" class="sd-stat sd-stat-link">
            <span class="sd-stat-value"><?php echo esc_html( Helpers\format_currency( $stats['lifetime_giving'] ) ); ?></span>
            <span class="sd-stat-label"><?php esc_html_e( 'Lifetime Giving', 'shelterkit-donations' ); ?></span>
        </a>
        <a href="<?php echo esc_url( $giving_url ); ?>" class="sd-stat sd-stat-link">
            <span class="sd-stat-value"><?php echo esc_html( $stats['donation_count'] ); ?></span>
            <span class="sd-stat-label"><?php esc_html_e( 'Donations', 'shelterkit-donations' ); ?></span>
        </a>
        <a href="<?php echo esc_url( $memorials_url ); ?>" class="sd-stat sd-stat-link">
            <span class="sd-stat-value"><?php echo esc_html( $stats['memorial_count'] ); ?></span>
            <span class="sd-stat-label"><?php esc_html_e( 'Memorials', 'shelterkit-donations' ); ?></span>
        </a>
    </div>

    <?php if ( $membership ) : ?>
    <div class="sd-membership-status <?php echo $is_expiring ? 'sd-expiring' : ''; ?>">
        <h3><?php esc_html_e( 'Membership Status', 'shelterkit-donations' ); ?></h3>
        <p>
            <strong><?php echo esc_html( $membership['tier_label'] ?? $membership['tier'] ); ?></strong><br>
            <?php if ( $is_expiring ) : ?>
                <span class="sd-expiry-warning">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: number of days remaining */
                        _n( 'Expires in %d day!', 'Expires in %d days!', $days_remaining, 'shelterkit-donations' ),
                        $days_remaining
                    ) );
                    ?>
                </span>
            <?php else : ?>
                <?php echo esc_html( sprintf( /* translators: %s: expiration date */ __( 'Expires: %s', 'shelterkit-donations' ), Helpers\format_date( $membership['end_date'] ) ) ); ?>
            <?php endif; ?>
        </p>
        <?php if ( $is_expiring ) : ?>
            <a href="<?php echo esc_url( $membership_url ); ?>" class="button sd-renew-btn">
                <?php esc_html_e( 'Renew Membership', 'shelterkit-donations' ); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $recent_donations['items'] ) ) : ?>
    <div class="sd-recent-donations">
        <h3><?php esc_html_e( 'Recent Donations', 'shelterkit-donations' ); ?></h3>
        <ul>
            <?php foreach ( $recent_donations['items'] as $donation ) : ?>
            <li><?php echo esc_html( Helpers\format_date( $donation['donation_date'] ) . ' — ' . $donation['amount_formatted'] ); ?></li>
            <?php endforeach; ?>
        </ul>
        <a href="<?php echo esc_url( $giving_url ); ?>"><?php esc_html_e( 'View All →', 'shelterkit-donations' ); ?></a>
    </div>
    <?php endif; ?>

    <?php
    $addr    = is_array( $donor['address'] ?? null ) ? $donor['address'] : [];
    $addr_1  = (string) ( $addr['address_1'] ?? $addr['line_1'] ?? '' );
    $addr_2  = (string) ( $addr['address_2'] ?? $addr['line_2'] ?? '' );
    $addr_zip = (string) ( $addr['postcode'] ?? $addr['postal_code'] ?? '' );
    ?>
    <div class="sd-profile-edit">
        <h3><?php esc_html_e( 'Contact Details', 'shelterkit-donations' ); ?></h3>
        <form method="post" class="sd-profile-form">
            <?php wp_nonce_field( 'sd_profile_action', 'sd_profile_nonce' ); ?>
            <input type="hidden" name="sd_account_action" value="update_profile">
            <p class="sd-field sd-field--half">
                <label for="sd-first-name"><?php esc_html_e( 'First name', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-first-name" name="first_name" value="<?php echo esc_attr( (string) ( $donor['first_name'] ?? '' ) ); ?>">
            </p>
            <p class="sd-field sd-field--half">
                <label for="sd-last-name"><?php esc_html_e( 'Last name', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-last-name" name="last_name" value="<?php echo esc_attr( (string) ( $donor['last_name'] ?? '' ) ); ?>">
            </p>
            <p class="sd-field">
                <label for="sd-phone"><?php esc_html_e( 'Phone', 'shelterkit-donations' ); ?></label>
                <input type="tel" id="sd-phone" name="phone" value="<?php echo esc_attr( (string) ( $donor['phone'] ?? '' ) ); ?>">
            </p>
            <p class="sd-field">
                <label for="sd-address-1"><?php esc_html_e( 'Address', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-address-1" name="address_1" value="<?php echo esc_attr( $addr_1 ); ?>" placeholder="<?php esc_attr_e( 'Street address', 'shelterkit-donations' ); ?>">
            </p>
            <p class="sd-field">
                <label for="sd-address-2" class="screen-reader-text"><?php esc_html_e( 'Address line 2', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-address-2" name="address_2" value="<?php echo esc_attr( $addr_2 ); ?>" placeholder="<?php esc_attr_e( 'Apt, suite, etc. (optional)', 'shelterkit-donations' ); ?>">
            </p>
            <p class="sd-field sd-field--half">
                <label for="sd-city"><?php esc_html_e( 'City', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-city" name="city" value="<?php echo esc_attr( (string) ( $addr['city'] ?? '' ) ); ?>">
            </p>
            <p class="sd-field sd-field--half">
                <label for="sd-state"><?php esc_html_e( 'State', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-state" name="state" value="<?php echo esc_attr( (string) ( $addr['state'] ?? '' ) ); ?>">
            </p>
            <p class="sd-field sd-field--half">
                <label for="sd-postcode"><?php esc_html_e( 'ZIP / Postal code', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-postcode" name="postcode" value="<?php echo esc_attr( $addr_zip ); ?>">
            </p>
            <p class="sd-field sd-field--half">
                <label for="sd-country"><?php esc_html_e( 'Country', 'shelterkit-donations' ); ?></label>
                <input type="text" id="sd-country" name="country" maxlength="2" value="<?php echo esc_attr( (string) ( $addr['country'] ?? 'US' ) ); ?>">
            </p>
            <p class="sd-field sd-field--actions">
                <button type="submit" class="button"><?php esc_html_e( 'Save details', 'shelterkit-donations' ); ?></button>
            </p>
        </form>
    </div>
</div>
