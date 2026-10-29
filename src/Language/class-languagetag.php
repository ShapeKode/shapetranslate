<?php
/**
 * Language tag formatting.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Converts ShapeTranslate language records to HTML-compatible language tags.
 */
final class LanguageTag {

	/**
	 * Returns a normalized BCP 47-style language tag.
	 *
	 * WordPress locales use underscores and may include a script, region, or
	 * variant. This preserves those subtags while applying their usual casing.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	public function format( $language ) {
		if ( ! is_array( $language ) ) {
			return '';
		}

		$locale = isset( $language['locale'] ) && is_scalar( $language['locale'] )
			? (string) $language['locale']
			: '';

		if ( '' === $locale && isset( $language['language_code'] ) && is_scalar( $language['language_code'] ) ) {
			$locale = (string) $language['language_code'];
		}

		$subtags = preg_split( '/[-_]/', $locale );

		if ( ! is_array( $subtags ) || empty( $subtags ) ) {
			return '';
		}

		$normalized = array();

		foreach ( $subtags as $index => $subtag ) {
			if ( '' === $subtag || ! preg_match( '/^[A-Za-z0-9]{1,12}$/', $subtag ) ) {
				return '';
			}

			if ( 0 === $index ) {
				$normalized[] = strtolower( $subtag );
			} elseif ( 4 === strlen( $subtag ) && preg_match( '/^[A-Za-z]+$/', $subtag ) ) {
				$normalized[] = ucfirst( strtolower( $subtag ) );
			} elseif (
				( 2 === strlen( $subtag ) && preg_match( '/^[A-Za-z]+$/', $subtag ) )
				|| ( 3 === strlen( $subtag ) && preg_match( '/^[0-9]+$/', $subtag ) )
			) {
				$normalized[] = strtoupper( $subtag );
			} else {
				$normalized[] = strtolower( $subtag );
			}
		}

		$tag = implode( '-', $normalized );

		/**
		 * Filters the HTML language tag for a ShapeTranslate language.
		 *
		 * @param string               $tag      Normalized language tag.
		 * @param array<string, mixed> $language Language record.
		 */
		$filtered = apply_filters( 'shapetranslate_language_tag', $tag, $language );

		return is_string( $filtered ) && $this->is_valid( $filtered ) ? $filtered : $tag;
	}

	/**
	 * Returns the writing direction a language is read in.
	 *
	 * Static because the answer is a property of the record and nothing else:
	 * the callers that need it are spread across the switcher, the document
	 * head, the admin screens, and the locale module, and injecting this class
	 * into each of them to read one flag would cost more than it explains.
	 *
	 * @param array<string, mixed>|mixed $language Language record.
	 * @return string Either `ltr` or `rtl`.
	 */
	public static function direction( $language ) {
		return is_array( $language ) && ! empty( $language['is_rtl'] ) ? 'rtl' : 'ltr';
	}

	/**
	 * Reports whether a language tag is safe for HTML and hreflang output.
	 *
	 * @param string $tag Language tag.
	 * @return bool
	 */
	public function is_valid( $tag ) {
		return 1 === preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,12})*$/', $tag );
	}
}
