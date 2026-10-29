( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	'use strict';

	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;

	blocks.registerBlockType( 'shapetranslate/language-switcher', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Switcher settings', 'shapetranslate' ), initialOpen: true },
						createElement( SelectControl, {
							label: __( 'Display', 'shapetranslate' ),
							value: attributes.display,
							options: [
								{ label: __( 'Native name', 'shapetranslate' ), value: 'native_name' },
								{ label: __( 'Language name', 'shapetranslate' ), value: 'name' },
								{ label: __( 'Language code', 'shapetranslate' ), value: 'language_code' },
								{ label: __( 'Flag only', 'shapetranslate' ), value: 'flag' }
							],
							onChange: function ( value ) {
								setAttributes( { display: value } );
							}
						} ),
						createElement( SelectControl, {
							label: __( 'Layout', 'shapetranslate' ),
							value: attributes.layout,
							options: [
								{ label: __( 'Horizontal list', 'shapetranslate' ), value: 'horizontal' },
								{ label: __( 'Vertical list', 'shapetranslate' ), value: 'vertical' },
								{ label: __( 'Dropdown', 'shapetranslate' ), value: 'dropdown' }
							],
							onChange: function ( value ) {
								setAttributes( { layout: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide current language', 'shapetranslate' ),
							checked: attributes.hideCurrent,
							onChange: function ( value ) {
								setAttributes( { hideCurrent: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Hide languages the site has no content in', 'shapetranslate' ),
							help: __( 'The same choice as the Missing translation option of that name, and it wins. Leave it off to use any of the other three.', 'shapetranslate' ),
							checked: attributes.hideMissing,
							onChange: function ( value ) {
								setAttributes( { hideMissing: value } );
							}
						} ),
						createElement( SelectControl, {
							label: __( 'Missing translation', 'shapetranslate' ),
							value: attributes.unavailableBehavior,
							disabled: attributes.hideMissing,
							help: attributes.hideMissing ?
								__( 'Turn off the checkbox above to choose one of these.', 'shapetranslate' ) :
								__( 'What a language does on a page it has no translation of.', 'shapetranslate' ),
							options: [
								{ label: __( 'Hide languages the site has no content in', 'shapetranslate' ), value: 'hide' },
								{ label: __( 'Show as unavailable', 'shapetranslate' ), value: 'disabled' },
								{ label: __( 'Link to language homepage', 'shapetranslate' ), value: 'home' },
								{ label: __( 'Keep current URL', 'shapetranslate' ), value: 'current' }
							],
							onChange: function ( value ) {
								setAttributes( { unavailableBehavior: value } );
							}
						} ),
						'flag' !== attributes.display && createElement( ToggleControl, {
							label: __( 'Show flags', 'shapetranslate' ),
							checked: attributes.showFlags,
							onChange: function ( value ) {
								setAttributes( { showFlags: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show disabled languages as unavailable', 'shapetranslate' ),
							checked: attributes.showDisabled,
							onChange: function ( value ) {
								setAttributes( { showDisabled: value } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Accessible label', 'shapetranslate' ),
							value: attributes.ariaLabel,
							onChange: function ( value ) {
								setAttributes( { ariaLabel: value } );
							}
						} )
					)
				),
				createElement( ServerSideRender, {
					block: 'shapetranslate/language-switcher',
					attributes: attributes
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.serverSideRender
);
