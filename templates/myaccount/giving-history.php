<?php
/**
 * My Account — Giving History template.
 *
 * Override from a theme at `starter-shelter/myaccount/giving-history.php`.
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
        <label for="year"><?php esc_html_e( 'Filter by Year:', 'starter-shelter' ); ?></label>
        <select name="year" id="year" onchange="this.form.submit()">
            <option value=""><?php esc_html_e( 'All Years', 'starter-shelter' ); ?></option>
            <?php foreach ( $years as $y ) : ?>
            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $current_year, $y ); ?>><?php echo esc_html( $y ); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if ( empty( $donations['items'] ) ) : ?>
    <p><?php esc_html_e( 'No donations found.', 'starter-shelter' ); ?></p>
    <?php else : ?>
    <table class="sd-donations-table woocommerce-orders-table">
        <thead><tr><th><?php esc_html_e( 'Date', 'starter-shelter' ); ?></th><th><?php esc_html_e( 'Amount', 'starter-shelter' ); ?></th><th><?php esc_html_e( 'Allocation', 'starter-shelter' ); ?></th></tr></thead>
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
                <td><strong><?php esc_html_e( 'Page Total', 'starter-shelter' ); ?></strong></td>
                <td><strong><?php echo esc_html( Helpers\format_currency( $page_total ) ); ?></strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <nav class="sd-pagination">
        <?php if ( $current_page > 1 ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'history-page', $current_page - 1 ) ); ?>" class="sd-page-link">
                ← <?php esc_html_e( 'Previous', 'starter-shelter' ); ?>
            </a>
        <?php endif; ?>

        <span class="sd-page-info">
            <?php printf(
                esc_html__( 'Page %1$d of %2$d (%3$d total)', 'starter-shelter' ),
                $current_page,
                $total_pages,
                $donations['total'] ?? 0
            ); ?>
        </span>

        <?php if ( $current_page < $total_pages ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'history-page', $current_page + 1 ) ); ?>" class="sd-page-link">
                <?php esc_html_e( 'Next', 'starter-shelter' ); ?> →
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</div>
