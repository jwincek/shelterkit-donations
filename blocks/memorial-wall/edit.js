/**
 * Memorial Wall Block — Editor Component
 *
 * No-build approach using wp.* globals.
 * Uses ServerSideRender for live preview in the editor.
 *
 * @package Starter_Shelter
 * @since   2.1.0
 */

( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const {
        InspectorControls,
        BlockControls,
        useBlockProps,
    } = wp.blockEditor;
    const {
        PanelBody,
        TextControl,
        ToggleControl,
        SelectControl,
        RangeControl,
        ToolbarGroup,
        ToolbarButton,
        Placeholder,
        Tip,
    } = wp.components;
    const { __ } = wp.i18n;
    const { useSelect } = wp.data;
    const ServerSideRender = wp.serverSideRender;

    // Layout icons as SVG paths.
    const layoutIcons = {
        grid: el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
            el( 'path', { fill: 'currentColor', d: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z' } )
        ),
        masonry: el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
            el( 'path', { fill: 'currentColor', d: 'M4 4h6v8H4V4zm10 0h6v4h-6V4zM4 14h6v6H4v-6zm10 6h6v-8h-6v8z' } )
        ),
        list: el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
            el( 'path', { fill: 'currentColor', d: 'M4 5h16v3H4V5zm0 5h16v3H4v-3zm0 5h16v3H4v-3z' } )
        ),
    };

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        // Check if memorials exist.
        const hasMemorials = useSelect( function( select ) {
            const posts = select( 'core' ).getEntityRecords( 'postType', 'sd_memorial', {
                per_page: 1,
                status: 'publish',
            } );
            // null means still loading, empty array means no posts.
            return posts === null ? null : posts.length > 0;
        }, [] );

        const currentLayout = attributes.layout || 'grid';

        return el( Fragment, {},

            // ─── Block Toolbar ─────────────────────────────────────────
            el( BlockControls, {},
                el( ToolbarGroup, {},
                    el( ToolbarButton, {
                        icon: layoutIcons.grid,
                        title: __( 'Grid Layout', 'shelter-donations' ),
                        isPressed: currentLayout === 'grid',
                        onClick: function() { setAttributes( { layout: 'grid' } ); },
                    } ),
                    el( ToolbarButton, {
                        icon: layoutIcons.masonry,
                        title: __( 'Masonry Layout', 'shelter-donations' ),
                        isPressed: currentLayout === 'masonry',
                        onClick: function() { setAttributes( { layout: 'masonry' } ); },
                    } ),
                    el( ToolbarButton, {
                        icon: layoutIcons.list,
                        title: __( 'List Layout', 'shelter-donations' ),
                        isPressed: currentLayout === 'list',
                        onClick: function() { setAttributes( { layout: 'list' } ); },
                    } )
                )
            ),

            // ─── Inspector Controls ────────────────────────────────────
            el( InspectorControls, {},

                // Quick tip.
                el( 'div', { style: { padding: '0 16px 16px' } },
                    el( Tip, {},
                        __( 'Visitors can search by honoree name or donor name. Use the toolbar to quickly switch layouts.', 'shelter-donations' )
                    )
                ),

                // Layout panel.
                el( PanelBody, {
                    title: __( 'Layout', 'shelter-donations' ),
                    initialOpen: true,
                },
                    el( SelectControl, {
                        label: __( 'Layout Style', 'shelter-donations' ),
                        value: attributes.layout || 'grid',
                        options: [
                            { value: 'grid',    label: __( 'Grid', 'shelter-donations' ) },
                            { value: 'masonry', label: __( 'Masonry', 'shelter-donations' ) },
                            { value: 'list',    label: __( 'List', 'shelter-donations' ) },
                        ],
                        onChange: function( val ) { setAttributes( { layout: val } ); },
                        help: __( 'Grid: uniform cards. Masonry: variable heights. List: single column.', 'shelter-donations' ),
                    } ),
                    currentLayout !== 'list' && el( RangeControl, {
                        label: __( 'Columns', 'shelter-donations' ),
                        value: attributes.columns || 3,
                        onChange: function( val ) { setAttributes( { columns: val } ); },
                        min: 1,
                        max: 6,
                        help: __( 'Number of columns on desktop. Adjusts responsively on smaller screens.', 'shelter-donations' ),
                    } ),
                    el( SelectControl, {
                        label: __( 'Card Style', 'shelter-donations' ),
                        value: attributes.cardStyle || 'elevated',
                        options: [
                            { value: 'elevated', label: __( 'Elevated (Shadow)', 'shelter-donations' ) },
                            { value: 'flat',     label: __( 'Flat', 'shelter-donations' ) },
                            { value: 'bordered', label: __( 'Bordered', 'shelter-donations' ) },
                        ],
                        onChange: function( val ) { setAttributes( { cardStyle: val } ); },
                    } )
                ),

                // Content panel.
                el( PanelBody, {
                    title: __( 'Content', 'shelter-donations' ),
                    initialOpen: false,
                },
                    el( RangeControl, {
                        label: __( 'Items Per Page', 'shelter-donations' ),
                        value: attributes.perPage || 12,
                        onChange: function( val ) { setAttributes( { perPage: val } ); },
                        min: 1,
                        max: 50,
                    } ),
                    el( SelectControl, {
                        label: __( 'Default Type Filter', 'shelter-donations' ),
                        value: attributes.defaultType || 'all',
                        options: [
                            { value: 'all',    label: __( 'All', 'shelter-donations' ) },
                            { value: 'person', label: __( 'People', 'shelter-donations' ) },
                            { value: 'pet',    label: __( 'Pets', 'shelter-donations' ) },
                        ],
                        onChange: function( val ) { setAttributes( { defaultType: val } ); },
                        help: __( 'Which memorials to show when the page first loads.', 'shelter-donations' ),
                    } ),
                    el( RangeControl, {
                        label: __( 'Truncate Tribute (characters)', 'shelter-donations' ),
                        value: attributes.truncateTribute || 100,
                        onChange: function( val ) { setAttributes( { truncateTribute: val } ); },
                        min: 50,
                        max: 500,
                        help: __( 'Maximum characters shown in card. Full text visible on single memorial page.', 'shelter-donations' ),
                    } )
                ),

                // Display options panel.
                el( PanelBody, {
                    title: __( 'Display Options', 'shelter-donations' ),
                    initialOpen: false,
                },
                    el( ToggleControl, {
                        label: __( 'Show Search', 'shelter-donations' ),
                        checked: attributes.showSearch !== false,
                        onChange: function( val ) { setAttributes( { showSearch: val } ); },
                        help: __( 'Allow visitors to search tributes by honoree or donor name.', 'shelter-donations' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Filters', 'shelter-donations' ),
                        checked: attributes.showFilters !== false,
                        onChange: function( val ) { setAttributes( { showFilters: val } ); },
                    } ),
                    // Only show year filter toggle if filters are enabled.
                    attributes.showFilters !== false && el( ToggleControl, {
                        label: __( 'Show Year Filter', 'shelter-donations' ),
                        checked: attributes.showYearFilter !== false,
                        onChange: function( val ) { setAttributes( { showYearFilter: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Pagination', 'shelter-donations' ),
                        checked: attributes.showPagination !== false,
                        onChange: function( val ) { setAttributes( { showPagination: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Images', 'shelter-donations' ),
                        checked: attributes.showImage !== false,
                        onChange: function( val ) { setAttributes( { showImage: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Name', 'shelter-donations' ),
                        checked: attributes.showDonorName !== false,
                        onChange: function( val ) { setAttributes( { showDonorName: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Date', 'shelter-donations' ),
                        checked: attributes.showDate !== false,
                        onChange: function( val ) { setAttributes( { showDate: val } ); },
                    } )
                ),

                // Pagination panel.
                el( PanelBody, {
                    title: __( 'Pagination', 'shelter-donations' ),
                    initialOpen: false,
                },
                    el( SelectControl, {
                        label: __( 'Pagination Style', 'shelter-donations' ),
                        value: attributes.paginationStyle || 'paged',
                        options: [
                            { value: 'paged',     label: __( 'Previous / Next', 'shelter-donations' ) },
                            { value: 'load-more', label: __( 'Load More Button', 'shelter-donations' ) },
                        ],
                        onChange: function( val ) { setAttributes( { paginationStyle: val } ); },
                        help: __(
                            'Previous/Next updates the page via smooth navigation. Load More appends items without page reload.',
                            'shelter-donations'
                        ),
                    } )
                ),

                // Advanced panel.
                el( PanelBody, {
                    title: __( 'Advanced', 'shelter-donations' ),
                    initialOpen: false,
                },
                    el( TextControl, {
                        label: __( 'Empty Message', 'shelter-donations' ),
                        value: attributes.emptyMessage || '',
                        onChange: function( val ) { setAttributes( { emptyMessage: val } ); },
                        help: __( 'Custom message when no memorials match. Default: "No tributes found."', 'shelter-donations' ),
                    } )
                )
            ),

            // ─── Block preview ─────────────────────────────────────────
            el( 'div', blockProps,
                // Show placeholder if no memorials exist yet.
                hasMemorials === false
                    ? el( Placeholder, {
                        icon: el( 'svg', { viewBox: '0 0 24 24', width: 48, height: 48 },
                            el( 'path', {
                                fill: 'currentColor',
                                d: 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z'
                            } )
                        ),
                        label: __( 'Memorial Wall', 'shelter-donations' ),
                        instructions: __( 'No memorial tributes have been created yet. Once donors submit tributes through the Memorial Form block or WooCommerce checkout, they will appear here.', 'shelter-donations' ),
                    } )
                    : el( ServerSideRender, {
                        block: 'shelter-donations/memorial-wall',
                        attributes: attributes,
                    } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/memorial-wall', {
        edit: Edit,
    } );
} )( window.wp );
