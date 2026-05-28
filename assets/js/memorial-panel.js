/**
 * Memorial Document Setting Panels.
 *
 * Replaces the classic meta boxes for sd_memorial with three
 * PluginDocumentSettingPanel components in the block editor sidebar.
 *
 * No build step required — uses wp.createElement via IIFE.
 *
 * @since 2.1.0
 * @package Starter_Shelter
 */
( function( wp ) {
	'use strict';

	var el             = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var useState       = wp.element.useState;
	var useEffect      = wp.element.useEffect;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var PluginPrePublishPanel      = wp.editPost.PluginPrePublishPanel;
	var useEntityProp  = wp.coreData.useEntityProp;
	var useSelect      = wp.data.useSelect;
	var useDispatch    = wp.data.useDispatch;
	var apiFetch       = wp.apiFetch;
	var __             = wp.i18n.__;

	var TextControl     = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var SelectControl   = wp.components.SelectControl;
	var ToggleControl   = wp.components.ToggleControl;
	var ComboboxControl = wp.components.ComboboxControl;
	var Button          = wp.components.Button;
	var Notice          = wp.components.Notice;

	// ── Helper: read/write a single meta key ──

	function useMemorialMeta() {
		var entityProp = useEntityProp( 'postType', 'sd_memorial', 'meta' );
		var meta       = entityProp[ 0 ];
		var setMeta    = entityProp[ 1 ];

		return {
			meta: meta,
			set: function( key, value ) {
				var update = {};
				update[ key ] = value;
				setMeta( update );
			},
		};
	}

	// ── Root component: post-type guard ──

	function MemorialPanels() {
		var postType = useSelect( function( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		var editPost = useDispatch( 'core/edit-post' );

		// Auto-open the settings sidebar so editors see the panels immediately.
		useEffect( function() {
			if ( postType === 'sd_memorial' && editPost.openGeneralSidebar ) {
				editPost.openGeneralSidebar( 'edit-post/document' );
			}
		}, [ postType ] );

		if ( postType !== 'sd_memorial' ) {
			return null;
		}

		return el( Fragment, {},
			el( MemorialDetailsPanel ),
			el( DonationInfoPanel ),
			el( FamilyNotificationPanel ),
			el( PrePublishValidationPanel )
		);
	}

	// ── Panel 1: Memorial Details ──

	function MemorialDetailsPanel() {
		var m = useMemorialMeta();

		return el( PluginDocumentSettingPanel, {
			name:  'sd-memorial-details',
			title: __( 'Memorial Details', 'starter-shelter' ),
		},
			el( TextControl, {
				label:    __( 'Honoree Name', 'starter-shelter' ),
				value:    m.meta._sd_honoree_name || '',
				onChange: function( v ) { m.set( '_sd_honoree_name', v ); },
				help:     __( 'Also used as the post title.', 'starter-shelter' ),
				__nextHasNoMarginBottom: true,
			} ),
			// Two orthogonal axes, mirroring the customer-facing
			// memorial-form: dedication (the occasion) + honoree type
			// (the subject). The old single "Type" select conflated them.
			el( SelectControl, {
				label:   __( 'Dedication', 'starter-shelter' ),
				value:   m.meta._sd_dedication_type || 'memory',
				options: [
					{ value: 'memory', label: __( 'In Memory Of', 'starter-shelter' ) },
					{ value: 'honor',  label: __( 'In Honor Of', 'starter-shelter' ) },
				],
				onChange: function( v ) { m.set( '_sd_dedication_type', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			el( SelectControl, {
				label:   __( 'Honoree Type', 'starter-shelter' ),
				value:   m.meta._sd_memorial_type || 'person',
				options: [
					{ value: 'person', label: __( 'Person', 'starter-shelter' ) },
					{ value: 'pet',    label: __( 'Pet', 'starter-shelter' ) },
				],
				onChange: function( v ) { m.set( '_sd_memorial_type', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			m.meta._sd_memorial_type === 'pet' && el( SelectControl, {
				label:   __( 'Pet Species', 'starter-shelter' ),
				value:   m.meta._sd_pet_species || '',
				options: [
					{ value: '',       label: __( '— Select —', 'starter-shelter' ) },
					{ value: 'dog',    label: __( 'Dog', 'starter-shelter' ) },
					{ value: 'cat',    label: __( 'Cat', 'starter-shelter' ) },
					{ value: 'bird',   label: __( 'Bird', 'starter-shelter' ) },
					{ value: 'horse',  label: __( 'Horse', 'starter-shelter' ) },
					{ value: 'rabbit', label: __( 'Rabbit', 'starter-shelter' ) },
					{ value: 'other',  label: __( 'Other', 'starter-shelter' ) },
				],
				onChange: function( v ) { m.set( '_sd_pet_species', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			el( TextareaControl, {
				label:    __( 'Tribute Message', 'starter-shelter' ),
				value:    m.meta._sd_tribute_message || '',
				onChange: function( v ) { m.set( '_sd_tribute_message', v ); },
				rows:     6,
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	// ── Panel 2: Donation Info (with donor quick-create) ──

	function DonationInfoPanel() {
		var m = useMemorialMeta();

		// Donor search state.
		var donorOptionsState   = useState( [] );
		var donorOptions        = donorOptionsState[ 0 ];
		var setDonorOptions     = donorOptionsState[ 1 ];

		var selectedLabelState  = useState( '' );
		var selectedLabel       = selectedLabelState[ 0 ];
		var setSelectedLabel    = selectedLabelState[ 1 ];

		// Quick-create state.
		var showQCState         = useState( false );
		var showQC              = showQCState[ 0 ];
		var setShowQC           = showQCState[ 1 ];

		var qcNameState         = useState( '' );
		var qcName              = qcNameState[ 0 ];
		var setQcName           = qcNameState[ 1 ];

		var qcSavingState       = useState( false );
		var qcSaving            = qcSavingState[ 0 ];
		var setQcSaving         = qcSavingState[ 1 ];

		var qcNoticeState       = useState( null );
		var qcNotice            = qcNoticeState[ 0 ];
		var setQcNotice         = qcNoticeState[ 1 ];

		// Load initial donor name when editing an existing memorial.
		useEffect( function() {
			var donorId = m.meta._sd_donor_id;
			if ( donorId && ! selectedLabel ) {
				apiFetch( { path: '/wp/v2/sd_donor/' + donorId } ).then( function( donor ) {
					var name = donor.title.rendered;
					setSelectedLabel( name );
					setDonorOptions( [ { value: String( donorId ), label: name } ] );
				} ).catch( function() {
					// Donor may have been deleted.
					setSelectedLabel( __( '(unknown donor)', 'starter-shelter' ) );
				} );
			}
		}, [] );

		// Search donors as user types.
		var onDonorFilter = function( inputValue ) {
			if ( ! inputValue || inputValue.length < 2 ) {
				return;
			}
			apiFetch( {
				path: '/wp/v2/sd_donor?search=' + encodeURIComponent( inputValue ) + '&per_page=10',
			} ).then( function( results ) {
				setDonorOptions( results.map( function( d ) {
					return { value: String( d.id ), label: d.title.rendered };
				} ) );
			} );
		};

		// Select a donor from the combobox.
		var onDonorChange = function( value ) {
			var id = value ? parseInt( value, 10 ) : 0;
			m.set( '_sd_donor_id', id );

			if ( id ) {
				var match = donorOptions.find( function( o ) { return o.value === value; } );
				if ( match ) {
					setSelectedLabel( match.label );
					// Auto-fill display name if empty.
					if ( ! m.meta._sd_donor_display_name ) {
						m.set( '_sd_donor_display_name', match.label );
					}
				}
			} else {
				setSelectedLabel( '' );
			}
		};

		// Quick-create donor.
		var handleQuickCreate = function() {
			if ( ! qcName.trim() ) { return; }

			setQcSaving( true );
			setQcNotice( null );

			apiFetch( {
				path:   '/starter-shelter/v1/donors/find-or-create',
				method: 'POST',
				data:   { display_name: qcName.trim() },
			} ).then( function( result ) {
				var id    = result.id;
				var name  = result.display_name;
				var isNew = result.created;

				// Update donor combobox.
				setDonorOptions( [ { value: String( id ), label: name } ] );
				setSelectedLabel( name );
				m.set( '_sd_donor_id', id );

				// Auto-fill display name if empty.
				if ( ! m.meta._sd_donor_display_name ) {
					m.set( '_sd_donor_display_name', name );
				}

				setQcNotice( {
					status:  'success',
					message: isNew
						? __( 'New donor created.', 'starter-shelter' )
						: __( 'Linked to existing donor.', 'starter-shelter' ),
				} );
				setQcName( '' );
				setQcSaving( false );

				// Collapse after brief delay.
				setTimeout( function() {
					setShowQC( false );
					setQcNotice( null );
				}, 2000 );
			} ).catch( function( error ) {
				setQcNotice( {
					status:  'error',
					message: error.message || __( 'Failed to create donor.', 'starter-shelter' ),
				} );
				setQcSaving( false );
			} );
		};

		return el( PluginDocumentSettingPanel, {
			name:  'sd-donation-info',
			title: __( 'Donation Info', 'starter-shelter' ),
		},
			// Donor combobox.
			el( ComboboxControl, {
				label:               __( 'Donated By', 'starter-shelter' ),
				value:               m.meta._sd_donor_id ? String( m.meta._sd_donor_id ) : null,
				options:             donorOptions,
				onFilterValueChange: onDonorFilter,
				onChange:             onDonorChange,
				__nextHasNoMarginBottom: true,
			} ),

			// Quick-create toggle.
			el( 'div', { style: { marginBottom: '16px' } },
				el( Button, {
					variant: 'link',
					onClick: function() {
						setShowQC( ! showQC );
						setQcNotice( null );
					},
				}, showQC
					? __( 'Cancel', 'starter-shelter' )
					: __( '+ New Donor', 'starter-shelter' )
				)
			),

			// Quick-create form.
			showQC && el( 'div', {
				style: {
					padding:      '12px',
					background:   '#f0f0f1',
					borderRadius: '4px',
					marginBottom: '16px',
				},
			},
				el( TextControl, {
					label:       __( 'Donor Name', 'starter-shelter' ),
					value:       qcName,
					onChange:     setQcName,
					placeholder: __( 'e.g. John & Mary Smith', 'starter-shelter' ),
					onKeyDown:   function( e ) {
						if ( e.key === 'Enter' ) {
							e.preventDefault();
							handleQuickCreate();
						}
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( Button, {
					variant:  'secondary',
					onClick:  handleQuickCreate,
					isBusy:   qcSaving,
					disabled: ! qcName.trim() || qcSaving,
					style:    { marginTop: '8px' },
				}, __( 'Create Donor', 'starter-shelter' ) ),
				qcNotice && el( Notice, {
					status:      qcNotice.status,
					isDismissible: false,
					style:       { marginTop: '8px' },
				}, qcNotice.message )
			),

			// Display name override.
			el( TextControl, {
				label:    __( 'Display Name', 'starter-shelter' ),
				value:    m.meta._sd_donor_display_name || '',
				onChange: function( v ) { m.set( '_sd_donor_display_name', v ); },
				help:     __( 'Name shown on the memorial wall. Leave empty to pull from donor record.', 'starter-shelter' ),
				__nextHasNoMarginBottom: true,
			} ),

			// Amount.
			el( TextControl, {
				label:    __( 'Amount', 'starter-shelter' ),
				value:    m.meta._sd_amount ? String( m.meta._sd_amount ) : '',
				onChange: function( v ) { m.set( '_sd_amount', parseFloat( v ) || 0 ); },
				type:     'number',
				min:      0,
				step:     '0.01',
				__nextHasNoMarginBottom: true,
			} ),

			// Date.
			el( TextControl, {
				label:    __( 'Date', 'starter-shelter' ),
				value:    ( m.meta._sd_donation_date || '' ).substring( 0, 10 ),
				onChange: function( v ) { m.set( '_sd_donation_date', v ); },
				type:     'date',
				__nextHasNoMarginBottom: true,
			} ),

			// Anonymous toggle.
			el( ToggleControl, {
				label:    __( 'Anonymous', 'starter-shelter' ),
				checked:  !! m.meta._sd_is_anonymous,
				onChange: function( v ) { m.set( '_sd_is_anonymous', v ); },
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	// ── Panel 3: Family Notification ──

	function FamilyNotificationPanel() {
		var m       = useMemorialMeta();
		var enabled = !! m.meta._sd_notify_family_enabled;

		return el( PluginDocumentSettingPanel, {
			name:  'sd-family-notification',
			title: __( 'Family Notification', 'starter-shelter' ),
		},
			el( ToggleControl, {
				label:    __( 'Notify Family', 'starter-shelter' ),
				checked:  enabled,
				onChange: function( v ) { m.set( '_sd_notify_family_enabled', v ); },
				__nextHasNoMarginBottom: true,
			} ),
			enabled && el( Fragment, {},
				el( TextControl, {
					label:    __( 'Family Name', 'starter-shelter' ),
					value:    m.meta._sd_notify_family_name || '',
					onChange: function( v ) { m.set( '_sd_notify_family_name', v ); },
					__nextHasNoMarginBottom: true,
				} ),
				el( TextControl, {
					label:    __( 'Family Email', 'starter-shelter' ),
					value:    m.meta._sd_notify_family_email || '',
					onChange: function( v ) { m.set( '_sd_notify_family_email', v ); },
					type:     'email',
					__nextHasNoMarginBottom: true,
				} )
			),
			m.meta._sd_family_notified_date && el( 'p', {
				style: { color: '#757575', fontSize: '12px', marginTop: '12px' },
			},
				__( 'Notification sent: ', 'starter-shelter' ),
				el( 'strong', {}, m.meta._sd_family_notified_date )
			)
		);
	}

	// ── Pre-publish validation ──

	function PrePublishValidationPanel() {
		var m = useMemorialMeta();

		var errors = [];

		if ( ! m.meta._sd_honoree_name || ! m.meta._sd_honoree_name.trim() ) {
			errors.push( __( 'Honoree Name is required.', 'starter-shelter' ) );
		}
		if ( ! m.meta._sd_memorial_type ) {
			errors.push( __( 'Memorial Type is required.', 'starter-shelter' ) );
		}

		if ( errors.length === 0 ) {
			return null;
		}

		return el( PluginPrePublishPanel, {
			title:          __( 'Memorial: Missing Fields', 'starter-shelter' ),
			initialOpen:    true,
		},
			el( 'ul', { style: { margin: 0, paddingLeft: '20px', color: '#d63638' } },
				errors.map( function( msg ) {
					return el( 'li', { key: msg }, msg );
				} )
			)
		);
	}

	// ── Register ──

	registerPlugin( 'sd-memorial-panels', {
		render: MemorialPanels,
		icon:   'heart',
	} );

} )( window.wp );
