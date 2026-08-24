<?php
/**
 * Donation receipt email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$donation = $data['donation'] ?? [];
$donor = $data['donor'] ?? [];

echo '= ' . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelterkit-donations' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'shelterkit-donations' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for your generous donation to support our animal shelter. Your contribution makes a real difference in the lives of the animals in our care.', 'shelterkit-donations' );
echo "\n\n";

echo '= ' . esc_html__( 'Donation Details', 'shelterkit-donations' ) . " =\n\n";

echo esc_html__( 'Amount:', 'shelterkit-donations' ) . ' ' . esc_html( $donation['amount_formatted'] ?? '' ) . "\n";
echo esc_html__( 'Date:', 'shelterkit-donations' ) . ' ' . esc_html( $donation['date_formatted'] ?? '' ) . "\n";
echo esc_html__( 'Allocation:', 'shelterkit-donations' ) . ' ' . esc_html( $donation['allocation_label'] ?? $donation['allocation'] ?? '' ) . "\n";

if ( ! empty( $donation['dedication'] ) ) {
    echo esc_html__( 'Dedication:', 'shelterkit-donations' ) . ' ' . esc_html( $donation['dedication'] ) . "\n";
}

echo esc_html__( 'Reference:', 'shelterkit-donations' ) . ' #' . esc_html( $donation['id'] ?? '' ) . "\n\n";

echo esc_html__( 'This donation is tax-deductible to the extent allowed by law. No goods or services were provided in exchange for this contribution.', 'shelterkit-donations' );
echo "\n\n";

echo esc_html__( 'Please keep this email as your receipt for tax purposes.', 'shelterkit-donations' );
echo "\n\n";

echo esc_html__( 'With gratitude,', 'shelterkit-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
