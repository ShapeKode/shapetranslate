/**
 * Names the language of every template on the Jeg Kit theme builder dashboard.
 *
 * The dashboard is a React application whose template table takes no extra
 * columns and whose rows carry no identifier, so the language is written beside
 * each name once the table has drawn itself. The answer is never guessed: the
 * builder's own list route hands back the language alongside the name and the
 * identifier, and this reads the very list the table was drawn from.
 *
 * Matching a drawn row to a listed template is therefore a matter of order.
 * When the two agree — the usual case, because the table draws the list it was
 * given — each row takes the entry in its position. When they have drifted
 * apart, because a row was dragged into a new place before anything was
 * re-fetched, each row takes the first entry of the same name that no earlier
 * row has claimed. A row that matches nothing is left exactly as the builder
 * drew it, which is also what happens to every row if any of this fails.
 */
( function () {
	'use strict';

	var settings = window.shapeTranslateJegKit;

	if ( ! settings || ! settings.templates || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var LIST_ROUTE = 'jkit/v1/getTemplateLists';
	var CHIP_CLASS = 'shapetranslate-jkit-language';
	var ROOT_ID = 'jkit-admin-dashboard';

	/**
	 * Routes after which the lists this reads are no longer the lists drawn.
	 */
	var MUTATING_ROUTES = [
		'jkit/v1/createTemplate',
		'jkit/v1/cloneTemplate',
		'jkit/v1/deleteTemplate',
		'jkit/v1/updateTemplate',
		'jkit/v1/updatePriority',
		'jkit/v1/updateTemplateStatus'
	];

	var payloadKey = settings.payloadKey || 'shapetranslate';
	var postTypes = settings.postTypes || [];
	var listRoute = settings.listRoute || '/' + LIST_ROUTE;
	var labelFormat = settings.labelFormat || '%s';

	var lists = settings.templates;
	var observer = null;
	var refreshTimer = null;

	/**
	 * Returns the path one apiFetch call was made with.
	 *
	 * @param {Object} options Request options.
	 * @return {string} Request path, empty when the call names none.
	 */
	function pathOf( options ) {
		return options && 'string' === typeof options.path ? options.path : '';
	}

	/**
	 * Returns the location one list request asked about.
	 *
	 * @param {string} path Request path.
	 * @return {string} Post type, empty when the path names none.
	 */
	function locationOf( path ) {
		var matched = /[?&]type=([^&]+)/.exec( path );

		return matched ? decodeURIComponent( matched[ 1 ] ) : '';
	}

	/**
	 * Reports whether a path leads somewhere that rewrites a location's list.
	 *
	 * @param {string} path Request path.
	 * @return {boolean} Whether the lists held here are about to be stale.
	 */
	function isMutating( path ) {
		for ( var i = 0; i < MUTATING_ROUTES.length; i++ ) {
			if ( -1 !== path.indexOf( MUTATING_ROUTES[ i ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keeps the answer to one list request.
	 *
	 * @param {string} postType Location the request asked about.
	 * @param {Object} response Answer the builder's route gave.
	 * @return {void}
	 */
	function keep( postType, response ) {
		if ( postType && response && 'object' === typeof response ) {
			lists[ postType ] = response;
		}
	}

	/**
	 * Asks the builder's own route for one location's list again.
	 *
	 * @param {string} postType Location to ask about.
	 * @return {void}
	 */
	function refresh( postType ) {
		if ( ! postType ) {
			return;
		}

		window.wp
			.apiFetch( { path: listRoute + '?type=' + encodeURIComponent( postType ) } )
			.then(
				function ( response ) {
					keep( postType, response );
					apply();
				},
				function () {}
			);
	}

	/**
	 * Asks again for whichever location is on screen, once the dust settles.
	 *
	 * @return {void}
	 */
	function scheduleRefresh() {
		window.clearTimeout( refreshTimer );

		refreshTimer = window.setTimeout( function () {
			var postType = activeLocation();

			if ( postType ) {
				refresh( postType );
				return;
			}

			for ( var i = 0; i < postTypes.length; i++ ) {
				refresh( postTypes[ i ] );
			}
		}, 250 );
	}

	/**
	 * Returns the location the dashboard is showing.
	 *
	 * The heading carries the name the builder gave the location, and the
	 * builder's own menu is what says which location that name belongs to, so
	 * the two are read together rather than the name being recognized here.
	 *
	 * @return {string} Post type, empty when the screen names no location.
	 */
	function activeLocation() {
		var heading = document.querySelector( '#' + ROOT_ID + ' .f-theme-content-header h3' );
		var option = window.JkitDashboardOption;

		if ( ! heading || ! option || ! option.themeBuilderMenu ) {
			return '';
		}

		var title = heading.textContent.trim();
		var menus = option.themeBuilderMenu;

		for ( var i = 0; i < menus.length; i++ ) {
			var submenu = menus[ i ] && menus[ i ].submenu ? menus[ i ].submenu : [];

			for ( var j = 0; j < submenu.length; j++ ) {
				if ( submenu[ j ] && submenu[ j ].title === title ) {
					return -1 === postTypes.indexOf( submenu[ j ].slug ) ? '' : submenu[ j ].slug;
				}
			}
		}

		return '';
	}

	/**
	 * Returns the name one drawn row carries.
	 *
	 * @param {Element} row Row element.
	 * @return {string} Template name, empty when the row shows none.
	 */
	function titleOf( row ) {
		var name = row.querySelector( 'li.tab-detail .item > span:not(.' + CHIP_CLASS + ')' );

		return name ? name.textContent.trim() : '';
	}

	/**
	 * Pairs each drawn row with the template it was drawn from.
	 *
	 * @param {Array} rows  Drawn rows, in the order they appear.
	 * @param {Array} items Templates the location holds, in list order.
	 * @return {Array} One template or null per row, in row order.
	 */
	function align( rows, items ) {
		var titles = rows.map( titleOf );
		var paired = [];
		var i;

		if ( titles.length === items.length ) {
			var ordered = true;

			for ( i = 0; i < titles.length; i++ ) {
				if ( items[ i ].title !== titles[ i ] ) {
					ordered = false;
					break;
				}
			}

			if ( ordered ) {
				return items.slice();
			}
		}

		var claimed = {};

		for ( i = 0; i < titles.length; i++ ) {
			paired.push( claim( items, titles[ i ], claimed ) );
		}

		return paired;
	}

	/**
	 * Returns the first template of one name that no earlier row has taken.
	 *
	 * @param {Array}  items   Templates the location holds.
	 * @param {string} title   Name the row shows.
	 * @param {Object} claimed Positions already taken.
	 * @return {Object|null} Template, or null when the name matches none.
	 */
	function claim( items, title, claimed ) {
		for ( var i = 0; i < items.length; i++ ) {
			if ( ! claimed[ i ] && items[ i ].title === title ) {
				claimed[ i ] = true;

				return items[ i ];
			}
		}

		return null;
	}

	/**
	 * Writes one language beside one row's name, or takes it away again.
	 *
	 * @param {Element}     row  Row element.
	 * @param {Object|null} item Template the row was drawn from.
	 * @return {void}
	 */
	function mark( row, item ) {
		var described = item && item[ payloadKey ] ? item[ payloadKey ] : null;
		var chip = row.querySelector( '.' + CHIP_CLASS );

		if ( ! described || ! described.label ) {
			if ( chip && chip.parentNode ) {
				chip.parentNode.removeChild( chip );
			}

			return;
		}

		if ( chip && chip.textContent === described.label ) {
			return;
		}

		if ( ! chip ) {
			var cell = row.querySelector( 'li.tab-detail .item' );

			if ( ! cell ) {
				return;
			}

			chip = document.createElement( 'span' );
			chip.className = CHIP_CLASS;
			cell.appendChild( chip );
		}

		chip.textContent = described.label;
		chip.setAttribute( 'title', labelFormat.replace( '%s', described.label ) );
	}

	/**
	 * Names the language of every row the dashboard is showing.
	 *
	 * @return {void}
	 */
	function apply() {
		var list = document.querySelector( '#' + ROOT_ID + ' .template-lists' );

		if ( ! list ) {
			return;
		}

		var rows = Array.prototype.slice.call(
			list.querySelectorAll( '.list-content > ul.sortable-items' )
		);

		if ( ! rows.length ) {
			return;
		}

		var postType = activeLocation();
		var status = -1 !== list.className.indexOf( 'draft' ) ? 'draft' : 'publish';
		var held = postType && lists[ postType ] ? lists[ postType ][ status ] : null;
		var items = Array.isArray( held ) ? held : [];
		var paired = align( rows, items );

		// The watch is lifted for the writes rather than the reports of them being
		// ignored for a moment afterwards. Ignoring them for a moment means
		// choosing how long the moment is, and a table redrawn inside it is a
		// table left unnamed. Nothing else runs between these two lines, so
		// nothing else can be missed while they are apart.
		unwatch();

		try {
			for ( var i = 0; i < rows.length; i++ ) {
				mark( rows[ i ], paired[ i ] || null );
			}
		} finally {
			watch();
		}
	}

	/**
	 * Starts reporting the dashboard being drawn, or drawn again.
	 *
	 * @return {void}
	 */
	function watch() {
		var root = document.getElementById( ROOT_ID );

		if ( ! root || ! window.MutationObserver ) {
			return;
		}

		if ( ! observer ) {
			observer = new window.MutationObserver( apply );
		}

		observer.observe( root, { childList: true, subtree: true } );
	}

	/**
	 * Stops reporting, and throws away anything reported but not yet delivered.
	 *
	 * @return {void}
	 */
	function unwatch() {
		if ( observer ) {
			observer.disconnect();
		}
	}

	window.wp.apiFetch.use( function ( options, next ) {
		var path = pathOf( options );
		var result = next( options );

		if ( ! result || 'function' !== typeof result.then ) {
			return result;
		}

		if ( -1 !== path.indexOf( LIST_ROUTE ) ) {
			result.then(
				function ( response ) {
					keep( locationOf( path ), response );
					apply();
				},
				function () {}
			);
		} else if ( isMutating( path ) ) {
			result.then( scheduleRefresh, scheduleRefresh );
		}

		return result;
	} );

	/**
	 * Starts watching the dashboard, and names whatever it is already showing.
	 *
	 * @return {void}
	 */
	function start() {
		watch();
		apply();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
