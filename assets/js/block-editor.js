/**
 * Appends the edited post's language to filterable editor REST requests.
 *
 * The block editor loads parent pages, terms, and link search results over the
 * REST API. Without a language those lists mix every language, so the request is
 * tagged here and ShapeTranslate constrains the collection on the server.
 *
 * @package ShapeTranslate
 */
( function ( wp ) {
	'use strict';

	var config = window.shapeTranslateEditor;

	if ( ! wp || ! wp.apiFetch || ! config || ! config.routes || ! config.routes.length ) {
		return;
	}

	/**
	 * Reads the language currently selected in the editor.
	 *
	 * The field comes first because the post editor lets someone change a post's
	 * language without reloading, and from that moment the field is the only
	 * thing that knows. The Site Editor has no such field: it opens a template
	 * part rather than a post, so the language the page was rendered for is the
	 * answer there. Failing both, the default language keeps a list consistent
	 * rather than mixing every language.
	 *
	 * @return {string} Language identifier, or an empty string when unknown.
	 */
	function currentLanguage() {
		var field = document.querySelector( '[name="' + config.field + '"]' );

		if ( field && field.value ) {
			return field.value;
		}

		return config.language || config.defaultLanguage || '';
	}

	/**
	 * Reports whether a request path is one ShapeTranslate can constrain.
	 *
	 * Single-item routes end with an identifier and are left alone: fetching one
	 * known post must never depend on the language being edited.
	 *
	 * @param {string} path Request path.
	 * @return {boolean} True when the collection accepts a language.
	 */
	function isFilterable( path ) {
		var route = path.split( '?' )[ 0 ].replace( /^\/+|\/+$/g, '' );

		return config.routes.indexOf( route ) !== -1;
	}

	wp.apiFetch.use( function ( options, next ) {
		// A defined url means a direct call rather than a REST route.
		if ( typeof options.url !== 'undefined' || typeof options.path !== 'string' ) {
			return next( options );
		}

		if ( ! isFilterable( options.path ) ) {
			return next( options );
		}

		var language = currentLanguage();

		if ( ! language ) {
			return next( options );
		}

		/*
		 * The Site Editor edits the site rather than a page, and a header has to
		 * lead somewhere in every language. Its listings therefore ask to be
		 * answered with the untranslated originals as well, so a language nothing
		 * has been written in yet is still something that can be worked on.
		 */
		var fallbackParam = config.fallbackParam || 'lang_fallback';

		if ( typeof options.data === 'undefined' || null === options.data ) {
			options.path +=
				( options.path.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'lang=' + encodeURIComponent( language );

			if ( config.fallback ) {
				options.path += '&' + fallbackParam + '=1';
			}
		} else {
			options.data.lang = language;

			if ( config.fallback ) {
				options.data[ fallbackParam ] = '1';
			}
		}

		return next( options );
	} );
} )( window.wp );
