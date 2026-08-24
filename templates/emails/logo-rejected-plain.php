<?php
/**
 * Business Logo Rejected Email Template (Plain Text)
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$membership       = $data['membership'] ?? [];
$donor            = $data['donor'] ?? [];
$business_name    = $membership['business_name'] ?? __( 'Your business', 'shelterkit-donations' );
$rejection_reason = $args['reason'] ?? __( 'The logo did not meet our display requirements.', 'shelterkit-donations' );

echo '= ' . esc_html( $heading ) . " =\n\n";

printf(
    /* translators: %s: recipient name */
    esc_html__( 'Dear %s,', 'shelterkit-donations' ),
    esc_html( $donor['first_name'] ?? $donor['full_name'] ?? __( 'Valued Member', 'shelterkit-donations' ) )
);
echo "\n\n";

printf(
    /* translators: %s: business name */
    esc_html__( 'Thank you for submitting your logo for %s. Unfortunately, we were unable to approve the logo in its current form.', 'shelterkit-donations' ),
    esc_html( $business_name )
);
echo "\n\n";

echo esc_html__( 'REASON:', 'shelterkit-donations' ) . "\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain email body; wp_strip_all_tags() removes markup and esc_html() would wrongly entity-encode plain text.
echo wp_strip_all_tags( $rejection_reason ) . "\n\n";

echo esc_html__( 'To update your logo, please ensure it meets the following requirements:', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'High resolution (minimum 300x300 pixels)', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'PNG or JPG format with transparent or white background preferred', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'Clear, legible design without offensive content', 'shelterkit-donations' ) . "\n";
echo '- ' . esc_html__( 'You must have rights to use the logo', 'shelterkit-donations' ) . "\n\n";

echo esc_html__( 'You can upload a new logo through your My Account page, or reply to this email with an updated version attached.', 'shelterkit-donations' ) . "\n\n";

echo esc_html__( 'If you have any questions or need assistance, please don\'t hesitate to contact us.', 'shelterkit-donations' ) . "\n\n";

echo esc_html__( 'Best regards,', 'shelterkit-donations' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-set footer text emitted in a text/plain email, mirroring WooCommerce's own plain email footer; same filtered value WC core prints.
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
