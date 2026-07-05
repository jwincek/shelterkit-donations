<?php
/**
 * Shared partial: Donor display name field.
 *
 * Collects the name the donor wants displayed on the memorial wall,
 * membership listing, or donation record.
 *
 * @package Starter_Shelter
 * @since 2.1.0
 *
 * @var string $form_id   The form instance ID.
 * @var string $namespace The Interactivity API store namespace.
 * @var string $label     Optional custom label text.
 * @var string $help      Optional help text.
 */

defined( 'ABSPATH' ) || exit;

$label = $label ?? __( 'Your Name', 'shelter-donations' );
$help  = $help ?? __( 'How your name will appear on the record.', 'shelter-donations' );
?>
<div class="sd-form-section sd-donor-name-section">
	<label for="<?php echo esc_attr( $form_id ); ?>-donor-name" class="sd-section-label">
		<?php echo esc_html( $label ); ?>
	</label>
	<input type="text" id="<?php echo esc_attr( $form_id ); ?>-donor-name" class="sd-text-input" maxlength="200"
		placeholder="<?php esc_attr_e( 'Enter your name...', 'shelter-donations' ); ?>"
		data-wp-on--input="actions.setDonorName"
		data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].donorName">
	<p class="description sd-field-help"><?php echo esc_html( $help ); ?></p>
</div>
