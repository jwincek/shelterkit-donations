( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl, SelectControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

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
                    } ),
                    el( RangeControl, {
                        label: __( 'Default Amount', 'shelterkit-donations' ),
                        value: attributes.defaultAmount || 50,
                        onChange: function( value ) { setAttributes( { defaultAmount: value } ); },
                        min: 1,
                        max: 1000,
                    } ),
                    el( RangeControl, {
                        label: __( 'Minimum Amount', 'shelterkit-donations' ),
                        value: attributes.minAmount || 10,
                        onChange: function( value ) { setAttributes( { minAmount: value } ); },
                        min: 1,
                        max: 100,
                    } )
                ),
                el( PanelBody, { title: __( 'Default Values', 'shelterkit-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Default Dedication Type', 'shelterkit-donations' ),
                        value: attributes.defaultDedicationType || 'memory',
                        options: [
                            { value: 'memory', label: __( 'In Memory Of', 'shelterkit-donations' ) },
                            { value: 'honor', label: __( 'In Honor Of', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { defaultDedicationType: value } ); },
                    } ),
                    el( SelectControl, {
                        label: __( 'Default Honoree Type', 'shelterkit-donations' ),
                        value: attributes.defaultHonoreeType || 'person',
                        options: [
                            { value: 'person', label: __( 'Person', 'shelterkit-donations' ) },
                            { value: 'pet', label: __( 'Pet', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { defaultHonoreeType: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Anonymous Option', 'shelterkit-donations' ),
                        checked: attributes.showAnonymous,
                        onChange: function( value ) { setAttributes( { showAnonymous: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Family Notification', 'shelterkit-donations' ),
                        checked: attributes.showFamilyNotification,
                        onChange: function( value ) { setAttributes( { showFamilyNotification: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Send Card Option', 'shelterkit-donations' ),
                        checked: attributes.showSendCard,
                        onChange: function( value ) { setAttributes( { showSendCard: value } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/memorial-form',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/memorial-form', {
        edit: Edit,
    } );
} )( window.wp );
