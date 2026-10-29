/**
 * Admin behaviour for ShapeTranslate settings and wizard screens.
 *
 * Everything here is progressive: each screen renders and saves without this
 * file, so a failure to load costs presentation rather than function.
 *
 * Browser APIs used beyond ES5: `Element.closest`, `Element.classList`,
 * `HTMLElement.dataset`, `HTMLElement.hidden`, `String.prototype.normalize`,
 * `Element.scrollIntoView` with an options object, and `navigator.clipboard`.
 * Each is either guarded before use or degrades to plain behaviour.
 *
 * @package ShapeTranslate
 */
( function () {
	'use strict';

	/**
	 * Number of options from which the list opens with a search field.
	 *
	 * The bundled language catalog runs to a few hundred entries, which no one
	 * can scroll comfortably. A short list — the handful of languages a site has
	 * already added — is faster to read than to type into, so it stays plain.
	 *
	 * @type {number}
	 */
	var SEARCH_THRESHOLD = 8;

	/**
	 * Milliseconds a type-to-jump query stays open for more keys.
	 *
	 * @type {number}
	 */
	var TYPEAHEAD_TIMEOUT = 600;

	/**
	 * Milliseconds a copy button reports success before returning to its label.
	 *
	 * @type {number}
	 */
	var COPIED_TIMEOUT = 1500;

	/**
	 * Every enhanced select on the screen, for the shared outside-click handler.
	 *
	 * One listener on the document serves all of them. Registering a listener per
	 * select instead would bind the same behaviour to the document once per
	 * language field, and none of those could be unbound afterwards.
	 *
	 * @type {Array}
	 */
	var flagSelects = [];

	/**
	 * Returns a listener that calls one method of one object.
	 *
	 * @param {Object} instance Object owning the method.
	 * @param {string} method   Method name.
	 * @return {Function} Event listener.
	 */
	function bound( instance, method ) {
		return function ( event ) {
			instance[ method ]( event );
		};
	}

	/**
	 * Builds the flag element for one option.
	 *
	 * The wrapper is rendered even when a language has no bundled flag, so every
	 * label in the list starts at the same horizontal position.
	 *
	 * @param {string} url Flag URL, or an empty value.
	 * @return {HTMLElement} Flag wrapper.
	 */
	function buildFlag( url ) {
		var wrapper = document.createElement( 'span' );
		var image;

		wrapper.className = 'shapetranslate-flag-select__flag';

		if ( url ) {
			image = document.createElement( 'img' );

			image.className = 'shapetranslate-flag';
			image.src = url;
			image.alt = '';
			image.width = 18;
			image.height = 12;
			image.loading = 'lazy';
			image.decoding = 'async';
			wrapper.appendChild( image );
		}

		return wrapper;
	}

	/**
	 * Folds text to the form both the index and a query are compared in.
	 *
	 * Accents are stripped so a query typed on a keyboard that has none still
	 * reaches the language it names: `espanol` finds `Español`.
	 *
	 * @param {string} value Raw text.
	 * @return {string} Comparable text.
	 */
	function fold( value ) {
		var text = String( value ).toLowerCase();

		return text.normalize ? text.normalize( 'NFD' ).replace( /[\u0300-\u036F]/g, '' ) : text;
	}

	/**
	 * Dispatches a bubbling `change` event on one element.
	 *
	 * The constructor is used where it exists and the legacy factory elsewhere,
	 * so anything already listening to the select hears the same event either
	 * way.
	 *
	 * @param {HTMLElement} element Element to dispatch on.
	 * @return {void}
	 */
	function fireChange( element ) {
		var event;

		if ( 'function' === typeof window.Event ) {
			event = new window.Event( 'change', { bubbles: true } );
		} else {
			event = document.createEvent( 'Event' );
			event.initEvent( 'change', true, false );
		}

		element.dispatchEvent( event );
	}

	/**
	 * Replaces a select with a listbox that can show a flag beside each option.
	 *
	 * A native option cannot contain an image, so the select stays in the DOM as
	 * the value that is read and submitted, and this listbox drives it. Choosing
	 * an option dispatches `change` on the select, so anything already listening
	 * to it keeps working. When scripting is unavailable the plain select is what
	 * renders, which is why it is hidden here rather than in the stylesheet.
	 *
	 * A list long enough to need one opens with a search field. Filtering hides
	 * the items it excludes rather than rebuilding the list, so an item's place
	 * in `items` keeps matching its option's index in the select.
	 *
	 * @constructor
	 * @param {HTMLElement}       wrapper Container holding one select.
	 * @param {HTMLSelectElement} select  Select the listbox drives.
	 */
	function FlagSelect( wrapper, select ) {
		this.wrapper = wrapper;
		this.select = select;
		this.label = select.id ? document.querySelector( 'label[for="' + select.id + '"]' ) : null;
		this.toggle = document.createElement( 'button' );
		this.panel = document.createElement( 'div' );
		this.list = document.createElement( 'ul' );
		this.items = [];
		this.haystacks = [];
		this.searchable = select.options.length >= SEARCH_THRESHOLD;
		this.search = this.searchable ? document.createElement( 'input' ) : null;
		this.empty = this.searchable ? document.createElement( 'li' ) : null;
		this.matches = [];
		this.activeIndex = -1;
		this.typed = '';
		this.typedTimer = 0;

		// Whichever element holds focus while the list is open is the one that
		// owns `aria-activedescendant`.
		this.focusTarget = this.search || this.list;
	}

	/**
	 * Builds the listbox, wires it up, and puts it on the screen.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.mount = function () {
		this.buildChrome();
		this.buildOptions();
		this.buildSearch();
		this.bindEvents();
		this.insert();

		this.filter( '' );
		this.syncSelected();
		this.renderToggle();
	};

	/**
	 * Creates the toggle, the panel, and the list, and relates them by ARIA.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.buildChrome = function () {
		var toggle = this.toggle;
		var list = this.list;

		toggle.type = 'button';
		toggle.className = 'shapetranslate-flag-select__toggle';
		toggle.id = ( this.select.id || 'shapetranslate-flag-select' ) + '-toggle';
		toggle.setAttribute( 'aria-haspopup', 'listbox' );
		toggle.setAttribute( 'aria-expanded', 'false' );

		this.panel.className = 'shapetranslate-flag-select__panel';
		this.panel.hidden = true;

		list.className = 'shapetranslate-flag-select__list';
		list.id = toggle.id + '-list';
		list.tabIndex = -1;
		list.setAttribute( 'role', 'listbox' );
		toggle.setAttribute( 'aria-controls', list.id );
	};

	/**
	 * Renders one list item per option, and indexes what each one is found by.
	 *
	 * The items are collected in a fragment and inserted once, so a catalog of a
	 * few hundred languages reaches the document in a single operation.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.buildOptions = function () {
		var self = this;
		var fragment = document.createDocumentFragment();

		Array.prototype.forEach.call( this.select.options, function ( option, index ) {
			var item = document.createElement( 'li' );
			var text = document.createElement( 'span' );
			var name = option.textContent.trim();

			item.className = 'shapetranslate-flag-select__option';
			item.id = self.list.id + '-' + index;
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', 'false' );

			text.className = 'shapetranslate-flag-select__label';
			text.textContent = name;

			item.appendChild( buildFlag( option.dataset.flag ) );
			item.appendChild( text );
			fragment.appendChild( item );
			self.items.push( item );

			// A locale and a language code are what a reader knows a language by as
			// often as its name, so `bn`, `bn_BD` and `Bengali` all have to find it.
			self.haystacks.push(
				fold(
					[
						name,
						option.value,
						option.dataset.languageCode || '',
						option.dataset.urlSlug || ''
					].join( ' ' )
				)
			);
		} );

		this.list.appendChild( fragment );
	};

	/**
	 * Adds the search field and the empty-state row to a list long enough to need them.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.buildSearch = function () {
		var placeholder;

		if ( ! this.searchable ) {
			this.panel.appendChild( this.list );

			return;
		}

		// Labels come from the wrapper because this script is shared by every
		// admin screen and is enqueued without a translation handle.
		placeholder = this.wrapper.dataset.searchLabel || 'Search';

		this.search.type = 'search';
		this.search.className = 'shapetranslate-flag-select__search';
		this.search.id = this.toggle.id + '-search';
		this.search.autocomplete = 'off';
		this.search.placeholder = placeholder;
		this.search.setAttribute( 'aria-label', placeholder );
		this.search.setAttribute( 'role', 'combobox' );
		this.search.setAttribute( 'aria-expanded', 'true' );
		this.search.setAttribute( 'aria-autocomplete', 'list' );
		this.search.setAttribute( 'aria-controls', this.list.id );

		this.empty.className = 'shapetranslate-flag-select__empty';
		this.empty.setAttribute( 'role', 'presentation' );
		this.empty.textContent = this.wrapper.dataset.emptyText || 'No results found.';
		this.empty.hidden = true;

		this.panel.appendChild( this.search );
		this.list.appendChild( this.empty );
		this.panel.appendChild( this.list );
	};

	/**
	 * Attaches every listener the listbox needs.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.bindEvents = function () {
		var onKeydown = bound( this, 'onKeydown' );

		this.toggle.addEventListener( 'click', bound( this, 'onToggleClick' ) );
		this.toggle.addEventListener( 'keydown', bound( this, 'onToggleKeydown' ) );

		if ( this.search ) {
			this.search.addEventListener( 'input', bound( this, 'onSearchInput' ) );
			this.search.addEventListener( 'keydown', onKeydown );
		}

		this.list.addEventListener( 'keydown', onKeydown );
		this.list.addEventListener( 'click', bound( this, 'onListClick' ) );
		this.list.addEventListener( 'mousemove', bound( this, 'onListMousemove' ) );
		this.select.addEventListener( 'change', bound( this, 'onSelectChange' ) );
	};

	/**
	 * Hides the native select and puts the listbox in its place.
	 *
	 * Both new elements are inserted together so the wrapper is laid out once.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.insert = function () {
		var fragment = document.createDocumentFragment();

		this.select.hidden = true;
		this.wrapper.classList.add( 'is-enhanced' );

		fragment.appendChild( this.toggle );
		fragment.appendChild( this.panel );
		this.wrapper.appendChild( fragment );

		if ( this.label ) {
			this.label.htmlFor = this.toggle.id;
		}
	};

	/**
	 * Draws the selected option, with its flag, on the toggle.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.renderToggle = function () {
		var option = this.select.options[ this.select.selectedIndex ];
		var text = document.createElement( 'span' );
		var fragment = document.createDocumentFragment();

		text.className = 'shapetranslate-flag-select__label';
		text.textContent = option ? option.textContent.trim() : '';

		fragment.appendChild( buildFlag( option ? option.dataset.flag : '' ) );
		fragment.appendChild( text );

		this.toggle.textContent = '';
		this.toggle.appendChild( fragment );
	};

	/**
	 * Marks the item that matches the select's own value.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.syncSelected = function () {
		var selectedIndex = this.select.selectedIndex;

		this.items.forEach( function ( item, index ) {
			var selected = index === selectedIndex;

			item.classList.toggle( 'is-selected', selected );
			item.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
		} );
	};

	/**
	 * Moves the active highlight to one item, or clears it.
	 *
	 * @param {number} index Item index, or a value outside the list to clear.
	 * @return {void}
	 */
	FlagSelect.prototype.setActive = function ( index ) {
		var previous = this.activeIndex >= 0 ? this.items[ this.activeIndex ] : null;
		var item;

		if ( previous ) {
			previous.classList.remove( 'is-active' );
		}

		this.activeIndex = -1;
		this.focusTarget.removeAttribute( 'aria-activedescendant' );

		if ( index < 0 || index >= this.items.length ) {
			return;
		}

		item = this.items[ index ];

		if ( item.hidden ) {
			return;
		}

		this.activeIndex = index;
		item.classList.add( 'is-active' );
		this.focusTarget.setAttribute( 'aria-activedescendant', item.id );

		if ( ! this.panel.hidden ) {
			item.scrollIntoView( { block: 'nearest' } );
		}
	};

	/**
	 * Moves the active option through what the filter left behind.
	 *
	 * @param {number} delta Steps to move; a negative value moves up.
	 * @return {void}
	 */
	FlagSelect.prototype.moveActive = function ( delta ) {
		var matches = this.matches;
		var current;
		var next;

		if ( ! matches.length ) {
			return;
		}

		current = matches.indexOf( this.activeIndex );

		if ( current < 0 ) {
			next = 0 < delta ? 0 : matches.length - 1;
		} else {
			next = current + delta;
		}

		this.setActive( matches[ Math.min( Math.max( next, 0 ), matches.length - 1 ) ] );
	};

	/**
	 * Shows the options a query matches and hides the rest.
	 *
	 * An empty query restores the whole list and keeps the current value in
	 * view. Anything else moves to the first match, so Enter takes what the
	 * reader is already looking at.
	 *
	 * @param {string} query Raw query text.
	 * @return {void}
	 */
	FlagSelect.prototype.filter = function ( query ) {
		var self = this;
		var needle = fold( query.trim() );
		var leading = -1;
		var index;

		this.matches = [];

		this.items.forEach( function ( item, itemIndex ) {
			var hit = '' === needle || -1 !== self.haystacks[ itemIndex ].indexOf( needle );

			item.hidden = ! hit;

			if ( hit ) {
				self.matches.push( itemIndex );
			}
		} );

		if ( this.empty ) {
			this.empty.hidden = 0 !== this.matches.length;
		}

		if ( '' === needle && -1 !== this.matches.indexOf( this.select.selectedIndex ) ) {
			this.setActive( this.select.selectedIndex );

			return;
		}

		// Matching anywhere in the text is what makes a half-remembered name
		// findable, but a name that begins with the query is almost always the
		// one meant: `fr` leads on French rather than on Afrikaans.
		for ( index = 0; index < this.matches.length; index++ ) {
			if ( 0 === this.haystacks[ this.matches[ index ] ].indexOf( needle ) ) {
				leading = this.matches[ index ];
				break;
			}
		}

		if ( -1 !== leading ) {
			this.setActive( leading );

			return;
		}

		this.setActive( this.matches.length ? this.matches[ 0 ] : -1 );
	};

	/**
	 * Closes the list.
	 *
	 * @param {boolean} refocus Whether focus returns to the toggle.
	 * @return {void}
	 */
	FlagSelect.prototype.close = function ( refocus ) {
		if ( this.panel.hidden ) {
			return;
		}

		this.panel.hidden = true;
		this.toggle.setAttribute( 'aria-expanded', 'false' );
		this.setActive( -1 );

		if ( refocus ) {
			this.toggle.focus();
		}
	};

	/**
	 * Opens the list on the value it currently holds.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.open = function () {
		if ( ! this.panel.hidden ) {
			return;
		}

		this.panel.hidden = false;
		this.toggle.setAttribute( 'aria-expanded', 'true' );

		if ( this.search ) {
			// A fresh query every time: the narrowed list someone left behind is
			// rarely the one they open it for next.
			this.search.value = '';
		}

		this.filter( '' );
		this.focusTarget.focus();
	};

	/**
	 * Writes one option back to the select and closes the list.
	 *
	 * @param {number} index Item index.
	 * @return {void}
	 */
	FlagSelect.prototype.choose = function ( index ) {
		if ( index < 0 || index >= this.items.length ) {
			return;
		}

		this.select.selectedIndex = index;
		fireChange( this.select );
		this.close( true );
	};

	/**
	 * Mirrors the type-to-jump behaviour of a native select.
	 *
	 * A list that has a search field has no use for it, because those keys go to
	 * the field.
	 *
	 * @param {string} key Key that was pressed.
	 * @return {void}
	 */
	FlagSelect.prototype.typeahead = function ( key ) {
		var self = this;
		var current;
		var start;
		var offset;
		var index;

		if ( 1 !== key.length ) {
			return;
		}

		window.clearTimeout( this.typedTimer );
		this.typed += key.toLowerCase();
		this.typedTimer = window.setTimeout( function () {
			self.typed = '';
		}, TYPEAHEAD_TIMEOUT );

		current = Math.max( this.activeIndex, 0 );
		start = this.typed.length > 1 ? current : current + 1;

		for ( offset = 0; offset < this.items.length; offset++ ) {
			index = ( start + offset ) % this.items.length;

			if ( 0 === this.items[ index ].textContent.trim().toLowerCase().indexOf( this.typed ) ) {
				this.setActive( index );

				return;
			}
		}
	};

	/**
	 * Handles the keys that drive the list, from the field or from the list.
	 *
	 * Home, End and Space are left alone when there is a search field: inside
	 * a text field those keys belong to the caret and to typing.
	 *
	 * @param {KeyboardEvent} event Key event.
	 * @return {void}
	 */
	FlagSelect.prototype.onKeydown = function ( event ) {
		if ( event.ctrlKey || event.metaKey || event.altKey ) {
			return;
		}

		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				this.moveActive( 1 );
				break;
			case 'ArrowUp':
				event.preventDefault();
				this.moveActive( -1 );
				break;
			case 'Home':
				if ( this.search || ! this.matches.length ) {
					break;
				}

				event.preventDefault();
				this.setActive( this.matches[ 0 ] );
				break;
			case 'End':
				if ( this.search || ! this.matches.length ) {
					break;
				}

				event.preventDefault();
				this.setActive( this.matches[ this.matches.length - 1 ] );
				break;
			case 'Enter':
				// Also keeps the surrounding form from being submitted by a query
				// that matched nothing.
				event.preventDefault();
				this.choose( this.activeIndex );
				break;
			case ' ':
				if ( this.search ) {
					break;
				}

				event.preventDefault();
				this.choose( this.activeIndex );
				break;
			case 'Escape':
				event.preventDefault();
				this.close( true );
				break;
			case 'Tab':
				this.close( false );
				break;
			default:
				if ( ! this.search ) {
					this.typeahead( event.key );
				}

				break;
		}
	};

	/**
	 * Opens or closes the list from the toggle.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.onToggleClick = function () {
		if ( this.panel.hidden ) {
			this.open();
		} else {
			this.close( true );
		}
	};

	/**
	 * Opens the list from the toggle with an arrow key.
	 *
	 * @param {KeyboardEvent} event Key event.
	 * @return {void}
	 */
	FlagSelect.prototype.onToggleKeydown = function ( event ) {
		if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
			event.preventDefault();
			this.open();
		}
	};

	/**
	 * Narrows the list to what the reader has typed.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.onSearchInput = function () {
		this.filter( this.search.value );
	};

	/**
	 * Chooses the option that was clicked.
	 *
	 * @param {MouseEvent} event Click event.
	 * @return {void}
	 */
	FlagSelect.prototype.onListClick = function ( event ) {
		var item = event.target.closest( '.shapetranslate-flag-select__option' );

		if ( item ) {
			this.choose( this.items.indexOf( item ) );
		}
	};

	/**
	 * Follows the pointer with the active highlight.
	 *
	 * @param {MouseEvent} event Pointer event.
	 * @return {void}
	 */
	FlagSelect.prototype.onListMousemove = function ( event ) {
		var item = event.target.closest( '.shapetranslate-flag-select__option' );

		if ( item ) {
			this.setActive( this.items.indexOf( item ) );
		}
	};

	/**
	 * Redraws the listbox after the select changed, from here or from elsewhere.
	 *
	 * @return {void}
	 */
	FlagSelect.prototype.onSelectChange = function () {
		this.syncSelected();
		this.renderToggle();
	};

	/**
	 * Reports whether one element is inside this select's wrapper.
	 *
	 * @param {EventTarget} target Element to test.
	 * @return {boolean} True when the element belongs to this select.
	 */
	FlagSelect.prototype.contains = function ( target ) {
		return this.wrapper.contains( target );
	};

	/**
	 * Asks for confirmation before following an element that requests it.
	 *
	 * @param {MouseEvent} event Click event.
	 * @return {void}
	 */
	function onConfirmClick( event ) {
		var target = event.target.closest( '[data-shapetranslate-confirm]' );

		if ( target && ! window.confirm( target.dataset.shapetranslateConfirm ) ) {
			event.preventDefault();
		}
	}

	/**
	 * Closes every enhanced select the click landed outside of.
	 *
	 * @param {MouseEvent} event Click event.
	 * @return {void}
	 */
	function onDocumentClick( event ) {
		flagSelects.forEach( function ( instance ) {
			if ( ! instance.contains( event.target ) ) {
				instance.close( false );
			}
		} );
	}

	/**
	 * Enhances every flag select on the screen.
	 *
	 * @return {void}
	 */
	function initFlagSelects() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-shapetranslate-flag-select]' ),
			function ( wrapper ) {
				var select = wrapper.querySelector( 'select' );
				var instance;

				if ( ! select || ! select.options.length || wrapper.classList.contains( 'is-enhanced' ) ) {
					return;
				}

				instance = new FlagSelect( wrapper, select );
				instance.mount();
				flagSelects.push( instance );
			}
		);
	}

	/**
	 * Shows only the wizard fields the selected URL format uses.
	 *
	 * The wizard shows a domain field per language, but only the separate-domain
	 * format uses one. Without scripting every field stays visible and still saves.
	 *
	 * @return {void}
	 */
	function initUrlModes() {
		var urlModes = document.querySelector( '[data-shapetranslate-url-mode]' );
		var panels;

		/**
		 * Matches the visible panel to the selected format.
		 *
		 * @return {void}
		 */
		function syncModePanels() {
			var checked = urlModes.querySelector( 'input[type="radio"]:checked' );
			var mode = checked ? checked.value : '';

			Array.prototype.forEach.call( panels, function ( panel ) {
				panel.hidden = panel.dataset.shapetranslateModePanel !== mode;
			} );
		}

		if ( ! urlModes ) {
			return;
		}

		panels = document.querySelectorAll( '[data-shapetranslate-mode-panel]' );

		urlModes.addEventListener( 'change', syncModePanels );
		syncModePanels();
	}

	/**
	 * Keeps the printed shortcode in step with the switcher controls.
	 *
	 * Settings > Switcher prints the shortcode that reproduces whatever the
	 * controls are set to. Rebuilding it as the controls move is what shows a
	 * reader that the screen and the shortcode are the same set of options under
	 * two spellings.
	 *
	 * @return {void}
	 */
	function initSwitcherUsage() {
		var usage = document.querySelector( '[data-shapetranslate-switcher-usage]' );
		var options;
		var shortcodeOutput;
		var copyTimer = 0;

		/**
		 * Reads one control as the value the shortcode would carry.
		 *
		 * @param {HTMLElement} field Select or checkbox.
		 * @return {string} Option value.
		 */
		function readValue( field ) {
			if ( 'checkbox' === field.type ) {
				return field.checked ? 'true' : 'false';
			}

			return field.value;
		}

		/**
		 * Prints the shortcode the controls currently describe.
		 *
		 * @return {void}
		 */
		function render() {
			var shortcode = '[shapetranslate_switcher';

			if ( ! shortcodeOutput ) {
				return;
			}

			options.forEach( function ( option ) {
				shortcode += ' ' + option[ 0 ] + '="' + readValue( option[ 1 ] ) + '"';
			} );

			shortcodeOutput.textContent = shortcode + ']';
		}

		/**
		 * Copies the snippet a copy button names, and reports that it did.
		 *
		 * @param {MouseEvent} event Click event.
		 * @return {void}
		 */
		function onCopyClick( event ) {
			var button = event.target.closest( '[data-shapetranslate-copy]' );
			var target;

			if ( ! button ) {
				return;
			}

			target = usage.querySelector(
				'[data-shapetranslate-usage="' + button.dataset.shapetranslateCopy + '"]'
			);

			if ( ! target ) {
				return;
			}

			navigator.clipboard.writeText( target.textContent ).then( function () {
				var original = button.dataset.label || button.textContent;

				button.dataset.label = original;
				button.textContent = button.dataset.copied || original;

				window.clearTimeout( copyTimer );
				copyTimer = window.setTimeout( function () {
					button.textContent = original;
				}, COPIED_TIMEOUT );
			} );
		}

		if ( ! usage ) {
			return;
		}

		// The order the options read in, fixed here rather than left to whichever
		// order the fields happen to be found in.
		options = [
			[ 'display', document.getElementById( 'shapetranslate-switcher-display' ) ],
			[ 'layout', document.getElementById( 'shapetranslate-switcher-layout' ) ],
			[ 'unavailable_behavior', document.getElementById( 'shapetranslate-unavailable-behavior' ) ],
			[ 'show_flags', document.querySelector( 'input[name="switcher[show_flags]"]' ) ],
			[ 'hide_current', document.querySelector( 'input[name="switcher[hide_current]"]' ) ],
			[ 'hide_missing', document.querySelector( 'input[name="switcher[hide_missing]"]' ) ],
			[ 'show_disabled', document.querySelector( 'input[name="switcher[show_disabled]"]' ) ]
		].filter( function ( option ) {
			return !! option[ 1 ];
		} );

		shortcodeOutput = usage.querySelector( '[data-shapetranslate-usage="shortcode"]' );

		options.forEach( function ( option ) {
			option[ 1 ].addEventListener( 'change', render );
		} );

		// Clipboard access needs a secure context. Where there is none the snippet
		// is still there to select by hand, so the button goes rather than sitting
		// on the screen doing nothing.
		if ( navigator.clipboard ) {
			usage.addEventListener( 'click', onCopyClick );
		} else {
			Array.prototype.forEach.call(
				usage.querySelectorAll( '[data-shapetranslate-copy]' ),
				function ( button ) {
					button.hidden = true;
				}
			);
		}

		render();
	}

	/**
	 * Fills the language form from the catalog entry that was picked.
	 *
	 * @return {void}
	 */
	function initLanguagePreset() {
		var preset = document.getElementById( 'shapetranslate-language-preset' );
		var fields;
		var missingField;

		/**
		 * Copies the selected catalog entry into the form.
		 *
		 * @return {void}
		 */
		function applyPreset() {
			var option = preset.options[ preset.selectedIndex ];

			if ( ! option || ! option.value ) {
				return;
			}

			fields.name.value = option.dataset.name || '';
			fields.nativeName.value = option.dataset.nativeName || '';
			fields.locale.value = option.value;
			fields.languageCode.value = option.dataset.languageCode || '';
			fields.urlSlug.value = option.dataset.urlSlug || '';
			fields.isRtl.checked = '1' === option.dataset.isRtl;
		}

		if ( ! preset ) {
			return;
		}

		fields = {
			name: document.getElementById( 'shapetranslate-name' ),
			nativeName: document.getElementById( 'shapetranslate-native_name' ),
			locale: document.getElementById( 'shapetranslate-locale' ),
			languageCode: document.getElementById( 'shapetranslate-language_code' ),
			urlSlug: document.getElementById( 'shapetranslate-url_slug' ),
			isRtl: document.getElementById( 'shapetranslate-is-rtl' )
		};

		missingField = Object.keys( fields ).some( function ( key ) {
			return ! fields[ key ];
		} );

		if ( missingField ) {
			return;
		}

		preset.addEventListener( 'change', applyPreset );

		if ( preset.value && ! fields.name.value ) {
			applyPreset();
		}
	}

	document.removeEventListener( 'click', onConfirmClick );
	document.addEventListener( 'click', onConfirmClick );

	document.removeEventListener( 'click', onDocumentClick );
	document.addEventListener( 'click', onDocumentClick );

	initFlagSelects();
	initUrlModes();
	initSwitcherUsage();
	initLanguagePreset();
}() );
