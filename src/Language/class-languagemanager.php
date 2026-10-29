<?php
/**
 * Language management service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

use ShapeTranslate\Contracts\LanguageRepositoryInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates validation, persistence, and language lifecycle events.
 */
final class LanguageManager {

	/**
	 * Language repository.
	 *
	 * @var LanguageRepositoryInterface
	 */
	private $repository;

	/**
	 * Language validator.
	 *
	 * @var LanguageValidator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param LanguageRepositoryInterface $repository Language repository.
	 * @param LanguageValidator           $validator  Language validator.
	 */
	public function __construct( LanguageRepositoryInterface $repository, LanguageValidator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
	}

	/**
	 * Returns registered languages.
	 *
	 * @param bool $enabled_only Whether to return only enabled languages.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_languages( $enabled_only = false ) {
		$languages = $this->repository->all();

		/**
		 * Filters registered languages after they are loaded from storage.
		 *
		 * @param array<int, array<string, mixed>> $languages Registered languages.
		 */
		$languages = apply_filters( 'shapetranslate_registered_languages', $languages );

		if ( ! is_array( $languages ) ) {
			$languages = array();
		}

		$languages = array_filter(
			$languages,
			static function ( $language ) {
				return is_array( $language )
					&& isset(
						$language['id'],
						$language['name'],
						$language['native_name'],
						$language['locale'],
						$language['language_code'],
						$language['url_slug'],
						$language['enabled']
					);
			}
		);

		if ( $enabled_only ) {
			$languages = array_filter(
				$languages,
				static function ( $language ) {
					return is_array( $language ) && ! empty( $language['enabled'] );
				}
			);
		}

		return array_values( $languages );
	}

	/**
	 * Finds one language.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( $language_id ) {
		foreach ( $this->get_languages() as $language ) {
			if ( isset( $language['id'] ) && $language_id === $language['id'] ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Reports whether the persistent registry contains any languages.
	 *
	 * @return bool
	 */
	public function has_languages() {
		return ! empty( $this->repository->all() );
	}

	/**
	 * Creates a language.
	 *
	 * @param array<string, mixed> $input Raw language input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( array $input ) {
		$input = $this->filter_input( $input, '' );

		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$existing_languages = $this->repository->all();
		$old_default        = $this->repository->get_default_id();
		$language           = $this->validator->validate( $input, $existing_languages );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$is_first_language = empty( $existing_languages );

		if ( $is_first_language ) {
			$language['enabled'] = true;
		}

		$language['id']         = wp_generate_uuid4();
		$language['created_at'] = current_time( 'mysql', true );
		$language['updated_at'] = $language['created_at'];

		$default_changed = '' === $old_default && ! empty( $language['enabled'] );
		$language        = $this->repository->save( $language, $default_changed );

		/**
		 * Fires after a language has been registered.
		 *
		 * @param array<string, mixed> $language Registered language.
		 */
		do_action( 'shapetranslate_language_registered', $language );

		if ( $default_changed ) {
			$this->emit_default_changed( $language['id'], '' );
		}

		return $language;
	}

	/**
	 * Updates a language.
	 *
	 * @param string               $language_id Language identifier.
	 * @param array<string, mixed> $input       Raw language input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( $language_id, array $input ) {
		$existing = $this->repository->find( $language_id );

		if ( null === $existing ) {
			return new WP_Error( 'language_not_found', __( 'The requested language could not be found.', 'shapetranslate' ) );
		}

		$input = $this->filter_input( $input, $language_id );

		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$language = $this->validator->validate( $input, $this->repository->all(), $language_id );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( $language_id === $this->repository->get_default_id() && empty( $language['enabled'] ) ) {
			return new WP_Error( 'cannot_disable_default', __( 'The default language cannot be disabled.', 'shapetranslate' ) );
		}

		$language['id']         = $language_id;
		$language['order']      = (int) $existing['order'];
		$language['created_at'] = $existing['created_at'];
		$language['updated_at'] = current_time( 'mysql', true );

		$language = $this->repository->save( $language );

		/**
		 * Fires after a language has been updated.
		 *
		 * @param array<string, mixed> $language Updated language.
		 * @param array<string, mixed> $existing Previous language data.
		 */
		do_action( 'shapetranslate_language_updated', $language, $existing );

		return $language;
	}

	/**
	 * Deletes a language and reports any default-language change.
	 *
	 * The default language is refused, because the site would be left without the
	 * language its untranslated content is written in. Promoting another language
	 * to default first is the deliberate step that releases this one.
	 *
	 * @param string $language_id Language identifier.
	 * @return true|WP_Error
	 */
	public function delete( $language_id ) {
		$language = $this->repository->find( $language_id );

		if ( null === $language ) {
			return new WP_Error( 'language_not_found', __( 'The requested language could not be found.', 'shapetranslate' ) );
		}

		if ( $language_id === $this->repository->get_default_id() ) {
			return new WP_Error(
				'default_language_locked',
				__( 'The default language cannot be removed. Make another language the default first.', 'shapetranslate' )
			);
		}

		/**
		 * Filters whether a language may be deleted.
		 *
		 * Modules can return a WP_Error to preserve related data that still uses
		 * the language. Returning false uses the generic language-in-use error.
		 *
		 * @param true|WP_Error             $can_delete  Whether deletion may continue.
		 * @param string                    $language_id Language identifier.
		 * @param array<string, mixed>      $language    Language record.
		 */
		$can_delete = apply_filters( 'shapetranslate_pre_delete_language', true, $language_id, $language );

		if ( is_wp_error( $can_delete ) ) {
			return $can_delete;
		}

		if ( true !== $can_delete ) {
			return new WP_Error(
				'language_in_use',
				__( 'This language is assigned to content and cannot be deleted.', 'shapetranslate' )
			);
		}

		$old_default = $this->repository->get_default_id();
		$this->repository->delete( $language_id );
		$new_default = $this->repository->get_default_id();

		/**
		 * Fires after a language has been deleted.
		 *
		 * @param string               $language_id Deleted language identifier.
		 * @param array<string, mixed> $language    Deleted language data.
		 */
		do_action( 'shapetranslate_language_deleted', $language_id, $language );

		if ( $old_default !== $new_default ) {
			$this->emit_default_changed( $new_default, $old_default );
		}

		return true;
	}

	/**
	 * Sets an enabled language as the default.
	 *
	 * @param string $language_id Language identifier.
	 * @return true|WP_Error
	 */
	public function set_default( $language_id ) {
		$language = $this->repository->find( $language_id );

		if ( null === $language ) {
			return new WP_Error( 'language_not_found', __( 'The requested language could not be found.', 'shapetranslate' ) );
		}

		if ( empty( $language['enabled'] ) ) {
			return new WP_Error(
				'default_must_be_enabled',
				__( 'Enable a language before making it the default.', 'shapetranslate' )
			);
		}

		$old_default = $this->repository->get_default_id();
		$this->repository->set_default_id( $language_id );

		if ( $old_default !== $language_id ) {
			$this->emit_default_changed( $language_id, $old_default );
		}

		return true;
	}

	/**
	 * Enables or disables a language.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function toggle( $language_id ) {
		$language = $this->repository->find( $language_id );

		if ( null === $language ) {
			return new WP_Error( 'language_not_found', __( 'The requested language could not be found.', 'shapetranslate' ) );
		}

		if ( $language_id === $this->repository->get_default_id() && ! empty( $language['enabled'] ) ) {
			return new WP_Error( 'cannot_disable_default', __( 'The default language cannot be disabled.', 'shapetranslate' ) );
		}

		$previous               = $language;
		$language['enabled']    = empty( $language['enabled'] );
		$language['updated_at'] = current_time( 'mysql', true );
		$make_default           = ! empty( $language['enabled'] ) && '' === $this->repository->get_default_id();
		$language               = $this->repository->save( $language, $make_default );

		if ( $make_default ) {
			$this->emit_default_changed( $language_id, '' );
		}

		do_action( 'shapetranslate_language_updated', $language, $previous );

		return $language;
	}

	/**
	 * Moves a language in the explicit display order.
	 *
	 * @param string $language_id Language identifier.
	 * @param string $direction   Either up or down.
	 * @return true|WP_Error
	 */
	public function move( $language_id, $direction ) {
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
			return new WP_Error( 'invalid_direction', __( 'Invalid language ordering direction.', 'shapetranslate' ) );
		}

		if ( null === $this->repository->find( $language_id ) ) {
			return new WP_Error( 'language_not_found', __( 'The requested language could not be found.', 'shapetranslate' ) );
		}

		if ( $this->repository->move( $language_id, $direction ) ) {
			do_action( 'shapetranslate_language_order_changed', $this->repository->all() );
		}

		return true;
	}

	/**
	 * Gets the filtered default language identifier.
	 *
	 * @return string
	 */
	public function get_default_id() {
		$default_id = $this->repository->get_default_id();

		/**
		 * Filters the default language identifier.
		 *
		 * @param string $default_id Stored default language identifier.
		 */
		$filtered_id = apply_filters( 'shapetranslate_default_language_id', $default_id );

		return is_scalar( $filtered_id ) ? (string) $filtered_id : $default_id;
	}

	/**
	 * Gets the default language record.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_default_language() {
		$language = $this->find( $this->get_default_id() );

		/**
		 * Filters the default language record.
		 *
		 * @param array<string, mixed>|null $language Default language.
		 */
		$filtered_language = apply_filters( 'shapetranslate_default_language', $language );

		return is_array( $filtered_language ) || null === $filtered_language ? $filtered_language : $language;
	}

	/**
	 * Applies the pre-validation extension point safely.
	 *
	 * @param array<string, mixed> $input       Raw input.
	 * @param string               $language_id Existing language identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	private function filter_input( $input, $language_id ) {
		/**
		 * Filters language input immediately before validation.
		 *
		 * @param array<string, mixed> $input       Raw language input.
		 * @param string               $language_id Existing identifier, or an empty string for a new record.
		 */
		$input = apply_filters( 'shapetranslate_pre_validate_language', $input, $language_id );

		if ( ! is_array( $input ) ) {
			return new WP_Error( 'invalid_language_data', __( 'Language data must be an array.', 'shapetranslate' ) );
		}

		return $input;
	}

	/**
	 * Emits the default-language event.
	 *
	 * @param string $new_default New default identifier.
	 * @param string $old_default Previous default identifier.
	 * @return void
	 */
	private function emit_default_changed( $new_default, $old_default ) {
		/**
		 * Fires after the default language changes.
		 *
		 * @param string $new_default New default language identifier.
		 * @param string $old_default Previous default language identifier.
		 */
		do_action( 'shapetranslate_default_language_changed', $new_default, $old_default );
	}
}
