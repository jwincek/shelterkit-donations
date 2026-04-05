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
<div class="sd-form-success" role="status" aria-live="polite" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].success">
	<p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].success"></p>
	<a href="<?php echo esc_url( $checkout_url ); ?>" class="sd-checkout-link wp-element-button"><?php esc_html_e( 'Proceed to Checkout', 'starter-shelter' ); ?></a>
</div>
<div class="sd-form-error" role="alert" aria-live="assertive" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].error">
	<p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].error"></p>
</div>
