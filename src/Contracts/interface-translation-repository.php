<?php
/**
 * Translation repository contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Defines persistence operations for post translation groups.
 */
interface TranslationRepositoryInterface {

	/**
	 * Finds the translation assignment for a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_by_post( $post_id );

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
	 * Creates a translation group.
	 *
	 * @param string $group_id      Translation group identifier.
	 * @param int    $source_post_id Source post identifier.
	 * @return bool
	 */
	public function create_group( $group_id, $source_post_id );

	/**
	 * Adds a post to a translation group.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $group_id    Translation group identifier.
	 * @param string $language_id Language identifier.
	 * @return bool
	 */
	public function add_assignment( $post_id, $group_id, $language_id );

	/**
	 * Changes the language assigned to a post.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id Language identifier.
	 * @return bool
	 */
	public function update_assignment_language( $post_id, $language_id );

	/**
	 * Removes a post assignment.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	public function remove_assignment( $post_id );

	/**
	 * Changes the source post for a translation group.
	 *
	 * @param string $group_id      Translation group identifier.
	 * @param int    $source_post_id Source post identifier.
	 * @return bool
	 */
	public function update_group_source( $group_id, $source_post_id );

	/**
	 * Deletes an empty translation group.
	 *
	 * @param string $group_id Translation group identifier.
	 * @return bool
	 */
	public function delete_group( $group_id );

	/**
	 * Counts post assignments using a language.
	 *
	 * @param string $language_id Language identifier.
	 * @return int
	 */
	public function count_by_language( $language_id );

	/**
	 * Primes request-level assignment and group-member caches.
	 *
	 * @param array<int, int> $post_ids Post identifiers.
	 * @return void
	 */
	public function prime_for_posts( array $post_ids );
}
