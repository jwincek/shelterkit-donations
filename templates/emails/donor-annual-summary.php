<?php
/**
 * Annual giving summary email template.
 *
 * Override by copying to yourtheme/shelter-donations/emails/donor-annual-summary.php
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

use Starter_Shelter\Helpers;

$donor = $data['donor'] ?? [];
$summary = $args['summary'] ?? [];
$year = $args['year'] ?? gmdate( 'Y' );

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: recipient name */
        esc_html__( 'Dear %s,', 'shelterkit-donations' ),
        esc_html( $donor['first_name'] ?? __( 'Friend', 'shelterkit-donations' ) )
    );
    ?>
</p>

<p>
    <?php
    printf(
        /* translators: 1: year, 2: site name */
        esc_html__( 'Thank you for your generous support of %2$s throughout %1$d. Below is a summary of your charitable contributions for your tax records.', 'shelterkit-donations' ),
        (int) $year,
        esc_html( get_bloginfo( 'name' ) )
    );
    ?>
</p>

<div style="background-color: #f0f7f0; padding: 20px; border: 2px solid #28a745; margin: 25px 0;">
    <h2 style="margin-top: 0; text-align: center; color: #155724;">
        <?php printf( /* translators: %d: year */ esc_html__( '%d Annual Giving Summary', 'shelterkit-donations' ), (int) $year ); ?>
    </h2>

    <table style="width: 100%; border-collapse: collapse;">
        <tbody>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php esc_html_e( 'Donations', 'shelterkit-donations' ); ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">
                    <?php echo esc_html( $summary['donations']['formatted'] ?? '$0.00' ); ?>
                    <small style="color: #666;">(<?php echo esc_html( $summary['donations']['count'] ?? 0 ); ?> <?php esc_html_e( 'gifts', 'shelterkit-donations' ); ?>)</small>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php esc_html_e( 'Memorial Tributes', 'shelterkit-donations' ); ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">
                    <?php echo esc_html( $summary['memorials']['formatted'] ?? '$0.00' ); ?>
                    <small style="color: #666;">(<?php echo esc_html( $summary['memorials']['count'] ?? 0 ); ?> <?php esc_html_e( 'tributes', 'shelterkit-donations' ); ?>)</small>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;"><?php esc_html_e( 'Memberships', 'shelterkit-donations' ); ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">
                    <?php echo esc_html( $summary['memberships']['formatted'] ?? '$0.00' ); ?>
                </td>
            </tr>
            <tr style="font-weight: bold; font-size: 1.1em;">
                <td style="padding: 15px; border-top: 2px solid #155724;"><?php esc_html_e( 'Total Tax-Deductible Amount', 'shelterkit-donations' ); ?></td>
                <td style="padding: 15px; border-top: 2px solid #155724; text-align: right; color: #155724;">
                    <?php echo esc_html( $summary['grand_formatted'] ?? '$0.00' ); ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<?php if ( ! empty( $summary['by_allocation'] ) ) : ?>
<h3><?php esc_html_e( 'Donations by Purpose', 'shelterkit-donations' ); ?></h3>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <thead>
        <tr>
            <th style="text-align: left; padding: 10px; background-color: #f8f8f8;"><?php esc_html_e( 'Purpose', 'shelterkit-donations' ); ?></th>
            <th style="text-align: right; padding: 10px; background-color: #f8f8f8;"><?php esc_html_e( 'Amount', 'shelterkit-donations' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $summary['by_allocation'] as $allocation => $amount ) : ?>
        <tr>
            <td style="padding: 10px;"><?php echo esc_html( Helpers\get_allocation_label( $allocation ) ); ?></td>
            <td style="padding: 10px; text-align: right;"><?php echo esc_html( Helpers\format_currency( $amount ) ); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div style="background-color: #f8f9fa; padding: 15px; border: 1px solid #ddd; margin: 20px 0;">
    <p style="margin: 0; font-size: 0.9em; color: #666;">
        <strong><?php esc_html_e( 'Tax Information:', 'shelterkit-donations' ); ?></strong>
        <?php esc_html_e( 'No goods or services were provided in exchange for these contributions. Your donations are tax-deductible to the extent allowed by law.', 'shelterkit-donations' ); ?>
    </p>
    <?php $sd_tax_id = \Starter_Shelter\Helpers\starter_shelter_tax_id(); ?>
    <?php if ( '' !== $sd_tax_id ) : ?>
    <p style="margin: 10px 0 0; font-size: 0.9em; color: #666;">
        <?php
        printf(
            /* translators: %s: the shelter's tax identification number */
            esc_html__( 'Our Tax ID (EIN): %s', 'shelterkit-donations' ),
            esc_html( $sd_tax_id )
        );
        ?>
    </p>
    <?php endif; ?>
</div>

<p>
    <?php
    printf(
        /* translators: %s: donor dashboard URL */
        esc_html__( 'You can view your complete giving history and download itemized statements at any time from your %s.', 'shelterkit-donations' ),
        '<a href="' . esc_url( wc_get_account_endpoint_url( 'annual-statement' ) ) . '">' . esc_html__( 'Donor Dashboard', 'shelterkit-donations' ) . '</a>'
    );
    ?>
</p>

<p>
    <?php esc_html_e( 'Thank you for your continued support. Together, we are making a difference in the lives of animals in need.', 'shelterkit-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'With sincere gratitude,', 'shelterkit-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<p style="font-size: 0.85em; color: #888; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
    <?php
    printf(
        /* translators: %s: generation date */
        esc_html__( 'This statement was generated on %s. Please retain for your records.', 'shelterkit-donations' ),
        esc_html( wp_date( get_option( 'date_format' ) ) )
    );
    ?>
</p>

<?php
do_action( 'starter_shelter_annual_summary_email_footer', $donor, $summary, $year, $email );
do_action( 'woocommerce_email_footer', $email );
