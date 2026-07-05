( function( wp ) {
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const { PanelBody, TextControl, SelectControl } = wp.components;
	const { __ } = wp.i18n;

	const ALLOWED_BLOCKS = [
		'shelter-donations/donation-form',
		'shelter-donations/membership-form',
		'shelter-donations/memorial-form',
	];

	const DEFAULT_TEMPLATE = [
		[ 'shelter-donations/donation-form', {} ],
		[ 'shelter-donations/membership-form', {} ],
		[ 'shelter-donations/memorial-form', {} ],
	];

	const Edit = function( props ) {
		const { attributes, setAttributes } = props;
		const blockProps = useBlockProps( { className: 'sd-contribution-tabs' } );

		const innerBlocksProps = useInnerBlocksProps(
			{ className: 'sd-tabs-editor-panels' },
			{
				allowedBlocks: ALLOWED_BLOCKS,
				template: DEFAULT_TEMPLATE,
				templateLock: false,
				renderAppender: wp.blockEditor.InnerBlocks.ButtonBlockAppender,
			}
		);

		var tabLabels = attributes.tabLabels || [];

		return el( Fragment, {},
			el( InspectorControls, {},
				el( PanelBody, { title: __( 'Tab Settings', 'shelter-donations' ), initialOpen: true },
					tabLabels.map( function( label, i ) {
						return el( TextControl, {
							key: i,
							label: __( 'Tab', 'shelter-donations' ) + ' ' + ( i + 1 ) + ' ' + __( 'Label', 'shelter-donations' ),
							value: label,
							onChange: function( value ) {
								var newLabels = tabLabels.slice();
								newLabels[ i ] = value;
								setAttributes( { tabLabels: newLabels } );
							},
							__nextHasNoMarginBottom: true,
						} );
					} ),
					el( SelectControl, {
						label: __( 'Default Tab', 'shelter-donations' ),
						value: String( attributes.defaultTab || 0 ),
						options: tabLabels.map( function( label, i ) {
							return { value: String( i ), label: label };
						} ),
						onChange: function( value ) {
							setAttributes( { defaultTab: parseInt( value, 10 ) } );
						},
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el( 'div', blockProps,
				el( 'div', { className: 'sd-tabs-editor-header' },
					tabLabels.map( function( label, i ) {
						return el( 'span', {
							key: i,
							className: 'sd-tabs-editor-tab' + ( i === ( attributes.defaultTab || 0 ) ? ' sd-tab-active' : '' ),
						}, label );
					} )
				),
				el( 'div', innerBlocksProps )
			)
		);
	};

	wp.blocks.registerBlockType( 'shelter-donations/contribution-tabs', {
		edit: Edit,
		save: function() {
			return el( wp.blockEditor.InnerBlocks.Content );
		},
	} );
} )( window.wp );
