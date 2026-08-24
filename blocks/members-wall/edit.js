( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Members Wall', 'shelterkit-donations' ), initialOpen: true },
                    el( ToggleControl, {
                        label: __( 'Group by tier', 'shelterkit-donations' ),
                        checked: attributes.groupByTier !== false,
                        onChange: function( value ) { setAttributes( { groupByTier: value } ); },
                        help: __( 'Show members under their membership-level heading, highest first.', 'shelterkit-donations' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show business logos', 'shelterkit-donations' ),
                        checked: attributes.showLogos !== false,
                        onChange: function( value ) { setAttributes( { showLogos: value } ); },
                        help: __( 'Business members show their approved logo; individuals always show their name.', 'shelterkit-donations' ),
                    } ),
                    el( RangeControl, {
                        label: __( 'Columns', 'shelterkit-donations' ),
                        value: attributes.columns || 4,
                        min: 1,
                        max: 8,
                        onChange: function( value ) { setAttributes( { columns: value || 4 } ); },
                    } ),
                    el( RangeControl, {
                        label: __( 'Maximum members shown', 'shelterkit-donations' ),
                        value: attributes.maxItems || 200,
                        min: 1,
                        max: 500,
                        onChange: function( value ) { setAttributes( { maxItems: value || 200 } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/members-wall',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/members-wall', {
        edit: Edit,
    } );
} )( window.wp );
