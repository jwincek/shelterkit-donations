<?php
/**
 * Business Member Slideshow — server-side render.
 *
 * Renders all eligible business members (active + approved logo) up front
 * and rotates them client-side via a CSS transform on the track, so there
 * is no fetch/loading state. The Interactivity store (view.js) only moves
 * an index; off-screen slides are `inert` so they stay out of the tab
 * order and the accessibility tree.
 *
 * @package    Starter_Shelter
 * @subpackage Blocks
 * @since      2.2.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Blocks\BusinessSlideshow;

$show_name = $attributes['showName'] ?? true;
$autoplay  = $attributes['autoplay'] ?? true;
$interval  = max( 1500, (int) ( $attributes['intervalMs'] ?? 5000 ) );
$max_items = max( 1, (int) ( $attributes['maxItems'] ?? 50 ) );

// ─── Fetch eligible business members via the ability ─────────────────
$ability = function_exists( 'wp_get_ability' )
    ? wp_get_ability( 'shelter-memberships/business-directory' )
    : null;

$result = $ability ? $ability->execute( [ 'limit' => $max_items ] ) : null;

if ( ! $result || is_wp_error( $result ) ) {
    // Fallback for environments without the Abilities API.
    $result = \Starter_Shelter\Abilities\Memberships\business_directory( [ 'limit' => $max_items ] );
}

$items = $result['items'] ?? [];
$count = count( $items );

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'sd-business-slideshow' ] );

// ─── Empty state ─────────────────────────────────────────────────────
if ( 0 === $count ) {
    // Render nothing on the front end; show a hint in the editor so the
    // block isn't invisible while authoring.
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        echo '<div ' . $wrapper_attributes . '><p class="sd-bs-empty">'
            . esc_html__( 'No business members with an approved logo yet.', 'starter-shelter' )
            . '</p></div>';
    }
    return;
}

$has_controls = $count > 1;

$context = [
    'index'      => 0,
    'count'      => $count,
    'isPlaying'  => $autoplay && $has_controls,
    // Distinguishes an explicit pause (play/pause button) from a transient
    // hover/focus pause, so leaving the block doesn't override the user.
    'userPaused' => false,
    'config'     => [
        'intervalMs' => $interval,
        'autoplay'   => (bool) $autoplay,
    ],
];
?>

<div
    <?php echo $wrapper_attributes; ?>
    data-wp-interactive="starter-shelter/business-slideshow"
    <?php echo wp_interactivity_data_wp_context( $context ); ?>
    <?php if ( $has_controls ) : ?>
    data-wp-watch--autoplay="callbacks.autoplay"
    data-wp-on--mouseenter="actions.pause"
    data-wp-on--mouseleave="actions.resume"
    data-wp-on--focusin="actions.pause"
    data-wp-on--focusout="actions.resume"
    <?php endif; ?>
    role="group"
    aria-roledescription="<?php esc_attr_e( 'carousel', 'starter-shelter' ); ?>"
    aria-label="<?php esc_attr_e( 'Business members', 'starter-shelter' ); ?>"
>
    <div class="sd-bs-viewport">
        <div class="sd-bs-track" data-wp-style--transform="state.trackTransform">
            <?php foreach ( $items as $i => $item ) :
                $name    = (string) ( $item['business_name'] ?? '' );
                $logo    = (string) ( $item['logo_url'] ?? '' );
                $website = (string) ( $item['business_website'] ?? '' );
                ?>
            <div
                class="sd-bs-slide"
                <?php echo wp_interactivity_data_wp_context( [ 'slide' => $i ] ); ?>
                role="group"
                aria-roledescription="<?php esc_attr_e( 'slide', 'starter-shelter' ); ?>"
                aria-label="<?php echo esc_attr( sprintf( /* translators: 1: slide number, 2: total slides */ __( '%1$d of %2$d', 'starter-shelter' ), $i + 1, $count ) ); ?>"
                data-wp-bind--inert="state.isInactiveSlide"
                <?php echo 0 === $i ? '' : 'inert'; ?>
            >
                <?php if ( '' !== $website ) : ?>
                <a class="sd-bs-tile" href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer">
                <?php else : ?>
                <div class="sd-bs-tile">
                <?php endif; ?>

                    <?php if ( '' !== $logo ) : ?>
                    <img class="sd-bs-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy">
                    <?php endif; ?>

                    <?php if ( $show_name && '' !== $name ) : ?>
                    <span class="sd-bs-name"><?php echo esc_html( $name ); ?></span>
                    <?php endif; ?>

                <?php echo '' !== $website ? '</a>' : '</div>'; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ( $has_controls ) : ?>
    <div class="sd-bs-controls">
        <button
            type="button"
            class="sd-bs-arrow sd-bs-prev"
            data-wp-on--click="actions.prev"
            aria-label="<?php esc_attr_e( 'Previous business member', 'starter-shelter' ); ?>"
        >
            <span aria-hidden="true">&larr;</span>
        </button>

        <button
            type="button"
            class="sd-bs-play"
            data-wp-on--click="actions.togglePlay"
            data-wp-bind--aria-pressed="state.isPlaying"
            data-wp-bind--aria-label="state.playPauseLabel"
            aria-label="<?php esc_attr_e( 'Pause', 'starter-shelter' ); ?>"
        >
            <span data-wp-text="state.playPauseGlyph" aria-hidden="true">&#10073;&#10073;</span>
        </button>

        <button
            type="button"
            class="sd-bs-arrow sd-bs-next"
            data-wp-on--click="actions.next"
            aria-label="<?php esc_attr_e( 'Next business member', 'starter-shelter' ); ?>"
        >
            <span aria-hidden="true">&rarr;</span>
        </button>
    </div>

    <div class="sd-bs-dots" role="tablist" aria-label="<?php esc_attr_e( 'Choose business member', 'starter-shelter' ); ?>">
        <?php foreach ( $items as $i => $item ) : ?>
        <button
            type="button"
            class="sd-bs-dot<?php echo 0 === $i ? ' is-active' : ''; ?>"
            <?php echo wp_interactivity_data_wp_context( [ 'dot' => $i ] ); ?>
            data-wp-on--click="actions.goToDot"
            data-wp-class--is-active="state.isActiveDot"
            data-wp-bind--aria-selected="state.isActiveDot"
            aria-label="<?php echo esc_attr( sprintf( /* translators: %d: slide number */ __( 'Go to slide %d', 'starter-shelter' ), $i + 1 ) ); ?>"
        ></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
