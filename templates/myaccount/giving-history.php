<?php
/**
 * My Account — Giving History template.
 *
 * Override from a theme at `shelter-donations/myaccount/giving-history.php`.
 * Runs in the plugin's WooCommerce namespace (see donor-dashboard.php).
 *
 * @package Starter_Shelter
 * @subpackage Templates
 *
 * @var array    $donations    Paginated donations { items, total }.
 * @var int[]    $years        Years with donations, for the filter.
 * @var int|null $current_year Currently filtered year.
 */

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;

$page_total = 0;
if ( ! empty( $donations['items'] ) ) {
    foreach ( $donations['items'] as $d ) {
        $page_total += (float) ( $d['amount'] ?? 0 );
    }
}
$total_pages  = (int) ceil( ( $donations['total'] ?? 0 ) / 10 );
$current_page = isset( $_GET['history-page'] ) ? absint( $_GET['history-page'] ) : 1;
?>
<div class="sd-giving-history">
    <?php if ( ! empty( $years ) ) : ?>
    <form method="get" class="sd-year-filter">
        <label for="year"><?php esc_html_e( 'Filter by Year:', 'shelterkit-donations' ); ?></label>
        <select name="year" id="year" onchange="this.form.submit()">
            <option value=""><?php esc_html_e( 'All Years', 'shelterkit-donations' ); ?></option>
            <?php foreach ( $years as $y ) : ?>
            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $current_year, $y ); ?>><?php echo esc_html( $y ); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php
    if ( empty( $donations['items'] ) ) :
        $donate_url = Helpers\get_donation_page_url();
        ?>
    <p><?php esc_html_e( 'No donations found.', 'shelterkit-donations' ); ?></p>
		<?php if ( $donate_url ) : ?>
    <a href="<?php echo esc_url( $donate_url ); ?>" class="button"><?php esc_html_e( 'Make a Donation', 'shelterkit-donations' ); ?></a>
    <?php endif; ?>
    <?php else : ?>
    <table class="sd-donations-table woocommerce-orders-table">
        <thead><tr><th><?php esc_html_e( 'Date', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Amount', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Allocation', 'shelterkit-donations' ); ?></th></tr></thead>
        <tbody>
            <?php foreach ( $donations['items'] as $donation ) : ?>
            <tr>
                <td><?php echo esc_html( Helpers\format_date( $donation['donation_date'] ) ); ?></td>
                <td><?php echo esc_html( $donation['amount_formatted'] ); ?></td>
                <td><?php echo esc_html( $donation['allocation_label'] ?? $donation['allocation'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="sd-page-total">
                <td><strong><?php esc_html_e( 'Page Total', 'shelterkit-donations' ); ?></strong></td>
                <td><strong><?php echo esc_html( Helpers\format_currency( $page_total ) ); ?></strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

		<?php if ( $total_pages > 1 ) : ?>
    <nav class="sd-pagination">
			<?php if ( $current_page > 1 ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'history-page', $current_page - 1 ) ); ?>" class="sd-page-link">
                ← <?php esc_html_e( 'Previous', 'shelterkit-donations' ); ?>
            </a>
        <?php endif; ?>

        <span class="sd-page-info">
            <?php
            printf(
                /* translators: 1: current page number, 2: total pages, 3: total item count */
                esc_html__( 'Page %1$d of %2$d (%3$d total)', 'shelterkit-donations' ),
                (int) $current_page,
                (int) $total_pages,
                (int) ( $donations['total'] ?? 0 )
            );
            ?>
        </span>

			<?php if ( $current_page < $total_pages ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'history-page', $current_page + 1 ) ); ?>" class="sd-page-link">
                <?php esc_html_e( 'Next', 'shelterkit-donations' ); ?> →
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</div>
