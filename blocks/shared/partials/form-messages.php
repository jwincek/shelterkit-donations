<?php
/**
 * Shared partial: Form success and error message containers.
 *
 * Provides accessible, consistent messaging for all shelter form blocks.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 *
 * @var string $form_id   The form instance ID.
 * @var string $namespace The Interactivity API store namespace.
 * @var string $checkout_url URL to the checkout page.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sd-form-success" role="status" aria-live="polite"
	data-wp-class--sd-form-success-visible="state.forms['<?php echo esc_attr( $form_id ); ?>'].showSuccess">
	<svg viewBox="0 0 24 24" width="24" height="24" class="sd-success-icon" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="currentColor"/></svg>
	<p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].success"></p>
	<a href="<?php echo esc_url( $checkout_url ); ?>" class="sd-checkout-link wp-element-button"><?php esc_html_e( 'Proceed to Checkout', 'starter-shelter' ); ?></a>
</div>
<div class="sd-form-error" role="alert" aria-live="assertive"
	data-wp-class--sd-form-error-visible="state.forms['<?php echo esc_attr( $form_id ); ?>'].error">
	<p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].error"></p>
</div>
