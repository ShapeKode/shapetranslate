<?php
/**
 * Language repository contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Defines persistence operations for language records.
 */
interface LanguageRepositoryInterface {

	/**
	 * Returns all language records in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all();

	/**
	 * Finds a language by its stable identifier.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( $language_id );

	/**
	 * Saves a language record.
	 *
	 * @param array<string, mixed> $language     Language record.
	 * @param bool                 $make_default Whether to make this record the default atomically.
	 * @return array<string, mixed> Saved language record.
	 */
	public function save( array $language, $make_default = false );

	/**
	 * Deletes a language record.
	 *
	 * @param string $language_id Language identifier.
	 * @return bool True when a language was deleted.
	 */
	public function delete( $language_id );

	/**
	 * Gets the default language identifier.
	 *
	 * @return string
	 */
	public function get_default_id();

	/**
	 * Sets the default language identifier.
	 *
	 * @param string $language_id Language identifier, or an empty string.
	 * @return void
	 */
	public function set_default_id( $language_id );

	/**
	 * Moves a language one position in the requested direction.
	 *
	 * @param string $language_id Language identifier.
	 * @param string $direction   Either up or down.
	 * @return bool True when the order changed.
	 */
	public function move( $language_id, $direction );
}
