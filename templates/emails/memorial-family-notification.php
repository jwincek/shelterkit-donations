<?php
/**
 * Memorial family notification email template.
 *
 * Sent to the honoree's family when the donor asked for them to be told: at
 * checkout, or by staff (Notify Family, Quick Add). Worded for a gift in
 * memory of someone or in honor of someone living.
 * Override by copying to yourtheme/shelter-donations/emails/memorial-family-notification.php
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

$memorial = $data['memorial'] ?? [];
$donor = $data['donor'] ?? [];
$notify_family = isset( $memorial['id'] )
    ? Helpers\get_memorial_notify_family( (int) $memorial['id'] )
    : [ 'enabled' => false, 'name' => '', 'email' => '', 'address' => '', 'send_card' => false ];

$family_name  = '' !== $notify_family['name'] ? $notify_family['name'] : __( 'Friend', 'shelterkit-donations' );
$is_anonymous = ! empty( $memorial['is_anonymous'] );
$in_honor     = 'honor' === ( $memorial['dedication_type'] ?? 'memory' );
$honoree      = '<strong>' . esc_html( $memorial['honoree_name'] ?? '' ) . '</strong>';
$site         = esc_html( get_bloginfo( 'name' ) );

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: recipient name */
        esc_html__( 'Dear %s,', 'shelterkit-donations' ),
        esc_html( $family_name )
    );
    ?>
</p>

<p>
    <?php
    if ( $is_anonymous ) {
        printf(
            $in_honor
                /* translators: 1: honoree name, 2: site name */
                ? esc_html__( 'We wanted to let you know that a generous donor has made a gift to %2$s in honor of %1$s.', 'shelterkit-donations' )
                /* translators: 1: honoree name, 2: site name */
                : esc_html__( 'We wanted to let you know that a generous donor has made a memorial donation to %2$s in loving memory of %1$s.', 'shelterkit-donations' ),
            $honoree, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above, inside <strong>.
            $site // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        );
    } else {
        printf(
            $in_honor
                /* translators: 1: donor name, 2: site name, 3: honoree name */
                ? esc_html__( '%1$s has made a gift to %2$s in honor of %3$s.', 'shelterkit-donations' )
                /* translators: 1: donor name, 2: site name, 3: honoree name */
                : esc_html__( '%1$s has made a memorial donation to %2$s in loving memory of %3$s.', 'shelterkit-donations' ),
            '<strong>' . esc_html( $memorial['donor_name'] ?? '' ) . '</strong>',
            $site, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            $honoree // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above, inside <strong>.
        );
    }
    ?>
</p>

<div style="background-color: #f8f9fa; padding: 25px; margin: 25px 0; border-radius: 8px; text-align: center;">
    <p style="font-size: 1.2em; margin-bottom: 10px;">
        <?php echo $in_honor ? esc_html__( 'In Honor Of', 'shelterkit-donations' ) : esc_html__( 'In Loving Memory Of', 'shelterkit-donations' ); ?>
    </p>
    <h2 style="font-size: 1.8em; margin: 10px 0; color: #333;">
        <?php echo esc_html( $memorial['honoree_name'] ?? '' ); ?>
    </h2>
    <?php if ( ! empty( $memorial['memorial_type'] ) ) : ?>
    <p style="color: #666; font-style: italic;">
        <?php echo esc_html( Helpers\get_memorial_type_label( $memorial['memorial_type'] ) ); ?>
    </p>
    <?php endif; ?>
</div>

<?php if ( ! empty( $memorial['tribute_message'] ) ) : ?>
<h3><?php esc_html_e( 'A Message from the Donor', 'shelterkit-donations' ); ?></h3>

<blockquote style="border-left: 4px solid #6c757d; padding-left: 20px; margin: 20px 0; font-style: italic; color: #495057; font-size: 1.1em; line-height: 1.6;">
    "<?php echo esc_html( $memorial['tribute_message'] ); ?>"
</blockquote>
<?php endif; ?>

<p>
    <?php
    echo $in_honor
        ? esc_html__( 'This thoughtful gift will help us provide care and comfort to animals in need.', 'shelterkit-donations' )
        : esc_html__( 'This thoughtful gift will help us provide care and comfort to animals in need, creating a meaningful tribute to your loved one\'s memory.', 'shelterkit-donations' );
    ?>
</p>

<?php if ( ! empty( $memorial['id'] ) ) : ?>
<p style="background-color: #e7f3fe; padding: 15px; border-left: 4px solid #2196F3;">
    <?php esc_html_e( 'You can view the memorial tribute page here:', 'shelterkit-donations' ); ?><br>
    <a href="<?php echo esc_url( get_permalink( $memorial['id'] ) ); ?>"><?php echo esc_url( get_permalink( $memorial['id'] ) ); ?></a>
</p>
<?php endif; ?>

<p>
    <?php
    echo $in_honor
        ? esc_html__( 'We are delighted to be part of this tribute.', 'shelterkit-donations' )
        : esc_html__( 'We are deeply honored to be part of this tribute and send our sincere condolences to you and your family.', 'shelterkit-donations' );
    ?>
</p>

<p>
    <?php echo $in_honor ? esc_html__( 'With warm regards,', 'shelterkit-donations' ) : esc_html__( 'With heartfelt sympathy,', 'shelterkit-donations' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'starter_shelter_memorial_family_notification_email_footer', $memorial, $donor, $email );
do_action( 'woocommerce_email_footer', $email );
