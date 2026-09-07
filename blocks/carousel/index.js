/**
 * Editor code for the Ultralight Carousel block.
 *
 * Written against wp.element.createElement rather than JSX, so that the plugin
 * ships with no build step at all: what is published is what runs, which is
 * both easier to review and one fewer thing to keep in sync.
 *
 * The preview is a ServerSideRender of render.php, so the editor and the front
 * end can never disagree about what a slide looks like.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;

	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var ServerSideRender = wp.serverSideRender;

	/**
	 * Mirrors Slides::MAX_SLIDES, and a unit test holds the two together.
	 *
	 * The server silently keeps the first fifty. The media modal has no way to
	 * know that, so the author is told here rather than left to count.
	 */
	var MAX_SLIDES = 50;

	var SIZES = [
		{ label: __( 'Thumbnail', 'ultralight-carousel-via-sse' ), value: 'thumbnail' },
		{ label: __( 'Medium', 'ultralight-carousel-via-sse' ), value: 'medium' },
		{ label: __( 'Large', 'ultralight-carousel-via-sse' ), value: 'large' },
		{ label: __( 'Full size', 'ultralight-carousel-via-sse' ), value: 'full' }
	];

	/**
	 * The image picker, wherever it is shown.
	 *
	 * One function, two placements: the block toolbar and the sidebar. An author
	 * who wants to add a photograph looks at the block, not at the settings
	 * panel -- which is where the Gallery block puts its own button, and where
	 * this one was missing until 0.4.0.
	 *
	 * @param {Object}   props        Block props.
	 * @param {Function} renderButton Given `open`, returns the control to draw.
	 * @return {Object} Element.
	 */
	function ImagePicker( props, renderButton ) {
		return el(
			blockEditor.MediaUploadCheck,
			null,
			el( blockEditor.MediaUpload, {
				multiple: 'add',
				gallery: true,
				allowedTypes: [ 'image' ],
				value: props.attributes.ids,
				onSelect: function ( media ) {
					props.setAttributes( {
						ids: media.map( function ( item ) {
							return item.id;
						} )
					} );
				},
				render: function ( picker ) {
					return renderButton( picker.open );
				}
			} )
		);
	}

	/**
	 * Toolbar shown on the block itself.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Element.
	 */
	function Toolbar( props ) {
		return el(
			blockEditor.BlockControls,
			{ group: 'other' },
			el(
				components.ToolbarGroup,
				null,
				ImagePicker( props, function ( open ) {
					return el(
						components.ToolbarButton,
						{ onClick: open },
						wp.i18n.sprintf(
							/* translators: button on the block toolbar; %d is how many images the carousel holds. */
							wp.i18n._n(
								'Images (%d)',
								'Images (%d)',
								props.attributes.ids.length,
								'ultralight-carousel-via-sse'
							),
							props.attributes.ids.length
						)
					);
				} )
			)
		);
	}

	/**
	 * Panel shown in the sidebar whatever the state of the block.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Element.
	 */
	function Sidebar( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;

		return el(
			blockEditor.InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __( 'Carousel', 'ultralight-carousel-via-sse' ) },
				attributes.ids.length > MAX_SLIDES
					? el(
						components.Notice,
						{ status: 'warning', isDismissible: false },
						wp.i18n.sprintf(
							/* translators: %d: the most images one carousel can hold. */
							__( 'A carousel holds at most %d images. Only the first ones are shown; the rest are ignored.', 'ultralight-carousel-via-sse' ),
							MAX_SLIDES
						)
					)
					: null,
				el( components.SelectControl, {
					label: __( 'Image size', 'ultralight-carousel-via-sse' ),
					value: attributes.sizeSlug,
					options: SIZES,
					__nextHasNoMarginBottom: true,
					onChange: function ( value ) {
						setAttributes( { sizeSlug: value } );
					}
				} ),
				el( components.TextControl, {
					label: __( 'Accessible name', 'ultralight-carousel-via-sse' ),
					help: __( 'Read by screen readers, for example “Life at the school”. Leave empty for a generic name.', 'ultralight-carousel-via-sse' ),
					value: attributes.ariaLabel,
					__nextHasNoMarginBottom: true,
					onChange: function ( value ) {
						setAttributes( { ariaLabel: value } );
					}
				} ),
				ImagePicker( props, function ( open ) {
					return el(
						components.Button,
						{ variant: 'secondary', onClick: open },
						attributes.ids.length
							? __( 'Add or remove images', 'ultralight-carousel-via-sse' )
							: __( 'Add images', 'ultralight-carousel-via-sse' )
					);
				} ),
				el(
					'p',
					{ style: { marginTop: '0.5em' } },
					__( 'Pick them in the Media Library, like a Gallery. The order you pick is the order they rotate in.', 'ultralight-carousel-via-sse' )
				),
				el(
					'p',
					{ style: { marginTop: '1em', fontStyle: 'italic' } },
					__( 'Rotation speed and cross-fade are set for the whole site, under Settings → Ultralight Carousel.', 'ultralight-carousel-via-sse' )
				)
			)
		);
	}

	wp.blocks.registerBlockType( 'ulcar/carousel', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();
			var attributes = props.attributes;

			var body = attributes.ids.length
				? el( ServerSideRender, {
					block: 'ulcar/carousel',
					attributes: attributes
				} )
				: el( blockEditor.MediaPlaceholder, {
					icon: 'images-alt2',
					labels: {
						title: __( 'Ultralight Carousel', 'ultralight-carousel-via-sse' ),
						instructions: __( 'Pick the images to rotate through. One image never rotates; two or more do.', 'ultralight-carousel-via-sse' )
					},
					multiple: true,
					gallery: true,
					allowedTypes: [ 'image' ],
					onSelect: function ( media ) {
						props.setAttributes( {
							ids: media.map( function ( item ) {
								return item.id;
							} )
						} );
					}
				} );

			return el(
				Fragment,
				null,
				attributes.ids.length ? el( Toolbar, props ) : null,
				el( Sidebar, props ),
				el( 'div', blockProps, body )
			);
		},

		// A dynamic block keeps nothing in post content but its own comment, so
		// deactivating the plugin leaves no broken markup behind -- only the
		// absence of a carousel.
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
