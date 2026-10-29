<?php
/**
 * Public registered-string and language switcher template functions.
 *
 * @package ShapeTranslate
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'shapetranslate_register_string' ) ) {
	/**
	 * Registers a ShapeTranslate-controlled plain-text string.
	 *
	 * Call this on init or later, after ShapeTranslate services have booted.
	 *
	 * @param mixed $group           Developer-defined group.
	 * @param mixed $key             Stable key within the group.
	 * @param mixed $original_string Original plain-text value.
	 * @return string|WP_Error Deterministic string ID on success.
	 */
	function shapetranslate_register_string( $group, $key, $original_string ) {
		$manager = ShapeTranslate\Plugin::instance()->strings();

		if ( ! $manager instanceof ShapeTranslate\StringTranslation\StringManager ) {
			return new WP_Error(
				'shapetranslate_not_ready',
				__( 'Register ShapeTranslate strings on init or later.', 'shapetranslate' )
			);
		}

		return $manager->register_string( $group, $key, $original_string );
	}
}

if ( ! function_exists( 'shapetranslate_translate_string' ) ) {
	/**
	 * Retrieves a ShapeTranslate-controlled string for the active language.
	 *
	 * The returned plain text must be contextually escaped by the caller.
	 *
	 * @param mixed  $group       Developer-defined group.
	 * @param mixed  $key         Stable key within the group.
	 * @param mixed  $fallback    Optional original fallback and registration value.
	 * @param string $language_id Optional stable language ID for explicit retrieval.
	 * @return string
	 */
	function shapetranslate_translate_string( $group, $key, $fallback = '', $language_id = '' ) {
		$manager = ShapeTranslate\Plugin::instance()->strings();

		if ( ! $manager instanceof ShapeTranslate\StringTranslation\StringManager ) {
			return is_scalar( $fallback ) ? sanitize_textarea_field( (string) $fallback ) : '';
		}

		return $manager->get_string( $group, $key, $fallback, $language_id );
	}
}

if ( ! function_exists( 'shapetranslate_get_language_switcher' ) ) {
	/**
	 * Returns language switcher markup for the current request.
	 *
	 * Call this on or after `shapetranslate_loaded`. Earlier calls return an empty
	 * string rather than failing, so a theme template stays safe when ShapeTranslate
	 * is deactivated part-way through a request.
	 *
	 * Accepted arguments mirror the shortcode: `display` (`name`, `native_name`,
	 * `language_code`, `flag`), `layout` (`horizontal`, `vertical`, `dropdown`),
	 * `hide_current`, `hide_missing`, `unavailable_behavior` (`disabled`, `hide`,
	 * `home`, `current`), `show_flags`, `show_disabled`, `aria_label`, and
	 * `class_name`. Omitted arguments fall back to the configured switcher
	 * defaults. The returned markup is already escaped.
	 *
	 * @param array<string, mixed> $args Optional switcher arguments.
	 * @return string
	 */
	function shapetranslate_get_language_switcher( $args = array() ) {
		$switcher = ShapeTranslate\Plugin::instance()->switcher();

		if ( ! $switcher instanceof ShapeTranslate\Switcher\LanguageSwitcher ) {
			return '';
		}

		return $switcher->render( is_array( $args ) ? $args : array() );
	}
}

if ( ! function_exists( 'shapetranslate_language_switcher' ) ) {
	/**
	 * Echoes the language switcher for the current request.
	 *
	 * @param array<string, mixed> $args Optional switcher arguments.
	 * @return void
	 */
	function shapetranslate_language_switcher( $args = array() ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped in LanguageSwitcher::render().
		echo shapetranslate_get_language_switcher( $args );
	}
}
