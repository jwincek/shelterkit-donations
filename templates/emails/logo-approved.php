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
$business_name = $membership['business_name'] ?? __( 'Your business', 'shelter-donations' );

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
        esc_html__( 'Great news! The logo for %s has been approved and is now visible on our website.', 'shelter-donations' ),
        '<strong>' . esc_html( $business_name ) . '</strong>'
    );
    ?>
</p>

<p>
    <?php esc_html_e( 'Your business logo will appear on:', 'shelter-donations' ); ?>
</p>

<ul>
    <li><?php esc_html_e( 'Our Business Sponsors page', 'shelter-donations' ); ?></li>
    <li><?php esc_html_e( 'The Donor Wall (if applicable to your membership tier)', 'shelter-donations' ); ?></li>
    <li><?php esc_html_e( 'Our annual report and promotional materials', 'shelter-donations' ); ?></li>
</ul>

<p>
    <?php esc_html_e( 'Thank you for your generous support of our shelter and the animals in our care. Your business partnership makes a real difference!', 'shelter-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'If you have any questions about your business membership benefits, please don\'t hesitate to contact us.', 'shelter-donations' ); ?>
</p>

<p>
    <?php esc_html_e( 'With gratitude,', 'shelter-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
