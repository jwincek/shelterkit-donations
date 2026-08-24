( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl, SelectControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.shelterDonationsBlocks || {};

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Form Settings', 'shelterkit-donations' ), initialOpen: true },
                    el( TextControl, {
                        label: __( 'Title', 'shelterkit-donations' ),
                        value: attributes.title || '',
                        onChange: function( value ) { setAttributes( { title: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Subtitle', 'shelterkit-donations' ),
                        value: attributes.subtitle || '',
                        onChange: function( value ) { setAttributes( { subtitle: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Submit Button Text', 'shelterkit-donations' ),
                        value: attributes.submitButtonText || '',
                        onChange: function( value ) { setAttributes( { submitButtonText: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Membership Type', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Default Type', 'shelterkit-donations' ),
                        value: attributes.membershipType || 'individual',
                        options: [
                            { value: 'individual', label: __( 'Individual', 'shelterkit-donations' ) },
                            { value: 'business', label: __( 'Business / Sponsor', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { membershipType: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Type Toggle', 'shelterkit-donations' ),
                        help: __( 'Allow users to switch between individual and business.', 'shelterkit-donations' ),
                        checked: attributes.showTypeToggle,
                        onChange: function( value ) { setAttributes( { showTypeToggle: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Layout', 'shelterkit-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Tier Display', 'shelterkit-donations' ),
                        value: attributes.layout || 'cards',
                        options: [
                            { value: 'cards', label: __( 'Cards', 'shelterkit-donations' ) },
                            { value: 'table', label: __( 'Comparison Table', 'shelterkit-donations' ) },
                            { value: 'list', label: __( 'Simple List', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( RangeControl, {
                        label: __( 'Columns (Cards)', 'shelterkit-donations' ),
                        value: attributes.columns || 3,
                        onChange: function( value ) { setAttributes( { columns: value } ); },
                        min: 1,
                        max: 4,
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Benefits List', 'shelterkit-donations' ),
                        checked: attributes.showBenefits,
                        onChange: function( value ) { setAttributes( { showBenefits: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Options', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Anonymous Option', 'shelterkit-donations' ),
                        checked: attributes.showAnonymous,
                        onChange: function( value ) { setAttributes( { showAnonymous: value } ); },
                    } )
                ),
                // Hide the panel when no campaigns exist yet (only the
                // "— Select Campaign —" sentinel). Matches the gate in
                // donation-form/edit.js.
                blockData.campaigns && blockData.campaigns.length > 1 ? el( PanelBody, { title: __( 'Campaign', 'shelterkit-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Link to Campaign', 'shelterkit-donations' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns,
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                        help: __( 'When set, every signup through this form attaches to the campaign. Leave unset to let the form auto-tag from ?campaign={id} in the URL.', 'shelterkit-donations' ),
                    } )
                ) : null
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/membership-form',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/membership-form', {
        edit: Edit,
    } );
} )( window.wp );
