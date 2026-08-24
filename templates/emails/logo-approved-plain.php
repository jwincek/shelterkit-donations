<?php
/**
 * Business Logo Approved Email Template (Plain Text)
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$membership   = $data['membership'] ?? [];
$donor        = $data['donor'] ?? [];
$business_name = $membership['business_name'] ?? __( 'Your business', 'shelterkit-donations' );

echo '= ' . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelterkit-donations' ),
    esc_html( $donor['first_name'] ?? $donor['full_name'] ?? __( 'Valued Member', 'shelterkit-donations' ) )
);
echo "\n\n";

printf(
    /* translators: %s: business name */
    esc_html__( 'Great news! The logo for %s has been approved and is now visible on our website.', 'shelterkit-donations' ),
    esc_html( $business_name )
);
echo "\n\n";

echo esc_html__( 'Your business logo will appear on:', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'Our Business Sponsors page', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'The Donor Wall (if applicable to your membership tier)', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'Our annual report and promotional materials', 'shelterkit-donations' ) . "\n\n";

echo esc_html__( 'Thank you for your generous support of our shelter and the animals in our care. Your business partnership makes a real difference!', 'shelterkit-donations' ) . "\n\n";

echo esc_html__( 'If you have any questions about your business membership benefits, please don\'t hesitate to contact us.', 'shelterkit-donations' ) . "\n\n";

echo esc_html__( 'With gratitude,', 'shelterkit-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
