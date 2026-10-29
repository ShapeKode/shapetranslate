<?php
/**
 * Full page cache detection.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether a full page cache stands between PHP and the visitor.
 *
 * It matters because a cached hit never reaches PHP at all: no hook fires, no
 * header is written, and anything the plugin does per visitor simply does not
 * happen. What the plugin writes on the request that *was* rendered is worse
 * than useless — a Set-Cookie header stored alongside the page hands one
 * visitor's language to everyone the cache serves it to afterwards.
 *
 * Nothing here asks a specific cache plugin anything. WP_CACHE is the flag
 * WordPress itself uses to decide whether to load a page cache drop-in, so it
 * answers for every plugin that installs one; the named constants cover the
 * ones that cache pages without setting it.
 */
final class CacheCompatibility {

	/**
	 * Reports whether the site is served through a full page cache.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = ( defined( 'WP_CACHE' ) && WP_CACHE )
			|| defined( 'WPFC_MAIN_PATH' ) // WP Fastest Cache leaves WP_CACHE alone.
			|| defined( 'WPO_VERSION' );   // So does WP-Optimize.

		/**
		 * Filters whether ShapeTranslate treats this site as page-cached.
		 *
		 * Return true on a host that caches pages above WordPress — a CDN or a
		 * server-level cache leaves no constant behind — so the language cookie
		 * is written where a cached response cannot swallow it. Return false on
		 * a cache configured to vary on the language cookie itself.
		 *
		 * @param bool $active Whether a page cache was detected.
		 */
		return (bool) apply_filters( 'shapetranslate_is_cache_active', $active );
	}
}
