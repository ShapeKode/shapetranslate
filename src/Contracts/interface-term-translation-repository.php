<?php
/**
 * Term translation repository contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Defines persistence operations for term translation groups.
 */
interface TermTranslationRepositoryInterface {

	/**
	 * Finds an assignment by term identity inside a taxonomy.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, mixed>|null
	 */
	public function find_by_term( $term_id, $taxonomy );

	/**
	 * Finds an assignment by term-taxonomy identifier.
	 *
	 * @param int $term_taxonomy_id Term-taxonomy identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_by_term_taxonomy( $term_taxonomy_id );

	/**
	 * Finds a translation group.
	 *
	 * @param string $group_id Translation group identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_group( $group_id );

	/**
	 * Returns all assignments in a translation group.
	 *
	 * @param string $group_id Translation group identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_group_members( $group_id );

	/**
	 * Creates a term translation group.
	 *
	 * @param string $group_id               Translation group identifier.
	 * @param int    $source_term_taxonomy_id Source term-taxonomy identifier.
	 * @param string $taxonomy               Taxonomy name.
	 * @return bool
	 */
	public function create_group( $group_id, $source_term_taxonomy_id, $taxonomy );

	/**
	 * Adds a term to a translation group.
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param int    $term_id          Term identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $group_id         Translation group identifier.
	 * @param string $language_id      Language identifier.
	 * @return bool
	 */
	public function add_assignment( $term_taxonomy_id, $term_id, $taxonomy, $group_id, $language_id );

	/**
	 * Changes the language assigned to a term.
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $language_id      Language identifier.
	 * @return bool
	 */
	public function update_assignment_language( $term_taxonomy_id, $language_id );

	/**
	 * Moves a term assignment into another translation group.
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $group_id         Target translation group identifier.
	 * @return bool
	 */
	public function update_assignment_group( $term_taxonomy_id, $group_id );

	/**
	 * Removes a term assignment.
	 *
	 * @param int $term_taxonomy_id Term-taxonomy identifier.
	 * @return bool
	 */
	public function remove_assignment( $term_taxonomy_id );

	/**
	 * Changes the source term for a translation group.
	 *
	 * @param string $group_id               Translation group identifier.
	 * @param int    $source_term_taxonomy_id Source term-taxonomy identifier.
	 * @return bool
	 */
	public function update_group_source( $group_id, $source_term_taxonomy_id );

	/**
	 * Deletes a translation group.
	 *
	 * @param string $group_id Translation group identifier.
	 * @return bool
	 */
	public function delete_group( $group_id );

	/**
	 * Counts term assignments using a language.
	 *
	 * @param string $language_id Language identifier.
	 * @return int
	 */
	public function count_by_language( $language_id );

	/**
	 * Primes relationship caches for term-taxonomy identifiers.
	 *
	 * @param array<int, int> $term_taxonomy_ids Term-taxonomy identifiers.
	 * @return void
	 */
	public function prime_for_term_taxonomies( array $term_taxonomy_ids );
}
