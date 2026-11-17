( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl, __experimentalNumberControl: NumberControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    // The block existed without an editor script, so it could not be inserted
    // and a saved instance showed as unsupported in the editor (#25).
    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        const toggle = function( key, label ) {
            return el( ToggleControl, {
                label: label,
                checked: !! attributes[ key ],
                onChange: function( value ) {
                    const change = {};
                    change[ key ] = value;
                    setAttributes( change );
                },
            } );
        };

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Statistics', 'shelterkit-donations' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Period', 'shelterkit-donations' ),
                        value: attributes.period || 'all_time',
                        options: [
                            { value: 'this_month', label: __( 'This Month', 'shelterkit-donations' ) },
                            { value: 'this_year', label: __( 'This Year', 'shelterkit-donations' ) },
                            { value: 'fiscal_year', label: __( 'Fiscal Year', 'shelterkit-donations' ) },
                            { value: 'all_time', label: __( 'All Time', 'shelterkit-donations' ) },
                        ],
                        onChange: function( value ) { setAttributes( { period: value } ); },
                    } ),
                    toggle( 'showTotal', __( 'Show Total Raised', 'shelterkit-donations' ) ),
                    toggle( 'showCount', __( 'Show Number of Donations', 'shelterkit-donations' ) ),
                    toggle( 'showDonors', __( 'Show Number of Donors', 'shelterkit-donations' ) ),
                    toggle( 'showMembers', __( 'Show Active Members', 'shelterkit-donations' ) )
                ),
                el( PanelBody, { title: __( 'Behavior', 'shelterkit-donations' ), initialOpen: false },
                    toggle( 'animateNumbers', __( 'Animate Numbers', 'shelterkit-donations' ) ),
                    NumberControl ? el( NumberControl, {
                        label: __( 'Auto-Refresh Interval (seconds)', 'shelterkit-donations' ),
                        value: attributes.refreshInterval || 0,
                        onChange: function( value ) { setAttributes( { refreshInterval: parseInt( value, 10 ) || 0 } ); },
                        min: 0,
                        help: __( 'Set to 0 to disable. Recommended: 30-60 seconds.', 'shelterkit-donations' ),
                    } ) : null
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'shelter-donations/donor-stats',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'shelter-donations/donor-stats', {
        edit: Edit,
    } );
} )( window.wp );
