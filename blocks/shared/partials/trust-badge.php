<?php
/**
 * Shared partial: Secure payment trust badge.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 *
 * @var string $label Optional custom label text.
 */

defined( 'ABSPATH' ) || exit;

$label = $label ?? __( 'Secure payment powered by WooCommerce', 'shelter-donations' );
?>
<div class="sd-form-footer">
	<p class="sd-secure-notice">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
			<path d="M12 1C8.676 1 6 3.676 6 7v2H4v14h16V9h-2V7c0-3.324-2.676-6-6-6zm0 2c2.276 0 4 1.724 4 4v2H8V7c0-2.276 1.724-4 4-4z" fill="currentColor"/>
		</svg>
		<?php echo esc_html( $label ); ?>
	</p>
</div>
