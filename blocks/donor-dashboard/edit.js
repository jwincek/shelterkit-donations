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
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout', 'shelterkit-donations' ),
                        value: attributes.layout || 'cards',
                        options: [
                            { value: 'cards', label: __( 'Cards', 'shelterkit-donations' ) },
                            { value: 'list', label: __( 'List', 'shelterkit-donations' ) },
                            { value: 'compact', label: __( 'Compact', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Stats', 'shelterkit-donations' ),
                        checked: attributes.showStats !== false,
                        onChange: function( value ) { setAttributes( { showStats: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Recent Gifts', 'shelterkit-donations' ),
                        checked: attributes.showRecentGifts !== false,
                        onChange: function( value ) { setAttributes( { showRecentGifts: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Membership', 'shelterkit-donations' ),
                        checked: attributes.showMembership !== false,
                        onChange: function( value ) { setAttributes( { showMembership: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Level', 'shelterkit-donations' ),
                        checked: attributes.showDonorLevel !== false,
                        onChange: function( value ) { setAttributes( { showDonorLevel: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'shelterkit-donations' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Recent Gifts Count', 'shelterkit-donations' ),
                        value: attributes.recentGiftsCount || 5,
                        onChange: function( value ) { setAttributes( { recentGiftsCount: value } ); },
                        min: 1,
                        max: 20,
                    } ),
                    el( TextControl, {
                        label: __( 'Guest Message', 'shelterkit-donations' ),
                        value: attributes.guestMessage || '',
                        onChange: function( value ) { setAttributes( { guestMessage: value } ); },
                        help: __( 'Message shown to logged-out visitors.', 'shelterkit-donations' ),
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/donor-dashboard',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/donor-dashboard', {
        edit: Edit,
    } );
} )( window.wp );
