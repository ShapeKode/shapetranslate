<?php
/**
 * Registered string validation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the plain-text registered-string contract.
 */
final class RegisteredStringValidator {

	/**
	 * Maximum group length in bytes.
	 *
	 * @var int
	 */
	const MAX_GROUP_LENGTH = 100;

	/**
	 * Maximum key length in bytes.
	 *
	 * @var int
	 */
	const MAX_KEY_LENGTH = 191;

	/**
	 * Maximum original or translated value length in bytes.
	 *
	 * @var int
	 */
	const MAX_STRING_LENGTH = 10000;

	/**
	 * Validates one developer registration.
	 *
	 * @param mixed $group           Developer-defined group.
	 * @param mixed $key             Stable key within the group.
	 * @param mixed $original_string Original plain-text value.
	 * @return array<string, string>|WP_Error
	 */
	public function validate_registration( $group, $key, $original_string ) {
		$identity = $this->validate_identity( $group, $key );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( ! is_scalar( $original_string ) ) {
			return new WP_Error( 'invalid_registered_string', __( 'The original string must be plain text.', 'shapetranslate' ) );
		}

		$original_string = $this->sanitize_value( (string) $original_string );

		if ( '' === $original_string ) {
			return new WP_Error( 'empty_registered_string', __( 'The original string cannot be empty.', 'shapetranslate' ) );
		}

		if ( self::MAX_STRING_LENGTH < strlen( $original_string ) ) {
			return new WP_Error( 'registered_string_too_long', __( 'The original string is too long.', 'shapetranslate' ) );
		}

		$identity['original_string'] = $original_string;

		return $identity;
	}

	/**
	 * Validates a group and key used for lookup.
	 *
	 * @param mixed $group Developer-defined group.
	 * @param mixed $key   Stable key within the group.
	 * @return array<string, string>|WP_Error
	 */
	public function validate_identity( $group, $key ) {
		if ( ! is_scalar( $group ) || ! is_scalar( $key ) ) {
			return new WP_Error( 'invalid_string_identity', __( 'String groups and keys must be plain text.', 'shapetranslate' ) );
		}

		$group = sanitize_text_field( (string) $group );
		$key   = sanitize_text_field( (string) $key );

		if ( '' === $group ) {
			return new WP_Error( 'empty_string_group', __( 'A string group is required.', 'shapetranslate' ) );
		}

		if ( '' === $key ) {
			return new WP_Error( 'empty_string_key', __( 'A string key is required.', 'shapetranslate' ) );
		}

		if ( self::MAX_GROUP_LENGTH < strlen( $group ) ) {
			return new WP_Error( 'string_group_too_long', __( 'The string group is too long.', 'shapetranslate' ) );
		}

		if ( self::MAX_KEY_LENGTH < strlen( $key ) ) {
			return new WP_Error( 'string_key_too_long', __( 'The string key is too long.', 'shapetranslate' ) );
		}

		return array(
			'string_group' => $group,
			'string_key'   => $key,
		);
	}

	/**
	 * Sanitizes an editable translation. Empty values remain empty for deletion.
	 *
	 * @param mixed $translation Submitted translation.
	 * @return string|WP_Error
	 */
	public function validate_translation( $translation ) {
		if ( ! is_scalar( $translation ) ) {
			return new WP_Error( 'invalid_string_translation', __( 'A translation must be plain text.', 'shapetranslate' ) );
		}

		$translation = $this->sanitize_value( (string) $translation );

		if ( self::MAX_STRING_LENGTH < strlen( $translation ) ) {
			return new WP_Error( 'string_translation_too_long', __( 'The translation is too long.', 'shapetranslate' ) );
		}

		return $translation;
	}

	/**
	 * Validates a translation of theme or plugin text read through gettext.
	 *
	 * Such text is printed as the theme wrote it, markup included, so a
	 * translation may carry markup when its original does, filtered to what a
	 * post may contain. Its printf placeholders must match the original's: the
	 * theme passes the same arguments to whichever text it is given, and a
	 * placeholder with no argument behind it stops the page under PHP 8.
	 *
	 * @param mixed  $translation Submitted translation.
	 * @param string $original    Original text.
	 * @return string|WP_Error Empty when the translation should be removed.
	 */
	public function validate_gettext_translation( $translation, $original ) {
		if ( ! is_scalar( $translation ) ) {
			return new WP_Error( 'invalid_string_translation', __( 'A translation must be plain text.', 'shapetranslate' ) );
		}

		$translation = wp_check_invalid_utf8( (string) $translation );
		$translation = $original !== wp_strip_all_tags( $original )
			? wp_kses_post( $translation )
			: sanitize_textarea_field( $translation );

		if ( self::MAX_STRING_LENGTH < strlen( $translation ) ) {
			return new WP_Error( 'string_translation_too_long', __( 'The translation is too long.', 'shapetranslate' ) );
		}

		if ( '' !== $translation && self::placeholders( $translation ) !== self::placeholders( $original ) ) {
			return new WP_Error(
				'string_translation_placeholders',
				sprintf(
					/* translators: %s: original text. */
					__( 'The translation of "%s" must keep the same placeholders, such as %%s or %%1$s, as the original.', 'shapetranslate' ),
					wp_strip_all_tags( $original )
				)
			);
		}

		return $translation;
	}

	/**
	 * Lists the printf placeholders of a text, in a comparable form.
	 *
	 * Numbered placeholders may move, so they are compared as a set; plain
	 * ones are taken in order, so only their count and types matter.
	 *
	 * @param string $text Text.
	 * @return array<int, string>
	 */
	private static function placeholders( $text ) {
		preg_match_all( '/%(?:(\d+)\$)?[-+ 0#]*(?:\'.)?\d*(?:\.\d+)?([bcdeEfFgGosuxX])/', str_replace( '%%', '', (string) $text ), $matches, PREG_SET_ORDER );

		$found = array();

		foreach ( $matches as $match ) {
			$found[] = ( '' !== $match[1] ? $match[1] . '$' : '' ) . $match[2];
		}

		sort( $found );

		return $found;
	}

	/**
	 * Sanitizes a plain-text multiline value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_value( $value ) {
		return sanitize_textarea_field( wp_check_invalid_utf8( $value ) );
	}
}
