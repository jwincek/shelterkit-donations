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
                el( PanelBody, { title: __( 'Display Options', 'shelter-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout', 'shelter-donations' ),
                        value: attributes.layout || 'cards',
                        options: [
                            { value: 'cards', label: __( 'Cards', 'shelter-donations' ) },
                            { value: 'list', label: __( 'List', 'shelter-donations' ) },
                            { value: 'compact', label: __( 'Compact', 'shelter-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Stats', 'shelter-donations' ),
                        checked: attributes.showStats !== false,
                        onChange: function( value ) { setAttributes( { showStats: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Recent Gifts', 'shelter-donations' ),
                        checked: attributes.showRecentGifts !== false,
                        onChange: function( value ) { setAttributes( { showRecentGifts: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Membership', 'shelter-donations' ),
                        checked: attributes.showMembership !== false,
                        onChange: function( value ) { setAttributes( { showMembership: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Level', 'shelter-donations' ),
                        checked: attributes.showDonorLevel !== false,
                        onChange: function( value ) { setAttributes( { showDonorLevel: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'shelter-donations' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Recent Gifts Count', 'shelter-donations' ),
                        value: attributes.recentGiftsCount || 5,
                        onChange: function( value ) { setAttributes( { recentGiftsCount: value } ); },
                        min: 1,
                        max: 20,
                    } ),
                    el( TextControl, {
                        label: __( 'Guest Message', 'shelter-donations' ),
                        value: attributes.guestMessage || '',
                        onChange: function( value ) { setAttributes( { guestMessage: value } ); },
                        help: __( 'Message shown to logged-out visitors.', 'shelter-donations' ),
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
