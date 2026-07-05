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

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelter-donations' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'shelter-donations' ) )
);
echo "\n\n";

printf(
    /* translators: 1: year, 2: site name */
    esc_html__( 'Thank you for your generous support of %2$s throughout %1$d. Below is a summary of your charitable contributions for your tax records.', 'shelter-donations' ),
    (int) $year,
    esc_html( get_bloginfo( 'name' ) )
);
echo "\n\n";

echo "════════════════════════════════════════\n";
printf( /* translators: %d: year */ esc_html__( '%d ANNUAL GIVING SUMMARY', 'shelter-donations' ), (int) $year );
echo "\n════════════════════════════════════════\n\n";

echo esc_html__( 'Donations:', 'shelter-donations' ) . ' ' . esc_html( $summary['donations']['formatted'] ?? '$0.00' );
echo ' (' . esc_html( $summary['donations']['count'] ?? 0 ) . ' ' . esc_html__( 'gifts', 'shelter-donations' ) . ")\n";

echo esc_html__( 'Memorial Tributes:', 'shelter-donations' ) . ' ' . esc_html( $summary['memorials']['formatted'] ?? '$0.00' );
echo ' (' . esc_html( $summary['memorials']['count'] ?? 0 ) . ' ' . esc_html__( 'tributes', 'shelter-donations' ) . ")\n";

echo esc_html__( 'Memberships:', 'shelter-donations' ) . ' ' . esc_html( $summary['memberships']['formatted'] ?? '$0.00' ) . "\n\n";

echo "----------------------------------------\n";
echo esc_html__( 'TOTAL TAX-DEDUCTIBLE AMOUNT:', 'shelter-donations' ) . ' ' . esc_html( $summary['grand_formatted'] ?? '$0.00' ) . "\n";
echo "----------------------------------------\n\n";

if ( ! empty( $summary['donations']['by_allocation'] ) ) {
    echo "= " . esc_html__( 'Donations by Purpose', 'shelter-donations' ) . " =\n\n";
    foreach ( $summary['donations']['by_allocation'] as $allocation => $amount ) {
        echo esc_html( Helpers\get_allocation_label( $allocation ) ) . ': ' . esc_html( Helpers\format_currency( $amount ) ) . "\n";
    }
    echo "\n";
}

echo esc_html__( 'TAX INFORMATION:', 'shelter-donations' ) . "\n";
echo esc_html__( 'No goods or services were provided in exchange for these contributions. Your donations are tax-deductible to the extent allowed by law.', 'shelter-donations' ) . "\n";
printf(
    /* translators: %s: EIN number */
    esc_html__( 'Our Tax ID (EIN): %s', 'shelter-donations' ),
    esc_html( get_option( 'starter_shelter_ein', '[EIN Number]' ) )
);
echo "\n\n";

printf(
    /* translators: %s: giving history URL */
    esc_html__( 'View your complete giving history: %s', 'shelter-donations' ),
    esc_url( wc_get_account_endpoint_url( 'annual-statement' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for your continued support!', 'shelter-donations' );
echo "\n\n";

echo esc_html__( 'With sincere gratitude,', 'shelter-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n\n";

printf(
    /* translators: %s: generation date */
    esc_html__( 'This statement was generated on %s.', 'shelter-donations' ),
    esc_html( wp_date( get_option( 'date_format' ) ) )
);
echo "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
