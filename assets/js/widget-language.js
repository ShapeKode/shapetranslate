/**
 * Adds a "Show in language" select to the blocks of the widgets editors.
 *
 * Every block not in the skipped list gets a string attribute holding a
 * language identifier, and a panel in its settings to choose it. The settings
 * arrive in `window.shapeTranslateWidgetLanguage`, printed by the enqueue that
 * loads this file.
 *
 * @package ShapeTranslate
 */
( function ( wp, config ) {
	'use strict';

	var el;
	var withLanguage;

	if ( ! config || ! wp || ! wp.hooks || ! wp.compose || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	el = wp.element.createElement;

	/**
	 * Reports whether a block is left without the language control.
	 *
	 * @param {string} name Block name.
	 * @return {boolean} Whether the block is skipped.
	 */
	function isSkipped( name ) {
		return -1 !== config.skipped.indexOf( name );
	}

	wp.hooks.addFilter( 'blocks.registerBlockType', 'shapetranslate/widget-language', function ( settings, name ) {
		var attributes;

		if ( isSkipped( name ) ) {
			return settings;
		}

		attributes = Object.assign( {}, settings.attributes );
		attributes[ config.attribute ] = { type: 'string', 'default': '' };

		return Object.assign( {}, settings, { attributes: attributes } );
	} );

	withLanguage = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( isSkipped( props.name ) || ! props.attributes || ! ( config.attribute in props.attributes ) ) {
				return el( BlockEdit, props );
			}

			return el(
				wp.element.Fragment,
				null,
				el( BlockEdit, props ),
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: config.title, initialOpen: true },
						el( wp.components.SelectControl, {
							label: config.label,
							help: config.help,
							value: props.attributes[ config.attribute ] || '',
							options: config.options,
							onChange: function ( value ) {
								var update = {};

								update[ config.attribute ] = value;
								props.setAttributes( update );
							}
						} )
					)
				)
			);
		};
	}, 'withShapeTranslateLanguage' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'shapetranslate/widget-language', withLanguage );
}( window.wp, window.shapeTranslateWidgetLanguage ) );
