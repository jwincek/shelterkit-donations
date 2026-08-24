( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl, SelectControl, RangeControl, __experimentalNumberControl: NumberControl } = wp.components;
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
                el( PanelBody, { title: __( 'Amount Options', 'shelterkit-donations' ), initialOpen: false },
                    el( TextControl, {
                        label: __( 'Preset Amounts (comma-separated)', 'shelterkit-donations' ),
                        value: ( attributes.presetAmounts || [] ).join( ', ' ),
                        onChange: function( value ) {
                            var amounts = value.split( ',' ).map( function( n ) {
                                return parseInt( n.trim(), 10 );
                            } ).filter( function( n ) { return ! isNaN( n ) && n > 0; } );
                            setAttributes( { presetAmounts: amounts } );
                        },
                        help: __( 'Example: 25, 50, 100, 250, 500', 'shelterkit-donations' ),
                    } ),
                    NumberControl ? el( NumberControl, {
                        label: __( 'Default Amount', 'shelterkit-donations' ),
                        value: attributes.defaultAmount,
                        onChange: function( value ) { setAttributes( { defaultAmount: parseInt( value, 10 ) || 50 } ); },
                        min: 1,
                    } ) : el( TextControl, {
                        label: __( 'Default Amount', 'shelterkit-donations' ),
                        type: 'number',
                        value: attributes.defaultAmount,
                        onChange: function( value ) { setAttributes( { defaultAmount: parseInt( value, 10 ) || 50 } ); },
                    } ),
                    NumberControl ? el( NumberControl, {
                        label: __( 'Minimum Amount', 'shelterkit-donations' ),
                        value: attributes.minAmount,
                        onChange: function( value ) { setAttributes( { minAmount: parseInt( value, 10 ) || 1 } ); },
                        min: 1,
                    } ) : null,
                    NumberControl ? el( NumberControl, {
                        label: __( 'Maximum Amount', 'shelterkit-donations' ),
                        value: attributes.maxAmount,
                        onChange: function( value ) { setAttributes( { maxAmount: parseInt( value, 10 ) || 100000 } ); },
                        min: 1,
                    } ) : null
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Allocation Selector', 'shelterkit-donations' ),
                        checked: attributes.showAllocation,
                        onChange: function( value ) { setAttributes( { showAllocation: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Anonymous Option', 'shelterkit-donations' ),
                        checked: attributes.showAnonymous,
                        onChange: function( value ) { setAttributes( { showAnonymous: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Secure Badge', 'shelterkit-donations' ),
                        checked: attributes.showSecureBadge,
                        onChange: function( value ) { setAttributes( { showSecureBadge: value } ); },
                    } )
                ),
                blockData.campaigns && blockData.campaigns.length > 1 ? el( PanelBody, { title: __( 'Campaign', 'shelterkit-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Link to Campaign', 'shelterkit-donations' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns,
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                ) : null
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/donation-form',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/donation-form', {
        edit: Edit,
    } );
} )( window.wp );
