<?php
/**
 * Business Logo Rejected Email Template
 *
 * @package Starter_Shelter
 * @since 1.0.0
 *
 * @var Starter_Shelter\Emails\Config_Email $email      The email instance.
 * @var string                              $email_id   The email ID.
 * @var string                              $heading    The email heading.
 * @var array                               $data       Entity data (membership, donor).
 * @var array                               $args       Raw trigger arguments.
 * @var bool                                $plain_text Whether plain text version.
 */

defined( 'ABSPATH' ) || exit;

$membership       = $data['membership'] ?? [];
$donor            = $data['donor'] ?? [];
$business_name    = $membership['business_name'] ?? __( 'Your business', 'shelter-donations' );
$rejection_reason = $args['reason'] ?? __( 'The logo did not meet our display requirements.', 'shelter-donations' );

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: recipient name */
        esc_html__( 'Dear %s,', 'shelter-donations' ),
        esc_html( $donor['first_name'] ?? $donor['full_name'] ?? __( 'Valued Member', 'shelter-donations' ) )
    );
    ?>
</p>

<p>
    <?php
    printf(
        /* translators: %s: business name */
        esc_html__( 'Thank you for submitting your logo for %s. Unfortunately, we were unable to approve the logo in its current form.', 'shelter-donations' ),
        '<strong>' . esc_html( $business_name ) . '</strong>'
    );
    ?>
</p>

<div style="background: #f8f8f8; border-left: 4px solid #dba617; padding: 15px; margin: 20px 0;">
    <strong><?php esc_html_e( 'Reason:', 'shelter-donations' ); ?></strong><br>
    <?php echo wp_kses_post( $rejection_reason ); ?>
</div>

<p>
    <?php esc_html_e( 'To update your logo, please ensure it meets the following requirements:', 'shelter-donations' ); ?>
</p>

<ul>
    <li><?php esc_html_e( 'High resolution (minimum 300x300 pixels)', 'shelter-donations' ); ?></li>
    <li><?php esc_html_e( 'PNG or JPG format with transparent or white background preferred', 'shelter-donations' ); ?></li>
    <li><?php esc_html_e( 'Clear, legible design without offensive content', 'shelter-donations' ); ?></li>
    <li><?php esc_html_e( 'You must have rights to use the logo', 'shelter-donations' ); ?></li>
</ul>

<p>
    <?php esc_html_e( 'You can upload a new logo through your My Account page, or reply to this email with an updated version attached.', 'shelter-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'If you have any questions or need assistance, please don\'t hesitate to contact us.', 'shelter-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'Best regards,', 'shelter-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
