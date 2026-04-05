/**
 * Form Base - Shared actions and callbacks for shelter form blocks.
 *
 * Provides functions that register shared behavior into a store.
 * Uses the Interactivity API's store merging — calling store() with
 * the same namespace merges actions/callbacks into the existing store.
 *
 * Usage in each form store:
 *   const { state } = store( 'my-namespace', { state: { forms: {} }, ... } );
 *   registerAmountActions( 'my-namespace', state );
 *   registerAmountCallbacks( 'my-namespace', state );
 *
 * @package Starter_Shelter
 * @since 2.1.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { getSharedConfig, formatCurrency, parseAmount, __ } from './utils.js';

/**
 * Submit timeout in milliseconds.
 */
const SUBMIT_TIMEOUT = 30000;

/**
 * Shared submit-to-cart generator.
 *
 * Each store's submitToCart delegates to this via yield*.
 *
 * @param {Object}   stateRef      The store's state object (from store() return).
 * @param {Function} validate      Return error string or null.
 * @param {Function} buildFormData Return FormData.
 * @param {Function} resetForm     Reset form-specific fields.
 */
export function* submitToCart( stateRef, validate, buildFormData, resetForm ) {
	const ctx = getContext();
	const form = stateRef.forms[ ctx.formId ];
	// Read AJAX config from context (set in render.php) rather than
	// getConfig() which has cross-namespace issues.
	const config = {
		...getSharedConfig(),
		ajaxUrl:   ctx.ajaxUrl,
		cartNonce: ctx.cartNonce,
	};

	if ( ! form || form.isProcessing ) return;

	const validationError = validate( form, ctx );
	if ( validationError ) {
		form.error = validationError;
		return;
	}

	form.isProcessing = true;
	form.error = null;
	form.success = null;

	const controller = new AbortController();
	const timeoutId = setTimeout( () => controller.abort(), SUBMIT_TIMEOUT );

	try {
		const formData = buildFormData( form, ctx, config );

		const response = yield fetch( config.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
			signal: controller.signal,
		} );

		const result = yield response.json();

		if ( result.success ) {
			form.success = result.data?.message || __( 'addedToCart', 'Added to cart!' );

			if ( config.autoRedirectToCheckout && result.data?.checkout_url ) {
				window.location.href = result.data.checkout_url;
				return;
			}

			if ( resetForm ) {
				resetForm( form, ctx );
			}
		} else {
			form.error = result.data?.message || __( 'errorGeneric', 'Could not add to cart.' );
		}
	} catch ( error ) {
		if ( error.name === 'AbortError' ) {
			form.error = __( 'errorTimeout', 'Request timed out. Please try again.' );
		} else {
			form.error = __( 'errorNetwork', 'Network error. Please try again.' );
		}
	} finally {
		clearTimeout( timeoutId );
		form.isProcessing = false;
	}
}

/**
 * Register shared amount actions into a store namespace.
 *
 * Merges selectAmount, clearPresetAmount, and setCustomAmount into
 * the store via a second store() call.
 *
 * @param {string} namespace The store namespace.
 * @param {Object} stateRef  The store's state object.
 */
export function registerAmountActions( namespace, stateRef ) {
	store( namespace, {
		actions: {
			selectAmount() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				if ( form && ctx.buttonAmount > 0 ) {
					form.amount = ctx.buttonAmount;
					form.customAmount = '';
					form.error = null;
				}
			},

			clearPresetAmount() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				if ( form ) {
					form.amount = 0;
				}
			},

			setCustomAmount( event ) {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				if ( form ) {
					form.customAmount = event.target.value;
					form.amount = 0;
					form.error = null;
				}
			},

			toggleAnonymous() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				if ( form ) {
					form.isAnonymous = ! form.isAnonymous;
				}
			},
		},
	} );
}

/**
 * Register shared amount callbacks into a store namespace.
 *
 * Merges getEffectiveAmount, getDisplayAmount, and isAmountSelected.
 *
 * @param {string} namespace The store namespace.
 * @param {Object} stateRef  The store's state object.
 */
export function registerAmountCallbacks( namespace, stateRef ) {
	store( namespace, {
		callbacks: {
			getEffectiveAmount() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				return form ? ( form.amount || parseAmount( form.customAmount ) ) : 0;
			},

			getDisplayAmount() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				const amount = form ? ( form.amount || parseAmount( form.customAmount ) ) : 0;
				return formatCurrency( amount );
			},

			isAmountSelected() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				return form?.amount === ctx.buttonAmount && ! form?.customAmount;
			},
		},
	} );
}

/**
 * Register just the toggleAnonymous action (for stores without amount actions).
 *
 * @param {string} namespace The store namespace.
 * @param {Object} stateRef  The store's state object.
 */
export function registerToggleAnonymous( namespace, stateRef ) {
	store( namespace, {
		actions: {
			toggleAnonymous() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				if ( form ) {
					form.isAnonymous = ! form.isAnonymous;
				}
			},
		},
	} );
}

// ── Validation helpers ───────────────────────────────────────────────

/**
 * Validate amount against min/max bounds.
 *
 * @param {number} amount The effective amount.
 * @param {Object} ctx    The block context.
 * @returns {string|null} Error message or null if valid.
 */
export function validateAmount( amount, ctx ) {
	if ( amount < ( ctx.minAmount || 1 ) ) {
		return __( 'errorMinAmount', 'Please enter a valid amount.' );
	}
	if ( amount > ( ctx.maxAmount || 100000 ) ) {
		return __( 'errorMaxAmount', 'Amount exceeds maximum allowed.' );
	}
	return null;
}

/**
 * Create base FormData with shared fields.
 *
 * @param {string} productType The product type identifier.
 * @param {Object} config      The shared config.
 * @returns {FormData} FormData with action, nonce, and product_type set.
 */
export function createBaseFormData( productType, config ) {
	const formData = new FormData();
	formData.append( 'action', 'sd_add_to_cart' );
	formData.append( 'nonce', config.cartNonce );
	formData.append( 'product_type', productType );
	return formData;
}
