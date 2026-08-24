/**
 * Shelter Donations - Block Editor Scripts (No-Build)
 *
 * Provides ServerSideRender-based editing with InspectorControls
 * for all shelter donation blocks without requiring a build step.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

( function( wp ) {
    'use strict';

    const { registerBlockType, getBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const {
        PanelBody,
        TextControl,
        NumberControl,
        ToggleControl,
        SelectControl,
        RangeControl,
        ColorPicker,
        Placeholder,
        Spinner,
    } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    // Get localized data.
    const blockData = window.shelterDonationsBlocks || {};

    /* ==========================================================================
       Donation Form Block
       ========================================================================== */

    const donationFormEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Form Settings', 'shelterkit-donations' ), initialOpen: true },
                    el( TextControl, {
                        label: __( 'Title', 'shelterkit-donations' ),
                        value: attributes.title,
                        onChange: function( value ) { setAttributes( { title: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Subtitle', 'shelterkit-donations' ),
                        value: attributes.subtitle,
                        onChange: function( value ) { setAttributes( { subtitle: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Submit Button Text', 'shelterkit-donations' ),
                        value: attributes.submitButtonText,
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
                            } ).filter( function( n ) { return ! isNaN( n ); } );
                            setAttributes( { presetAmounts: amounts } );
                        },
                        help: __( 'Example: 25, 50, 100, 250, 500', 'shelterkit-donations' ),
                    } ),
                    el( NumberControl, {
                        label: __( 'Default Amount', 'shelterkit-donations' ),
                        value: attributes.defaultAmount,
                        onChange: function( value ) { setAttributes( { defaultAmount: parseInt( value, 10 ) } ); },
                        min: 1,
                    } ),
                    el( NumberControl, {
                        label: __( 'Minimum Amount', 'shelterkit-donations' ),
                        value: attributes.minAmount,
                        onChange: function( value ) { setAttributes( { minAmount: parseInt( value, 10 ) } ); },
                        min: 1,
                    } ),
                    el( NumberControl, {
                        label: __( 'Maximum Amount', 'shelterkit-donations' ),
                        value: attributes.maxAmount,
                        onChange: function( value ) { setAttributes( { maxAmount: parseInt( value, 10 ) } ); },
                        min: 1,
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Allocation Selector', 'shelterkit-donations' ),
                        checked: attributes.showAllocation,
                        onChange: function( value ) { setAttributes( { showAllocation: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Dedication Field', 'shelterkit-donations' ),
                        checked: attributes.showDedication,
                        onChange: function( value ) { setAttributes( { showDedication: value } ); },
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
                el( PanelBody, { title: __( 'Campaign', 'shelterkit-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Link to Campaign', 'shelterkit-donations' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [],
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/donation-form',
                    attributes: attributes,
                    EmptyResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'money-alt',
                            label: __( 'Donation Form', 'shelterkit-donations' ),
                        }, __( 'Configure the form settings in the sidebar.', 'shelterkit-donations' ) );
                    },
                    LoadingResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'money-alt',
                            label: __( 'Donation Form', 'shelterkit-donations' ),
                        }, el( Spinner ) );
                    },
                } )
            )
        );
    };

    // Re-register with edit function (block.json handles the rest).
    wp.domReady( function() {
        var block = getBlockType( 'shelter-donations/donation-form' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'shelter-donations/donation-form' );
            registerBlockType( 'shelter-donations/donation-form', Object.assign( {}, block, {
                edit: donationFormEdit,
            } ) );
        }
    } );

    /* ==========================================================================
       Memorial Wall Block
       ========================================================================== */

    const memorialWallEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Layout', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout Style', 'shelterkit-donations' ),
                        value: attributes.layout,
                        options: [
                            { value: 'grid', label: __( 'Grid', 'shelterkit-donations' ) },
                            { value: 'masonry', label: __( 'Masonry', 'shelterkit-donations' ) },
                            { value: 'list', label: __( 'List', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( RangeControl, {
                        label: __( 'Columns', 'shelterkit-donations' ),
                        value: attributes.columns,
                        onChange: function( value ) { setAttributes( { columns: value } ); },
                        min: 1,
                        max: 6,
                    } ),
                    el( SelectControl, {
                        label: __( 'Card Style', 'shelterkit-donations' ),
                        value: attributes.cardStyle,
                        options: [
                            { value: 'elevated', label: __( 'Elevated (Shadow)', 'shelterkit-donations' ) },
                            { value: 'flat', label: __( 'Flat', 'shelterkit-donations' ) },
                            { value: 'bordered', label: __( 'Bordered', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { cardStyle: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'shelterkit-donations' ), initialOpen: false },
                    el( NumberControl, {
                        label: __( 'Items Per Page', 'shelterkit-donations' ),
                        value: attributes.perPage,
                        onChange: function( value ) { setAttributes( { perPage: parseInt( value, 10 ) } ); },
                        min: 1,
                        max: 50,
                    } ),
                    el( SelectControl, {
                        label: __( 'Default Type Filter', 'shelterkit-donations' ),
                        value: attributes.defaultType,
                        options: [
                            { value: 'all', label: __( 'All', 'shelterkit-donations' ) },
                            { value: 'human', label: __( 'People', 'shelterkit-donations' ) },
                            { value: 'pet', label: __( 'Pets', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { defaultType: value } ); },
                    } ),
                    el( NumberControl, {
                        label: __( 'Truncate Tribute (characters)', 'shelterkit-donations' ),
                        value: attributes.truncateTribute,
                        onChange: function( value ) { setAttributes( { truncateTribute: parseInt( value, 10 ) } ); },
                        min: 50,
                        max: 500,
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Search', 'shelterkit-donations' ),
                        checked: attributes.showSearch,
                        onChange: function( value ) { setAttributes( { showSearch: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Filters', 'shelterkit-donations' ),
                        checked: attributes.showFilters,
                        onChange: function( value ) { setAttributes( { showFilters: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Year Filter', 'shelterkit-donations' ),
                        checked: attributes.showYearFilter,
                        onChange: function( value ) { setAttributes( { showYearFilter: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Pagination', 'shelterkit-donations' ),
                        checked: attributes.showPagination,
                        onChange: function( value ) { setAttributes( { showPagination: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Images', 'shelterkit-donations' ),
                        checked: attributes.showImage,
                        onChange: function( value ) { setAttributes( { showImage: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Name', 'shelterkit-donations' ),
                        checked: attributes.showDonorName,
                        onChange: function( value ) { setAttributes( { showDonorName: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Date', 'shelterkit-donations' ),
                        checked: attributes.showDate,
                        onChange: function( value ) { setAttributes( { showDate: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Advanced', 'shelterkit-donations' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Enable Router Navigation', 'shelterkit-donations' ),
                        checked: attributes.enableRouterNavigation,
                        onChange: function( value ) { setAttributes( { enableRouterNavigation: value } ); },
                        help: __( 'SPA-like navigation without full page reloads.', 'shelterkit-donations' ),
                    } ),
                    el( TextControl, {
                        label: __( 'Empty Message', 'shelterkit-donations' ),
                        value: attributes.emptyMessage,
                        onChange: function( value ) { setAttributes( { emptyMessage: value } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/memorial-wall',
                    attributes: attributes,
                    EmptyResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'heart',
                            label: __( 'Memorial Wall', 'shelterkit-donations' ),
                        }, __( 'No memorials found. Add some memorial donations first.', 'shelterkit-donations' ) );
                    },
                    LoadingResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'heart',
                            label: __( 'Memorial Wall', 'shelterkit-donations' ),
                        }, el( Spinner ) );
                    },
                } )
            )
        );
    };

    wp.domReady( function() {
        var block = getBlockType( 'shelter-donations/memorial-wall' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'shelter-donations/memorial-wall' );
            registerBlockType( 'shelter-donations/memorial-wall', Object.assign( {}, block, {
                edit: memorialWallEdit,
            } ) );
        }
    } );

    /* ==========================================================================
       Campaign Progress Block
       ========================================================================== */

    const campaignProgressEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Campaign', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Select Campaign', 'shelterkit-donations' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [],
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Layout', 'shelterkit-donations' ),
                        value: attributes.layout,
                        options: [
                            { value: 'horizontal', label: __( 'Horizontal', 'shelterkit-donations' ) },
                            { value: 'vertical', label: __( 'Vertical', 'shelterkit-donations' ) },
                            { value: 'compact', label: __( 'Compact', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Title', 'shelterkit-donations' ),
                        checked: attributes.showTitle,
                        onChange: function( value ) { setAttributes( { showTitle: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Description', 'shelterkit-donations' ),
                        checked: attributes.showDescription,
                        onChange: function( value ) { setAttributes( { showDescription: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'shelterkit-donations' ),
                        checked: attributes.showDonorCount,
                        onChange: function( value ) { setAttributes( { showDonorCount: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'shelterkit-donations' ),
                        checked: attributes.showEndDate,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Percentage', 'shelterkit-donations' ),
                        checked: attributes.showPercentage,
                        onChange: function( value ) { setAttributes( { showPercentage: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'shelterkit-donations' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Bar Height (px)', 'shelterkit-donations' ),
                        value: attributes.progressBarHeight,
                        onChange: function( value ) { setAttributes( { progressBarHeight: value } ); },
                        min: 8,
                        max: 48,
                    } ),
                    el( 'div', { style: { marginBottom: '16px' } },
                        el( 'label', { style: { display: 'block', marginBottom: '8px' } },
                            __( 'Progress Bar Color', 'shelterkit-donations' )
                        ),
                        el( ColorPicker, {
                            color: attributes.progressBarColor,
                            onChangeComplete: function( value ) { setAttributes( { progressBarColor: value.hex } ); },
                        } )
                    )
                ),
                el( PanelBody, { title: __( 'Advanced', 'shelterkit-donations' ), initialOpen: false },
                    el( NumberControl, {
                        label: __( 'Auto-Refresh Interval (seconds)', 'shelterkit-donations' ),
                        value: attributes.refreshInterval,
                        onChange: function( value ) { setAttributes( { refreshInterval: parseInt( value, 10 ) } ); },
                        min: 0,
                        help: __( 'Set to 0 to disable. Recommended: 30-60 seconds.', 'shelterkit-donations' ),
                    } )
                )
            ),
            el( 'div', blockProps,
                attributes.campaignId
                    ? el( ServerSideRender, {
                        block: 'shelter-donations/campaign-progress',
                        attributes: attributes,
                        LoadingResponsePlaceholder: function() {
                            return el( Placeholder, {
                                icon: 'chart-line',
                                label: __( 'Campaign Progress', 'shelterkit-donations' ),
                            }, el( Spinner ) );
                        },
                    } )
                    : el( Placeholder, {
                        icon: 'chart-line',
                        label: __( 'Campaign Progress', 'shelterkit-donations' ),
                    }, __( 'Select a campaign in the sidebar.', 'shelterkit-donations' ) )
            )
        );
    };

    wp.domReady( function() {
        var block = getBlockType( 'shelter-donations/campaign-progress' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'shelter-donations/campaign-progress' );
            registerBlockType( 'shelter-donations/campaign-progress', Object.assign( {}, block, {
                edit: campaignProgressEdit,
            } ) );
        }
    } );

    /* ==========================================================================
       Donor Dashboard Block
       ========================================================================== */

    const donorDashboardEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Display Options', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout', 'shelterkit-donations' ),
                        value: attributes.layout,
                        options: [
                            { value: 'cards', label: __( 'Cards', 'shelterkit-donations' ) },
                            { value: 'list', label: __( 'List', 'shelterkit-donations' ) },
                            { value: 'compact', label: __( 'Compact', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Stats', 'shelterkit-donations' ),
                        checked: attributes.showStats,
                        onChange: function( value ) { setAttributes( { showStats: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Recent Gifts', 'shelterkit-donations' ),
                        checked: attributes.showRecentGifts,
                        onChange: function( value ) { setAttributes( { showRecentGifts: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Membership', 'shelterkit-donations' ),
                        checked: attributes.showMembership,
                        onChange: function( value ) { setAttributes( { showMembership: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Level', 'shelterkit-donations' ),
                        checked: attributes.showDonorLevel,
                        onChange: function( value ) { setAttributes( { showDonorLevel: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'shelterkit-donations' ), initialOpen: false },
                    el( NumberControl, {
                        label: __( 'Recent Gifts Count', 'shelterkit-donations' ),
                        value: attributes.recentGiftsCount,
                        onChange: function( value ) { setAttributes( { recentGiftsCount: parseInt( value, 10 ) } ); },
                        min: 1,
                        max: 20,
                    } ),
                    el( TextControl, {
                        label: __( 'Guest Message', 'shelterkit-donations' ),
                        value: attributes.guestMessage,
                        onChange: function( value ) { setAttributes( { guestMessage: value } ); },
                        help: __( 'Message shown to logged-out visitors.', 'shelterkit-donations' ),
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/donor-dashboard',
                    attributes: attributes,
                    LoadingResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'id-alt',
                            label: __( 'Donor Dashboard', 'shelterkit-donations' ),
                        }, el( Spinner ) );
                    },
                } )
            )
        );
    };

    wp.domReady( function() {
        var block = getBlockType( 'shelter-donations/donor-dashboard' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'shelter-donations/donor-dashboard' );
            registerBlockType( 'shelter-donations/donor-dashboard', Object.assign( {}, block, {
                edit: donorDashboardEdit,
            } ) );
        }
    } );

} )( window.wp );
