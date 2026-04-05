/**
 * Form Base - Shared actions and callbacks for shelter form blocks.
 *
 * Provides functions that register shared behavior into a store.
 * Uses the Interactivity API's store merging — calling store() with
 * the same namespace merges actions/callbacks into the existing store.
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

// ── DOM helpers ──────────────────────────────────────────────────────

/**
 * Refresh WooCommerce cart fragments (header cart widget, etc.).
 */
function refreshCartFragments() {
	if ( typeof jQuery !== 'undefined' && jQuery( document.body ).trigger ) {
		jQuery( document.body ).trigger( 'wc_fragment_refresh' );
	}
}

/**
 * Scroll the first field with an error into view.
 *
 * @param {string} formId The form instance ID.
 * @param {Object} fieldErrors Map of field names to error messages.
 */
function scrollToFirstError( formId, fieldErrors ) {
	const formEl = document.getElementById( formId );
	if ( ! formEl ) return;

	const firstField = Object.keys( fieldErrors )[ 0 ];
	if ( ! firstField ) return;

	// Try to find the input by common ID/name patterns.
	const selector = [
		`#${ formId }-${ firstField }`,
		`[data-wp-on--input="actions.set${ capitalize( firstField ) }"]`,
		`.sd-${ firstField }-section`,
	].join( ', ' );

	const el = formEl.querySelector( selector );
	if ( el ) {
		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		// Focus the input if possible.
		const input = el.matches( 'input, textarea, select' ) ? el : el.querySelector( 'input, textarea, select' );
		if ( input ) input.focus( { preventScroll: true } );
	}
}

function capitalize( str ) {
	return str.charAt( 0 ).toUpperCase() + str.slice( 1 );
}

// ── Submit handler ───────────────────────────────────────────────────

/**
 * Shared submit-to-cart generator.
 *
 * Each store's submitToCart delegates to this via yield*.
 *
 * @param {Object}   stateRef      The store's state object.
 * @param {Function} validate      Return { error, fieldErrors } or null.
 *                                 error: summary string, fieldErrors: { field: message }.
 *                                 May also return a plain string for backward compat.
 * @param {Function} buildFormData Return FormData.
 * @param {Function} resetForm     Reset form-specific fields.
 */
export function* submitToCart( stateRef, validate, buildFormData, resetForm ) {
	const ctx = getContext();
	const form = stateRef.forms[ ctx.formId ];
	const config = {
		...getSharedConfig(),
		ajaxUrl:   ctx.ajaxUrl,
		cartNonce: ctx.cartNonce,
	};

	if ( ! form || form.isProcessing ) return;

	// Clear previous field errors.
	form.fieldErrors = {};

	const validationResult = validate( form, ctx );
	if ( validationResult ) {
		// Support both string (legacy) and object (field-level) returns.
		if ( typeof validationResult === 'string' ) {
			form.error = validationResult;
		} else {
			form.error = validationResult.error || '';
			form.fieldErrors = validationResult.fieldErrors || {};
			scrollToFirstError( ctx.formId, form.fieldErrors );
		}
		return;
	}

	form.isProcessing = true;
	form.error = null;
	form.success = null;
	form.fieldErrors = {};
	form.showSuccess = false;

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
			form.showSuccess = true;

			// Refresh WooCommerce cart widgets.
			refreshCartFragments();

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

// ── Shared amount actions (donation + memorial) ──────────────────────

/**
 * Register shared amount actions into a store namespace.
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
					form.fieldErrors = {};
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
					form.fieldErrors = {};
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

// ── Shared amount callbacks ──────────────────────────────────────────

/**
 * Register shared amount callbacks into a store namespace.
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

// ── Shared field-error callbacks ─────────────────────────────────────

/**
 * Register shared field error callbacks.
 *
 * @param {string} namespace The store namespace.
 * @param {Object} stateRef  The store's state object.
 */
export function registerFieldErrorCallbacks( namespace, stateRef ) {
	store( namespace, {
		callbacks: {
			hasFieldError() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				return !! form?.fieldErrors?.[ ctx.fieldName ];
			},

			getFieldError() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				return form?.fieldErrors?.[ ctx.fieldName ] || '';
			},

			isAnonymousExplainer() {
				const ctx = getContext();
				const form = stateRef.forms[ ctx.formId ];
				return !! form?.isAnonymous;
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
	const min = ctx.minAmount || 1;
	if ( amount < min ) {
		return `The minimum gift amount is $${ min }.`;
	}
	const max = ctx.maxAmount || 100000;
	if ( amount > max ) {
		return `The maximum gift amount is $${ max.toLocaleString() }.`;
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
