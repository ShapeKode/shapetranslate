/**
 * Names the language of every template on the Elementor Theme Builder screen.
 *
 * The screen is a React application whose template cards take no extra columns
 * and carry no identifier of their own, so the language is written into each
 * card once the application has drawn it. The answer is never guessed: every
 * card's data passes through a filter on the way out, and each template is
 * handed back carrying the language it was written in and where its
 * translations are.
 *
 * Finding the card again is a matter of its own links. A card in the grid links
 * to its preview and a card opened on its own links to its editor, and both
 * addresses end in the identifier the data was keyed by. A card whose links
 * name nothing, or name a template this was told nothing about, is left exactly
 * as Elementor drew it — which is also what happens to every card if any of
 * this fails.
 */
( function () {
	'use strict';

	var settings = window.shapeTranslateElementorSiteEditor;

	if ( ! settings || ! window.MutationObserver || ! document.createElement ) {
		return;
	}

	var PAYLOAD_KEY = settings.payloadKey || 'shapetranslate';
	var CARD_SELECTOR = '.e-site-template';
	var HEADER_SELECTOR = '.eps-card__header';
	var MARKER = 'shapetranslateLanguages';
	var BAR_CLASS = 'shapetranslate-e-template-languages';

	var labels = settings.labels || {};
	var templates = settings.templates || {};
	var observer = null;
	var pending = null;

	/**
	 * Returns the template one card is drawn for.
	 *
	 * @param {Element} card Card element.
	 * @return {number} Template identifier, zero when the card names none.
	 */
	function templateIdOf( card ) {
		var links = card.querySelectorAll( 'a[href]' );
		var patterns = [ /\/site-editor\/templates\/[^/]+\/(\d+)/, /[?&]post=(\d+)/ ];
		var index;
		var pattern;
		var matched;

		for ( index = 0; index < links.length; index++ ) {
			for ( pattern = 0; pattern < patterns.length; pattern++ ) {
				matched = patterns[ pattern ].exec( links[ index ].getAttribute( 'href' ) || '' );

				if ( matched ) {
					return parseInt( matched[ 1 ], 10 ) || 0;
				}
			}
		}

		return 0;
	}

	/**
	 * Builds the flag image one language is shown by.
	 *
	 * @param {Object} language Language description.
	 * @return {Element|null} Image element, or null when the language has no flag.
	 */
	function flagOf( language ) {
		var image;

		if ( ! language || ! language.flag ) {
			return null;
		}

		image = document.createElement( 'img' );
		image.className = BAR_CLASS + '__flag';
		image.src = language.flag;
		image.alt = '';
		image.setAttribute( 'aria-hidden', 'true' );

		return image;
	}

	/**
	 * Returns one label with the language name written into it.
	 *
	 * @param {string} template Label holding a single placeholder.
	 * @param {string} name     Language name.
	 * @return {string} Completed label.
	 */
	function format( template, name ) {
		return ( template || '%s' ).replace( '%s', name );
	}

	/**
	 * Builds the chip naming the language a template is written in.
	 *
	 * @param {Object} language Language description, or null.
	 * @return {Element} Chip element.
	 */
	function currentChip( language ) {
		var chip = document.createElement( 'span' );
		var flag = flagOf( language );
		var name = document.createElement( 'span' );

		chip.className = BAR_CLASS + '__current';

		if ( flag ) {
			chip.appendChild( flag );
		}

		name.className = BAR_CLASS + '__name';
		name.textContent = language ? language.label : ( labels.untitled || '' );
		chip.appendChild( name );

		chip.title = language ? format( labels.language, language.label ) : ( labels.untitled || '' );

		return chip;
	}

	/**
	 * Builds the link leading to one language's translation.
	 *
	 * Each link says which of the two things it is before it is read. A tick is
	 * a translation that exists and the link opens it; a plus is one that does
	 * not and the link starts it. The mark is hidden from assistive technology
	 * because the link already carries the whole sentence as its label, and a
	 * tick read aloud beside it would only say the same thing twice.
	 *
	 * @param {Object} language Language description carrying its address.
	 * @return {Element} Link element.
	 */
	function translationLink( language ) {
		var link = document.createElement( 'a' );
		var flag = flagOf( language );
		var mark = document.createElement( 'span' );
		var title = format( language.exists ? labels.edit : labels.create, language.label );

		link.className = BAR_CLASS + '__link' + ( language.exists ? ' is-translated' : ' is-missing' );
		link.href = language.url;
		link.title = title;
		link.setAttribute( 'aria-label', title );

		if ( flag ) {
			link.appendChild( flag );
		} else {
			link.appendChild( document.createTextNode( language.id ) );
		}

		mark.className = BAR_CLASS + '__mark';
		mark.textContent = language.exists ? '\u2713' : '+';
		mark.setAttribute( 'aria-hidden', 'true' );
		link.appendChild( mark );

		return link;
	}

	/**
	 * Writes the languages of one template into the card drawn for it.
	 *
	 * The bar goes below the card's header rather than inside it. The header is
	 * a single flex row holding the name, the dates, and the action buttons,
	 * and the name is the part given the leftover width — anything added beside
	 * it is width taken away from the name. A strip of its own costs the card a
	 * line and costs the header nothing.
	 *
	 * @param {Element} card      Card element.
	 * @param {Object}  described Template description.
	 * @return {void}
	 */
	function decorate( card, described ) {
		var header = card.querySelector( HEADER_SELECTOR );
		var bar = document.createElement( 'div' );
		var index;

		bar.className = BAR_CLASS;
		bar.appendChild( currentChip( described.language ) );

		for ( index = 0; index < described.translations.length; index++ ) {
			bar.appendChild( translationLink( described.translations[ index ] ) );
		}

		if ( header && header.parentNode === card ) {
			card.insertBefore( bar, header.nextSibling );
		} else {
			card.insertBefore( bar, card.firstChild );
		}
	}

	/**
	 * Names every card the application has drawn and not yet been asked about.
	 *
	 * @return {void}
	 */
	function render() {
		var cards = document.querySelectorAll( CARD_SELECTOR );
		var index;
		var card;
		var templateId;

		for ( index = 0; index < cards.length; index++ ) {
			card = cards[ index ];

			if ( card.dataset && card.dataset[ MARKER ] ) {
				continue;
			}

			templateId = templateIdOf( card );

			if ( ! templateId || ! templates[ templateId ] ) {
				continue;
			}

			if ( card.dataset ) {
				card.dataset[ MARKER ] = String( templateId );
			}

			decorate( card, templates[ templateId ] );
		}
	}

	/**
	 * Asks for another pass once the application has finished this one.
	 *
	 * @return {void}
	 */
	function schedule() {
		if ( null !== pending ) {
			return;
		}

		pending = window.setTimeout( function () {
			pending = null;

			try {
				render();
			} catch ( error ) {
				stop();
			}
		}, 50 );
	}

	/**
	 * Stops watching, leaving the screen as the application drew it.
	 *
	 * @return {void}
	 */
	function stop() {
		if ( observer ) {
			observer.disconnect();
			observer = null;
		}
	}

	/**
	 * Reads the templates one answer described, keeping the newest answer.
	 *
	 * @param {*} payload Parsed response body.
	 * @return {boolean} Whether anything was learned.
	 */
	function learn( payload ) {
		var items = payload && payload.data ? payload.data : payload;
		var learned = false;
		var index;
		var item;

		if ( ! items ) {
			return false;
		}

		if ( ! ( items instanceof Array ) ) {
			items = [ items ];
		}

		for ( index = 0; index < items.length; index++ ) {
			item = items[ index ];

			if ( item && item[ PAYLOAD_KEY ] && item[ PAYLOAD_KEY ].id ) {
				templates[ item[ PAYLOAD_KEY ].id ] = item[ PAYLOAD_KEY ];
				learned = true;
			}
		}

		return learned;
	}

	/**
	 * Listens to the answers the application asks for about its templates.
	 *
	 * Only the template list is read, and only a copy of it: the response the
	 * application receives is the one it asked for, untouched and unconsumed.
	 * A site editor session that creates or duplicates a template is therefore
	 * named as soon as it is drawn, rather than after a reload.
	 *
	 * @return {void}
	 */
	function listen() {
		var original = window.fetch;

		if ( 'function' !== typeof original ) {
			return;
		}

		window.fetch = function ( resource ) {
			var request = original.apply( this, arguments );
			var url = 'string' === typeof resource ? resource : ( resource && resource.url ) || '';

			if ( -1 === url.indexOf( 'site-editor/templates' ) ) {
				return request;
			}

			return request.then( function ( response ) {
				try {
					response
						.clone()
						.json()
						.then( function ( payload ) {
							if ( learn( payload ) ) {
								schedule();
							}
						} )
						.catch( function () {} );
				} catch ( error ) {
					// The answer is the application's; reading it is optional.
				}

				return response;
			} );
		};
	}

	/**
	 * Starts watching the application for cards it has drawn.
	 *
	 * @return {void}
	 */
	function start() {
		var root = document.getElementById( 'e-app' ) || document.body;

		if ( ! root ) {
			return;
		}

		observer = new window.MutationObserver( schedule );
		observer.observe( root, { childList: true, subtree: true } );

		schedule();
	}

	listen();

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
