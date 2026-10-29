<?php
/**
 * Language input validation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes and validates language records at the domain boundary.
 */
final class LanguageValidator {

	/**
	 * Validates user-provided language fields.
	 *
	 * @param array<string, mixed>             $input              Raw language input.
	 * @param array<int, array<string, mixed>> $existing_languages Existing records.
	 * @param string                           $editing_id          Record being edited.
	 * @return array<string, mixed>|WP_Error
	 */
	public function validate( array $input, array $existing_languages, $editing_id = '' ) {
		$language = array(
			'name'          => sanitize_text_field( $this->scalar_value( $input, 'name' ) ),
			'locale'        => sanitize_text_field( $this->scalar_value( $input, 'locale' ) ),
			'language_code' => strtolower( sanitize_text_field( $this->scalar_value( $input, 'language_code' ) ) ),
			'url_slug'      => sanitize_title( $this->scalar_value( $input, 'url_slug' ) ),
			'is_rtl'        => $this->boolean_value( $input, 'is_rtl' ),
			'native_name'   => sanitize_text_field( $this->scalar_value( $input, 'native_name' ) ),
			'enabled'       => $this->boolean_value( $input, 'enabled' ),
			'domain'        => $this->host_value( $this->scalar_value( $input, 'domain' ) ),
		);

		$language['locale'] = str_replace( '-', '_', $language['locale'] );

		if ( '' === $language['name'] ) {
			return new WP_Error( 'missing_name', __( 'Language name is required.', 'shapetranslate' ) );
		}

		if ( '' === $language['native_name'] ) {
			return new WP_Error( 'missing_native_name', __( 'Native name is required.', 'shapetranslate' ) );
		}

		if ( $this->is_too_long( $language['name'], 100 ) || $this->is_too_long( $language['native_name'], 100 ) ) {
			return new WP_Error( 'name_too_long', __( 'Language names must be 100 characters or fewer.', 'shapetranslate' ) );
		}

		if ( ! preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,12}){0,3}$/', $language['locale'] ) ) {
			return new WP_Error(
				'invalid_locale',
				__( 'Enter a valid WordPress locale, such as en_US or pt_BR.', 'shapetranslate' )
			);
		}

		if ( ! preg_match( '/^[a-z]{2,3}$/', $language['language_code'] ) ) {
			return new WP_Error(
				'invalid_language_code',
				__( 'Language code must contain two or three lowercase letters.', 'shapetranslate' )
			);
		}

		if ( '' === $language['url_slug'] || $this->is_too_long( $language['url_slug'], 80 ) ) {
			return new WP_Error(
				'invalid_url_slug',
				__( 'URL slug is required and must be 80 characters or fewer.', 'shapetranslate' )
			);
		}

		/*
		 * The domain is only read by the domain URL mode, and it stays optional
		 * there so a site can register its languages first and point them at
		 * hostnames afterwards. An entry that is present must still be a host.
		 */
		if ( '' !== $this->scalar_value( $input, 'domain' ) && '' === $language['domain'] ) {
			return new WP_Error(
				'invalid_domain',
				__( 'Enter a valid domain, such as example.fr.', 'shapetranslate' )
			);
		}

		foreach ( $existing_languages as $existing_language ) {
			if (
				! is_array( $existing_language )
				|| ! isset( $existing_language['id'], $existing_language['locale'], $existing_language['url_slug'] )
			) {
				continue;
			}

			if ( $editing_id === $existing_language['id'] ) {
				continue;
			}

			if ( 0 === strcasecmp( $language['locale'], $existing_language['locale'] ) ) {
				return new WP_Error( 'duplicate_locale', __( 'That locale is already registered.', 'shapetranslate' ) );
			}

			if ( 0 === strcasecmp( $language['url_slug'], $existing_language['url_slug'] ) ) {
				return new WP_Error( 'duplicate_url_slug', __( 'That URL slug is already in use.', 'shapetranslate' ) );
			}

			if (
				'' !== $language['domain']
				&& isset( $existing_language['domain'] )
				&& 0 === strcasecmp( $language['domain'], (string) $existing_language['domain'] )
			) {
				return new WP_Error( 'duplicate_domain', __( 'That domain is already in use.', 'shapetranslate' ) );
			}
		}

		return $language;
	}

	/**
	 * Reduces a submitted domain to a bare, comparable hostname.
	 *
	 * @param string $value Submitted domain.
	 * @return string Empty when the value is not a usable hostname.
	 */
	private function host_value( $value ) {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );

		if ( '' === $value ) {
			return '';
		}

		// Accepts a pasted URL as readily as a bare host.
		if ( false !== strpos( $value, '//' ) ) {
			$parts = wp_parse_url( $value );
			$value = is_array( $parts ) && isset( $parts['host'] ) ? $parts['host'] : '';
		}

		$value = (string) preg_replace( '#[/?].*$#', '', $value );
		$value = (string) preg_replace( '/:\d+$/', '', $value );
		$value = trim( $value, '.' );

		if ( $this->is_too_long( $value, 253 ) ) {
			return '';
		}

		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/', $value ) ? $value : '';
	}

	/**
	 * Returns a scalar input value or an empty string.
	 *
	 * @param array<string, mixed> $input Input array.
	 * @param string               $key   Input key.
	 * @return string
	 */
	private function scalar_value( $input, $key ) {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}

	/**
	 * Normalizes checkbox and API-style boolean values.
	 *
	 * @param array<string, mixed> $input Input array.
	 * @param string               $key   Input key.
	 * @return bool
	 */
	private function boolean_value( $input, $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return false;
		}

		if ( is_bool( $input[ $key ] ) ) {
			return $input[ $key ];
		}

		$value = strtolower( trim( (string) $input[ $key ] ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Checks a character limit with or without the mbstring extension.
	 *
	 * @param string $value  Value to check.
	 * @param int    $length Maximum character count.
	 * @return bool
	 */
	private function is_too_long( $value, $length ) {
		$current_length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

		return $current_length > $length;
	}
}
