/**
 * Donation Form Store
 *
 * Handles general donation form state and cart submission.
 * Multi-instance capable via form IDs.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { parseAmount, sanitizeText } from './utils.js';
import {
	submitToCart,
	registerAmountActions,
	registerAmountCallbacks,
	registerFieldErrorCallbacks,
	validateAmount,
	createBaseFormData,
} from './form-base.js';

const NAMESPACE = 'starter-shelter/donation-form';

const { state, actions } = store( NAMESPACE, {
	state: {
		forms: {},
	},

	actions: {
		initForm() {
			const ctx = getContext();
			const { formId, defaultAmount = 50, campaignId = null } = ctx;

			if ( ! state.forms[ formId ] ) {
				state.forms[ formId ] = {
					amount: defaultAmount,
					customAmount: '',
					allocation: 'general-fund',
					isAnonymous: false,
					dedication: '',
					campaignId,
					isProcessing: false,
					error: null,
					success: null,
					fieldErrors: {},
					showSuccess: false,
				};
			}
		},

		// Form-specific actions.
		setAllocation( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				form.allocation = event.target.value;
			}
		},

		setDedication( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				form.dedication = sanitizeText( event.target.value );
			}
		},

		*submitToCart() {
			yield* submitToCart(
				state,
				( form, ctx ) => {
					const amount = form.amount || parseAmount( form.customAmount );
					const amountErr = validateAmount( amount, ctx );
					if ( amountErr ) {
						return { error: amountErr, fieldErrors: { amount: amountErr } };
					}
					return null;
				},
				( form, ctx, config ) => {
					const amount = form.amount || parseAmount( form.customAmount );
					const formData = createBaseFormData( 'donation', config );
					formData.append( 'amount', amount );
					formData.append( 'allocation', form.allocation );

					if ( form.campaignId ) formData.append( 'campaign_id', form.campaignId );
					if ( form.isAnonymous ) formData.append( 'is_anonymous', '1' );
					if ( form.dedication ) formData.append( 'dedication', form.dedication );
					return formData;
				},
				( form, ctx ) => {
					Object.assign( form, {
						amount: ctx.defaultAmount || 50,
						customAmount: '',
						allocation: 'general-fund',
						isAnonymous: false,
						dedication: '',
					} );
				}
			);
		},

		reset() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				Object.assign( form, {
					amount: ctx.defaultAmount || 50,
					customAmount: '',
					allocation: 'general-fund',
					isAnonymous: false,
					dedication: '',
					error: null,
					success: null,
					fieldErrors: {},
					showSuccess: false,
					isProcessing: false,
				} );
			}
		},
	},

	callbacks: {
		canProceed() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form || form.isProcessing ) return false;
			const amount = form.amount || parseAmount( form.customAmount );
			return amount >= ( ctx.minAmount || 1 ) && ctx.productConfigured;
		},
	},
} );

// Merge shared amount actions/callbacks + toggleAnonymous.
registerAmountActions( NAMESPACE, state );
registerAmountCallbacks( NAMESPACE, state );
registerFieldErrorCallbacks( NAMESPACE, state );

export { state, actions };
