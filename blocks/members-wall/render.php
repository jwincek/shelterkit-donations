<?php
/**
 * Members Wall — server-side render.
 *
 * Static, tiered recognition wall of active members who have not opted out
 * of public recognition. Pure SSR (no Interactivity): the set is finite
 * and there's no filtering/rotation, so it renders once. Members are
 * grouped into tier sections ordered highest-first.
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

namespace Starter_Shelter\Blocks\MembersWall;

$group_by_tier = $attributes['groupByTier'] ?? true;
$show_logos    = $attributes['showLogos'] ?? true;
$columns       = max( 1, min( 8, (int) ( $attributes['columns'] ?? 4 ) ) );
$max_items     = max( 1, (int) ( $attributes['maxItems'] ?? 200 ) );

// ─── Fetch eligible members via the ability ──────────────────────────
$ability = function_exists( 'wp_get_ability' )
    ? wp_get_ability( 'shelter-memberships/recognition-wall' )
    : null;

$result = $ability ? $ability->execute( [ 'limit' => $max_items ] ) : null;

if ( ! $result || is_wp_error( $result ) ) {
    $result = \Starter_Shelter\Abilities\Memberships\recognition_wall( [ 'limit' => $max_items ] );
}

$items = $result['items'] ?? [];

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => 'sd-members-wall',
    'style' => '--sd-mw-columns: ' . $columns,
] );

// ─── Empty state ─────────────────────────────────────────────────────
if ( empty( $items ) ) {
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        echo '<div ' . $wrapper_attributes . '><p class="sd-mw-empty">'
            . esc_html__( 'No members to recognize yet (active members who haven\'t opted out will appear here).', 'starter-shelter' )
            . '</p></div>';
    }
    return;
}

// Group into tier sections (items arrive sorted by tier_rank DESC, then
// name, so equal-tier members are already adjacent). When grouping is off,
// use a single unlabeled group.
$groups = [];
foreach ( $items as $item ) {
    $key = $group_by_tier ? (string) ( $item['tier_label'] ?? '' ) : '_all';
    if ( ! isset( $groups[ $key ] ) ) {
        $groups[ $key ] = [];
    }
    $groups[ $key ][] = $item;
}

/**
 * Render a single member tile (logo for business when enabled, else name),
 * linked to the business website when present.
 */
$render_tile = static function ( array $item ) use ( $show_logos ): void {
    $name    = (string) ( $item['display_name'] ?? '' );
    $logo    = (string) ( $item['logo_url'] ?? '' );
    $website = (string) ( $item['business_website'] ?? '' );
    $has_logo = $show_logos && '' !== $logo;

    $open  = '' !== $website
        ? '<a class="sd-mw-tile" href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer">'
        : '<div class="sd-mw-tile">';
    $close = '' !== $website ? '</a>' : '</div>';

    echo $open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url above.

    if ( $has_logo ) {
        printf(
            '<img class="sd-mw-logo" src="%s" alt="%s" loading="lazy">',
            esc_url( $logo ),
            esc_attr( $name )
        );
    } else {
        printf( '<span class="sd-mw-name">%s</span>', esc_html( $name ) );
    }

    echo $close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup.
};
?>

<div <?php echo $wrapper_attributes; ?>>
    <?php foreach ( $groups as $label => $members ) :
        $has_title = $group_by_tier && '' !== $label;
        $title_id  = $has_title ? 'sd-mw-tier-' . sanitize_title( (string) $label ) : '';
    ?>
    <section class="sd-mw-tier"<?php echo $title_id ? ' aria-labelledby="' . esc_attr( $title_id ) . '"' : ''; ?>>
        <?php if ( $has_title ) : ?>
        <h3 class="sd-mw-tier-title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $label ); ?></h3>
        <?php endif; ?>
        <ul class="sd-mw-grid" role="list">
            <?php foreach ( $members as $item ) : ?>
            <li class="sd-mw-item">
                <?php $render_tile( $item ); ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
</div>
