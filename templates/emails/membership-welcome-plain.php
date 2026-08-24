<?php
/**
 * Membership welcome email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$membership = $data['membership'] ?? [];
$donor = $data['donor'] ?? [];

echo '= ' . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelterkit-donations' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'shelterkit-donations' ) )
);
echo "\n\n";

printf(
    /* translators: 1: site name, 2: tier label */
    esc_html__( 'Welcome to the %1$s family! Thank you for becoming a %2$s member.', 'shelterkit-donations' ),
    esc_html( get_bloginfo( 'name' ) ),
    esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' )
);
echo "\n\n";

echo '= ' . esc_html__( 'Membership Details', 'shelterkit-donations' ) . " =\n\n";

echo esc_html__( 'Membership Level:', 'shelterkit-donations' ) . ' ' . esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ) . "\n";
echo esc_html__( 'Type:', 'shelterkit-donations' ) . ' ' . esc_html( ucfirst( $membership['membership_type'] ?? 'Individual' ) ) . "\n";

if ( 'business' === ( $membership['membership_type'] ?? '' ) && ! empty( $membership['business_name'] ) ) {
    echo esc_html__( 'Business Name:', 'shelterkit-donations' ) . ' ' . esc_html( $membership['business_name'] ) . "\n";
}

echo esc_html__( 'Start Date:', 'shelterkit-donations' ) . ' ' . esc_html( Helpers\format_date( $membership['start_date'] ?? '' ) ) . "\n";
echo esc_html__( 'Expiration Date:', 'shelterkit-donations' ) . ' ' . esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ) . "\n";
echo esc_html__( 'Member ID:', 'shelterkit-donations' ) . ' #' . esc_html( $membership['id'] ?? '' ) . "\n\n";

if ( ! empty( $membership['benefits'] ) ) {
    echo '= ' . esc_html__( 'Your Member Benefits', 'shelterkit-donations' ) . " =\n\n";
    foreach ( $membership['benefits'] as $benefit ) {
        echo '* ' . esc_html( $benefit ) . "\n";
    }
    echo "\n";
}

printf(
    /* translators: %s: donor dashboard URL */
    esc_html__( 'Visit your Donor Dashboard to view your membership status: %s', 'shelterkit-donations' ),
    esc_url( wc_get_account_endpoint_url( 'donor-dashboard' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for supporting our mission!', 'shelterkit-donations' );
echo "\n\n";

echo esc_html__( 'With gratitude,', 'shelterkit-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
