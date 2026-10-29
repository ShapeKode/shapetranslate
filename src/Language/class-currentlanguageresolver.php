<?php
/**
 * Current language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Provides a filterable current-language boundary for future detection modules.
 */
final class CurrentLanguageResolver {

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager $language_manager Language manager.
	 */
	public function __construct( LanguageManager $language_manager ) {
		$this->language_manager = $language_manager;
	}

	/**
	 * Resolves the current enabled language, defaulting to the configured default.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve() {
		$languages = $this->language_manager->get_languages( true );
		$default   = $this->language_manager->get_default_id();

		/**
		 * Filters the current language identifier.
		 *
		 * URL, cookie, user, or domain detection modules can select an enabled
		 * language here. Phase 1 intentionally supplies only the default fallback.
		 *
		 * @param string                           $default   Default language identifier.
		 * @param array<int, array<string, mixed>> $languages Enabled languages.
		 */
		$filtered_id = apply_filters( 'shapetranslate_current_language_id', $default, $languages );
		$current_id  = is_scalar( $filtered_id ) ? (string) $filtered_id : $default;
		$current     = null;

		foreach ( $languages as $language ) {
			if ( isset( $language['id'] ) && $current_id === $language['id'] ) {
				$current = $language;
				break;
			}
		}

		if ( null === $current && $current_id !== $default ) {
			foreach ( $languages as $language ) {
				if ( isset( $language['id'] ) && $default === $language['id'] ) {
					$current = $language;
					break;
				}
			}
		}

		/**
		 * Filters the resolved current language record.
		 *
		 * @param array<string, mixed>|null         $current   Current language.
		 * @param array<int, array<string, mixed>> $languages Enabled languages.
		 */
		$filtered_language = apply_filters( 'shapetranslate_current_language', $current, $languages );

		return is_array( $filtered_language ) || null === $filtered_language ? $filtered_language : $current;
	}
}
