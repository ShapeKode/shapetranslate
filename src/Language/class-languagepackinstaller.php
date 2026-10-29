<?php
/**
 * WordPress language pack installation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads the WordPress core translation files for a newly added language.
 *
 * Content in a new language is only half of it: the strings WordPress and the
 * theme print around that content come from the locale's language pack, and a
 * locale without one shows them in English. Once a pack is installed, core's
 * own update checks keep it current, so only the first download needs help.
 */
final class LanguagePackInstaller {

	/**
	 * Installs the language pack for one locale.
	 *
	 * @param string $locale WordPress locale.
	 * @return bool|null True when installed, false when the download failed, null
	 *                   when nothing was attempted.
	 */
	public function install( $locale ) {
		$locale = is_string( $locale ) ? $locale : '';

		// en_US ships with WordPress, and installing packs is its own capability.
		if ( '' === $locale || 'en_US' === $locale || ! current_user_can( 'install_languages' ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		$installed = false !== wp_download_language_pack( $locale );

		// Lets the next update check offer this locale's theme and plugin translations.
		wp_clean_themes_cache();
		wp_clean_plugins_cache();

		return $installed;
	}
}
