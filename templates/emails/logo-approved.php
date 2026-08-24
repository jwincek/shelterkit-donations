<?php
/**
 * Business Logo Approved Email Template
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

$membership   = $data['membership'] ?? [];
$donor        = $data['donor'] ?? [];
$business_name = $membership['business_name'] ?? __( 'Your business', 'shelterkit-donations' );

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: recipient name */
        esc_html__( 'Dear %s,', 'shelterkit-donations' ),
        esc_html( $donor['first_name'] ?? $donor['full_name'] ?? __( 'Valued Member', 'shelterkit-donations' ) )
    );
    ?>
</p>

<p>
    <?php
    printf(
        /* translators: %s: business name */
        esc_html__( 'Great news! The logo for %s has been approved and is now visible on our website.', 'shelterkit-donations' ),
        '<strong>' . esc_html( $business_name ) . '</strong>'
    );
    ?>
</p>

<p>
    <?php esc_html_e( 'Your business logo will appear on:', 'shelterkit-donations' ); ?>
</p>

<ul>
    <li><?php esc_html_e( 'Our Business Sponsors page', 'shelterkit-donations' ); ?></li>
    <li><?php esc_html_e( 'The Donor Wall (if applicable to your membership tier)', 'shelterkit-donations' ); ?></li>
    <li><?php esc_html_e( 'Our annual report and promotional materials', 'shelterkit-donations' ); ?></li>
</ul>

<p>
    <?php esc_html_e( 'Thank you for your generous support of our shelter and the animals in our care. Your business partnership makes a real difference!', 'shelterkit-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'If you have any questions about your business membership benefits, please don\'t hesitate to contact us.', 'shelterkit-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'With gratitude,', 'shelterkit-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
