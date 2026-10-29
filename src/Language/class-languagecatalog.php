<?php
/**
 * WordPress language catalog.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts the WordPress translation catalog for language setup presets.
 */
final class LanguageCatalog {

	/**
	 * Returns language presets keyed by locale.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_languages() {
		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$catalog      = array();
		$translations = wp_get_available_translations();

		$catalog['en_US'] = array(
			'name'          => 'English (United States)',
			'native_name'   => 'English (United States)',
			'locale'        => 'en_US',
			'language_code' => 'en',
			'url_slug'      => 'en',
			'is_rtl'        => false,
		);

		foreach ( $translations as $locale => $translation ) {
			if ( ! is_string( $locale ) || ! is_array( $translation ) || ! $this->is_valid_locale( $locale ) ) {
				continue;
			}

			$language_code = $this->get_language_code( $translation, $locale );

			$catalog[ $locale ] = array(
				'name'          => $this->text_value( $translation, 'english_name', $locale ),
				'native_name'   => $this->text_value( $translation, 'native_name', $locale ),
				'locale'        => $locale,
				'language_code' => $language_code,
				'url_slug'      => $language_code,
				'is_rtl'        => $this->is_rtl_locale( $locale ),
			);
		}

		$site_locale = get_locale();

		if ( is_string( $site_locale ) && $this->is_valid_locale( $site_locale ) && ! isset( $catalog[ $site_locale ] ) ) {
			$language_code           = $this->get_language_code( array(), $site_locale );
			$catalog[ $site_locale ] = array(
				'name'          => $site_locale,
				'native_name'   => $site_locale,
				'locale'        => $site_locale,
				'language_code' => $language_code,
				'url_slug'      => $language_code,
				'is_rtl'        => $this->is_rtl_locale( $site_locale ),
			);
		}

		/**
		 * Filters language presets shown in the ShapeTranslate setup selector.
		 *
		 * @param array<string, array<string, mixed>> $catalog Language presets keyed by locale.
		 */
		$catalog = apply_filters( 'shapetranslate_language_catalog', $catalog );
		$catalog = is_array( $catalog ) ? $this->normalize_catalog( $catalog ) : array();

		uasort(
			$catalog,
			static function ( $first, $second ) {
				return strcasecmp( $first['name'], $second['name'] );
			}
		);

		return $catalog;
	}

	/**
	 * Normalizes filtered catalog records before display.
	 *
	 * @param array<mixed> $catalog Filtered catalog.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalize_catalog( $catalog ) {
		$normalized = array();

		foreach ( $catalog as $locale => $language ) {
			if ( ! is_string( $locale ) || ! is_array( $language ) || ! $this->is_valid_locale( $locale ) ) {
				continue;
			}

			$language_code = isset( $language['language_code'] ) && is_scalar( $language['language_code'] )
				? strtolower( sanitize_text_field( $language['language_code'] ) )
				: '';

			if ( ! preg_match( '/^[a-z]{2,3}$/', $language_code ) ) {
				continue;
			}

			$url_slug = isset( $language['url_slug'] ) && is_scalar( $language['url_slug'] )
				? sanitize_title( $language['url_slug'] )
				: '';

			$normalized[ $locale ] = array(
				'name'          => $this->text_value( $language, 'name', $locale ),
				'native_name'   => $this->text_value( $language, 'native_name', $locale ),
				'locale'        => $locale,
				'language_code' => $language_code,
				'url_slug'      => '' !== $url_slug ? $url_slug : $language_code,
				'is_rtl'        => ! empty( $language['is_rtl'] ),
			);
		}

		return $normalized;
	}

	/**
	 * Extracts a valid ISO language code from translation metadata.
	 *
	 * @param array<string, mixed> $translation Translation metadata.
	 * @param string               $locale      WordPress locale.
	 * @return string
	 */
	private function get_language_code( $translation, $locale ) {
		$iso_codes = isset( $translation['iso'] ) && is_array( $translation['iso'] )
			? $translation['iso']
			: array();

		foreach ( $iso_codes as $iso_code ) {
			$iso_code = is_scalar( $iso_code ) ? strtolower( sanitize_text_field( $iso_code ) ) : '';

			if ( preg_match( '/^[a-z]{2,3}$/', $iso_code ) ) {
				return $iso_code;
			}
		}

		$parts = preg_split( '/[_-]/', strtolower( $locale ) );

		return isset( $parts[0] ) && preg_match( '/^[a-z]{2,3}$/', $parts[0] ) ? $parts[0] : 'und';
	}

	/**
	 * Returns sanitized text from a catalog record.
	 *
	 * @param array<string, mixed> $record   Catalog record.
	 * @param string               $key      Record key.
	 * @param string               $fallback Fallback text.
	 * @return string
	 */
	private function text_value( $record, $key, $fallback ) {
		$value = isset( $record[ $key ] ) && is_scalar( $record[ $key ] )
			? sanitize_text_field( $record[ $key ] )
			: '';

		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Checks whether a locale fits the Phase 1 locale format.
	 *
	 * @param string $locale Locale to check.
	 * @return bool
	 */
	private function is_valid_locale( $locale ) {
		return (bool) preg_match( '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,12}){0,3}$/', $locale );
	}

	/**
	 * Identifies common right-to-left WordPress locales.
	 *
	 * @param string $locale WordPress locale.
	 * @return bool
	 */
	private function is_rtl_locale( $locale ) {
		$parts = preg_split( '/[_-]/', strtolower( $locale ) );
		$code  = isset( $parts[0] ) ? $parts[0] : '';

		return in_array( $code, array( 'ar', 'ary', 'azb', 'bal', 'ckb', 'dv', 'fa', 'he', 'ps', 'ug', 'ur', 'yi' ), true );
	}
}
