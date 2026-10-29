<?php
/**
 * Registered string application service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use ShapeTranslate\Contracts\StringRepositoryInterface;
use ShapeTranslate\Language\CurrentLanguageResolver;
use ShapeTranslate\Language\LanguageManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates registration, cached retrieval, and translation mutations.
 */
final class StringManager {

	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface
	 */
	private $repository;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private $current_language_resolver;

	/**
	 * Registered string validator.
	 *
	 * @var RegisteredStringValidator
	 */
	private $validator;

	/**
	 * Current-request definitions waiting for a bounded persistence check.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $registered = array();

	/**
	 * Constructor.
	 *
	 * @param StringRepositoryInterface $repository                String repository.
	 * @param LanguageManager           $language_manager          Language manager.
	 * @param CurrentLanguageResolver   $current_language_resolver Current language resolver.
	 * @param RegisteredStringValidator $validator                 Registered string validator.
	 */
	public function __construct(
		StringRepositoryInterface $repository,
		LanguageManager $language_manager,
		CurrentLanguageResolver $current_language_resolver,
		RegisteredStringValidator $validator
	) {
		$this->repository                = $repository;
		$this->language_manager          = $language_manager;
		$this->current_language_resolver = $current_language_resolver;
		$this->validator                 = $validator;
	}

	/**
	 * Registers a stable plain-text string definition.
	 *
	 * Registration is queued and deduplicated for one bounded persistence check.
	 *
	 * @param mixed $group           Developer-defined group.
	 * @param mixed $key             Stable key within the group.
	 * @param mixed $original_string Original plain-text value.
	 * @return string|WP_Error Deterministic string ID on success.
	 */
	public function register_string( $group, $key, $original_string ) {
		$input = array(
			'group'           => $group,
			'key'             => $key,
			'original_string' => $original_string,
		);

		/**
		 * Filters a developer string registration before validation.
		 *
		 * @param array<string, mixed> $input Raw registration input.
		 */
		$filtered = apply_filters( 'shapetranslate_pre_register_string', $input );

		if ( is_array( $filtered ) ) {
			$input = wp_parse_args( $filtered, $input );
		}

		$definition = $this->validator->validate_registration(
			$input['group'],
			$input['key'],
			$input['original_string']
		);

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$string_id                      = self::generate_string_id( $definition['string_group'], $definition['string_key'] );
		$definition['string_id']        = $string_id;
		$this->registered[ $string_id ] = $definition;

		return $string_id;
	}

	/**
	 * Persists newly registered or changed definitions.
	 *
	 * @return int Number of definitions created or updated.
	 */
	public function flush_registered_strings() {
		if ( empty( $this->registered ) ) {
			return 0;
		}

		$pending          = $this->registered;
		$this->registered = array();
		$changed          = $this->repository->save_definitions( $pending );

		foreach ( $changed as $definition ) {
			/**
			 * Fires after a registered definition is created or its original changes.
			 *
			 * @param array<string, mixed> $definition Definition including created/updated change.
			 */
			do_action( 'shapetranslate_registered_string_saved', $definition );
		}

		return count( $changed );
	}

	/**
	 * Gets a translation for the current or explicitly selected language.
	 *
	 * The returned plain text is not contextually escaped; callers must use the
	 * appropriate escaping function when rendering it.
	 *
	 * @param mixed  $group       Developer-defined group.
	 * @param mixed  $key         Stable key within the group.
	 * @param mixed  $fallback    Optional original fallback and registration value.
	 * @param string $language_id Optional stable language ID. Empty uses current language.
	 * @return string
	 */
	public function get_string( $group, $key, $fallback = '', $language_id = '' ) {
		$identity = $this->validator->validate_identity( $group, $key );

		if ( is_wp_error( $identity ) ) {
			return $this->safe_fallback( $fallback );
		}

		$string_id = self::generate_string_id( $identity['string_group'], $identity['string_key'] );
		$original  = $this->safe_fallback( $fallback );

		if ( '' !== $original ) {
			$registered_id = $this->register_string( $identity['string_group'], $identity['string_key'], $original );

			if ( is_wp_error( $registered_id ) ) {
				return $original;
			}
		} elseif ( isset( $this->registered[ $string_id ] ) ) {
			$original = $this->registered[ $string_id ]['original_string'];
		} else {
			$definition = $this->repository->find_definition( $string_id );
			$original   = is_array( $definition ) ? $definition['original_string'] : '';
		}

		$language = $this->resolve_language( $language_id );

		if ( null === $language ) {
			return $original;
		}

		$translation     = $this->repository->find_translation( $string_id, $language['id'] );
		$has_translation = null !== $translation;
		$value           = $has_translation ? $translation : $original;

		/**
		 * Filters one resolved registered-string value.
		 *
		 * @param string               $value           Resolved plain-text value.
		 * @param array<string, mixed> $definition      String identity and original value.
		 * @param array<string, mixed> $language        Resolved enabled language.
		 * @param bool                 $has_translation Whether a stored translation was used.
		 */
		$filtered = apply_filters(
			'shapetranslate_string_translation',
			$value,
			array(
				'string_id'       => $string_id,
				'string_group'    => $identity['string_group'],
				'string_key'      => $identity['string_key'],
				'original_string' => $original,
			),
			$language,
			$has_translation
		);

		return is_scalar( $filtered ) ? (string) $filtered : $value;
	}

	/**
	 * Saves a page of translations for one enabled language.
	 *
	 * Empty submitted values delete existing translations and restore fallback.
	 *
	 * @param string               $language_id Stable language ID.
	 * @param array<string, mixed> $translations Values keyed by deterministic string ID.
	 * @return int|WP_Error Number of changed translations.
	 */
	public function save_translations( $language_id, $translations ) {
		$language = $this->language_manager->find( $language_id );

		if ( ! is_array( $language ) || empty( $language['enabled'] ) ) {
			return new WP_Error( 'invalid_string_language', __( 'Select an enabled language.', 'shapetranslate' ) );
		}

		if ( ! is_array( $translations ) ) {
			return new WP_Error( 'invalid_string_translations', __( 'No valid string translations were submitted.', 'shapetranslate' ) );
		}

		$this->flush_registered_strings();
		$submitted = array();

		foreach ( array_slice( $translations, 0, 100, true ) as $string_id => $translation ) {
			$string_id = is_scalar( $string_id ) ? strtolower( (string) $string_id ) : '';

			if ( 1 === preg_match( '/\A[a-f0-9]{64}\z/', $string_id ) ) {
				$submitted[ $string_id ] = $translation;
			}
		}

		$definitions = $this->repository->get_definitions( array_keys( $submitted ) );
		$existing    = $this->repository->get_translations( array_keys( $definitions ), array( $language_id ) );
		$validated   = array();
		$changed     = 0;

		foreach ( $definitions as $string_id => $definition ) {
			$translation = GettextStrings::is_group( $definition['string_group'] )
				? $this->validator->validate_gettext_translation( $submitted[ $string_id ], (string) $definition['original_string'] )
				: $this->validator->validate_translation( $submitted[ $string_id ] );

			if ( is_wp_error( $translation ) ) {
				return $translation;
			}

			$validated[ $string_id ] = $translation;
		}

		foreach ( $definitions as $string_id => $definition ) {
			$translation = $validated[ $string_id ];

			$current = isset( $existing[ $string_id ][ $language_id ] )
				? $existing[ $string_id ][ $language_id ]
				: null;

			if ( '' === $translation ) {
				if ( null !== $current && $this->repository->delete_translation( $string_id, $language_id ) ) {
					++$changed;
					do_action( 'shapetranslate_string_translation_deleted', $string_id, $language_id, $current );
				}
				continue;
			}

			if ( $translation !== $current && $this->repository->save_translation( $string_id, $language_id, $translation ) ) {
				++$changed;

				/**
				 * Fires after one registered string translation is saved.
				 *
				 * @param string               $string_id   Deterministic string ID.
				 * @param string               $language_id Stable language ID.
				 * @param string               $translation Saved plain-text value.
				 * @param string|null          $current     Previous value.
				 * @param array<string, mixed> $definition  Registered definition.
				 */
				do_action(
					'shapetranslate_string_translation_saved',
					$string_id,
					$language_id,
					$translation,
					$current,
					$definition
				);
			}
		}

		return $changed;
	}

	/**
	 * Removes stale translations after a language is deleted.
	 *
	 * @param string $language_id Deleted language ID.
	 * @return int
	 */
	public function delete_language_translations( $language_id ) {
		return $this->repository->delete_language_translations( $language_id );
	}

	/**
	 * Generates a stable ID from a validated group and key.
	 *
	 * @param string $group Validated group.
	 * @param string $key   Validated key.
	 * @return string
	 */
	public static function generate_string_id( $group, $key ) {
		return hash( 'sha256', $group . "\x1F" . $key );
	}

	/**
	 * Resolves an enabled language record.
	 *
	 * @param string $language_id Optional stable language ID.
	 * @return array<string, mixed>|null
	 */
	private function resolve_language( $language_id ) {
		$language = '' === $language_id
			? $this->current_language_resolver->resolve()
			: $this->language_manager->find( $language_id );

		if ( ! is_array( $language ) || empty( $language['enabled'] ) ) {
			return null;
		}

		return $language;
	}

	/**
	 * Sanitizes a fallback without turning invalid input into an error.
	 *
	 * @param mixed $fallback Candidate fallback.
	 * @return string
	 */
	private function safe_fallback( $fallback ) {
		$value = $this->validator->validate_translation( $fallback );

		return is_wp_error( $value ) ? '' : $value;
	}
}
