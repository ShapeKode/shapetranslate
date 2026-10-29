<?php
/**
 * Options API language repository.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Infrastructure;

use ShapeTranslate\Contracts\LanguageRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the versioned language registry in one WordPress option.
 */
final class OptionsLanguageRepository implements LanguageRepositoryInterface {

	/**
	 * In-request registry cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private $registry;

	/**
	 * Site prefix associated with the request cache.
	 *
	 * @var string
	 */
	private $cache_prefix = '';

	/**
	 * Registry option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'shapetranslate_language_registry';

	/**
	 * Current registry schema version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Creates the registry option when it does not exist.
	 *
	 * @return void
	 */
	public static function install() {
		add_option( self::OPTION_NAME, self::default_registry(), '', false );
	}

	/**
	 * Returns all language records in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		$registry  = $this->get_registry();
		$languages = array_values( $registry['languages'] );

		usort( $languages, array( $this, 'compare_languages' ) );

		return $languages;
	}

	/**
	 * Finds a language by its stable identifier.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( $language_id ) {
		$registry = $this->get_registry();

		return isset( $registry['languages'][ $language_id ] )
			? $registry['languages'][ $language_id ]
			: null;
	}

	/**
	 * Saves a language record and optionally sets it as default in one write.
	 *
	 * @param array<string, mixed> $language     Language record.
	 * @param bool                 $make_default Whether to set this language as default.
	 * @return array<string, mixed> Saved language record.
	 */
	public function save( array $language, $make_default = false ) {
		$registry    = $this->get_registry();
		$language_id = $language['id'];

		if ( ! isset( $registry['languages'][ $language_id ] ) && ! isset( $language['order'] ) ) {
			$language['order'] = $this->next_order( $registry['languages'] );
		}

		$registry['languages'][ $language_id ] = $language;

		if ( $make_default ) {
			$registry['default_language_id'] = $language_id;
		}

		$this->persist( $registry );

		return $language;
	}

	/**
	 * Deletes a language record.
	 *
	 * @param string $language_id Language identifier.
	 * @return bool True when a language was deleted.
	 */
	public function delete( $language_id ) {
		$registry = $this->get_registry();

		if ( ! isset( $registry['languages'][ $language_id ] ) ) {
			return false;
		}

		unset( $registry['languages'][ $language_id ] );

		if ( $language_id === $registry['default_language_id'] ) {
			$registry['default_language_id'] = $this->find_fallback_id( $registry['languages'] );
		}

		$this->persist( $registry );

		return true;
	}

	/**
	 * Gets the default language identifier.
	 *
	 * @return string
	 */
	public function get_default_id() {
		$registry = $this->get_registry();

		return $registry['default_language_id'];
	}

	/**
	 * Sets the default language identifier.
	 *
	 * @param string $language_id Language identifier, or an empty string.
	 * @return void
	 */
	public function set_default_id( $language_id ) {
		$registry = $this->get_registry();

		if ( '' !== $language_id && ! isset( $registry['languages'][ $language_id ] ) ) {
			return;
		}

		$registry['default_language_id'] = $language_id;
		$this->persist( $registry );
	}

	/**
	 * Moves a language one position in the requested direction.
	 *
	 * @param string $language_id Language identifier.
	 * @param string $direction   Either up or down.
	 * @return bool True when the order changed.
	 */
	public function move( $language_id, $direction ) {
		$registry  = $this->get_registry();
		$languages = array_values( $registry['languages'] );

		usort( $languages, array( $this, 'compare_languages' ) );

		$current_index = null;

		foreach ( $languages as $index => $language ) {
			if ( $language_id === $language['id'] ) {
				$current_index = $index;
				break;
			}
		}

		if ( null === $current_index ) {
			return false;
		}

		$target_index = 'up' === $direction ? $current_index - 1 : $current_index + 1;

		if ( ! isset( $languages[ $target_index ] ) ) {
			return false;
		}

		$current_language            = $languages[ $current_index ];
		$languages[ $current_index ] = $languages[ $target_index ];
		$languages[ $target_index ]  = $current_language;

		foreach ( $languages as $index => $language ) {
			$language['order']                        = ( $index + 1 ) * 10;
			$registry['languages'][ $language['id'] ] = $language;
		}

		$this->persist( $registry );

		return true;
	}

	/**
	 * Sorts language records by explicit order, then name.
	 *
	 * @param array<string, mixed> $first  First language.
	 * @param array<string, mixed> $second Second language.
	 * @return int
	 */
	private function compare_languages( $first, $second ) {
		$order_comparison = (int) $first['order'] <=> (int) $second['order'];

		if ( 0 !== $order_comparison ) {
			return $order_comparison;
		}

		return strcasecmp( $first['name'], $second['name'] );
	}

	/**
	 * Returns the next available order value.
	 *
	 * @param array<string, array<string, mixed>> $languages Language map.
	 * @return int
	 */
	private function next_order( $languages ) {
		$maximum = 0;

		foreach ( $languages as $language ) {
			$maximum = max( $maximum, (int) $language['order'] );
		}

		return $maximum + 10;
	}

	/**
	 * Selects a replacement default, preferring enabled languages.
	 *
	 * @param array<string, array<string, mixed>> $languages Language map.
	 * @return string
	 */
	private function find_fallback_id( $languages ) {
		$ordered = array_values( $languages );
		usort( $ordered, array( $this, 'compare_languages' ) );

		foreach ( $ordered as $language ) {
			if ( ! empty( $language['enabled'] ) ) {
				return $language['id'];
			}
		}

		return '';
	}

	/**
	 * Reads and normalizes the registry.
	 *
	 * @return array{schema_version: int, default_language_id: string, languages: array<string, array<string, mixed>>}
	 */
	private function get_registry() {
		global $wpdb;

		if ( $this->cache_prefix !== $wpdb->prefix ) {
			$this->cache_prefix = $wpdb->prefix;
			$this->registry     = null;
		}

		if ( null !== $this->registry ) {
			return $this->registry;
		}

		$registry = get_option( self::OPTION_NAME, self::default_registry() );

		if ( ! is_array( $registry ) ) {
			$this->registry = self::default_registry();

			return $this->registry;
		}

		$registry = wp_parse_args( $registry, self::default_registry() );

		if ( ! is_array( $registry['languages'] ) ) {
			$registry['languages'] = array();
		}

		$registry['schema_version']      = is_numeric( $registry['schema_version'] )
			? (int) $registry['schema_version']
			: self::SCHEMA_VERSION;
		$registry['default_language_id'] = is_scalar( $registry['default_language_id'] )
			? (string) $registry['default_language_id']
			: '';
		$registry['languages']           = $this->normalize_languages( $registry['languages'] );

		if ( ! isset( $registry['languages'][ $registry['default_language_id'] ] ) ) {
			$registry['default_language_id'] = '';
		}

		$this->registry = $registry;

		return $this->registry;
	}

	/**
	 * Normalizes stored records defensively before they reach domain services.
	 *
	 * @param array<mixed> $languages Stored language map.
	 * @return array<string, array<string, mixed>>
	 */
	private function normalize_languages( $languages ) {
		$normalized = array();

		foreach ( $languages as $language_id => $language ) {
			if ( ! is_string( $language_id ) || '' === $language_id || ! is_array( $language ) ) {
				continue;
			}

			$language = wp_parse_args(
				$language,
				array(
					'name'          => '',
					'locale'        => '',
					'language_code' => '',
					'url_slug'      => '',
					'is_rtl'        => false,
					'native_name'   => '',
					'enabled'       => false,
					'domain'        => '',
					'order'         => 0,
					'created_at'    => '',
					'updated_at'    => '',
				)
			);

			$language['id']      = $language_id;
			$language['is_rtl']  = (bool) $language['is_rtl'];
			$language['enabled'] = (bool) $language['enabled'];
			$language['order']   = (int) $language['order'];

			$string_fields = array(
				'name',
				'locale',
				'language_code',
				'url_slug',
				'native_name',
				'domain',
				'created_at',
				'updated_at',
			);

			foreach ( $string_fields as $field ) {
				$language[ $field ] = is_scalar( $language[ $field ] ) ? (string) $language[ $field ] : '';
			}

			$normalized[ $language_id ] = $language;
		}

		return $normalized;
	}

	/**
	 * Writes the complete registry without autoloading it on unrelated requests.
	 *
	 * @param array<string, mixed> $registry Registry data.
	 * @return void
	 */
	private function persist( $registry ) {
		$registry['schema_version'] = self::SCHEMA_VERSION;
		update_option( self::OPTION_NAME, $registry, false );
		$this->registry = $registry;
	}

	/**
	 * Returns a new empty registry.
	 *
	 * @return array{schema_version: int, default_language_id: string, languages: array<string, array<string, mixed>>}
	 */
	private static function default_registry() {
		return array(
			'schema_version'      => self::SCHEMA_VERSION,
			'default_language_id' => '',
			'languages'           => array(),
		);
	}
}
