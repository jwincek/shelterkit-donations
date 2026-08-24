<?php
/**
 * Shared partial: Anonymous donation/membership toggle.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 *
 * @var string $form_id   The form instance ID.
 * @var string $namespace The Interactivity API store namespace.
 * @var string $label     The checkbox label text.
 */

defined( 'ABSPATH' ) || exit;

$label = $label ?? __( 'Make my donation anonymous', 'shelterkit-donations' );
?>
<div class="sd-form-section sd-anonymous-section">
	<label class="sd-checkbox-label">
		<input type="checkbox" class="sd-checkbox"
			data-wp-on--change="actions.toggleAnonymous"
			data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].isAnonymous">
		<span class="sd-checkbox-custom">
			<svg class="sd-checkbox-icon" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg>
		</span>
		<span class="sd-checkbox-text"><?php echo esc_html( $label ); ?></span>
	</label>
	<p class="sd-anonymous-explainer sd-collapsed"
		data-wp-class--sd-collapsed="!callbacks.isAnonymousExplainer"
		aria-live="polite">
		<?php esc_html_e( 'Your name will not be displayed publicly.', 'shelterkit-donations' ); ?>
	</p>
</div>
