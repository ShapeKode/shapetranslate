<?php
/**
 * Taxonomy-backed post translation repository.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Infrastructure;

use ShapeTranslate\Contracts\TranslationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stores post languages and translation groups as hidden taxonomy terms.
 *
 * Writes go through the term API, so WordPress keeps its caches and counts.
 * Reads join the core term tables in one prepared query, because the term API
 * cannot answer "which language and group" for many posts at once, and every
 * read path is held in an explicit request-level cache.
 */
final class DatabaseTranslationRepository implements TranslationRepositoryInterface {
	// Reads join core term tables directly; each one is prepared and request-cached.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Assignment cache keyed by post ID. Null values represent cache misses.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $assignments = array();

	/**
	 * Group cache keyed by group ID. Null values represent cache misses.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $groups = array();

	/**
	 * Group member cache keyed by group ID.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $members = array();

	/**
	 * Site prefix associated with the request cache.
	 *
	 * @var string
	 */
	private $cache_prefix = '';

	/**
	 * Returns a derived table of post assignments.
	 *
	 * Columns: `post_id`, `group_id`, `language_id`. Join it wherever a query
	 * needs a post's language or group; see RelationshipTaxonomies::membership_sql().
	 *
	 * @return string Parenthesized SQL, ready to alias.
	 */
	public static function assignments_sql() {
		return '(' . RelationshipTaxonomies::membership_sql( RelationshipTaxonomies::POST_LANGUAGE, RelationshipTaxonomies::POST_GROUP, 'post_id' ) . ')';
	}

	/**
	 * Returns a derived table of post translation groups.
	 *
	 * Columns: `group_id`, `source_post_id`.
	 *
	 * @return string Parenthesized SQL, ready to alias.
	 */
	public static function groups_sql() {
		return '(' . RelationshipTaxonomies::groups_sql( RelationshipTaxonomies::POST_GROUP, 'source_post_id' ) . ')';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $post_id Post identifier.
	 */
	public function find_by_post( $post_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$post_id = absint( $post_id );

		if ( array_key_exists( $post_id, $this->assignments ) ) {
			return $this->assignments[ $post_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lr.object_id AS post_id, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				WHERE lr.object_id = %d
				LIMIT 1",
				RelationshipTaxonomies::POST_LANGUAGE,
				RelationshipTaxonomies::POST_GROUP,
				$post_id
			),
			ARRAY_A
		);

		$this->assignments[ $post_id ] = is_array( $row ) ? $this->normalize_assignment( $row ) : null;

		return $this->assignments[ $post_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id Translation group identifier.
	 */
	public function find_group( $group_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$group_id = (string) $group_id;

		if ( array_key_exists( $group_id, $this->groups ) ) {
			return $this->groups[ $group_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT grp.slug AS group_id, CAST(COALESCE(src.meta_value, 0) AS UNSIGNED) AS source_post_id
				FROM {$wpdb->terms} AS grp
				INNER JOIN {$wpdb->term_taxonomy} AS grptt ON grptt.term_id = grp.term_id AND grptt.taxonomy = %s
				LEFT JOIN {$wpdb->termmeta} AS src ON src.term_id = grp.term_id AND src.meta_key = %s
				WHERE grp.slug = %s
				LIMIT 1",
				RelationshipTaxonomies::POST_GROUP,
				RelationshipTaxonomies::SOURCE_META,
				$group_id
			),
			ARRAY_A
		);

		$this->groups[ $group_id ] = is_array( $row ) ? $this->normalize_group( $row ) : null;

		return $this->groups[ $group_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id Translation group identifier.
	 */
	public function get_group_members( $group_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$group_id = (string) $group_id;

		if ( isset( $this->members[ $group_id ] ) ) {
			return $this->members[ $group_id ];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lr.object_id AS post_id, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				WHERE gt.slug = %s
				ORDER BY lr.object_id ASC",
				RelationshipTaxonomies::POST_LANGUAGE,
				RelationshipTaxonomies::POST_GROUP,
				$group_id
			),
			ARRAY_A
		);

		$this->members[ $group_id ] = array();

		foreach ( (array) $rows as $row ) {
			$assignment                                  = $this->normalize_assignment( $row );
			$this->members[ $group_id ][]                = $assignment;
			$this->assignments[ $assignment['post_id'] ] = $assignment;
		}

		return $this->members[ $group_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id       Translation group identifier.
	 * @param int    $source_post_id Source post identifier.
	 */
	public function create_group( $group_id, $source_post_id ) {
		$this->ensure_site_context();

		if ( ! RelationshipTaxonomies::create_group_term( RelationshipTaxonomies::POST_GROUP, $group_id, $source_post_id ) ) {
			return false;
		}

		$this->groups[ (string) $group_id ] = array(
			'group_id'       => (string) $group_id,
			'source_post_id' => absint( $source_post_id ),
		);

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A post holds one assignment, and a group one post per language. The
	 * term API enforces neither, so both are checked here.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $group_id    Translation group identifier.
	 * @param string $language_id Language identifier.
	 */
	public function add_assignment( $post_id, $group_id, $language_id ) {
		$this->ensure_site_context();

		$post_id = absint( $post_id );

		if ( 0 === $post_id || null !== $this->find_by_post( $post_id ) || $this->group_has_language( $group_id, $language_id, $post_id ) ) {
			return false;
		}

		if ( ! RelationshipTaxonomies::set_object_term( $post_id, RelationshipTaxonomies::POST_LANGUAGE, $language_id ) ) {
			return false;
		}

		if ( ! RelationshipTaxonomies::set_object_term( $post_id, RelationshipTaxonomies::POST_GROUP, $group_id ) ) {
			RelationshipTaxonomies::clear_object_terms( $post_id, array( RelationshipTaxonomies::POST_LANGUAGE ) );

			return false;
		}

		$this->assignments[ $post_id ] = array(
			'post_id'     => $post_id,
			'group_id'    => (string) $group_id,
			'language_id' => (string) $language_id,
		);
		unset( $this->members[ (string) $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id Language identifier.
	 */
	public function update_assignment_language( $post_id, $language_id ) {
		$assignment = $this->find_by_post( $post_id );

		if ( null === $assignment ) {
			return false;
		}

		if ( $language_id === $assignment['language_id'] ) {
			return true;
		}

		if (
			$this->group_has_language( $assignment['group_id'], $language_id, $assignment['post_id'] )
			|| ! RelationshipTaxonomies::set_object_term( $assignment['post_id'], RelationshipTaxonomies::POST_LANGUAGE, $language_id )
		) {
			return false;
		}

		$assignment['language_id']                     = (string) $language_id;
		$this->assignments[ $assignment['post_id'] ] = $assignment;
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $post_id Post identifier.
	 */
	public function remove_assignment( $post_id ) {
		$post_id    = absint( $post_id );
		$assignment = $this->find_by_post( $post_id );

		if ( null === $assignment ) {
			return false;
		}

		RelationshipTaxonomies::clear_object_terms( $post_id, array( RelationshipTaxonomies::POST_LANGUAGE, RelationshipTaxonomies::POST_GROUP ) );

		$this->assignments[ $post_id ] = null;
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id       Translation group identifier.
	 * @param int    $source_post_id Source post identifier.
	 */
	public function update_group_source( $group_id, $source_post_id ) {
		$this->ensure_site_context();

		if ( ! RelationshipTaxonomies::update_group_source( RelationshipTaxonomies::POST_GROUP, $group_id, $source_post_id ) ) {
			return false;
		}

		unset( $this->groups[ (string) $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id Translation group identifier.
	 */
	public function delete_group( $group_id ) {
		$this->ensure_site_context();

		$deleted = RelationshipTaxonomies::delete_term( RelationshipTaxonomies::POST_GROUP, $group_id );

		unset( $this->groups[ (string) $group_id ], $this->members[ (string) $group_id ] );

		return $deleted;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $language_id Language identifier.
	 */
	public function count_by_language( $language_id ) {
		global $wpdb;
		$this->ensure_site_context();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				WHERE lt.slug = %s",
				RelationshipTaxonomies::POST_LANGUAGE,
				RelationshipTaxonomies::POST_GROUP,
				(string) $language_id
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<int, int> $post_ids Post identifiers.
	 */
	public function prime_for_posts( array $post_ids ) {
		global $wpdb;
		$this->ensure_site_context();

		$post_ids    = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$uncached = array_values(
			array_filter(
				$post_ids,
				function ( $post_id ) {
					return ! array_key_exists( $post_id, $this->assignments );
				}
			)
		);

		if ( ! empty( $uncached ) ) {
			foreach ( $uncached as $post_id ) {
				$this->assignments[ $post_id ] = null;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT lr.object_id AS post_id, gt.slug AS group_id, lt.slug AS language_id
					FROM {$wpdb->term_relationships} AS lr
					INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
					INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
					INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
					INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
					INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
					WHERE lr.object_id IN (" . implode( ', ', array_fill( 0, count( $uncached ), '%d' ) ) . ')',
					array_merge( array( RelationshipTaxonomies::POST_LANGUAGE, RelationshipTaxonomies::POST_GROUP ), $uncached )
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$assignment                                  = $this->normalize_assignment( $row );
				$this->assignments[ $assignment['post_id'] ] = $assignment;
			}
		}

		$group_ids = array();

		foreach ( $post_ids as $post_id ) {
			$assignment = $this->assignments[ $post_id ];

			if ( null !== $assignment && ! isset( $this->members[ $assignment['group_id'] ] ) ) {
				$group_ids[] = $assignment['group_id'];
			}
		}

		$group_ids = array_values( array_unique( $group_ids ) );

		if ( empty( $group_ids ) ) {
			return;
		}

		$uncached_group_ids = array_values(
			array_filter(
				$group_ids,
				function ( $group_id ) {
					return ! array_key_exists( $group_id, $this->groups );
				}
			)
		);

		if ( ! empty( $uncached_group_ids ) ) {
			foreach ( $uncached_group_ids as $group_id ) {
				$this->groups[ $group_id ] = null;
			}

			$group_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT grp.slug AS group_id, CAST(COALESCE(src.meta_value, 0) AS UNSIGNED) AS source_post_id
					FROM {$wpdb->terms} AS grp
					INNER JOIN {$wpdb->term_taxonomy} AS grptt ON grptt.term_id = grp.term_id AND grptt.taxonomy = %s
					LEFT JOIN {$wpdb->termmeta} AS src ON src.term_id = grp.term_id AND src.meta_key = %s
					WHERE grp.slug IN (" . implode( ', ', array_fill( 0, count( $uncached_group_ids ), '%s' ) ) . ')',
					array_merge( array( RelationshipTaxonomies::POST_GROUP, RelationshipTaxonomies::SOURCE_META ), $uncached_group_ids )
				),
				ARRAY_A
			);

			foreach ( (array) $group_rows as $row ) {
				$group                              = $this->normalize_group( $row );
				$this->groups[ $group['group_id'] ] = $group;
			}
		}

		foreach ( $group_ids as $group_id ) {
			$this->members[ $group_id ] = array();
		}

		$member_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lr.object_id AS post_id, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				WHERE gt.slug IN (" . implode( ', ', array_fill( 0, count( $group_ids ), '%s' ) ) . ')
				ORDER BY lr.object_id ASC',
				array_merge( array( RelationshipTaxonomies::POST_LANGUAGE, RelationshipTaxonomies::POST_GROUP ), $group_ids )
			),
			ARRAY_A
		);

		foreach ( (array) $member_rows as $row ) {
			$assignment                                  = $this->normalize_assignment( $row );
			$this->members[ $assignment['group_id'] ][]  = $assignment;
			$this->assignments[ $assignment['post_id'] ] = $assignment;
		}
	}

	/**
	 * Reports whether another post already answers for a language in a group.
	 *
	 * @param string $group_id    Translation group identifier.
	 * @param string $language_id Language identifier.
	 * @param int    $post_id     Post that may hold the slot itself.
	 * @return bool
	 */
	private function group_has_language( $group_id, $language_id, $post_id ) {
		foreach ( $this->get_group_members( $group_id ) as $member ) {
			if ( (string) $language_id === $member['language_id'] && absint( $post_id ) !== $member['post_id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a database assignment row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_assignment( $row ) {
		return array(
			'post_id'     => absint( $row['post_id'] ),
			'group_id'    => (string) $row['group_id'],
			'language_id' => (string) $row['language_id'],
		);
	}

	/**
	 * Normalizes a database group row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_group( $row ) {
		return array(
			'group_id'       => (string) $row['group_id'],
			'source_post_id' => absint( $row['source_post_id'] ),
		);
	}

	/**
	 * Clears request caches after a multisite blog switch.
	 *
	 * @return void
	 */
	private function ensure_site_context() {
		global $wpdb;

		if ( $this->cache_prefix === $wpdb->prefix ) {
			return;
		}

		$this->cache_prefix = $wpdb->prefix;
		$this->assignments  = array();
		$this->groups       = array();
		$this->members      = array();
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
