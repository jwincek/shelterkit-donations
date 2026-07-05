<?php
/**
 * My Account — My Memberships template.
 *
 * Override from a theme at `shelter-donations/myaccount/my-memberships.php`.
 * Runs in the plugin's WooCommerce namespace (see donor-dashboard.php).
 *
 * @package Starter_Shelter
 * @subpackage Templates
 *
 * @var array $active_memberships  Hydrated active memberships.
 * @var array $expired_memberships Hydrated past memberships.
 * @var bool  $list_publicly       Whether the member is currently listed on the public wall.
 */

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;
?>
<div class="sd-my-memberships">
    <?php if ( ! empty( $active_memberships ) ) : ?>
    <div class="sd-recognition-pref">
        <h3><?php esc_html_e( 'Public Recognition', 'shelter-donations' ); ?></h3>
        <form method="post" class="sd-recognition-form">
            <?php wp_nonce_field( 'sd_recognition', 'sd_recognition_nonce' ); ?>
            <input type="hidden" name="sd_account_action" value="recognition">
            <label class="sd-recognition-toggle">
                <input type="checkbox" name="sd_list_publicly" value="1" <?php checked( ! empty( $list_publicly ) ); ?>>
                <?php esc_html_e( 'List me on the public Members Wall', 'shelter-donations' ); ?>
            </label>
            <p class="sd-recognition-help">
                <?php esc_html_e( 'When unchecked, your name and logo are hidden from the public members wall.', 'shelter-donations' ); ?>
            </p>
            <button type="submit" class="button"><?php esc_html_e( 'Save preference', 'shelter-donations' ); ?></button>
        </form>
    </div>

    <h3><?php esc_html_e( 'Active Memberships', 'shelter-donations' ); ?></h3>
    <?php foreach ( $active_memberships as $m ) :
        $m_days = (int) ( ( strtotime( $m['end_date'] ) - time() ) / DAY_IN_SECONDS );
        $m_expiring = $m_days >= 0 && $m_days <= 30;
    ?>
    <div class="sd-membership-card sd-active <?php echo $m_expiring ? 'sd-expiring' : ''; ?>">
        <div class="sd-membership-info">
            <h4><?php echo esc_html( $m['tier_label'] ?? $m['tier'] ); ?></h4>
            <p>
                <?php if ( $m_expiring ) : ?>
                    <span class="sd-expiry-warning">
                        <?php echo esc_html( sprintf(
                            /* translators: %d: number of days remaining */
                            _n( 'Expires in %d day', 'Expires in %d days', $m_days, 'shelter-donations' ),
                            $m_days
                        ) ); ?>
                    </span>
                <?php else : ?>
                    <?php echo esc_html( sprintf( /* translators: %s: expiration date */ __( 'Valid until: %s', 'shelter-donations' ), Helpers\format_date( $m['end_date'] ) ) ); ?>
                <?php endif; ?>
            </p>
            <?php if ( ! empty( $m['amount'] ) ) : ?>
            <p class="sd-membership-amount"><?php echo esc_html( Helpers\format_currency( (float) $m['amount'] ) ); ?>/<?php esc_html_e( 'year', 'shelter-donations' ); ?></p>
            <?php endif; ?>
        </div>
        <div class="sd-membership-actions">
            <?php
            $renew_url = Helpers\get_membership_page_url();
            if ( $m_expiring && $renew_url ) :
                ?>
            <a href="<?php echo esc_url( $renew_url ); ?>" class="button"><?php esc_html_e( 'Renew', 'shelter-donations' ); ?></a>
            <?php endif; ?>

            <form method="post" class="sd-auto-renew-form">
                <?php wp_nonce_field( 'sd_membership_action', 'sd_membership_nonce' ); ?>
                <input type="hidden" name="sd_account_action" value="toggle_auto_renew">
                <input type="hidden" name="membership_id" value="<?php echo esc_attr( (string) ( $m['id'] ?? 0 ) ); ?>">
                <label class="sd-auto-renew-toggle">
                    <input type="checkbox" name="auto_renew" value="1" <?php checked( ! empty( $m['auto_renew'] ) ); ?> onchange="this.form.submit()">
                    <?php esc_html_e( 'Automatic renewal', 'shelter-donations' ); ?>
                </label>
                <noscript><button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'shelter-donations' ); ?></button></noscript>
            </form>

            <form method="post" class="sd-cancel-form" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this membership? This cannot be undone.', 'shelter-donations' ) ); ?>');">
                <?php wp_nonce_field( 'sd_membership_action', 'sd_membership_nonce' ); ?>
                <input type="hidden" name="sd_account_action" value="cancel_membership">
                <input type="hidden" name="membership_id" value="<?php echo esc_attr( (string) ( $m['id'] ?? 0 ) ); ?>">
                <button type="submit" class="button button-small sd-cancel-btn"><?php esc_html_e( 'Cancel membership', 'shelter-donations' ); ?></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ( ! empty( $expired_memberships ) ) : ?>
    <h3><?php esc_html_e( 'Past Memberships', 'shelter-donations' ); ?></h3>
    <?php foreach ( $expired_memberships as $m ) : ?>
    <div class="sd-membership-card sd-expired">
        <div class="sd-membership-info">
            <h4><?php echo esc_html( $m['tier_label'] ?? $m['tier'] ); ?></h4>
            <p><?php echo esc_html( sprintf(
                /* translators: 1: membership start date, 2: membership end date */
                __( '%1$s — %2$s', 'shelter-donations' ),
                Helpers\format_date( $m['start_date'] ?? '' ),
                Helpers\format_date( $m['end_date'] ?? '' )
            ) ); ?></p>
        </div>
        <?php $rejoin_url = Helpers\get_membership_page_url(); ?>
        <?php if ( $rejoin_url ) : ?>
        <div class="sd-membership-actions">
            <a href="<?php echo esc_url( $rejoin_url ); ?>" class="button button-small"><?php esc_html_e( 'Rejoin', 'shelter-donations' ); ?></a>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ( empty( $active_memberships ) && empty( $expired_memberships ) ) :
        $join_url = Helpers\get_membership_page_url();
        ?>
    <p><?php esc_html_e( 'You don\'t have any memberships yet.', 'shelter-donations' ); ?></p>
    <?php if ( $join_url ) : ?>
    <a href="<?php echo esc_url( $join_url ); ?>" class="button"><?php esc_html_e( 'Become a Member', 'shelter-donations' ); ?></a>
    <?php endif; ?>
    <?php endif; ?>
</div>
