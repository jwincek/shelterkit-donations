( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, ToggleControl, ColorPalette, Placeholder } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.shelterDonationsBlocks || {};

    const SWATCHES = [
        { name: __( 'Shelter Green', 'shelterkit-donations' ), color: '#059669' },
        { name: __( 'Ocean', 'shelterkit-donations' ),          color: '#0284c7' },
        { name: __( 'Sunset', 'shelterkit-donations' ),         color: '#f59e0b' },
        { name: __( 'Rose', 'shelterkit-donations' ),           color: '#e11d48' },
        { name: __( 'Slate', 'shelterkit-donations' ),          color: '#475569' },
    ];

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Campaign', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Select Campaign', 'shelterkit-donations' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [ { value: 0, label: __( '— Select Campaign —', 'shelterkit-donations' ) } ],
                        onChange: function( value ) {
                            const id = parseInt( value, 10 );
                            setAttributes( { campaignId: id > 0 ? id : undefined } );
                        },
                        help: __( 'Leave blank to auto-select the first active campaign at render time.', 'shelterkit-donations' ),
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Goal', 'shelterkit-donations' ),
                        checked: attributes.showGoal !== false,
                        onChange: function( value ) { setAttributes( { showGoal: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Raised / Joined', 'shelterkit-donations' ),
                        checked: attributes.showRaised !== false,
                        onChange: function( value ) { setAttributes( { showRaised: value } ); },
                        help: __( 'Donation drives show $ raised; membership drives show count joined.', 'shelterkit-donations' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'shelterkit-donations' ),
                        checked: attributes.showDonors !== false,
                        onChange: function( value ) { setAttributes( { showDonors: value } ); },
                        help: __( 'Hidden automatically on membership drives.', 'shelterkit-donations' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'shelterkit-donations' ),
                        checked: attributes.showEndDate !== false,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donate Button', 'shelterkit-donations' ),
                        checked: attributes.showDonateButton !== false,
                        onChange: function( value ) { setAttributes( { showDonateButton: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'shelterkit-donations' ), initialOpen: false },
                    el( 'div', { className: 'sd-campaign-card__color-control' },
                        el( 'p', { className: 'components-base-control__label' }, __( 'Bar Color', 'shelterkit-donations' ) ),
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
                        label: __( 'Campaign Card', 'shelterkit-donations' ),
                    }, __( 'Select a campaign in the sidebar. If left blank, the block renders the first active campaign at view time.', 'shelterkit-donations' ) )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/campaign-card', {
        edit: Edit,
    } );
} )( window.wp );
