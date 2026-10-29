<?php
/**
 * Hidden taxonomies that hold translation relationships.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Infrastructure;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and writes the taxonomies translation relationships live in.
 *
 * A language and a translation group are each a term, and an object belongs
 * to them through an ordinary term relationship. WordPress then owns the
 * storage, its caches and its cleanup, so the plugin needs no tables of its
 * own for this. Posts and terms get separate taxonomies because a post ID and
 * a term-taxonomy ID are different number spaces in the same relationship
 * table: post 12 and term-taxonomy 12 must never share a language.
 *
 * The group map is never serialized into a term description, as Polylang
 * does. Each member carries its own language term instead, so every question
 * about a group stays an indexed join rather than an unserialize in PHP.
 */
final class RelationshipTaxonomies {

	/**
	 * Language of a post.
	 *
	 * @var string
	 */
	const POST_LANGUAGE = 'shapetranslate_language';

	/**
	 * Translation group of a post.
	 *
	 * @var string
	 */
	const POST_GROUP = 'shapetranslate_post_group';

	/**
	 * Language of a term, keyed by its term-taxonomy ID.
	 *
	 * @var string
	 */
	const TERM_LANGUAGE = 'shapetranslate_term_language';

	/**
	 * Translation group of a term, keyed by its term-taxonomy ID.
	 *
	 * @var string
	 */
	const TERM_GROUP = 'shapetranslate_term_group';

	/**
	 * Term meta on a group term naming the group's source object.
	 *
	 * @var string
	 */
	const SOURCE_META = '_shapetranslate_source';

	/**
	 * Returns every relationship taxonomy.
	 *
	 * @return array<int, string>
	 */
	public static function all() {
		return array( self::POST_LANGUAGE, self::POST_GROUP, self::TERM_LANGUAGE, self::TERM_GROUP );
	}

	/**
	 * Registers the taxonomies.
	 *
	 * They are attached to no object type. WordPress would otherwise list them
	 * on edit screens, in REST responses and in get_object_taxonomies(), and
	 * delete a post's relationships before ShapeTranslate has read them to repair
	 * the group the post leaves. Lifecycle hooks remove relationships instead.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( self::all() as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			register_taxonomy(
				$taxonomy,
				array(),
				array(
					'label'                 => $taxonomy,
					'public'                => false,
					'publicly_queryable'    => false,
					'show_ui'               => false,
					'show_in_menu'          => false,
					'show_in_nav_menus'     => false,
					'show_in_rest'          => false,
					'show_tagcloud'         => false,
					'show_in_quick_edit'    => false,
					'show_admin_column'     => false,
					'hierarchical'          => false,
					'query_var'             => false,
					'rewrite'               => false,
					// Count every relationship, not only published posts, so a count answers "is this language used".
					'update_count_callback' => '_update_generic_term_count',
				)
			);
		}
	}

	/**
	 * Returns the ID of the term a slug names.
	 *
	 * @param string $taxonomy Relationship taxonomy.
	 * @param string $slug     Language or group identifier.
	 * @return int 0 when there is none.
	 */
	public static function find_term_id( $taxonomy, $slug ) {
		self::register();

		$term = get_term_by( 'slug', (string) $slug, $taxonomy );

		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Returns the ID of the term a slug names, creating the term if needed.
	 *
	 * Identifiers are stored as the slug, so one that WordPress would rewrite
	 * on the way in is refused rather than stored under a name no query finds.
	 *
	 * @param string $taxonomy Relationship taxonomy.
	 * @param string $slug     Language or group identifier.
	 * @return int 0 on failure.
	 */
	public static function ensure_term_id( $taxonomy, $slug ) {
		$slug = (string) $slug;

		if ( '' === $slug || sanitize_title( $slug ) !== $slug ) {
			return 0;
		}

		$term_id = self::find_term_id( $taxonomy, $slug );

		if ( 0 < $term_id ) {
			return $term_id;
		}

		$inserted = wp_insert_term( $slug, $taxonomy, array( 'slug' => $slug ) );

		if ( is_wp_error( $inserted ) ) {
			$existing = $inserted->get_error_data( 'term_exists' );

			return is_numeric( $existing ) ? absint( $existing ) : 0;
		}

		return absint( $inserted['term_id'] );
	}

	/**
	 * Puts an object in exactly one term of a taxonomy.
	 *
	 * @param int    $object_id Post ID or term-taxonomy ID.
	 * @param string $taxonomy  Relationship taxonomy.
	 * @param string $slug      Language or group identifier.
	 * @return bool
	 */
	public static function set_object_term( $object_id, $taxonomy, $slug ) {
		$term_id = self::ensure_term_id( $taxonomy, $slug );

		if ( 0 === $term_id ) {
			return false;
		}

		return ! is_wp_error( wp_set_object_terms( absint( $object_id ), array( $term_id ), $taxonomy, false ) );
	}

	/**
	 * Removes an object from every term of the given taxonomies.
	 *
	 * @param int                $object_id  Post ID or term-taxonomy ID.
	 * @param array<int, string> $taxonomies Relationship taxonomies.
	 * @return void
	 */
	public static function clear_object_terms( $object_id, array $taxonomies ) {
		self::register();

		wp_delete_object_term_relationships( absint( $object_id ), $taxonomies );
	}

	/**
	 * Creates a group term that does not exist yet.
	 *
	 * @param string $taxonomy  Group taxonomy.
	 * @param string $group_id  Translation group identifier.
	 * @param int    $source_id Source post ID or term-taxonomy ID.
	 * @return bool False when the group already exists or could not be stored.
	 */
	public static function create_group_term( $taxonomy, $group_id, $source_id ) {
		if ( 0 < self::find_term_id( $taxonomy, $group_id ) ) {
			return false;
		}

		$term_id = self::ensure_term_id( $taxonomy, $group_id );

		if ( 0 === $term_id ) {
			return false;
		}

		update_term_meta( $term_id, self::SOURCE_META, absint( $source_id ) );

		return true;
	}

	/**
	 * Points a group term at a new source object.
	 *
	 * @param string $taxonomy  Group taxonomy.
	 * @param string $group_id  Translation group identifier.
	 * @param int    $source_id Source post ID or term-taxonomy ID.
	 * @return bool
	 */
	public static function update_group_source( $taxonomy, $group_id, $source_id ) {
		$term_id = self::find_term_id( $taxonomy, $group_id );

		if ( 0 === $term_id ) {
			return false;
		}

		// update_term_meta() reports an unchanged value as a failure; storage did not fail.
		if ( absint( get_term_meta( $term_id, self::SOURCE_META, true ) ) === absint( $source_id ) ) {
			return true;
		}

		return false !== update_term_meta( $term_id, self::SOURCE_META, absint( $source_id ) );
	}

	/**
	 * Deletes a term by its slug.
	 *
	 * @param string $taxonomy Relationship taxonomy.
	 * @param string $slug     Language or group identifier.
	 * @return bool True also when there was nothing to delete.
	 */
	public static function delete_term( $taxonomy, $slug ) {
		$term_id = self::find_term_id( $taxonomy, $slug );

		return 0 === $term_id || true === wp_delete_term( $term_id, $taxonomy );
	}

	/**
	 * Returns a derived table of objects with both a language and a group.
	 *
	 * It has the columns `object_id` (or the given name), `language_id` and
	 * `group_id`, the shape a relationship table would have, so a query can
	 * join it like one. MySQL merges the derived table into the outer query,
	 * so the joins still run on the relationship table's primary key.
	 *
	 * The SQL holds only table names and fixed taxonomy names, and no `%`, so
	 * it is safe inside a string that is later passed through prepare().
	 *
	 * @param string $language_taxonomy Language taxonomy.
	 * @param string $group_taxonomy    Group taxonomy.
	 * @param string $object_column     Name the object ID column is selected as.
	 * @return string
	 */
	public static function membership_sql( $language_taxonomy, $group_taxonomy, $object_column = 'object_id' ) {
		global $wpdb;

		$language_taxonomy = sanitize_key( $language_taxonomy );
		$group_taxonomy    = sanitize_key( $group_taxonomy );
		$object_column     = sanitize_key( $object_column );

		return "SELECT shapetranslate_lr.object_id AS {$object_column}, shapetranslate_lt.slug AS language_id, shapetranslate_gt.slug AS group_id
			FROM {$wpdb->term_relationships} AS shapetranslate_lr
			INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ltt ON shapetranslate_ltt.term_taxonomy_id = shapetranslate_lr.term_taxonomy_id AND shapetranslate_ltt.taxonomy = '{$language_taxonomy}'
			INNER JOIN {$wpdb->terms} AS shapetranslate_lt ON shapetranslate_lt.term_id = shapetranslate_ltt.term_id
			INNER JOIN {$wpdb->term_relationships} AS shapetranslate_gr ON shapetranslate_gr.object_id = shapetranslate_lr.object_id
			INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_gtt ON shapetranslate_gtt.term_taxonomy_id = shapetranslate_gr.term_taxonomy_id AND shapetranslate_gtt.taxonomy = '{$group_taxonomy}'
			INNER JOIN {$wpdb->terms} AS shapetranslate_gt ON shapetranslate_gt.term_id = shapetranslate_gtt.term_id";
	}

	/**
	 * Returns a derived table of group terms with their source object.
	 *
	 * Columns: `group_id` and the source object ID under the given name.
	 *
	 * @param string $group_taxonomy Group taxonomy.
	 * @param string $source_column  Name the source ID column is selected as.
	 * @return string
	 */
	public static function groups_sql( $group_taxonomy, $source_column = 'source_id' ) {
		global $wpdb;

		$group_taxonomy = sanitize_key( $group_taxonomy );
		$source_meta    = self::SOURCE_META;
		$source_column  = sanitize_key( $source_column );

		return "SELECT shapetranslate_grp.slug AS group_id, CAST(COALESCE(shapetranslate_src.meta_value, 0) AS UNSIGNED) AS {$source_column}
			FROM {$wpdb->terms} AS shapetranslate_grp
			INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_grptt ON shapetranslate_grptt.term_id = shapetranslate_grp.term_id AND shapetranslate_grptt.taxonomy = '{$group_taxonomy}'
			LEFT JOIN {$wpdb->termmeta} AS shapetranslate_src ON shapetranslate_src.term_id = shapetranslate_grp.term_id AND shapetranslate_src.meta_key = '{$source_meta}'";
	}
}
