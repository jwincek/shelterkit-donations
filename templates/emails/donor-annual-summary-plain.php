<?php
/**
 * Annual giving summary email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$donor = $data['donor'] ?? [];
$summary = $args['summary'] ?? [];
$year = $args['year'] ?? gmdate( 'Y' );

echo '= ' . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelterkit-donations' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'shelterkit-donations' ) )
);
echo "\n\n";

printf(
    /* translators: 1: year, 2: site name */
    esc_html__( 'Thank you for your generous support of %2$s throughout %1$d. Below is a summary of your charitable contributions for your tax records.', 'shelterkit-donations' ),
    (int) $year,
    esc_html( get_bloginfo( 'name' ) )
);
echo "\n\n";

echo "════════════════════════════════════════\n";
printf( /* translators: %d: year */ esc_html__( '%d ANNUAL GIVING SUMMARY', 'shelterkit-donations' ), (int) $year );
echo "\n════════════════════════════════════════\n\n";

echo esc_html__( 'Donations:', 'shelterkit-donations' ) . ' ' . esc_html( $summary['donations']['formatted'] ?? '$0.00' );
echo ' (' . esc_html( $summary['donations']['count'] ?? 0 ) . ' ' . esc_html__( 'gifts', 'shelterkit-donations' ) . ")\n";

echo esc_html__( 'Memorial Tributes:', 'shelterkit-donations' ) . ' ' . esc_html( $summary['memorials']['formatted'] ?? '$0.00' );
echo ' (' . esc_html( $summary['memorials']['count'] ?? 0 ) . ' ' . esc_html__( 'tributes', 'shelterkit-donations' ) . ")\n";

echo esc_html__( 'Memberships:', 'shelterkit-donations' ) . ' ' . esc_html( $summary['memberships']['formatted'] ?? '$0.00' ) . "\n\n";

echo "----------------------------------------\n";
echo esc_html__( 'TOTAL TAX-DEDUCTIBLE AMOUNT:', 'shelterkit-donations' ) . ' ' . esc_html( $summary['grand_formatted'] ?? '$0.00' ) . "\n";
echo "----------------------------------------\n\n";

if ( ! empty( $summary['donations']['by_allocation'] ) ) {
    echo '= ' . esc_html__( 'Donations by Purpose', 'shelterkit-donations' ) . " =\n\n";
    foreach ( $summary['donations']['by_allocation'] as $allocation => $amount ) {
        echo esc_html( Helpers\get_allocation_label( $allocation ) ) . ': ' . esc_html( Helpers\format_currency( $amount ) ) . "\n";
    }
    echo "\n";
}

echo esc_html__( 'TAX INFORMATION:', 'shelterkit-donations' ) . "\n";
echo esc_html__( 'No goods or services were provided in exchange for these contributions. Your donations are tax-deductible to the extent allowed by law.', 'shelterkit-donations' ) . "\n";
$sd_tax_id = \Starter_Shelter\Helpers\starter_shelter_tax_id();
if ( '' !== $sd_tax_id ) {
    printf(
        /* translators: %s: the shelter's tax identification number */
        esc_html__( 'Our Tax ID (EIN): %s', 'shelterkit-donations' ),
        esc_html( $sd_tax_id )
    );
    echo "\n";
}
echo "\n";

printf(
    /* translators: %s: giving history URL */
    esc_html__( 'View your complete giving history: %s', 'shelterkit-donations' ),
    esc_url( wc_get_account_endpoint_url( 'annual-statement' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for your continued support!', 'shelterkit-donations' );
echo "\n\n";

echo esc_html__( 'With sincere gratitude,', 'shelterkit-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n\n";

printf(
    /* translators: %s: generation date */
    esc_html__( 'This statement was generated on %s.', 'shelterkit-donations' ),
    esc_html( wp_date( get_option( 'date_format' ) ) )
);
echo "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
