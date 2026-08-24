<?php
/**
 * Membership renewal reminder email template.
 *
 * Override by copying to yourtheme/shelter-donations/emails/membership-renewal.php
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

$membership = $data['membership'] ?? [];
$donor = $data['donor'] ?? [];

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

<p style="background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;">
    <?php
    printf(
        /* translators: 1: tier label, 2: expiration date */
        esc_html__( 'Your %1$s membership will expire on %2$s.', 'shelterkit-donations' ),
        '<strong>' . esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ) . '</strong>',
        '<strong>' . esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ) . '</strong>'
    );
    ?>
</p>

<p>
    <?php esc_html_e( 'We hope you\'ve enjoyed being a member and experiencing the benefits of supporting our shelter. Your membership has helped us:', 'shelterkit-donations' ); ?>
</p>

<ul style="padding-left: 20px; margin-bottom: 20px;">
    <li><?php esc_html_e( 'Provide food and shelter for animals in need', 'shelterkit-donations' ); ?></li>
    <li><?php esc_html_e( 'Offer veterinary care and medical treatments', 'shelterkit-donations' ); ?></li>
    <li><?php esc_html_e( 'Find forever homes for our furry friends', 'shelterkit-donations' ); ?></li>
    <li><?php esc_html_e( 'Support community education and outreach programs', 'shelterkit-donations' ); ?></li>
</ul>

<h2><?php esc_html_e( 'Renew Your Membership', 'shelterkit-donations' ); ?></h2>

<p>
    <?php esc_html_e( 'Don\'t let your membership lapse! Renew today to continue enjoying your benefits and supporting the animals who depend on us.', 'shelterkit-donations' ); ?>
</p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url( home_url( '/membership/' ) ); ?>" style="display: inline-block; background-color: #28a745; color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
        <?php esc_html_e( 'Renew My Membership', 'shelterkit-donations' ); ?>
    </a>
</p>

<h2><?php esc_html_e( 'Current Membership Summary', 'shelterkit-donations' ); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <tbody>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Membership Level', 'shelterkit-donations' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ); ?></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Expiration Date', 'shelterkit-donations' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ); ?></td>
        </tr>
        <?php if ( ! empty( $membership['days_remaining'] ) ) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Days Remaining', 'shelterkit-donations' ); ?></th>
            <td style="padding: 12px;">
                <strong style="color: #dc3545;"><?php echo esc_html( $membership['days_remaining'] ); ?> <?php esc_html_e( 'days', 'shelterkit-donations' ); ?></strong>
            </td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<p>
    <?php esc_html_e( 'If you have any questions about your membership or would like to upgrade to a higher level, please don\'t hesitate to contact us.', 'shelterkit-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'Thank you for your continued support!', 'shelterkit-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'Warm regards,', 'shelterkit-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'starter_shelter_membership_renewal_email_footer', $membership, $donor, $email );
do_action( 'woocommerce_email_footer', $email );
