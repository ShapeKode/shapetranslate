/**
 * Carries the edited post's language into inline term creation.
 *
 * The classic editor adds a category over admin-ajax, and the request WordPress
 * builds for it names the taxonomy and the term but never the post. Nothing in
 * it says which language the editor is writing in, so a category added while
 * writing a Bengali post would be filed under the site's default language and
 * then be missing from the very list the editor is looking at.
 *
 * wpList serializes every input inside the taxonomy's own add form, so putting
 * the language there is all it takes for the term to arrive with it. The block
 * editor needs none of this: it creates terms over the REST API, which already
 * carries the language.
 */
( function () {
	'use strict';

	var FIELD_NAME = 'shapetranslate_term_language_id';

	/**
	 * Returns the language select rendered by the Language meta box.
	 *
	 * @return {HTMLSelectElement|null} Language select, or null when absent.
	 */
	function getLanguageSelect() {
		return document.getElementById( 'shapetranslate-post-language' );
	}

	/**
	 * Returns every inline add-term form on the screen.
	 *
	 * WordPress gives each hierarchical taxonomy meta box one, marked by the
	 * class it renders for every taxonomy rather than only for categories.
	 *
	 * @return {Array} Add-term form elements.
	 */
	function getAddForms() {
		return Array.prototype.slice.call(
			document.querySelectorAll( '.categorydiv p.category-add' )
		);
	}

	/**
	 * Returns the hidden language input of one add form, creating it once.
	 *
	 * @param {HTMLElement} form Add-term form.
	 * @return {HTMLInputElement} Hidden language input.
	 */
	function getField( form ) {
		var field = form.querySelector( 'input[name="' + FIELD_NAME + '"]' );

		if ( ! field ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = FIELD_NAME;
			form.appendChild( field );
		}

		return field;
	}

	/**
	 * Writes the currently selected post language into every add form.
	 *
	 * @param {HTMLSelectElement} select Language select.
	 * @return {void}
	 */
	function sync( select ) {
		getAddForms().forEach( function ( form ) {
			getField( form ).value = select.value;
		} );
	}

	/**
	 * Wires the meta box language up to the inline add forms.
	 *
	 * @return {void}
	 */
	function init() {
		var select = getLanguageSelect();

		if ( ! select ) {
			return;
		}

		sync( select );
		select.addEventListener( 'change', function () {
			sync( select );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
