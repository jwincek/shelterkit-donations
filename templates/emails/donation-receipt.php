<?php
/**
 * Donation receipt email template.
 *
 * Override by copying to yourtheme/shelter-donations/emails/donation-receipt.php
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 *
 * @var Starter_Shelter\Emails\Config_Email $email      Email instance.
 * @var string                              $heading    Email heading.
 * @var array                               $data       Entity data.
 * @var array                               $args       Trigger arguments.
 * @var bool                                $plain_text Whether plain text.
 */

defined( 'ABSPATH' ) || exit;

$donation = $data['donation'] ?? [];
$donor = $data['donor'] ?? [];

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: recipient name */
        esc_html__( 'Dear %s,', 'shelter-donations' ),
        esc_html( $donor['first_name'] ?? __( 'Friend', 'shelter-donations' ) )
    );
    ?>
</p>

<p>
    <?php esc_html_e( 'Thank you for your generous donation to support our animal shelter. Your contribution makes a real difference in the lives of the animals in our care.', 'shelter-donations' ); ?>
</p>

<h2><?php esc_html_e( 'Donation Details', 'shelter-donations' ); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <tbody>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Amount', 'shelter-donations' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $donation['amount_formatted'] ?? '' ); ?></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Date', 'shelter-donations' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $donation['date_formatted'] ?? '' ); ?></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Allocation', 'shelter-donations' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $donation['allocation_label'] ?? $donation['allocation'] ?? '' ); ?></td>
        </tr>
        <?php if ( ! empty( $donation['dedication'] ) ) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Dedication', 'shelter-donations' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $donation['dedication'] ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Reference', 'shelter-donations' ); ?></th>
            <td style="padding: 12px;">#<?php echo esc_html( $donation['id'] ?? '' ); ?></td>
        </tr>
    </tbody>
</table>

<p style="background-color: #f0f7f0; padding: 15px; border-left: 4px solid #28a745;">
    <?php esc_html_e( 'This donation is tax-deductible to the extent allowed by law. No goods or services were provided in exchange for this contribution.', 'shelter-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'Please keep this email as your receipt for tax purposes.', 'shelter-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'With gratitude,', 'shelter-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
/**
 * Hook: starter_shelter_donation_receipt_email_footer
 */
do_action( 'starter_shelter_donation_receipt_email_footer', $donation, $donor, $email );

do_action( 'woocommerce_email_footer', $email );
