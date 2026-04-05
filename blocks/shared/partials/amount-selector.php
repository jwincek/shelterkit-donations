<?php
/**
 * Shared partial: Preset amount selector with custom input.
 *
 * Renders an accessible radiogroup of preset amounts and a custom amount input.
 * Used by donation and memorial form blocks.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 *
 * @var string $form_id        The form instance ID.
 * @var string $namespace      The Interactivity API store namespace.
 * @var array  $preset_amounts Array of preset amount values.
 * @var int    $min_amount     Minimum allowed amount.
 * @var int    $max_amount     Maximum allowed amount.
 * @var string $legend         Legend text for the fieldset.
 */

defined( 'ABSPATH' ) || exit;

$legend = $legend ?? __( 'Select Amount', 'starter-shelter' );
?>
<fieldset class="sd-form-section sd-amount-section">
	<legend class="sd-section-label"><?php echo esc_html( $legend ); ?></legend>
	<div class="sd-preset-amounts" role="radiogroup" aria-label="<?php echo esc_attr( $legend ); ?>">
		<?php foreach ( $preset_amounts as $amt ) : ?>
			<button type="button" role="radio" class="sd-amount-button"
				data-wp-on--click="actions.selectAmount"
				data-wp-class--selected="callbacks.isAmountSelected"
				data-wp-bind--aria-checked="callbacks.isAmountSelected"
				data-wp-context='{"buttonAmount":<?php echo (int) $amt; ?>}'>$<?php echo number_format( (int) $amt ); ?></button>
		<?php endforeach; ?>
	</div>
	<div class="sd-custom-amount">
		<label for="<?php echo esc_attr( $form_id ); ?>-custom" class="sd-custom-label"><?php esc_html_e( 'Or enter custom amount:', 'starter-shelter' ); ?></label>
		<div class="sd-input-wrapper">
			<span class="sd-currency-symbol">$</span>
			<input type="number" id="<?php echo esc_attr( $form_id ); ?>-custom" class="sd-custom-input"
				min="<?php echo esc_attr( $min_amount ); ?>" max="<?php echo esc_attr( $max_amount ); ?>" step="1" placeholder="0"
				data-wp-on--input="actions.setCustomAmount" data-wp-on--focus="actions.clearPresetAmount"
				data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].customAmount">
		</div>
		<p class="sd-field-help"><?php printf( esc_html__( '$%s minimum', 'starter-shelter' ), number_format( (int) $min_amount ) ); ?></p>
	</div>
</fieldset>
