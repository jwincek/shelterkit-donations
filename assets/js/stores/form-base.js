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
 * Signal that an add-to-cart is starting.
 *
 * WC's mini-cart frontend script uses a two-phase lazy-load pattern: only
 * `wc-blocks_adding_to_cart` is wired up initially. Firing that event causes
 * the mini-cart to asynchronously load its full bundle, which then registers
 * the listener for `wc-blocks_added_to_cart` (the success event). If we only
 * fire the success event — as the plugin did before — the listener doesn't
 * exist yet on first add and the cart UI never refreshes until a page reload.
 *
 * Fire this BEFORE the fetch, then fire refreshCartFragments() on success.
 */
function signalAddingToCart() {
	if ( typeof document !== 'undefined' && document.body && typeof CustomEvent === 'function' ) {
		document.body.dispatchEvent( new CustomEvent( 'wc-blocks_adding_to_cart', { bubbles: true, cancelable: true } ) );
	}
}

/**
 * Refresh WooCommerce cart UIs after a successful AJAX add-to-cart.
 *
 * WC has two cart UI families that listen on different mechanisms:
 *
 * - The classic mini-cart widget refreshes via the jQuery
 *   `wc_fragment_refresh` event on document.body.
 * - The WC Blocks mini-cart (`woocommerce/mini-cart` block) listens for the
 *   native DOM event `wc-blocks_added_to_cart` on document.body. The handler
 *   reads `detail.preserveCartData`: if false (our case — we modify the cart
 *   via admin-ajax, not the WC Store API, so the data store has no fresh
 *   data), the mini-cart refetches via the WC Store REST API. WC's own button
 *   passes true here because it already pushed fresh data via receiveCart().
 *
 * The wp.data dispatch is a no-op on pages that don't enqueue @wordpress/data
 * globally; the DOM event is the reliable path.
 *
 * Pair this with signalAddingToCart() fired earlier — see that function.
 */
function refreshCartFragments() {
	if ( typeof jQuery !== 'undefined' && jQuery( document.body ).trigger ) {
		jQuery( document.body ).trigger( 'wc_fragment_refresh' );
	}

	if ( typeof document !== 'undefined' && document.body && typeof CustomEvent === 'function' ) {
		document.body.dispatchEvent( new CustomEvent( 'wc-blocks_added_to_cart', {
			bubbles: true,
			cancelable: true,
			detail: { preserveCartData: false },
		} ) );
	}

	if ( window.wp?.data?.dispatch ) {
		const cartStore = window.wp.data.dispatch( 'wc/store/cart' );
		if ( cartStore && typeof cartStore.invalidateResolutionForStore === 'function' ) {
			cartStore.invalidateResolutionForStore();
		}
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

		// Notify WC's mini-cart so it can lazy-load its bundle before our
		// success handler fires wc-blocks_added_to_cart.
		signalAddingToCart();

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
