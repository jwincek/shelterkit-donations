<?php
/**
 * Membership Form Block - Tier selection and signup.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

declare( strict_types = 1 );

use Starter_Shelter\Core\Config;

$form_id          = $attributes['formId'] ?: wp_unique_id( 'sd-membership-' );
$membership_type  = $attributes['membershipType'] ?? 'individual';
$show_type_toggle = $attributes['showTypeToggle'] ?? false;
$default_tier     = $attributes['defaultTier'] ?? '';
$layout           = $attributes['layout'] ?? 'cards';
$columns          = $attributes['columns'] ?? 3;
$show_benefits    = $attributes['showBenefits'] ?? true;
$show_anonymous   = $attributes['showAnonymous'] ?? false;
$title            = $attributes['title'] ?? __( 'Become a Member', 'starter-shelter' );
$subtitle         = $attributes['subtitle'] ?? __( 'Join our community of animal advocates.', 'starter-shelter' );
$submit_text      = $attributes['submitButtonText'] ?? __( 'Join Now', 'starter-shelter' );

// Get tiers from config (nested under 'tiers' key in tiers.json).
$all_tiers        = Config::get_item( 'tiers', 'tiers', [] );
$individual_tiers = $all_tiers['individual'] ?? [];
$business_tiers   = $all_tiers['business'] ?? [];

$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/';

// Check product configuration.
$individual_product_id = (int) get_option( 'sd_membership_product_id', 0 );
$business_product_id   = (int) get_option( 'sd_business_membership_product_id', 0 );
$product_ok = ( $membership_type === 'business' ) 
    ? ( $business_product_id && function_exists( 'wc_get_product' ) && wc_get_product( $business_product_id ) )
    : ( $individual_product_id && function_exists( 'wc_get_product' ) && wc_get_product( $individual_product_id ) );

wp_interactivity_state( 'starter-shelter/membership-form', [
    'forms' => [
        $form_id => [
            'membershipType' => $membership_type,
            'selectedTier'   => $default_tier,
            'isAnonymous'    => false,
            'businessName'   => '',
            'donorName'      => '',
            'logoPreview'    => '',
            'isProcessing'   => false,
            'error'          => null,
            'success'        => null,
        ],
    ],
] );

$context = wp_json_encode( [
    'formId'            => $form_id,
    'membershipType'    => $membership_type,
    'defaultTier'       => $default_tier,
    'checkoutUrl'       => $checkout_url,
    'productConfigured' => $product_ok,
    'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
    'cartNonce'         => wp_create_nonce( 'sd_add_to_cart' ),
    'tiers'             => [
        'individual' => $individual_tiers,
        'business'   => $business_tiers,
    ],
] );

$wrapper = get_block_wrapper_attributes( [
    'class' => 'sd-membership-form sd-layout-' . esc_attr( $layout ),
    'id'    => $form_id,
    'data-wp-interactive' => '{"namespace":"starter-shelter/membership-form"}',
    'data-wp-context'     => $context,
    'data-wp-init'        => 'actions.initForm',
] );

// Get the tiers for current type.
$current_tiers = ( $membership_type === 'business' ) ? $business_tiers : $individual_tiers;
?>
<div <?php echo $wrapper; ?>>
    <div class="sd-form-header">
        <?php if ( $title ) : ?><h2 class="sd-form-title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
        <?php if ( $subtitle ) : ?><p class="sd-form-subtitle"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
        <?php if ( ! $product_ok ) : ?>
            <div class="sd-config-warning" role="alert"><p><?php esc_html_e( 'Membership form not configured.', 'starter-shelter' ); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="sd-membership-form-inner">
        <?php if ( $show_type_toggle && ! empty( $individual_tiers ) && ! empty( $business_tiers ) ) : ?>
        <!-- Type Toggle -->
        <div class="sd-form-section sd-type-toggle-section">
            <div class="sd-type-toggle">
                <label class="sd-type-option">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_type" value="individual"
                        data-wp-on--change="actions.setMembershipType"
                        data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].membershipType === 'individual'">
                    <span class="sd-type-label"><?php esc_html_e( 'Individual', 'starter-shelter' ); ?></span>
                </label>
                <label class="sd-type-option">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_type" value="business"
                        data-wp-on--change="actions.setMembershipType"
                        data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].membershipType === 'business'">
                    <span class="sd-type-label"><?php esc_html_e( 'Business', 'starter-shelter' ); ?></span>
                </label>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tier Selection -->
        <div class="sd-form-section sd-tiers-section">
            <span class="sd-section-label"><?php esc_html_e( 'Select Your Level', 'starter-shelter' ); ?></span>

            <?php
            // Render a tier grid for each membership type. Only the active type is visible.
            $tier_sets = [
                'individual' => $individual_tiers,
                'business'   => $business_tiers,
            ];

            foreach ( $tier_sets as $type => $tiers ) :
                if ( empty( $tiers ) ) continue;
                $is_default = ( $type === $membership_type );
            ?>
            <div class="sd-tiers-grid sd-columns-<?php echo esc_attr( $columns ); ?> <?php echo $is_default ? '' : 'sd-collapsed'; ?>"
                data-wp-class--sd-collapsed="<?php echo $type === 'individual' ? 'callbacks.isBusinessMembership' : '!callbacks.isBusinessMembership'; ?>">
                <?php
                $prev_benefits = [];
                foreach ( $tiers as $slug => $tier ) :
                    $price = $tier['price'] ?? $tier['amount'] ?? 0;
                    $name = $tier['name'] ?? $tier['label'] ?? ucfirst( $slug );
                    $benefits = $tier['benefits'] ?? [];
                    $new_benefits = array_diff( $benefits, $prev_benefits );
                    $featured = $tier['featured'] ?? false;
                    $prev_benefits = $benefits;
                ?>
                    <div class="sd-tier-card <?php echo $featured ? 'sd-tier-featured' : ''; ?>"
                         data-wp-class--selected="callbacks.isTierSelected"
                         data-wp-context='{"tierSlug":"<?php echo esc_attr( $slug ); ?>"}'>
                        <?php if ( $featured ) : ?>
                            <div class="sd-tier-badge"><?php esc_html_e( 'Most Popular', 'starter-shelter' ); ?></div>
                        <?php endif; ?>

                        <button type="button" class="sd-tier-select-btn" data-wp-on--click="actions.selectTier">
                            <div class="sd-tier-header">
                                <h3 class="sd-tier-name"><?php echo esc_html( $name ); ?></h3>
                                <div class="sd-tier-price">
                                    <span class="sd-price-amount">$<?php echo esc_html( number_format( $price ) ); ?></span>
                                    <span class="sd-price-period">/<?php esc_html_e( 'year', 'starter-shelter' ); ?></span>
                                </div>
                            </div>

                            <?php if ( $show_benefits && ! empty( $benefits ) ) : ?>
                            <ul class="sd-tier-benefits">
                                <?php foreach ( $benefits as $benefit ) :
                                    $is_new = in_array( $benefit, $new_benefits, true );
                                ?>
                                    <li class="<?php echo $is_new ? 'sd-benefit-new' : 'sd-benefit-inherited'; ?>">
                                        <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg>
                                        <?php echo esc_html( $benefit ); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Business Details (for business memberships) -->
        <div class="sd-form-section sd-business-section sd-collapsed"
            data-wp-class--sd-collapsed="!callbacks.isBusinessMembership"
            data-wp-bind--aria-hidden="!callbacks.isBusinessMembership">
            <div class="sd-field-wrapper">
                <label for="<?php echo esc_attr( $form_id ); ?>-business" class="sd-section-label">
                    <?php esc_html_e( 'Business Name', 'starter-shelter' ); ?> <span class="sd-required">*</span>
                </label>
                <input type="text" id="<?php echo esc_attr( $form_id ); ?>-business" class="sd-text-input" maxlength="200"
                    placeholder="<?php esc_attr_e( 'Enter your business name...', 'starter-shelter' ); ?>"
                    data-wp-on--input="actions.setBusinessName"
                    data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].businessName">
            </div>

            <div class="sd-field-wrapper sd-logo-upload">
                <label class="sd-section-label"><?php esc_html_e( 'Business Logo', 'starter-shelter' ); ?> <span class="sd-optional">(<?php esc_html_e( 'Optional', 'starter-shelter' ); ?>)</span></label>
                <div class="sd-logo-dropzone" data-wp-on--click="actions.triggerLogoInput"
                    data-wp-class--sd-has-logo="callbacks.hasLogo">
                    <input type="file" id="<?php echo esc_attr( $form_id ); ?>-logo" class="sd-logo-input"
                        accept="image/png,image/jpeg,image/svg+xml"
                        data-wp-on--change="actions.setLogo">
                    <div class="sd-logo-preview sd-collapsed" data-wp-class--sd-collapsed="!callbacks.hasLogo">
                        <img data-wp-bind--src="callbacks.getLogoSrc" alt="" class="sd-logo-img">
                        <div class="sd-logo-info">
                            <span class="sd-logo-status"><?php esc_html_e( 'Logo selected', 'starter-shelter' ); ?></span>
                            <span class="sd-logo-note"><?php esc_html_e( 'Will be reviewed by our team before publishing.', 'starter-shelter' ); ?></span>
                        </div>
                        <button type="button" class="sd-logo-remove" data-wp-on--click="actions.removeLogo" aria-label="<?php esc_attr_e( 'Remove logo', 'starter-shelter' ); ?>">&times;</button>
                    </div>
                    <div class="sd-logo-placeholder" data-wp-class--sd-collapsed="callbacks.hasLogo">
                        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" fill="currentColor"/></svg>
                        <span><?php esc_html_e( 'Click to upload logo', 'starter-shelter' ); ?></span>
                        <span class="sd-logo-formats"><?php esc_html_e( 'PNG, JPG, or SVG', 'starter-shelter' ); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Donor display name (shared partial).
        $namespace = 'starter-shelter/membership-form';
        $label = __( 'Your Name', 'starter-shelter' );
        $help  = __( 'How your name will appear on our membership records.', 'starter-shelter' );
        require dirname( __DIR__ ) . '/shared/partials/donor-name.php';

        // Anonymous toggle (shared partial).
        if ( $show_anonymous ) :
            $label = __( 'Keep my membership anonymous', 'starter-shelter' );
            require dirname( __DIR__ ) . '/shared/partials/anonymous-toggle.php';
        endif;
        ?>

        <!-- Summary & Submit -->
        <div class="sd-form-section sd-summary-section">
            <div class="sd-membership-summary sd-collapsed"
                data-wp-class--sd-collapsed="!callbacks.hasTierSelected">
                <span class="sd-summary-tier" data-wp-text="callbacks.getSelectedTierName"></span>
                <span class="sd-summary-value" data-wp-text="callbacks.getDisplayPrice">$0</span>
                <span class="sd-summary-period">/<?php esc_html_e( 'year', 'starter-shelter' ); ?></span>
            </div>

            <button type="button" class="sd-submit-button wp-element-button" data-wp-on--click="actions.submitToCart"
                data-wp-bind--disabled="!callbacks.canProceed"
                data-wp-class--is-processing="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                <span data-wp-bind--hidden="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing"><?php echo esc_html( $submit_text ); ?></span>
                <span class="sd-button-loading" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                    <span class="sd-spinner-small"></span> <?php esc_html_e( 'Processing...', 'starter-shelter' ); ?>
                </span>
            </button>

            <?php
            $namespace = 'starter-shelter/membership-form';
            require dirname( __DIR__ ) . '/shared/partials/form-messages.php';
            ?>
        </div>
    </div>

    <?php
    $label = __( 'Secure payment powered by WooCommerce', 'starter-shelter' );
    require dirname( __DIR__ ) . '/shared/partials/trust-badge.php';
    ?>
</div>
