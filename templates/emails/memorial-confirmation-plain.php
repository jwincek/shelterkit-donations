<?php
/**
 * Memorial confirmation email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$memorial = $data['memorial'] ?? [];
$donor = $data['donor'] ?? [];

echo '= ' . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelterkit-donations' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'shelterkit-donations' ) )
);
echo "\n\n";

printf(
    /* translators: %s: honoree name */
    esc_html__( 'Thank you for your heartfelt memorial tribute in honor of %s.', 'shelterkit-donations' ),
    esc_html( $memorial['honoree_name'] ?? '' )
);
echo "\n\n";

echo '= ' . esc_html__( 'Memorial Details', 'shelterkit-donations' ) . " =\n\n";

echo esc_html__( 'In Memory Of:', 'shelterkit-donations' ) . ' ' . esc_html( $memorial['honoree_name'] ?? '' ) . "\n";
echo esc_html__( 'Memorial Type:', 'shelterkit-donations' ) . ' ' . esc_html( Helpers\get_memorial_type_label( $memorial['memorial_type'] ?? '' ) ) . "\n";

if ( ! empty( $memorial['pet_species'] ) ) {
    echo esc_html__( 'Species:', 'shelterkit-donations' ) . ' ' . esc_html( Helpers\get_species_label( $memorial['pet_species'] ) ) . "\n";
}

echo esc_html__( 'Donation Amount:', 'shelterkit-donations' ) . ' ' . esc_html( $memorial['amount_formatted'] ?? '' ) . "\n";
echo esc_html__( 'Date:', 'shelterkit-donations' ) . ' ' . esc_html( Helpers\format_date( $memorial['donation_date'] ?? '' ) ) . "\n\n";

if ( ! empty( $memorial['tribute_message'] ) ) {
    echo '= ' . esc_html__( 'Your Tribute Message', 'shelterkit-donations' ) . " =\n\n";
    echo '"' . esc_html( $memorial['tribute_message'] ) . '"' . "\n\n";
}

if ( ! empty( $memorial['id'] ) ) {
    echo esc_html__( 'View your memorial page:', 'shelterkit-donations' ) . "\n";
    echo esc_url( get_permalink( $memorial['id'] ) ) . "\n\n";
}

$notify_family = isset( $memorial['id'] )
    ? Helpers\get_memorial_notify_family( (int) $memorial['id'] )
    : [ 'enabled' => false ];
if ( $notify_family['enabled'] ) {
    echo esc_html__( 'We will notify the family of your thoughtful tribute as you requested.', 'shelterkit-donations' ) . "\n\n";
}

echo esc_html__( 'This donation is tax-deductible. Please keep this email for your records.', 'shelterkit-donations' );
echo "\n\n";

echo esc_html__( 'With deepest gratitude,', 'shelterkit-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
