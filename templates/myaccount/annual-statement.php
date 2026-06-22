<?php
/**
 * My Account — Annual Statement template.
 *
 * Override from a theme at `starter-shelter/myaccount/annual-statement.php`.
 * Runs in the plugin's WooCommerce namespace (see donor-dashboard.php).
 * The print-optimized receipt is a separate standalone document — see
 * My_Account::build_print_statement_html().
 *
 * @package Starter_Shelter
 * @subpackage Templates
 *
 * @var array  $summary   shelter-reports/annual-summary output.
 * @var int    $year      Selected year.
 * @var int[]  $years     Years with contributions.
 * @var string $print_url URL to the printable statement.
 */

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;
?>
<div class="sd-annual-statement">
    <div class="sd-statement-header">
        <form method="get" class="sd-year-selector">
            <label for="statement-year"><?php esc_html_e( 'Select Year:', 'starter-shelter' ); ?></label>
            <select name="year" id="statement-year" onchange="this.form.submit()">
                <?php foreach ( $years as $y ) : ?>
                <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="<?php echo esc_url( $print_url ); ?>" class="button" target="_blank" rel="noopener"><?php esc_html_e( 'Print / Save as PDF', 'starter-shelter' ); ?></a>
    </div>

    <div class="sd-statement-content">
        <h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
        <h3><?php esc_html_e( 'Charitable Contribution Statement', 'starter-shelter' ); ?></h3>
        <p><strong><?php echo esc_html( $summary['donor']['name'] ); ?></strong></p>
        <p><?php echo esc_html( sprintf( __( 'Year: %d', 'starter-shelter' ), $year ) ); ?></p>

        <table class="sd-statement-summary">
            <tr><td><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></td><td><?php echo esc_html( $summary['donations']['formatted'] ); ?></td></tr>
            <tr><td><?php esc_html_e( 'Memorials', 'starter-shelter' ); ?></td><td><?php echo esc_html( $summary['memorials']['formatted'] ); ?></td></tr>
            <tr><td><?php esc_html_e( 'Memberships', 'starter-shelter' ); ?></td><td><?php echo esc_html( $summary['memberships']['formatted'] ); ?></td></tr>
            <tr class="sd-total"><td><strong><?php esc_html_e( 'Total', 'starter-shelter' ); ?></strong></td><td><strong><?php echo esc_html( $summary['grand_formatted'] ); ?></strong></td></tr>
        </table>
    </div>
</div>
