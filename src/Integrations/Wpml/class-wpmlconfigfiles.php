<?php
/**
 * Discovery of wpml-config.xml files.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Wpml;

defined( 'ABSPATH' ) || exit;

/**
 * Locates the wpml-config.xml files shipped by plugins, themes, and the site.
 *
 * Discovery never scans the plugins directory. Active plugins are already known
 * from the `active_plugins` option, so one readability check per active plugin
 * is enough and the cost stays flat as a site grows.
 */
final class WpmlConfigFiles {

	/**
	 * Configuration file name declared by the WPML specification.
	 *
	 * @var string
	 */
	const FILE_NAME = 'wpml-config.xml';

	/**
	 * Returns every readable configuration file, keyed by context.
	 *
	 * The context identifies where a file came from. It becomes the string
	 * translation group for any option the file declares, so a site owner can
	 * tell which plugin or theme asked for a string.
	 *
	 * @return array<string, string> A context identifier as key, an absolute path as value.
	 */
	public function locate() {
		$files = array_merge(
			$this->plugin_files(),
			$this->theme_files(),
			$this->mu_plugin_files(),
			$this->custom_files()
		);

		/**
		 * Filters the wpml-config.xml files ShapeTranslate reads.
		 *
		 * A site can add a file for a plugin that ships none, or drop one whose
		 * declarations conflict with its own configuration.
		 *
		 * @param array<string, string> $files Absolute paths keyed by context identifier.
		 */
		$filtered = apply_filters( 'shapetranslate_wpml_config_files', $files );

		if ( ! is_array( $filtered ) ) {
			return $files;
		}

		$resolved = array();

		foreach ( $filtered as $context => $path ) {
			if ( ! is_string( $context ) || '' === $context || ! is_string( $path ) || '' === $path ) {
				continue;
			}

			if ( is_readable( $path ) ) {
				$resolved[ $context ] = $path;
			}
		}

		return $resolved;
	}

	/**
	 * Returns a fingerprint of the located files and their modification times.
	 *
	 * The parsed result is cached against this value, so activating a plugin,
	 * switching a theme, or shipping an updated file invalidates the cache
	 * without anyone having to clear it.
	 *
	 * @param array<string, string> $files Absolute paths keyed by context identifier.
	 * @return string
	 */
	public function fingerprint( array $files ) {
		$parts = array();

		foreach ( $files as $context => $path ) {
			$modified = file_exists( $path ) ? filemtime( $path ) : 0;
			$parts[]  = $context . ':' . $path . ':' . (int) $modified;
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Returns the configuration files of active plugins.
	 *
	 * Network activated plugins are included on multisite, because a plugin can
	 * be active for a site without appearing in that site's own option.
	 *
	 * @return array<string, string>
	 */
	private function plugin_files() {
		$plugins = array();

		if ( is_multisite() ) {
			$sitewide = get_site_option( 'active_sitewide_plugins', array() );

			if ( is_array( $sitewide ) ) {
				$plugins = array_keys( $sitewide );
			}
		}

		$active = get_option( 'active_plugins', array() );

		if ( is_array( $active ) ) {
			$plugins = array_merge( $plugins, $active );
		}

		$files = array();

		foreach ( array_unique( $plugins ) as $plugin ) {
			if ( ! is_string( $plugin ) || '' === $plugin ) {
				continue;
			}

			$directory = dirname( $plugin );

			if ( '.' === $directory || '' === $directory ) {
				continue;
			}

			$path = trailingslashit( WP_PLUGIN_DIR ) . $directory . '/' . self::FILE_NAME;

			if ( is_readable( $path ) ) {
				$files[ 'plugins/' . $directory ] = $path;
			}
		}

		return $files;
	}

	/**
	 * Returns the configuration files of the active theme and its parent.
	 *
	 * @return array<string, string>
	 */
	private function theme_files() {
		$files    = array();
		$template = get_template_directory();
		$path     = $template . '/' . self::FILE_NAME;

		if ( is_readable( $path ) ) {
			$files[ 'themes/' . get_template() ] = $path;
		}

		$stylesheet = get_stylesheet_directory();
		$path       = $stylesheet . '/' . self::FILE_NAME;

		if ( $stylesheet !== $template && is_readable( $path ) ) {
			$files[ 'themes/' . get_stylesheet() ] = $path;
		}

		return $files;
	}

	/**
	 * Returns the configuration files of must-use plugins.
	 *
	 * Both the loader directory itself and the sub-directories of proxy loaded
	 * plugins are checked, because a must-use plugin is often a single loader
	 * file next to a full plugin folder.
	 *
	 * @return array<string, string>
	 */
	private function mu_plugin_files() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! is_readable( WPMU_PLUGIN_DIR ) ) {
			return array();
		}

		$files = array();
		$path  = trailingslashit( WPMU_PLUGIN_DIR ) . self::FILE_NAME;

		if ( is_readable( $path ) ) {
			$files['mu-plugins'] = $path;
		}

		$nested = glob( trailingslashit( WPMU_PLUGIN_DIR ) . '*/' . self::FILE_NAME );

		if ( ! is_array( $nested ) ) {
			return $files;
		}

		foreach ( $nested as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$files[ 'mu-plugins/' . basename( dirname( $file ) ) ] = $file;
		}

		return $files;
	}

	/**
	 * Returns the site's own configuration file.
	 *
	 * A site owner can declare rules for a plugin that ships no file of its own
	 * by dropping one into the ShapeTranslate directory inside wp-content. It is
	 * read last, so it is the file that survives an update.
	 *
	 * @return array<string, string>
	 */
	private function custom_files() {
		if ( ! defined( 'SHAPETRANSLATE_LOCAL_DIR' ) ) {
			return array();
		}

		$path = trailingslashit( SHAPETRANSLATE_LOCAL_DIR ) . self::FILE_NAME;

		if ( ! is_readable( $path ) ) {
			return array();
		}

		return array( 'shapetranslate' => $path );
	}
}
