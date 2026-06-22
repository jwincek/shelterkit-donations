( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();
        const intervalSeconds = Math.round( ( attributes.intervalMs || 5000 ) / 1000 );

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Slideshow', 'starter-shelter' ), initialOpen: true },
                    el( ToggleControl, {
                        label: __( 'Show business name', 'starter-shelter' ),
                        checked: attributes.showName !== false,
                        onChange: function( value ) { setAttributes( { showName: value } ); },
                        help: __( 'Caption each logo with the business name.', 'starter-shelter' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Autoplay', 'starter-shelter' ),
                        checked: attributes.autoplay !== false,
                        onChange: function( value ) { setAttributes( { autoplay: value } ); },
                        help: __( 'Auto-advance slides. Pauses on hover/focus and respects reduced-motion settings.', 'starter-shelter' ),
                    } ),
                    el( RangeControl, {
                        label: __( 'Autoplay interval (seconds)', 'starter-shelter' ),
                        value: intervalSeconds,
                        min: 2,
                        max: 15,
                        disabled: attributes.autoplay === false,
                        onChange: function( value ) { setAttributes( { intervalMs: ( value || 5 ) * 1000 } ); },
                    } ),
                    el( RangeControl, {
                        label: __( 'Maximum members shown', 'starter-shelter' ),
                        value: attributes.maxItems || 50,
                        min: 1,
                        max: 100,
                        onChange: function( value ) { setAttributes( { maxItems: value || 50 } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/business-slideshow',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/business-slideshow', {
        edit: Edit,
    } );
} )( window.wp );
