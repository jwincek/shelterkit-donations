( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl, RangeControl, ColorPicker, Placeholder, __experimentalNumberControl: NumberControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.shelterDonationsBlocks || {};

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Campaign', 'shelter-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Select Campaign', 'shelter-donations' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [ { value: 0, label: __( '— Select Campaign —', 'shelter-donations' ) } ],
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelter-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Layout', 'shelter-donations' ),
                        value: attributes.layout || 'horizontal',
                        options: [
                            { value: 'horizontal', label: __( 'Horizontal', 'shelter-donations' ) },
                            { value: 'vertical', label: __( 'Vertical', 'shelter-donations' ) },
                            { value: 'compact', label: __( 'Compact', 'shelter-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Title', 'shelter-donations' ),
                        checked: attributes.showTitle !== false,
                        onChange: function( value ) { setAttributes( { showTitle: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Description', 'shelter-donations' ),
                        checked: attributes.showDescription === true,
                        onChange: function( value ) { setAttributes( { showDescription: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'shelter-donations' ),
                        checked: attributes.showDonorCount !== false,
                        onChange: function( value ) { setAttributes( { showDonorCount: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'shelter-donations' ),
                        checked: attributes.showEndDate !== false,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Percentage', 'shelter-donations' ),
                        checked: attributes.showPercentage !== false,
                        onChange: function( value ) { setAttributes( { showPercentage: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'shelter-donations' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Bar Height (px)', 'shelter-donations' ),
                        value: attributes.progressBarHeight || 24,
                        onChange: function( value ) { setAttributes( { progressBarHeight: value } ); },
                        min: 8,
                        max: 48,
                    } )
                ),
                el( PanelBody, { title: __( 'Advanced', 'shelter-donations' ), initialOpen: false },
                    NumberControl ? el( NumberControl, {
                        label: __( 'Auto-Refresh Interval (seconds)', 'shelter-donations' ),
                        value: attributes.refreshInterval || 0,
                        onChange: function( value ) { setAttributes( { refreshInterval: parseInt( value, 10 ) || 0 } ); },
                        min: 0,
                        help: __( 'Set to 0 to disable. Recommended: 30-60 seconds.', 'shelter-donations' ),
                    } ) : null
                )
            ),
            el( 'div', blockProps,
                attributes.campaignId
                    ? el( ServerSideRender, {
                        block: 'shelter-donations/campaign-progress',
                        attributes: attributes,
                    } )
                    : el( Placeholder, {
                        icon: 'chart-line',
                        label: __( 'Campaign Progress', 'shelter-donations' ),
                    }, __( 'Select a campaign in the sidebar.', 'shelter-donations' ) )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/campaign-progress', {
        edit: Edit,
    } );
} )( window.wp );
