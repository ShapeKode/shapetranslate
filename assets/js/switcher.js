/**
 * Opens a dropdown switcher on the side of it that has room.
 *
 * The stylesheet takes the panel out of flow and opens it downward, which is
 * right wherever there is space below. A switcher near the bottom of the page —
 * a footer being only the most common case — has none: the panel hangs past the
 * end of the document, the scrollable area grows, and the page visibly bounces.
 * There is no way to ask CSS how much room an element has, so the measurement
 * happens here and the answer is written back as data attributes the stylesheet
 * reads.
 *
 * The summary's click is taken over rather than watched. A `details` element
 * fires `toggle` asynchronously, so a listener on it runs after the browser has
 * already painted the panel in the wrong direction — one frame down, then up,
 * which is the jump itself. Opening it here keeps the decision and the opening
 * in a single task, so the first paint is already correct.
 *
 * Nothing here is required for the switcher to work: where this does not run,
 * every panel opens downward, exactly as it did before.
 *
 * Browser APIs used beyond ES5: `Element.closest`, `Element.matches`,
 * `HTMLElement.dataset`, `window.requestAnimationFrame`, and passive event
 * listeners. Each is guarded or optional, so an engine without one degrades to
 * the stylesheet's own downward panel rather than failing.
 *
 * @package ShapeTranslate
 */
( function () {
	'use strict';

	var SELECTOR = '.shapetranslate-switcher__dropdown';

	/**
	 * Room to leave between the panel and the edge of the viewport, in pixels.
	 *
	 * A panel ending flush against the edge reads as cut off even when all of it
	 * is on screen, so a side counts as fitting only with this much to spare.
	 *
	 * @type {number}
	 */
	var EDGE = 8;

	/**
	 * Dropdowns currently open, in the order they were opened.
	 *
	 * @type {Array}
	 */
	var openPanels = [];

	/**
	 * Identifier of the pending animation frame, or zero when none is queued.
	 *
	 * @type {number}
	 */
	var frame = 0;

	/**
	 * Remembers one open dropdown, without ever listing it twice.
	 *
	 * @param {HTMLDetailsElement} details Open dropdown.
	 * @return {void}
	 */
	function remember( details ) {
		if ( -1 === openPanels.indexOf( details ) ) {
			openPanels.push( details );
		}
	}

	/**
	 * Forgets one dropdown and clears the direction it was opened in.
	 *
	 * The attributes are cleared rather than left at their last value, so a panel
	 * reopened somewhere else starts from the direction the stylesheet describes.
	 *
	 * @param {HTMLDetailsElement} details Dropdown that is no longer open.
	 * @return {void}
	 */
	function forget( details ) {
		var index = openPanels.indexOf( details );

		if ( -1 !== index ) {
			openPanels.splice( index, 1 );
		}

		delete details.dataset.shapetranslateDrop;
		delete details.dataset.shapetranslateAlign;
	}

	/**
	 * Points one open dropdown at the side of the switcher that can hold it.
	 *
	 * Every measurement is taken against the summary and the panel's own size,
	 * never against where the panel currently sits. A rule that read the panel's
	 * position would change that position and then disagree with itself on the
	 * next scroll, flipping the panel back and forth.
	 *
	 * @param {HTMLDetailsElement} details Open dropdown.
	 * @return {void}
	 */
	function place( details ) {
		var list = details.querySelector( '.shapetranslate-switcher__list' );
		var anchor;
		var below;
		var above;
		var rtl;
		var width;
		var overflows;

		if ( ! list ) {
			return;
		}

		// Measured against the summary, not the details element: the panel hangs
		// off the summary's edge, and an open details box already contains the
		// panel, so its own edges would answer the wrong question.
		anchor = ( details.querySelector( 'summary' ) || details ).getBoundingClientRect();
		below = window.innerHeight - anchor.bottom;
		above = anchor.top;

		// Downward stays the default: it flips only when the panel does not fit
		// below and above is genuinely roomier, so a switcher with space on both
		// sides keeps opening the way a reader expects. Where neither side fits,
		// the roomier one still shows more of the list than the other would.
		if ( list.offsetHeight + EDGE > below && above > below ) {
			details.dataset.shapetranslateDrop = 'up';
		} else {
			details.dataset.shapetranslateDrop = 'down';
		}

		// The same question sideways. A panel wider than the room left of the
		// viewport edge would push the document wider and scroll the page across,
		// which is the same bounce in the other axis.
		rtl = 'rtl' === window.getComputedStyle( list ).direction;
		width = list.offsetWidth;
		overflows = rtl ? anchor.right - width < EDGE : anchor.left + width > window.innerWidth - EDGE;

		details.dataset.shapetranslateAlign = overflows ? 'end' : 'start';
	}

	/**
	 * Opens one dropdown with its direction already decided.
	 *
	 * @param {HTMLDetailsElement} details Closed dropdown.
	 * @return {void}
	 */
	function show( details ) {
		// Reading a layout property below flushes the pending style change, so the
		// panel is measured in its open state without a frame being painted first.
		details.open = true;
		place( details );
		remember( details );
	}

	/**
	 * Closes one dropdown and forgets where it was.
	 *
	 * @param {HTMLDetailsElement} details Open dropdown.
	 * @return {void}
	 */
	function hide( details ) {
		details.open = false;
		forget( details );
	}

	/**
	 * Measures every open dropdown once, on the frame that was scheduled for it.
	 *
	 * @return {void}
	 */
	function placeOpen() {
		frame = 0;

		// Copied first: measuring never changes the list, but reading it once
		// keeps the loop off the live array either way.
		openPanels.slice().forEach( place );
	}

	/**
	 * Queues one measurement pass for the next frame.
	 *
	 * Scrolling changes how much room a switcher has without changing anything
	 * about the switcher, so an open panel is measured again on the next frame
	 * rather than on every event.
	 *
	 * @return {void}
	 */
	function schedule() {
		if ( ! frame && openPanels.length ) {
			frame = window.requestAnimationFrame( placeOpen );
		}
	}

	/**
	 * Opens or closes the dropdown whose summary was clicked.
	 *
	 * @param {MouseEvent} event Click event.
	 * @return {void}
	 */
	function onClick( event ) {
		var summary;
		var details;

		if ( event.defaultPrevented || 0 !== event.button ) {
			return;
		}

		summary = event.target.closest ? event.target.closest( SELECTOR + ' > summary' ) : null;

		if ( ! summary ) {
			return;
		}

		details = summary.parentElement;

		event.preventDefault();

		if ( details.open ) {
			hide( details );
		} else {
			show( details );
		}
	}

	/**
	 * Follows a dropdown opened by something other than its summary.
	 *
	 * A script, or a browser's find-in-page, can open one too. `toggle` is not a
	 * bubbling event, so this runs in the capture phase.
	 *
	 * @param {Event} event Toggle event.
	 * @return {void}
	 */
	function onToggle( event ) {
		var details = event.target;

		if ( ! details || ! details.matches || ! details.matches( SELECTOR ) ) {
			return;
		}

		if ( details.open ) {
			remember( details );
			place( details );

			return;
		}

		forget( details );
	}

	document.removeEventListener( 'click', onClick );
	document.addEventListener( 'click', onClick );

	document.removeEventListener( 'toggle', onToggle, true );
	document.addEventListener( 'toggle', onToggle, true );

	window.removeEventListener( 'scroll', schedule );
	window.addEventListener( 'scroll', schedule, { passive: true } );

	window.removeEventListener( 'resize', schedule );
	window.addEventListener( 'resize', schedule, { passive: true } );
}() );
