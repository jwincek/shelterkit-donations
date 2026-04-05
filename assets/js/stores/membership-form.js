/**
 * Membership Form Store
 *
 * Handles both individual and business membership forms.
 * Tier selection with price display and cart submission.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { formatCurrency, __, sanitizeText } from './utils.js';
import {
	submitToCart,
	registerToggleAnonymous,
	registerFieldErrorCallbacks,
	createBaseFormData,
} from './form-base.js';

const NAMESPACE = 'starter-shelter/membership-form';

// File objects can't be stored in reactive state (Proxy breaks them).
// Keep them in a plain Map keyed by form ID.
const logoFiles = new Map();

const { state, actions } = store( NAMESPACE, {
	state: {
		forms: {},
	},

	actions: {
		initForm() {
			const ctx = getContext();
			const { formId, membershipType = 'individual', defaultTier = null } = ctx;

			if ( ! state.forms[ formId ] ) {
				state.forms[ formId ] = {
					membershipType,
					selectedTier: defaultTier,
					isAnonymous: false,
					donorName: '',
					logoPreview: '',
					isProcessing: false,
					error: null,
					success: null,
					fieldErrors: {},
					showSuccess: false,
				};
			}
		},

		selectTier() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form && ctx.tierSlug ) {
				form.selectedTier = ctx.tierSlug;
				form.error = null;
			}
		},

		setMembershipType( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				form.membershipType = event.target.value;
				form.selectedTier = null;
			}
		},

		setDonorName( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) form.donorName = sanitizeText( event.target.value ).substring( 0, 200 );
		},

		triggerLogoInput( event ) {
			// Prevent the click from bubbling if it's on the remove button.
			if ( event.target.closest( '.sd-logo-remove' ) ) return;
			const ctx = getContext();
			const input = document.getElementById( ctx.formId + '-logo' );
			if ( input ) input.click();
		},

		setLogo( event ) {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			const file = event.target.files?.[ 0 ];
			if ( ! form || ! file ) return;

			// Validate file type and size (2MB max).
			const allowed = [ 'image/png', 'image/jpeg', 'image/svg+xml' ];
			if ( ! allowed.includes( file.type ) ) {
				form.error = __( 'errorLogoType', 'Please upload a PNG, JPG, or SVG file.' );
				return;
			}
			if ( file.size > 2 * 1024 * 1024 ) {
				form.error = __( 'errorLogoSize', 'Logo must be under 2MB.' );
				return;
			}

			// Store the File outside reactive state — Proxy breaks File objects.
			logoFiles.set( ctx.formId, file );
			form.logoPreview = URL.createObjectURL( file );
			form.error = null;
		},

		removeLogo( event ) {
			event.stopPropagation();
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				if ( form.logoPreview ) URL.revokeObjectURL( form.logoPreview );
				logoFiles.delete( ctx.formId );
				form.logoPreview = '';
				const input = document.getElementById( ctx.formId + '-logo' );
				if ( input ) input.value = '';
			}
		},

		*submitToCart() {
			yield* submitToCart(
				state,
				( form, ctx ) => {
					const fieldErrors = {};
					if ( ! form.selectedTier ) {
						fieldErrors.tier = __( 'errorSelectTier', 'Please select a membership level.' );
					}
					if ( form.membershipType === 'business' && ! form.donorName.trim() ) {
						fieldErrors.donorName = __( 'errorBusinessName', 'Please enter your business name.' );
					}
					const tiers = ctx.tiers?.[ form.membershipType ] || {};
					if ( form.selectedTier && ! tiers[ form.selectedTier ] ) {
						fieldErrors.tier = __( 'errorInvalidTier', 'Selected tier is not available.' );
					}

					if ( Object.keys( fieldErrors ).length ) {
						const firstError = Object.values( fieldErrors )[ 0 ];
						return { error: firstError, fieldErrors };
					}
					return null;
				},
				( form, ctx, config ) => {
					const productType = form.membershipType === 'business' ? 'business_membership' : 'membership';
					const tiers = ctx.tiers?.[ form.membershipType ] || {};
					const tier = tiers[ form.selectedTier ];

					const formData = createBaseFormData( productType, config );
					formData.append( 'amount', tier.price || tier.amount || 0 );
					formData.append( 'tier', form.selectedTier );

					if ( form.isAnonymous ) formData.append( 'is_anonymous', '1' );
					if ( form.donorName ) {
						formData.append( 'donor_name', form.donorName );
						// For business memberships, donorName IS the business name.
						if ( form.membershipType === 'business' ) {
							formData.append( 'business_name', form.donorName );
						}
					}
					const logoFile = logoFiles.get( ctx.formId );
					if ( form.membershipType === 'business' && logoFile ) {
						formData.append( 'business_logo', logoFile );
					}
					return formData;
				},
				( form, ctx ) => {
					Object.assign( form, {
						selectedTier: ctx.defaultTier || null,
						isAnonymous: false,
						businessName: '',
					} );
				}
			);
		},

		reset() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( form ) {
				if ( form.logoPreview ) URL.revokeObjectURL( form.logoPreview );
				logoFiles.delete( ctx.formId );
				Object.assign( form, {
					selectedTier: ctx.defaultTier || null,
					isAnonymous: false,
					donorName: '',
					logoPreview: '',
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
		getSelectedTier() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form?.selectedTier ) return null;
			const tiers = ctx.tiers?.[ form.membershipType ] || {};
			return tiers[ form.selectedTier ] || null;
		},

		getDisplayPrice() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form?.selectedTier ) return '$0';
			const tiers = ctx.tiers?.[ form.membershipType ] || {};
			const tier = tiers[ form.selectedTier ];
			return formatCurrency( tier?.price || tier?.amount || 0 );
		},

		getSelectedTierName() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form?.selectedTier ) return '';
			const tiers = ctx.tiers?.[ form.membershipType ] || {};
			const tier = tiers[ form.selectedTier ];
			return tier?.name || tier?.label || '';
		},

		hasTierSelected() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return !! form?.selectedTier;
		},

		canProceed() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			if ( ! form || form.isProcessing ) return false;
			const hasTier = !! form.selectedTier;
			const businessValid = form.membershipType !== 'business' || form.donorName.trim().length > 0;
			return hasTier && businessValid && ctx.productConfigured;
		},

		isTierSelected() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.selectedTier === ctx.tierSlug;
		},

		getTierPrice() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			const tiers = ctx.tiers?.[ form?.membershipType || 'individual' ] || {};
			const tier = tiers[ ctx.tierSlug ];
			return formatCurrency( tier?.price || tier?.amount || 0 );
		},

		getTierBenefits() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			const tiers = ctx.tiers?.[ form?.membershipType || 'individual' ] || {};
			const tier = tiers[ ctx.tierSlug ];
			return tier?.benefits || [];
		},

		getDonorNameLabel() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.membershipType === 'business'
				? __( 'businessName', 'Business Name' )
				: __( 'yourName', 'Your Name' );
		},

		getDonorNamePlaceholder() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.membershipType === 'business'
				? __( 'businessNamePlaceholder', 'Enter your business name...' )
				: __( 'yourNamePlaceholder', 'Enter your name...' );
		},

		isFamilyTier() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.membershipType === 'individual' && form?.selectedTier === 'family';
		},

		getDonorNameHelp() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.membershipType === 'business'
				? __( 'businessNameHelp', 'Your business name as it will appear on our website.' )
				: __( 'yourNameHelp', 'How your name will appear on our membership records.' );
		},

		hasLogo() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return !! form?.logoPreview;
		},

		getLogoSrc() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.logoPreview || '';
		},

		isBusinessMembership() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.membershipType === 'business';
		},

		getMembershipTypeLabel() {
			const ctx = getContext();
			const form = state.forms[ ctx.formId ];
			return form?.membershipType === 'business'
				? __( 'businessMembership', 'Business Membership' )
				: __( 'individualMembership', 'Individual Membership' );
		},
	},
} );

// Merge shared toggleAnonymous action.
registerToggleAnonymous( NAMESPACE, state );
registerFieldErrorCallbacks( NAMESPACE, state );

export { state, actions };
