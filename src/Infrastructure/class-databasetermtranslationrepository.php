<?php
/**
 * Taxonomy-backed term translation repository.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Infrastructure;

use ShapeTranslate\Contracts\TermTranslationRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stores term languages and translation groups as hidden taxonomy terms.
 *
 * A translated term is addressed by its term-taxonomy ID, which is the object
 * ID of its relationships here. Its term ID and taxonomy name are read from
 * WordPress's own term taxonomy table on every query rather than copied, so
 * they can never drift from the term they describe.
 */
final class DatabaseTermTranslationRepository implements TermTranslationRepositoryInterface {
	// Reads join core term tables directly; each one is prepared and request-cached.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Assignment cache keyed by term-taxonomy ID.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $assignments = array();

	/**
	 * Term lookup cache keyed by taxonomy and term ID.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $terms = array();

	/**
	 * Group cache keyed by group ID.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $groups = array();

	/**
	 * Group members keyed by group ID.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $members = array();

	/**
	 * Site prefix associated with request caches.
	 *
	 * @var string
	 */
	private $cache_prefix = '';

	/**
	 * Returns a derived table of term assignments.
	 *
	 * Columns: `term_taxonomy_id`, `term_id`, `taxonomy`, `group_id`, `language_id`.
	 *
	 * @return string Parenthesized SQL, ready to alias.
	 */
	public static function assignments_sql() {
		global $wpdb;

		$membership = RelationshipTaxonomies::membership_sql( RelationshipTaxonomies::TERM_LANGUAGE, RelationshipTaxonomies::TERM_GROUP, 'term_taxonomy_id' );

		return "(SELECT shapetranslate_tm.term_taxonomy_id, shapetranslate_ott.term_id, shapetranslate_ott.taxonomy, shapetranslate_tm.group_id, shapetranslate_tm.language_id
			FROM ({$membership}) AS shapetranslate_tm
			INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ott ON shapetranslate_ott.term_taxonomy_id = shapetranslate_tm.term_taxonomy_id)";
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function find_by_term( $term_id, $taxonomy ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_id   = absint( $term_id );
		$taxonomy  = sanitize_key( $taxonomy );
		$cache_key = $this->term_cache_key( $term_id, $taxonomy );

		if ( array_key_exists( $cache_key, $this->terms ) ) {
			return $this->terms[ $cache_key ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lr.object_id AS term_taxonomy_id, ott.term_id, ott.taxonomy, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				INNER JOIN {$wpdb->term_taxonomy} AS ott ON ott.term_taxonomy_id = lr.object_id
				WHERE ott.term_id = %d AND ott.taxonomy = %s
				LIMIT 1",
				RelationshipTaxonomies::TERM_LANGUAGE,
				RelationshipTaxonomies::TERM_GROUP,
				$term_id,
				$taxonomy
			),
			ARRAY_A
		);

		$this->terms[ $cache_key ] = is_array( $row ) ? $this->normalize_assignment( $row ) : null;

		if ( null !== $this->terms[ $cache_key ] ) {
			$this->assignments[ $this->terms[ $cache_key ]['term_taxonomy_id'] ] = $this->terms[ $cache_key ];
		}

		return $this->terms[ $cache_key ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $term_taxonomy_id Term-taxonomy identifier.
	 */
	public function find_by_term_taxonomy( $term_taxonomy_id ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_taxonomy_id = absint( $term_taxonomy_id );

		if ( array_key_exists( $term_taxonomy_id, $this->assignments ) ) {
			return $this->assignments[ $term_taxonomy_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lr.object_id AS term_taxonomy_id, ott.term_id, ott.taxonomy, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				INNER JOIN {$wpdb->term_taxonomy} AS ott ON ott.term_taxonomy_id = lr.object_id
				WHERE lr.object_id = %d
				LIMIT 1",
				RelationshipTaxonomies::TERM_LANGUAGE,
				RelationshipTaxonomies::TERM_GROUP,
				$term_taxonomy_id
			),
			ARRAY_A
		);

		$this->assignments[ $term_taxonomy_id ] = is_array( $row ) ? $this->normalize_assignment( $row ) : null;
		$this->cache_term_assignment( $this->assignments[ $term_taxonomy_id ] );

		return $this->assignments[ $term_taxonomy_id ];
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
				"SELECT grp.slug AS group_id, CAST(COALESCE(src.meta_value, 0) AS UNSIGNED) AS source_term_taxonomy_id, COALESCE(stt.taxonomy, '') AS taxonomy
				FROM {$wpdb->terms} AS grp
				INNER JOIN {$wpdb->term_taxonomy} AS grptt ON grptt.term_id = grp.term_id AND grptt.taxonomy = %s
				LEFT JOIN {$wpdb->termmeta} AS src ON src.term_id = grp.term_id AND src.meta_key = %s
				LEFT JOIN {$wpdb->term_taxonomy} AS stt ON stt.term_taxonomy_id = CAST(COALESCE(src.meta_value, 0) AS UNSIGNED)
				WHERE grp.slug = %s
				LIMIT 1",
				RelationshipTaxonomies::TERM_GROUP,
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
				"SELECT lr.object_id AS term_taxonomy_id, ott.term_id, ott.taxonomy, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				INNER JOIN {$wpdb->term_taxonomy} AS ott ON ott.term_taxonomy_id = lr.object_id
				WHERE gt.slug = %s
				ORDER BY lr.object_id ASC",
				RelationshipTaxonomies::TERM_LANGUAGE,
				RelationshipTaxonomies::TERM_GROUP,
				$group_id
			),
			ARRAY_A
		);

		$this->members[ $group_id ] = array();

		foreach ( (array) $rows as $row ) {
			$assignment                   = $this->normalize_assignment( $row );
			$this->members[ $group_id ][] = $assignment;
			$this->cache_assignment( $assignment );
		}

		return $this->members[ $group_id ];
	}

	/**
	 * {@inheritdoc}
	 *
	 * The taxonomy is not stored: a group's taxonomy is its source term's.
	 *
	 * @param string $group_id                Translation group identifier.
	 * @param int    $source_term_taxonomy_id Source term-taxonomy identifier.
	 * @param string $taxonomy                Taxonomy name.
	 */
	public function create_group( $group_id, $source_term_taxonomy_id, $taxonomy ) {
		$this->ensure_site_context();

		if ( ! RelationshipTaxonomies::create_group_term( RelationshipTaxonomies::TERM_GROUP, $group_id, $source_term_taxonomy_id ) ) {
			return false;
		}

		$this->groups[ (string) $group_id ] = array(
			'group_id'                => (string) $group_id,
			'source_term_taxonomy_id' => absint( $source_term_taxonomy_id ),
			'taxonomy'                => sanitize_key( $taxonomy ),
		);

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A term holds one assignment, and a group one term per language. The
	 * term API enforces neither, so both are checked here.
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param int    $term_id          Term identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @param string $group_id         Translation group identifier.
	 * @param string $language_id      Language identifier.
	 */
	public function add_assignment( $term_taxonomy_id, $term_id, $taxonomy, $group_id, $language_id ) {
		$this->ensure_site_context();

		$term_taxonomy_id = absint( $term_taxonomy_id );

		if (
			0 === $term_taxonomy_id
			|| null !== $this->find_by_term_taxonomy( $term_taxonomy_id )
			|| $this->group_has_language( $group_id, $language_id, $term_taxonomy_id )
		) {
			return false;
		}

		if ( ! RelationshipTaxonomies::set_object_term( $term_taxonomy_id, RelationshipTaxonomies::TERM_LANGUAGE, $language_id ) ) {
			return false;
		}

		if ( ! RelationshipTaxonomies::set_object_term( $term_taxonomy_id, RelationshipTaxonomies::TERM_GROUP, $group_id ) ) {
			RelationshipTaxonomies::clear_object_terms( $term_taxonomy_id, array( RelationshipTaxonomies::TERM_LANGUAGE ) );

			return false;
		}

		$this->cache_assignment(
			array(
				'term_taxonomy_id' => $term_taxonomy_id,
				'term_id'          => absint( $term_id ),
				'taxonomy'         => sanitize_key( $taxonomy ),
				'group_id'         => (string) $group_id,
				'language_id'      => (string) $language_id,
			)
		);
		unset( $this->members[ (string) $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $language_id      Language identifier.
	 */
	public function update_assignment_language( $term_taxonomy_id, $language_id ) {
		$assignment = $this->find_by_term_taxonomy( $term_taxonomy_id );

		if ( null === $assignment ) {
			return false;
		}

		if ( $language_id === $assignment['language_id'] ) {
			return true;
		}

		if (
			$this->group_has_language( $assignment['group_id'], $language_id, $assignment['term_taxonomy_id'] )
			|| ! RelationshipTaxonomies::set_object_term( $assignment['term_taxonomy_id'], RelationshipTaxonomies::TERM_LANGUAGE, $language_id )
		) {
			return false;
		}

		$assignment['language_id'] = (string) $language_id;
		$this->cache_assignment( $assignment );
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * A move onto a language the target group already holds is refused rather
	 * than producing a group that answers twice for one language.
	 *
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $group_id         Target translation group identifier.
	 */
	public function update_assignment_group( $term_taxonomy_id, $group_id ) {
		$assignment = $this->find_by_term_taxonomy( $term_taxonomy_id );

		if ( null === $assignment ) {
			return false;
		}

		if ( $group_id === $assignment['group_id'] ) {
			return true;
		}

		if (
			$this->group_has_language( $group_id, $assignment['language_id'], $assignment['term_taxonomy_id'] )
			|| ! RelationshipTaxonomies::set_object_term( $assignment['term_taxonomy_id'], RelationshipTaxonomies::TERM_GROUP, $group_id )
		) {
			return false;
		}

		$previous_group_id      = $assignment['group_id'];
		$assignment['group_id'] = (string) $group_id;
		$this->cache_assignment( $assignment );
		unset( $this->members[ $previous_group_id ], $this->members[ (string) $group_id ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int $term_taxonomy_id Term-taxonomy identifier.
	 */
	public function remove_assignment( $term_taxonomy_id ) {
		$term_taxonomy_id = absint( $term_taxonomy_id );
		$assignment       = $this->find_by_term_taxonomy( $term_taxonomy_id );

		if ( null === $assignment ) {
			return false;
		}

		RelationshipTaxonomies::clear_object_terms( $term_taxonomy_id, array( RelationshipTaxonomies::TERM_LANGUAGE, RelationshipTaxonomies::TERM_GROUP ) );

		$this->assignments[ $term_taxonomy_id ] = null;
		$this->terms[ $this->term_cache_key( $assignment['term_id'], $assignment['taxonomy'] ) ] = null;
		unset( $this->members[ $assignment['group_id'] ] );

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $group_id                Translation group identifier.
	 * @param int    $source_term_taxonomy_id Source term-taxonomy identifier.
	 */
	public function update_group_source( $group_id, $source_term_taxonomy_id ) {
		$this->ensure_site_context();

		if ( ! RelationshipTaxonomies::update_group_source( RelationshipTaxonomies::TERM_GROUP, $group_id, $source_term_taxonomy_id ) ) {
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

		$deleted = RelationshipTaxonomies::delete_term( RelationshipTaxonomies::TERM_GROUP, $group_id );

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
				INNER JOIN {$wpdb->term_taxonomy} AS ott ON ott.term_taxonomy_id = lr.object_id
				WHERE lt.slug = %s",
				RelationshipTaxonomies::TERM_LANGUAGE,
				RelationshipTaxonomies::TERM_GROUP,
				(string) $language_id
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<int, int> $term_taxonomy_ids Term-taxonomy identifiers.
	 */
	public function prime_for_term_taxonomies( array $term_taxonomy_ids ) {
		global $wpdb;
		$this->ensure_site_context();

		$term_taxonomy_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_taxonomy_ids ) ) ) );
		$uncached          = array_values(
			array_filter(
				$term_taxonomy_ids,
				function ( $term_taxonomy_id ) {
					return ! array_key_exists( $term_taxonomy_id, $this->assignments );
				}
			)
		);

		if ( ! empty( $uncached ) ) {
			foreach ( $uncached as $term_taxonomy_id ) {
				$this->assignments[ $term_taxonomy_id ] = null;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT lr.object_id AS term_taxonomy_id, ott.term_id, ott.taxonomy, gt.slug AS group_id, lt.slug AS language_id
					FROM {$wpdb->term_relationships} AS lr
					INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
					INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
					INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
					INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
					INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
					INNER JOIN {$wpdb->term_taxonomy} AS ott ON ott.term_taxonomy_id = lr.object_id
					WHERE lr.object_id IN (" . implode( ', ', array_fill( 0, count( $uncached ), '%d' ) ) . ')',
					array_merge( array( RelationshipTaxonomies::TERM_LANGUAGE, RelationshipTaxonomies::TERM_GROUP ), $uncached )
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$this->cache_assignment( $this->normalize_assignment( $row ) );
			}
		}

		$group_ids = array();

		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$assignment = $this->assignments[ $term_taxonomy_id ];

			if ( null !== $assignment && ! isset( $this->members[ $assignment['group_id'] ] ) ) {
				$group_ids[] = $assignment['group_id'];
			}
		}

		$group_ids = array_values( array_unique( $group_ids ) );

		if ( empty( $group_ids ) ) {
			return;
		}

		foreach ( $group_ids as $group_id ) {
			$this->members[ $group_id ] = array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lr.object_id AS term_taxonomy_id, ott.term_id, ott.taxonomy, gt.slug AS group_id, lt.slug AS language_id
				FROM {$wpdb->term_relationships} AS lr
				INNER JOIN {$wpdb->term_taxonomy} AS ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS lt ON lt.term_id = ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS gr ON gr.object_id = lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS gt ON gt.term_id = gtt.term_id
				INNER JOIN {$wpdb->term_taxonomy} AS ott ON ott.term_taxonomy_id = lr.object_id
				WHERE gt.slug IN (" . implode( ', ', array_fill( 0, count( $group_ids ), '%s' ) ) . ')
				ORDER BY lr.object_id ASC',
				array_merge( array( RelationshipTaxonomies::TERM_LANGUAGE, RelationshipTaxonomies::TERM_GROUP ), $group_ids )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$assignment                                 = $this->normalize_assignment( $row );
			$this->members[ $assignment['group_id'] ][] = $assignment;
			$this->cache_assignment( $assignment );
		}
	}

	/**
	 * Reports whether another term already answers for a language in a group.
	 *
	 * @param string $group_id         Translation group identifier.
	 * @param string $language_id      Language identifier.
	 * @param int    $term_taxonomy_id Term that may hold the slot itself.
	 * @return bool
	 */
	private function group_has_language( $group_id, $language_id, $term_taxonomy_id ) {
		foreach ( $this->get_group_members( $group_id ) as $member ) {
			if ( (string) $language_id === $member['language_id'] && absint( $term_taxonomy_id ) !== $member['term_taxonomy_id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Caches an assignment under all supported lookup keys.
	 *
	 * @param array<string, mixed> $assignment Assignment row.
	 * @return void
	 */
	private function cache_assignment( $assignment ) {
		$this->assignments[ $assignment['term_taxonomy_id'] ] = $assignment;
		$this->cache_term_assignment( $assignment );
	}

	/**
	 * Caches an assignment by its term and taxonomy.
	 *
	 * @param array<string, mixed>|null $assignment Assignment row.
	 * @return void
	 */
	private function cache_term_assignment( $assignment ) {
		if ( null === $assignment ) {
			return;
		}

		$this->terms[ $this->term_cache_key( $assignment['term_id'], $assignment['taxonomy'] ) ] = $assignment;
	}

	/**
	 * Builds a collision-free term lookup key.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function term_cache_key( $term_id, $taxonomy ) {
		return sanitize_key( $taxonomy ) . ':' . absint( $term_id );
	}

	/**
	 * Normalizes an assignment row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_assignment( $row ) {
		return array(
			'term_taxonomy_id' => absint( $row['term_taxonomy_id'] ),
			'term_id'          => absint( $row['term_id'] ),
			'taxonomy'         => sanitize_key( $row['taxonomy'] ),
			'group_id'         => (string) $row['group_id'],
			'language_id'      => (string) $row['language_id'],
		);
	}

	/**
	 * Normalizes a group row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function normalize_group( $row ) {
		return array(
			'group_id'                => (string) $row['group_id'],
			'source_term_taxonomy_id' => absint( $row['source_term_taxonomy_id'] ),
			'taxonomy'                => sanitize_key( $row['taxonomy'] ),
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
		$this->terms        = array();
		$this->groups       = array();
		$this->members      = array();
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
