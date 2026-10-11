/**
 * Ruvelo Comment Subscriptions blocks in the editor. The blocks are rendered
 * in PHP; the editor shows that same output (ServerSideRender) with a few
 * settings in the sidebar. Plain JavaScript with WordPress globals: no build step.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var Disabled = wp.components.Disabled;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'ruvelo/subscribe-form', {
		edit: function ( props ) {
			var attributes = Object.assign( {}, props.attributes );
			if ( ! attributes.postId && props.context && props.context.postId ) {
				attributes.postId = props.context.postId;
			}
			return el( 'div', useBlockProps(),
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings', 'ruvelo-comment-subscriptions' ) },
						el( ToggleControl, {
							label: __( 'Show the heading', 'ruvelo-comment-subscriptions' ),
							checked: props.attributes.showHeading,
							onChange: function ( value ) { props.setAttributes( { showHeading: value } ); },
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( Disabled, null, el( ServerSideRender, {
					block: 'ruvelo/subscribe-form',
					attributes: attributes,
					skipBlockSupportAttributes: true,
					EmptyResponsePlaceholder: function () {
						return el( Notice, { status: 'info', isDismissible: false },
							__( 'The form shows on published posts that take comments.', 'ruvelo-comment-subscriptions' )
						);
					},
				} ) )
			);
		},
		save: function () { return null; },
	} );

	wp.blocks.registerBlockType( 'ruvelo/manage-subscriptions', {
		edit: function () {
			return el( 'div', useBlockProps(),
				el( Disabled, null, el( ServerSideRender, { block: 'ruvelo/manage-subscriptions', skipBlockSupportAttributes: true } ) )
			);
		},
		save: function () { return null; },
	} );
}( window.wp ) );
