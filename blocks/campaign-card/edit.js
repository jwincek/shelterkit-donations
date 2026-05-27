( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, ToggleControl, ColorPalette, Placeholder } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.starterShelterBlocks || {};

    const SWATCHES = [
        { name: __( 'Shelter Green', 'starter-shelter' ), color: '#059669' },
        { name: __( 'Ocean', 'starter-shelter' ),          color: '#0284c7' },
        { name: __( 'Sunset', 'starter-shelter' ),         color: '#f59e0b' },
        { name: __( 'Rose', 'starter-shelter' ),           color: '#e11d48' },
        { name: __( 'Slate', 'starter-shelter' ),          color: '#475569' },
    ];

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Campaign', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Select Campaign', 'starter-shelter' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [ { value: 0, label: __( '— Select Campaign —', 'starter-shelter' ) } ],
                        onChange: function( value ) {
                            const id = parseInt( value, 10 );
                            setAttributes( { campaignId: id > 0 ? id : undefined } );
                        },
                        help: __( 'Leave blank to auto-select the first active campaign at render time.', 'starter-shelter' ),
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Goal', 'starter-shelter' ),
                        checked: attributes.showGoal !== false,
                        onChange: function( value ) { setAttributes( { showGoal: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Raised / Joined', 'starter-shelter' ),
                        checked: attributes.showRaised !== false,
                        onChange: function( value ) { setAttributes( { showRaised: value } ); },
                        help: __( 'Donation drives show $ raised; membership drives show count joined.', 'starter-shelter' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'starter-shelter' ),
                        checked: attributes.showDonors !== false,
                        onChange: function( value ) { setAttributes( { showDonors: value } ); },
                        help: __( 'Hidden automatically on membership drives.', 'starter-shelter' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'starter-shelter' ),
                        checked: attributes.showEndDate !== false,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donate Button', 'starter-shelter' ),
                        checked: attributes.showDonateButton !== false,
                        onChange: function( value ) { setAttributes( { showDonateButton: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'starter-shelter' ), initialOpen: false },
                    el( 'div', { className: 'sd-campaign-card__color-control' },
                        el( 'p', { className: 'components-base-control__label' }, __( 'Bar Color', 'starter-shelter' ) ),
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
                        block: 'starter-shelter/campaign-card',
                        attributes: attributes,
                    } )
                    : el( Placeholder, {
                        icon: 'megaphone',
                        label: __( 'Campaign Card', 'starter-shelter' ),
                    }, __( 'Select a campaign in the sidebar. If left blank, the block renders the first active campaign at view time.', 'starter-shelter' ) )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/campaign-card', {
        edit: Edit,
    } );
} )( window.wp );
