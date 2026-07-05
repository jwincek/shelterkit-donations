<?php
/**
 * Membership renewal reminder email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$membership = $data['membership'] ?? [];
$donor = $data['donor'] ?? [];

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelter-donations' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'shelter-donations' ) )
);
echo "\n\n";

printf(
    /* translators: 1: tier label, 2: expiration date */
    esc_html__( 'Your %1$s membership will expire on %2$s.', 'shelter-donations' ),
    esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ),
    esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) )
);
echo "\n\n";

echo esc_html__( 'Your membership has helped us:', 'shelter-donations' ) . "\n";
echo "* " . esc_html__( 'Provide food and shelter for animals in need', 'shelter-donations' ) . "\n";
echo "* " . esc_html__( 'Offer veterinary care and medical treatments', 'shelter-donations' ) . "\n";
echo "* " . esc_html__( 'Find forever homes for our furry friends', 'shelter-donations' ) . "\n";
echo "* " . esc_html__( 'Support community education and outreach programs', 'shelter-donations' ) . "\n\n";

echo "= " . esc_html__( 'Renew Your Membership', 'shelter-donations' ) . " =\n\n";

printf(
    /* translators: %s: renewal URL */
    esc_html__( 'Renew here: %s', 'shelter-donations' ),
    esc_url( home_url( '/membership/' ) )
);
echo "\n\n";

echo esc_html__( 'Current Membership:', 'shelter-donations' ) . ' ' . esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ) . "\n";
echo esc_html__( 'Expiration Date:', 'shelter-donations' ) . ' ' . esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ) . "\n";

if ( ! empty( $membership['days_remaining'] ) ) {
    echo esc_html__( 'Days Remaining:', 'shelter-donations' ) . ' ' . esc_html( $membership['days_remaining'] ) . "\n";
}

echo "\n" . esc_html__( 'Thank you for your continued support!', 'shelter-donations' );
echo "\n\n";

echo esc_html__( 'Warm regards,', 'shelter-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
