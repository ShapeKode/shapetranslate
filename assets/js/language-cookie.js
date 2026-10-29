/**
 * Writes the language cookie from the browser on a page-cached site.
 *
 * PHP cannot write it there: the response that would have carried the header is
 * a stored copy, and on the one request that is rendered the header is stored
 * with the page and replayed to every later reader. Writing it here makes it per
 * reader again, exactly as an uncached response would have.
 *
 * The cookie this builds is the one PHP would have sent — same name, path,
 * domain, lifetime, and flags — so the two describe one cookie rather than two
 * under the same name. Its values arrive in `window.shapeTranslateLanguageCookie`,
 * printed by the enqueue that loads this file.
 *
 * @package ShapeTranslate
 */
( function () {
	'use strict';

	var cookie = window.shapeTranslateLanguageCookie;
	var parts;

	if ( ! cookie || ! cookie.name || ! cookie.value ) {
		return;
	}

	parts = [ cookie.name + '=' + encodeURIComponent( cookie.value ), 'path=' + cookie.path ];

	if ( cookie.domain ) {
		parts.push( 'domain=' + cookie.domain );
	}

	if ( cookie.expires ) {
		parts.push( 'expires=' + new Date( cookie.expires * 1000 ).toUTCString() );
	}

	if ( cookie.secure ) {
		parts.push( 'secure' );
	}

	if ( cookie.sameSite ) {
		parts.push( 'SameSite=' + cookie.sameSite );
	}

	document.cookie = parts.join( '; ' );
}() );
