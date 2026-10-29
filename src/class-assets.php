<?php
/**
 * Asset cache busting.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the version string browsers cache a bundled asset against.
 *
 * The plugin version alone is not enough. It changes once per release, so any
 * stylesheet or script edited between releases keeps its old URL and browsers
 * keep serving what they already have. That is invisible to the person making
 * the change, because the markup around the asset does update, and it reaches
 * users too whenever a fix ships without a version bump.
 *
 * Appending the file's modification time makes the URL follow the file, so a
 * saved edit is the only thing needed for a browser to fetch it again.
 */
final class Assets {

	/**
	 * Returns the cache-busting version for a bundled asset.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string
	 */
	public static function version( $relative_path ) {
		$path = SHAPETRANSLATE_PATH . ltrim( (string) $relative_path, '/\\' );

		if ( ! is_readable( $path ) ) {
			return SHAPETRANSLATE_VERSION;
		}

		$modified = filemtime( $path );

		return false === $modified ? SHAPETRANSLATE_VERSION : SHAPETRANSLATE_VERSION . '.' . $modified;
	}
}
