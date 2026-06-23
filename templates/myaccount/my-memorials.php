<?php
/**
 * My Account — My Memorials template.
 *
 * Override from a theme at `starter-shelter/myaccount/my-memorials.php`.
 * Runs in the plugin's WooCommerce namespace (see donor-dashboard.php).
 *
 * @package Starter_Shelter
 * @subpackage Templates
 *
 * @var array $memorials Paginated memorials { items, total }.
 */

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;
?>
<div class="sd-my-memorials">
    <?php if ( empty( $memorials['items'] ) ) : ?>
    <p><?php esc_html_e( 'You haven\'t created any memorial tributes yet.', 'starter-shelter' ); ?></p>
    <?php else : ?>
    <div class="sd-memorials-grid">
        <?php foreach ( $memorials['items'] as $memorial ) :
            $type_label = Helpers\get_memorial_type_label( $memorial['memorial_type'] ?? '' );
            $tribute    = $memorial['tribute_message'] ?? '';
            $excerpt    = $tribute ? wp_trim_words( $tribute, 15 ) : '';
            $photo_url  = ! empty( $memorial['photo_url'] ) ? $memorial['photo_url'] : '';
        ?>
        <div class="sd-memorial-card">
            <?php if ( $photo_url ) : ?>
            <div class="sd-memorial-photo">
                <img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $memorial['honoree_name'] ); ?>" />
            </div>
            <?php endif; ?>
            <div class="sd-memorial-content">
                <span class="sd-memorial-type-badge"><?php echo esc_html( $type_label ); ?></span>
                <h4><?php echo esc_html( $memorial['honoree_name'] ); ?></h4>
                <?php if ( $excerpt ) : ?>
                <p class="sd-memorial-excerpt">"<?php echo esc_html( $excerpt ); ?>"</p>
                <?php endif; ?>
                <div class="sd-memorial-meta">
                    <span><?php echo esc_html( Helpers\format_date( $memorial['donation_date'] ) ); ?></span>
                    <span><?php echo esc_html( $memorial['amount_formatted'] ?? '' ); ?></span>
                </div>
                <a href="<?php echo esc_url( get_permalink( $memorial['id'] ) ); ?>" class="sd-memorial-link"><?php esc_html_e( 'View Memorial →', 'starter-shelter' ); ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
    $mem_total_pages = (int) ceil( ( $memorials['total'] ?? 0 ) / 12 );
    $mem_current_page = isset( $_GET['memorial-page'] ) ? absint( $_GET['memorial-page'] ) : 1;
    if ( $mem_total_pages > 1 ) : ?>
    <nav class="sd-pagination">
        <?php if ( $mem_current_page > 1 ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'memorial-page', $mem_current_page - 1 ) ); ?>" class="sd-page-link">← <?php esc_html_e( 'Previous', 'starter-shelter' ); ?></a>
        <?php endif; ?>
        <span class="sd-page-info"><?php printf( /* translators: 1: current page number, 2: total pages */ esc_html__( 'Page %1$d of %2$d', 'starter-shelter' ), $mem_current_page, $mem_total_pages ); ?></span>
        <?php if ( $mem_current_page < $mem_total_pages ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'memorial-page', $mem_current_page + 1 ) ); ?>" class="sd-page-link"><?php esc_html_e( 'Next', 'starter-shelter' ); ?> →</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</div>
