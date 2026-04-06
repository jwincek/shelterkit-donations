<?php
/**
 * Contribution Tabs Block - Tabbed container for giving forms.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 */

declare( strict_types = 1 );

$tab_labels  = $attributes['tabLabels'] ?? [ 'Give a Gift', 'Become a Member', 'Honor a Loved One' ];
$tab_icons   = $attributes['tabIcons'] ?? [ 'heart', 'groups', 'format-quote' ];
$default_tab = $attributes['defaultTab'] ?? 0;
$block_id    = wp_unique_id( 'sd-tabs-' );

// Map dashicon names to SVG paths for lightweight rendering.
$icon_svgs = [
	'heart'        => 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z',
	'groups'       => 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
	'format-quote' => 'M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z',
];

wp_interactivity_state( 'starter-shelter/contribution-tabs', [
	'tabs' => [
		$block_id => [
			'activeTab' => $default_tab,
		],
	],
] );

$context = wp_json_encode( [
	'blockId'  => $block_id,
	'tabCount' => count( $tab_labels ),
] );

$wrapper = get_block_wrapper_attributes( [
	'class'               => 'sd-contribution-tabs',
	'id'                  => $block_id,
	'data-wp-interactive' => '{"namespace":"starter-shelter/contribution-tabs"}',
	'data-wp-context'     => $context,
] );

?>
<div <?php echo $wrapper; ?>>
	<div class="sd-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Contribution type', 'starter-shelter' ); ?>">
		<?php foreach ( $tab_labels as $i => $label ) :
			$is_active = ( $i === $default_tab );
			$icon_path = $icon_svgs[ $tab_icons[ $i ] ?? '' ] ?? '';
		?>
		<button type="button" role="tab" class="sd-tab-button <?php echo $is_active ? 'sd-tab-active' : ''; ?>"
			id="<?php echo esc_attr( $block_id ); ?>-tab-<?php echo $i; ?>"
			aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
			aria-controls="<?php echo esc_attr( $block_id ); ?>-panel-<?php echo $i; ?>"
			data-wp-on--click="actions.selectTab"
			data-wp-class--sd-tab-active="callbacks.isActiveTab"
			data-wp-bind--aria-selected="callbacks.isActiveTab"
			data-wp-context='{"tabIndex":<?php echo $i; ?>}'>
			<?php if ( $icon_path ) : ?>
			<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" class="sd-tab-icon"><path d="<?php echo esc_attr( $icon_path ); ?>" fill="currentColor"/></svg>
			<?php endif; ?>
			<span><?php echo esc_html( $label ); ?></span>
		</button>
		<?php endforeach; ?>
	</div>

	<?php foreach ( $block->inner_blocks as $i => $inner_block ) :
		$is_active = ( $i === $default_tab );
		$panel_content = $inner_block->render();
	?>
	<div role="tabpanel" class="sd-tab-panel <?php echo $is_active ? 'sd-tab-panel-active' : ''; ?>"
		id="<?php echo esc_attr( $block_id ); ?>-panel-<?php echo $i; ?>"
		aria-labelledby="<?php echo esc_attr( $block_id ); ?>-tab-<?php echo $i; ?>"
		data-wp-class--sd-tab-panel-active="callbacks.isActiveTab"
		data-wp-context='{"tabIndex":<?php echo $i; ?>}'>
		<?php echo $panel_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php endforeach; ?>
</div>
