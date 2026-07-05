<?php
/**
 * My Account — My Candles template.
 *
 * The memorials this member has lit a candle for (a personal "memorial
 * garden"). Candles are a permanent tribute, so this is a read-only list;
 * each row links to the memorial, where the candle can be toggled.
 *
 * Override from a theme at `shelter-donations/myaccount/my-candles.php`.
 * Runs in the plugin's WooCommerce namespace (see donor-dashboard.php).
 *
 * @package Starter_Shelter
 * @subpackage Templates
 *
 * @var array $memorials Hydrated sd_memorial entities the user has lit, each
 *                       with an added `permalink` key.
 */

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;
?>
<div class="sd-my-candles">
    <?php if ( empty( $memorials ) ) : ?>
    <div class="sd-candles-empty">
        <p><?php esc_html_e( 'You haven\'t lit any candles yet.', 'shelter-donations' ); ?></p>
        <p><?php esc_html_e( 'Light a candle on a memorial to remember a beloved companion.', 'shelter-donations' ); ?></p>
    </div>
    <?php else : ?>
    <ul class="sd-candles-list">
        <?php foreach ( $memorials as $memorial ) :
            $name        = $memorial['honoree_name'] ?? '';
            $dedication  = $memorial['dedication_type_label'] ?? '';
            $count       = (int) ( $memorial['candle_count'] ?? 0 );
            $permalink   = $memorial['permalink'] ?? '';
        ?>
        <li class="sd-candle-row">
            <span class="sd-candle-flame" aria-hidden="true">🕯️</span>
            <div class="sd-candle-body">
                <?php if ( $dedication ) : ?>
                <span class="sd-candle-dedication"><?php echo esc_html( $dedication ); ?></span>
                <?php endif; ?>
                <p class="sd-candle-name">
                    <?php if ( $permalink ) : ?>
                    <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a>
                    <?php else : ?>
                    <?php echo esc_html( $name ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <span class="sd-candle-count">
                <?php
                printf(
                    esc_html( /* translators: %d: number of candles lit */ _n( '%d candle lit', '%d candles lit', $count, 'shelter-donations' ) ),
                    (int) $count
                );
                ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
