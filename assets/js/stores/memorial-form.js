/**
 * Memorial Form Store
 *
 * Handles memorial/tribute donation form state.
 * Dedicated form for In Memory Of / In Honor Of gifts.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { parseAmount, __, sanitizeText, isValidEmail } from './utils.js';
import {
	submitToCart,
	registerAmountActions,
	registerAmountCallbacks,
	registerFieldErrorCallbacks,
	validateAmount,
	createBaseFormData,
} from './form-base.js';

const NAMESPACE = 'shelter-donations/memorial-form';

const { state, actions } = store( NAMESPACE, {
	state: {
		forms: {},
	},

	actions: {
		initForm() {
			const ctx = getContext();
			const { formId, defaultAmount = 50 } = ctx;

			if ( ! state.forms[ formId ] ) {
				state.forms[ formId ] = {
					amount: defaultAmount,
					customAmount: '',
					isAnonymous: false,
					dedicationType: 'memory',
					honoreeType: 'person',
					honoreeName: '',
					tributeMessage: '',
					notifyFamily: false,
					familyName: '',
					familyEmail: '',
					familyAddress: '',
					sendCard: false,
					donorName: '',
					isProcessing: false,
					error: null,
					success: null,
					fieldErrors: {},
					showSuccess: false,
				};
			}
		},

		// Memorial-specific actions.
		setDedicationType( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.dedicationType = event.target.value;
		},

		setHonoreeType( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.honoreeType = event.target.value;
		},

		setHonoreeName( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				form.honoreeName = sanitizeText( event.target.value ).substring( 0, 100 );
				form.error = null;
			}
		},

		setTributeMessage( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				form.tributeMessage = sanitizeText( event.target.value ).substring( 0, 500 );
			}
		},

		// Family notification actions.
		toggleNotifyFamily() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.notifyFamily = ! form.notifyFamily;
		},

		setFamilyName( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.familyName = sanitizeText( event.target.value ).substring( 0, 100 );
		},

		setFamilyEmail( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.familyEmail = event.target.value.trim();
		},

		setFamilyAddress( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.familyAddress = sanitizeText( event.target.value ).substring( 0, 500 );
		},

		toggleSendCard() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.sendCard = ! form.sendCard;
		},

		setDonorName( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.donorName = sanitizeText( event.target.value ).substring( 0, 200 );
		},

		*submitToCart() {
			yield* submitToCart(
				state,
				( form, ctx ) => {
					const fieldErrors = {};
					const amount = form.amount || parseAmount( form.customAmount );
					const amountErr = validateAmount( amount, ctx );
					if ( amountErr ) fieldErrors.amount = amountErr;

					if ( ! form.honoreeName.trim() ) {
						fieldErrors.honoree = __( 'errorHonoreeName', 'Please enter the name of who this gift honors.' );
					}
					if ( form.notifyFamily && ! form.familyName.trim() ) {
						fieldErrors.familyName = __( 'errorFamilyName', 'Please enter the family contact name.' );
					}
					if ( form.notifyFamily && form.familyEmail && ! isValidEmail( form.familyEmail ) ) {
						fieldErrors.familyEmail = __( 'errorInvalidEmail', 'Please enter a valid email address.' );
					}

					if ( Object.keys( fieldErrors ).length ) {
						const firstError = Object.values( fieldErrors )[ 0 ];
						return { error: firstError, fieldErrors };
					}
					return null;
				},
				( form, ctx, config ) => {
					const amount = form.amount || parseAmount( form.customAmount );
					const formData = createBaseFormData( 'memorial', config );
					formData.append( 'amount', amount );

					if ( form.isAnonymous ) formData.append( 'is_anonymous', '1' );
					if ( form.donorName ) formData.append( 'donor_name', form.donorName );

					formData.append( 'dedication_enabled', '1' );
					formData.append( 'dedication_type', form.dedicationType );
					formData.append( 'honoree_name', form.honoreeName );
					formData.append( 'honoree_type', form.honoreeType );

					if ( form.tributeMessage ) formData.append( 'tribute_message', form.tributeMessage );

					if ( form.notifyFamily ) {
						// POST keys match the sd_memorial entity's flat
						// notify_family_* fields (closes the audit-CC-2
						// cart/entity meta-key divergence).
						formData.append( 'notify_family_enabled', '1' );
						formData.append( 'notify_family_name', form.familyName );
						if ( form.familyEmail ) formData.append( 'notify_family_email', form.familyEmail );
						if ( form.familyAddress ) formData.append( 'notify_family_address', form.familyAddress );
						if ( form.sendCard ) formData.append( 'notify_family_send_card', '1' );
					}
					return formData;
				},
				( form, ctx ) => {
					Object.assign( form, {
						amount: ctx.defaultAmount || 50,
						customAmount: '',
						isAnonymous: false,
						dedicationType: 'memory',
						honoreeType: 'person',
						honoreeName: '',
						tributeMessage: '',
						notifyFamily: false,
						familyName: '',
						familyEmail: '',
						familyAddress: '',
						sendCard: false,
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
					isAnonymous: false,
					dedicationType: 'memory',
					honoreeType: 'person',
					honoreeName: '',
					tributeMessage: '',
					notifyFamily: false,
					familyName: '',
					familyEmail: '',
					familyAddress: '',
					sendCard: false,
					donorName: '',
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
			const hasHonoree = form.honoreeName.trim().length > 0;
			const familyValid = ! form.notifyFamily || form.familyName.trim().length > 0;

			return amount >= ( ctx.minAmount || 1 ) && hasHonoree && familyValid && ctx.productConfigured;
		},

		isFamilyHidden() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return ! form?.notifyFamily;
		},

		getHonoreeLabel() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form ) return 'Name';
			return form.honoreeType === 'pet'
				? __( 'petName', "Pet's Name" )
				: __( 'personName', "Person's Name" );
		},

		getTributeCharCount() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.tributeMessage?.length || 0;
		},

		getDedicationSummary() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form || ! form.honoreeName ) return '';

			const typeLabel = form.dedicationType === 'memory'
				? __( 'inMemoryOf', 'In Memory Of' )
				: __( 'inHonorOf', 'In Honor Of' );
			const honoreeLabel = form.honoreeType === 'pet' ? ` (${ __( 'pet', 'Pet' ) })` : '';

			return `${ typeLabel }: ${ form.honoreeName }${ honoreeLabel }`;
		},

		getDedicationTypeLabel() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.dedicationType === 'memory'
				? __( 'inMemoryOf', 'In Memory Of' )
				: __( 'inHonorOf', 'In Honor Of' );
		},
	},
} );

// Merge shared amount actions/callbacks + toggleAnonymous.
registerAmountActions( NAMESPACE, state );
registerAmountCallbacks( NAMESPACE, state );
registerFieldErrorCallbacks( NAMESPACE, state );

export { state, actions };
