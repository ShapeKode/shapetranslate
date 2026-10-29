<?php
/**
 * Registered string repository contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Defines persistence and reporting operations for registered strings.
 */
interface StringRepositoryInterface {

	/**
	 * Persists missing or changed string definitions.
	 *
	 * @param array<string, array<string, string>> $definitions Definitions keyed by deterministic ID.
	 * @return array<int, array<string, mixed>> Changed definitions with a change value.
	 */
	public function save_definitions( array $definitions );

	/**
	 * Finds definitions by ID and primes definition caches.
	 *
	 * @param array<int, string> $string_ids Deterministic string IDs.
	 * @return array<string, array<string, mixed>> Definitions keyed by string ID.
	 */
	public function get_definitions( array $string_ids );

	/**
	 * Finds one registered string definition.
	 *
	 * @param string $string_id Deterministic string ID.
	 * @return array<string, mixed>|null
	 */
	public function find_definition( $string_id );

	/**
	 * Queries a paginated page of registered definitions.
	 *
	 * @param array<string, mixed> $args Validated reporting arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query_definitions( array $args );

	/**
	 * Returns registered group names in display order.
	 *
	 * @return array<int, string>
	 */
	public function get_groups();

	/**
	 * Bulk-loads translations for string and language combinations.
	 *
	 * @param array<int, string> $string_ids  Deterministic string IDs.
	 * @param array<int, string> $language_ids Stable language IDs.
	 * @return array<string, array<string, string>> Values keyed by string and language ID.
	 */
	public function get_translations( array $string_ids, array $language_ids );

	/**
	 * Finds one translated value.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @return string|null
	 */
	public function find_translation( $string_id, $language_id );

	/**
	 * Creates or updates one translated value.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @param string $translation Sanitized plain-text translation.
	 * @return bool
	 */
	public function save_translation( $string_id, $language_id, $translation );

	/**
	 * Deletes one translated value.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @return bool
	 */
	public function delete_translation( $string_id, $language_id );

	/**
	 * Deletes every translated value for a removed language.
	 *
	 * @param string $language_id Stable language ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_language_translations( $language_id );
}
