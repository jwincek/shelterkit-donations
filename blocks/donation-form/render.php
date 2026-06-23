<?php
/**
 * Donation Form Block - Server-side render.
 *
 * Simple donation form with amount selection and allocation.
 * For tribute/memorial gifts, use the Memorial Form block instead.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

declare( strict_types = 1 );

use Starter_Shelter\Core\Config;
use Starter_Shelter\Helpers;

$form_id = $attributes['formId'] ?: wp_unique_id( 'sd-donation-' );

// Attributes with defaults.
$preset_amounts   = $attributes['presetAmounts'] ?? [ 25, 50, 100, 250, 500 ];
$default_amount   = $attributes['defaultAmount'] ?? 50;
$show_allocation  = $attributes['showAllocation'] ?? true;
$show_anonymous   = $attributes['showAnonymous'] ?? true;
// Campaign source of truth: explicit block attribute wins; otherwise
// auto-tag from `?campaign={id}` so a click through campaign-card
// (or any campaign-aware deep link) lands here and the resulting
// donation is attached to the originating campaign.
$campaign_id      = (int) ( $attributes['campaignId'] ?? 0 ) ?: Helpers\resolve_campaign_id_from_request();
$campaign_id      = $campaign_id ?: null;
$title            = $attributes['title'] ?? __( 'Make a Donation', 'starter-shelter' );
$subtitle         = $attributes['subtitle'] ?? __( 'Your gift helps animals in need.', 'starter-shelter' );
$submit_text      = $attributes['submitButtonText'] ?? __( 'Add to Cart', 'starter-shelter' );
$show_secure      = $attributes['showSecureBadge'] ?? true;
$min_amount       = $attributes['minAmount'] ?? 1;
$max_amount       = $attributes['maxAmount'] ?? 100000;

// Get allocations.
$allocations = [];
if ( class_exists( '\Starter_Shelter\Core\Config' ) ) {
    $allocations = Config::get_item( 'settings', 'allocations', [] );
}
if ( empty( $allocations ) ) {
    $allocations = [
        'general-fund' => __( 'General Fund', 'starter-shelter' ),
        'medical-care' => __( 'Medical Care', 'starter-shelter' ),
    ];
}

$campaign = $campaign_id ? get_term( $campaign_id, 'sd_campaign' ) : null;
$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/';
$product_id = (int) get_option( 'sd_donation_product_id', 0 );
$product_ok = $product_id && function_exists( 'wc_get_product' ) && wc_get_product( $product_id );

$namespace = 'starter-shelter/donation-form';

// Initialize state.
wp_interactivity_state( $namespace, [
    'forms' => [
        $form_id => [
            'amount'       => $default_amount,
            'customAmount' => '',
            'allocation'   => array_key_first( $allocations ) ?: 'general-fund',
            'isAnonymous'  => false,
            'dedication'   => '',
            'campaignId'   => $campaign_id,
            'isProcessing' => false,
            'error'        => null,
            'success'      => null,
        ],
    ],
] );

$context = wp_json_encode( [
    'formId'            => $form_id,
    'presetAmounts'     => $preset_amounts,
    'defaultAmount'     => $default_amount,
    'campaignId'        => $campaign_id,
    'minAmount'         => $min_amount,
    'maxAmount'         => $max_amount,
    'productType'       => 'donation',
    'checkoutUrl'       => $checkout_url,
    'productConfigured' => $product_ok,
    'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
    'cartNonce'         => wp_create_nonce( 'sd_add_to_cart' ),
] );

$wrapper = get_block_wrapper_attributes( [
    'class'               => 'sd-donation-form',
    'id'                  => $form_id,
    'data-wp-interactive' => '{"namespace":"' . $namespace . '"}',
    'data-wp-context'     => $context,
    'data-wp-init'        => 'actions.initForm',
] );

$partials = dirname( __DIR__ ) . '/shared/partials';
?>
<div <?php echo $wrapper; ?>>
    <div class="sd-form-header">
        <?php if ( $title ) : ?><h2 class="sd-form-title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
        <?php if ( $subtitle ) : ?><p class="sd-form-subtitle"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
        <?php if ( $campaign && ! is_wp_error( $campaign ) ) : ?>
            <div class="sd-campaign-badge"><?php printf( /* translators: %s: campaign name. */ esc_html__( 'Supporting: %s', 'starter-shelter' ), '<strong>' . esc_html( $campaign->name ) . '</strong>' ); ?></div>
        <?php endif; ?>
        <?php if ( ! $product_ok ) : ?>
            <div class="sd-config-warning" role="alert"><p><?php esc_html_e( 'Donation form not configured.', 'starter-shelter' ); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="sd-donation-form-inner">
        <?php
        // Amount selector (shared partial).
        $legend = __( 'Select Amount', 'starter-shelter' );
        require $partials . '/amount-selector.php';
        ?>

        <?php if ( $show_allocation ) : ?>
        <div class="sd-form-section sd-allocation-section">
            <label class="sd-section-label" for="<?php echo esc_attr( $form_id ); ?>-alloc">
                <?php esc_html_e( 'Direct Your Gift', 'starter-shelter' ); ?>
            </label>
            <div class="sd-select-wrapper">
                <select id="<?php echo esc_attr( $form_id ); ?>-alloc" class="sd-select" data-wp-on--change="actions.setAllocation">
                    <?php foreach ( $allocations as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Anonymous toggle (shared partial).
        if ( $show_anonymous ) :
            $label = __( 'Make my donation anonymous', 'starter-shelter' );
            require $partials . '/anonymous-toggle.php';
        endif;
        ?>

        <!-- Summary & Submit -->
        <div class="sd-form-section sd-summary-section">
            <div class="sd-donation-summary">
                <span class="sd-summary-label"><?php esc_html_e( 'Your Gift:', 'starter-shelter' ); ?></span>
                <span class="sd-summary-amount" data-wp-text="callbacks.getDisplayAmount">
                    $<?php echo number_format( (int) $default_amount ); ?>
                </span>
            </div>

            <button type="button" class="sd-submit-button wp-element-button"
                data-wp-on--click="actions.submitToCart"
                data-wp-bind--disabled="!callbacks.canProceed"
                data-wp-class--is-processing="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                <span data-wp-bind--hidden="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                    <?php echo esc_html( $submit_text ); ?>
                </span>
                <span class="sd-button-loading" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                    <span class="sd-spinner-small"></span> <?php esc_html_e( 'Adding...', 'starter-shelter' ); ?>
                </span>
            </button>

            <?php require $partials . '/form-messages.php'; ?>
        </div>
    </div>

    <?php
    if ( $show_secure ) :
        $label = __( 'Secure donation powered by WooCommerce', 'starter-shelter' );
        require $partials . '/trust-badge.php';
    endif;
    ?>
</div>
