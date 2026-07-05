( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, ToggleControl, ColorPalette, Placeholder } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.shelterDonationsBlocks || {};

    const SWATCHES = [
        { name: __( 'Shelter Green', 'shelter-donations' ), color: '#059669' },
        { name: __( 'Ocean', 'shelter-donations' ),          color: '#0284c7' },
        { name: __( 'Sunset', 'shelter-donations' ),         color: '#f59e0b' },
        { name: __( 'Rose', 'shelter-donations' ),           color: '#e11d48' },
        { name: __( 'Slate', 'shelter-donations' ),          color: '#475569' },
    ];

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
                        onChange: function( value ) {
                            const id = parseInt( value, 10 );
                            setAttributes( { campaignId: id > 0 ? id : undefined } );
                        },
                        help: __( 'Leave blank to auto-select the first active campaign at render time.', 'shelter-donations' ),
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelter-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Goal', 'shelter-donations' ),
                        checked: attributes.showGoal !== false,
                        onChange: function( value ) { setAttributes( { showGoal: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Raised / Joined', 'shelter-donations' ),
                        checked: attributes.showRaised !== false,
                        onChange: function( value ) { setAttributes( { showRaised: value } ); },
                        help: __( 'Donation drives show $ raised; membership drives show count joined.', 'shelter-donations' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'shelter-donations' ),
                        checked: attributes.showDonors !== false,
                        onChange: function( value ) { setAttributes( { showDonors: value } ); },
                        help: __( 'Hidden automatically on membership drives.', 'shelter-donations' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'shelter-donations' ),
                        checked: attributes.showEndDate !== false,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donate Button', 'shelter-donations' ),
                        checked: attributes.showDonateButton !== false,
                        onChange: function( value ) { setAttributes( { showDonateButton: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'shelter-donations' ), initialOpen: false },
                    el( 'div', { className: 'sd-campaign-card__color-control' },
                        el( 'p', { className: 'components-base-control__label' }, __( 'Bar Color', 'shelter-donations' ) ),
                        el( ColorPalette, {
                            colors: SWATCHES,
                            value: attributes.progressBarColor || '#059669',
                            onChange: function( value ) { setAttributes( { progressBarColor: value || '#059669' } ); },
                            clearable: false,
                        } )
                    )
                )
            ),
            el( 'div', blockProps,
                attributes.campaignId
                    ? el( ServerSideRender, {
                        block: 'shelter-donations/campaign-card',
                        attributes: attributes,
                    } )
                    : el( Placeholder, {
                        icon: 'megaphone',
                        label: __( 'Campaign Card', 'shelter-donations' ),
                    }, __( 'Select a campaign in the sidebar. If left blank, the block renders the first active campaign at view time.', 'shelter-donations' ) )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/campaign-card', {
        edit: Edit,
    } );
} )( window.wp );
