<?php
/**
 * Shared language constraint for post and term queries.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Infrastructure\DatabaseTermTranslationRepository;
use ShapeTranslate\Infrastructure\DatabaseTranslationRepository;
use ShapeTranslate\Infrastructure\RelationshipTaxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the indexed assignment join that limits a query to one language.
 *
 * Frontend routing and the REST API both need the same constraint, so the SQL
 * lives here once. Queries for the default language also include content with no
 * stored assignment, which keeps a site that installed ShapeTranslate later working
 * before every item has been assigned.
 */
final class LanguageQueryConstraint {

	/**
	 * SQL alias used for the post assignment join.
	 *
	 * @var string
	 */
	const POST_ALIAS = 'shapetranslate_route_language';

	/**
	 * SQL alias used for the term assignment join.
	 *
	 * @var string
	 */
	const TERM_ALIAS = 'shapetranslate_term_language';

	/**
	 * Constrains a post query to one language.
	 *
	 * @param array<string, string> $clauses             SQL clauses.
	 * @param string                $language_id         Requested language identifier.
	 * @param string                $default_language_id Default language identifier.
	 * @return array<string, string>
	 */
	public function apply_to_posts( array $clauses, $language_id, $default_language_id ) {
		if ( ! isset( $clauses['join'], $clauses['where'] ) || '' === (string) $language_id ) {
			return $clauses;
		}

		if ( (string) $language_id === (string) $default_language_id ) {
			$clauses['where'] .= ' AND ' . $this->posts_default_language_condition( $language_id );

			return $clauses;
		}

		if ( false === strpos( $clauses['join'], self::POST_ALIAS ) ) {
			$clauses['join'] .= $this->posts_join_clause();
		}

		$clauses['where'] .= $this->posts_where_clause( $language_id, $default_language_id );

		return $clauses;
	}

	/**
	 * Returns the default-language test without the assignment join.
	 *
	 * The default language also answers for posts with no assignment, so the
	 * joined test has to read every post's assignment before it can count the
	 * listing: on a site with tens of thousands of posts that join costs more
	 * than the rest of the query. Asking only whether a post belongs to some
	 * other language gives the same rows and lets the posts index drive.
	 *
	 * The subquery is the assignment table spelled out rather than joined as a
	 * derived table, and STRAIGHT_JOIN keeps it starting from the post. Given
	 * the derived table, MySQL builds the whole site's assignments once per
	 * query, which made a short page list slower than before.
	 *
	 * @param string $language_id Default language identifier.
	 * @return string
	 */
	private function posts_default_language_condition( $language_id ) {
		global $wpdb;

		return $wpdb->prepare(
			"NOT EXISTS (
				SELECT STRAIGHT_JOIN 1 FROM {$wpdb->term_relationships} AS shapetranslate_ol_lr
				INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ol_ltt ON shapetranslate_ol_ltt.term_taxonomy_id = shapetranslate_ol_lr.term_taxonomy_id AND shapetranslate_ol_ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS shapetranslate_ol_lt ON shapetranslate_ol_lt.term_id = shapetranslate_ol_ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS shapetranslate_ol_gr ON shapetranslate_ol_gr.object_id = shapetranslate_ol_lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ol_gtt ON shapetranslate_ol_gtt.term_taxonomy_id = shapetranslate_ol_gr.term_taxonomy_id AND shapetranslate_ol_gtt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS shapetranslate_ol_gt ON shapetranslate_ol_gt.term_id = shapetranslate_ol_gtt.term_id
				WHERE shapetranslate_ol_lr.object_id = {$wpdb->posts}.ID
				AND shapetranslate_ol_lt.slug <> %s
			)",
			RelationshipTaxonomies::POST_LANGUAGE,
			RelationshipTaxonomies::POST_GROUP,
			(string) $language_id
		);
	}

	/**
	 * Constrains a post query to one language, falling back to the original.
	 *
	 * Some listings are navigation rather than content. A header's page list is
	 * the case that names the rest: it is how a reader moves around the site, so
	 * a language nobody has translated the pages into must still be given
	 * somewhere to go. Answering it with nothing is not "this language has
	 * nothing here" — it is a site with no way out of the page you are on.
	 *
	 * So the rule is widened by exactly one step: the requested language's
	 * version where it exists, and the default language's version where it does
	 * not. A page that has been translated is therefore never shown twice, and a
	 * page written only in some third language is not offered either, because it
	 * is not what this listing would fall back to.
	 *
	 * @param array<string, string> $clauses             SQL clauses.
	 * @param string                $language_id         Requested language identifier.
	 * @param string                $default_language_id Default language identifier.
	 * @return array<string, string>
	 */
	public function apply_to_posts_with_fallback( array $clauses, $language_id, $default_language_id ) {
		if ( ! isset( $clauses['join'], $clauses['where'] ) || '' === (string) $language_id ) {
			return $clauses;
		}

		if ( (string) $language_id === (string) $default_language_id ) {
			return $this->apply_to_posts( $clauses, $language_id, $default_language_id );
		}

		if ( false === strpos( $clauses['join'], self::POST_ALIAS ) ) {
			$clauses['join'] .= $this->posts_join_clause();
		}

		$condition = $this->posts_fallback_condition( $language_id, $default_language_id );

		if ( '' !== $condition ) {
			$clauses['where'] .= ' AND ' . $condition;
		}

		return $clauses;
	}

	/**
	 * Returns the fallback language test on its own.
	 *
	 * The `NOT EXISTS` is what keeps a translated page from appearing twice: the
	 * default-language original is admitted only while nothing in its group
	 * answers for the language being read.
	 *
	 * @param string $language_id         Requested language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return string Empty when no language was asked for.
	 */
	public function posts_fallback_condition( $language_id, $default_language_id ) {
		global $wpdb;

		if ( '' === (string) $language_id ) {
			return '';
		}

		if ( (string) $language_id === (string) $default_language_id ) {
			return $this->posts_language_condition( $language_id, $default_language_id );
		}

		/*
		 * The translated sibling is looked up on the core term tables directly,
		 * so the statement holds nothing but table names and placeholders.
		 *
		 * STRAIGHT_JOIN walks from the post's own group to its few members. Left
		 * to choose, MySQL starts from the language instead and reads every post
		 * in that language once per row, which took seconds on a large site.
		 */
		return $wpdb->prepare(
			"(shapetranslate_route_language.post_id IS NULL
				OR shapetranslate_route_language.language_id = %s
				OR (
					shapetranslate_route_language.language_id = %s
					AND NOT EXISTS (
						SELECT STRAIGHT_JOIN 1 FROM {$wpdb->terms} AS shapetranslate_ft_gt
						INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ft_gtt ON shapetranslate_ft_gtt.term_id = shapetranslate_ft_gt.term_id AND shapetranslate_ft_gtt.taxonomy = %s
						INNER JOIN {$wpdb->term_relationships} AS shapetranslate_ft_gr ON shapetranslate_ft_gr.term_taxonomy_id = shapetranslate_ft_gtt.term_taxonomy_id
						INNER JOIN {$wpdb->term_relationships} AS shapetranslate_ft_lr ON shapetranslate_ft_lr.object_id = shapetranslate_ft_gr.object_id
						INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ft_ltt ON shapetranslate_ft_ltt.term_taxonomy_id = shapetranslate_ft_lr.term_taxonomy_id AND shapetranslate_ft_ltt.taxonomy = %s
						INNER JOIN {$wpdb->terms} AS shapetranslate_ft_lt ON shapetranslate_ft_lt.term_id = shapetranslate_ft_ltt.term_id
						WHERE shapetranslate_ft_gt.slug = shapetranslate_route_language.group_id
						AND shapetranslate_ft_lt.slug = %s
					)
				))",
			(string) $language_id,
			(string) $default_language_id,
			RelationshipTaxonomies::POST_GROUP,
			RelationshipTaxonomies::POST_LANGUAGE,
			(string) $language_id
		);
	}

	/**
	 * Returns the join that carries each post's language assignment.
	 *
	 * WordPress answers some pages without WP_Query — the adjacent post links,
	 * the archive list, the calendar — and each writes its own SQL against the
	 * posts table. They are handed this fragment rather than a clause array,
	 * and the one that aliases the posts table says so.
	 *
	 * @param string $posts_alias Name the posts table is addressed by, if not its own.
	 * @return string
	 */
	public function posts_join_clause( $posts_alias = '' ) {
		global $wpdb;

		$table       = DatabaseTranslationRepository::assignments_sql();
		$alias       = self::POST_ALIAS;
		$posts_alias = is_string( $posts_alias ) && '' !== $posts_alias ? $posts_alias : $wpdb->posts;

		return " LEFT JOIN {$table} AS {$alias} ON ({$posts_alias}.ID = {$alias}.post_id)";
	}

	/**
	 * Returns the language test as a `WHERE` continuation.
	 *
	 * @param string $language_id         Requested language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return string Empty when no language was asked for.
	 */
	public function posts_where_clause( $language_id, $default_language_id ) {
		$condition = $this->posts_language_condition( $language_id, $default_language_id );

		return '' === $condition ? '' : ' AND ' . $condition;
	}

	/**
	 * Returns the language test on its own, with nothing joining it to a clause.
	 *
	 * A query that already has a `WHERE` of its own and a trailing `GROUP BY`,
	 * `ORDER BY`, or `LIMIT` cannot have a condition appended to the end of it.
	 * Such a query is given this to put directly after its own `WHERE`.
	 *
	 * @param string $language_id         Requested language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return string Empty when no language was asked for.
	 */
	public function posts_language_condition( $language_id, $default_language_id ) {
		global $wpdb;

		if ( '' === (string) $language_id ) {
			return '';
		}

		if ( (string) $default_language_id === (string) $language_id ) {
			/*
			 * Content written before ShapeTranslate carries no assignment at all,
			 * and the default language is the one it was written in.
			 */
			// The SQL alias is a fixed ShapeTranslate identifier; only the value is variable.
			return $wpdb->prepare(
				'(shapetranslate_route_language.language_id = %s OR shapetranslate_route_language.post_id IS NULL)',
				$language_id
			);
		}

		// The SQL alias is a fixed ShapeTranslate identifier; only the value is variable.
		return $wpdb->prepare(
			'shapetranslate_route_language.language_id = %s',
			$language_id
		);
	}

	/**
	 * Constrains a post query to a set of languages, keeping unassigned posts.
	 *
	 * Used where "every publicly reachable language" is the rule rather than one
	 * request language, so content assigned to a disabled language is excluded
	 * while content that predates ShapeTranslate still qualifies.
	 *
	 * @param array<string, string> $clauses      SQL clauses.
	 * @param array<int, string>    $language_ids Allowed language identifiers.
	 * @return array<string, string>
	 */
	public function restrict_posts_to_languages( array $clauses, array $language_ids ) {
		global $wpdb;

		$language_ids = array_values( array_unique( array_filter( array_map( 'strval', $language_ids ) ) ) );

		if ( ! isset( $clauses['join'], $clauses['where'] ) || empty( $language_ids ) ) {
			return $clauses;
		}

		if ( false === strpos( $clauses['join'], self::POST_ALIAS ) ) {
			$clauses['join'] .= ' LEFT JOIN ' . DatabaseTranslationRepository::assignments_sql()
				. " AS shapetranslate_route_language ON ({$wpdb->posts}.ID = shapetranslate_route_language.post_id)";
		}

		/*
		 * The placeholder list is built inside the call rather than beside it:
		 * one `%s` per identifier, so the statement carries no value of its own.
		 */
		$clauses['where'] .= $wpdb->prepare(
			' AND (shapetranslate_route_language.language_id IN ('
				. implode( ', ', array_fill( 0, count( $language_ids ), '%s' ) )
				. ') OR shapetranslate_route_language.post_id IS NULL)',
			$language_ids
		);

		return $clauses;
	}

	/**
	 * Constrains a term query to a set of languages, keeping unassigned terms.
	 *
	 * @param array<string, string> $clauses      SQL clauses.
	 * @param array<int, string>    $language_ids Allowed language identifiers.
	 * @return array<string, string>
	 */
	public function restrict_terms_to_languages( array $clauses, array $language_ids ) {
		global $wpdb;

		$language_ids = array_values( array_unique( array_filter( array_map( 'strval', $language_ids ) ) ) );

		if ( ! isset( $clauses['join'], $clauses['where'] ) || empty( $language_ids ) ) {
			return $clauses;
		}

		if ( false === strpos( $clauses['join'], self::TERM_ALIAS ) ) {
			$clauses['join'] .= ' LEFT JOIN ' . DatabaseTermTranslationRepository::assignments_sql()
				. ' AS shapetranslate_term_language ON (tt.term_taxonomy_id = shapetranslate_term_language.term_taxonomy_id)';
		}

		/** This placeholder list is built the way restrict_posts_to_languages() explains. */
		$clauses['where'] .= $wpdb->prepare(
			' AND (shapetranslate_term_language.language_id IN ('
				. implode( ', ', array_fill( 0, count( $language_ids ), '%s' ) )
				. ') OR shapetranslate_term_language.term_taxonomy_id IS NULL)',
			$language_ids
		);

		return $clauses;
	}

	/**
	 * Constrains a term query to one language.
	 *
	 * WP_Term_Query always aliases the term taxonomy table as `tt`, which carries
	 * the identifier the term assignment table is keyed by.
	 *
	 * @param array<string, string> $clauses             SQL clauses.
	 * @param string                $language_id         Requested language identifier.
	 * @param string                $default_language_id Default language identifier.
	 * @return array<string, string>
	 */
	public function apply_to_terms( array $clauses, $language_id, $default_language_id ) {
		global $wpdb;

		if ( ! isset( $clauses['join'], $clauses['where'] ) || '' === (string) $language_id ) {
			return $clauses;
		}

		$table = DatabaseTermTranslationRepository::assignments_sql();
		$alias = self::TERM_ALIAS;

		if ( false === strpos( $clauses['join'], $alias ) ) {
			// The derived table is fixed SQL generated by the repository.
			$clauses['join'] .= " LEFT JOIN {$table} AS {$alias} ON (tt.term_taxonomy_id = {$alias}.term_taxonomy_id)";
		}

		if ( (string) $default_language_id === (string) $language_id ) {
			// The SQL alias is a fixed ShapeTranslate identifier; only the value is variable.
			$clauses['where'] .= $wpdb->prepare(
				' AND (shapetranslate_term_language.language_id = %s OR shapetranslate_term_language.term_taxonomy_id IS NULL)',
				$language_id
			);
		} else {
			// The SQL alias is a fixed ShapeTranslate identifier; only the value is variable.
			$clauses['where'] .= $wpdb->prepare(
				' AND shapetranslate_term_language.language_id = %s',
				$language_id
			);
		}

		return $clauses;
	}

	/**
	 * Reports whether one language has anything to show for a set of post types.
	 *
	 * An archive is a route, not a translatable object: a language cannot be
	 * offered one because a matching row exists, only because the archive would
	 * hold something once the language filter runs. Asking that question here
	 * keeps it on the same rule the filter itself applies, including the part
	 * where unassigned content answers for the default language.
	 *
	 * Existence is all the caller needs, so the query stops at the first row
	 * rather than counting every one of them.
	 *
	 * @param array<int, string> $post_types          Post types the archive lists.
	 * @param string             $language_id         Language being offered.
	 * @param string             $default_language_id Default language identifier.
	 * @return bool
	 */
	public function has_posts_in_language( array $post_types, $language_id, $default_language_id ) {
		global $wpdb;

		$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );

		if ( empty( $post_types ) || '' === (string) $language_id ) {
			return false;
		}

		/*
		 * Every value arrives as its own placeholder, so the two forms of the
		 * language test are written out in full rather than assembled: a
		 * statement built from fragments cannot be read as the statement it
		 * becomes.
		 *
		 * A post holds at most one assignment, so "in the default language or
		 * unassigned" is the same as "not assigned to any other language".
		 */
		$values = array_merge( $post_types, array( RelationshipTaxonomies::POST_LANGUAGE, RelationshipTaxonomies::POST_GROUP, (string) $language_id ) );

		if ( (string) $language_id === (string) $default_language_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return null !== $wpdb->get_var(
				$wpdb->prepare(
					"SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}
					WHERE {$wpdb->posts}.post_status = 'publish'
					AND {$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ")
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->term_relationships} AS shapetranslate_lr
						INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ltt ON shapetranslate_ltt.term_taxonomy_id = shapetranslate_lr.term_taxonomy_id AND shapetranslate_ltt.taxonomy = %s
						INNER JOIN {$wpdb->terms} AS shapetranslate_lt ON shapetranslate_lt.term_id = shapetranslate_ltt.term_id
						INNER JOIN {$wpdb->term_relationships} AS shapetranslate_gr ON shapetranslate_gr.object_id = shapetranslate_lr.object_id
						INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_gtt ON shapetranslate_gtt.term_taxonomy_id = shapetranslate_gr.term_taxonomy_id AND shapetranslate_gtt.taxonomy = %s
						WHERE shapetranslate_lr.object_id = {$wpdb->posts}.ID
						AND shapetranslate_lt.slug <> %s
					)
					LIMIT 1",
					$values
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}
				WHERE {$wpdb->posts}.post_status = 'publish'
				AND {$wpdb->posts}.post_type IN (" . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ")
				AND EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} AS shapetranslate_lr
					INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ltt ON shapetranslate_ltt.term_taxonomy_id = shapetranslate_lr.term_taxonomy_id AND shapetranslate_ltt.taxonomy = %s
					INNER JOIN {$wpdb->terms} AS shapetranslate_lt ON shapetranslate_lt.term_id = shapetranslate_ltt.term_id
					INNER JOIN {$wpdb->term_relationships} AS shapetranslate_gr ON shapetranslate_gr.object_id = shapetranslate_lr.object_id
					INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_gtt ON shapetranslate_gtt.term_taxonomy_id = shapetranslate_gr.term_taxonomy_id AND shapetranslate_gtt.taxonomy = %s
					WHERE shapetranslate_lr.object_id = {$wpdb->posts}.ID
					AND shapetranslate_lt.slug = %s
				)
				LIMIT 1",
				$values
			)
		);
	}

	/**
	 * Reports whether the site publishes anything at all in one language.
	 *
	 * This asks a wider question than has_posts_in_language(): not "is there
	 * something here", but "does this site speak this language". A reader on an
	 * untranslated cart page is still served by a switcher that offers a language
	 * the site genuinely publishes in, while a language nothing has been written
	 * in yet has nothing to offer and can leave.
	 *
	 * @param string $language_id         Target language identifier.
	 * @param string $default_language_id Default language identifier.
	 * @return bool
	 */
	public function has_any_post_in_language( $language_id, $default_language_id ) {
		global $wpdb;

		if ( '' === (string) $language_id ) {
			return false;
		}

		if ( (string) $language_id === (string) $default_language_id ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT shapetranslate_lr.object_id FROM {$wpdb->term_relationships} AS shapetranslate_lr
				INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_ltt ON shapetranslate_ltt.term_taxonomy_id = shapetranslate_lr.term_taxonomy_id AND shapetranslate_ltt.taxonomy = %s
				INNER JOIN {$wpdb->terms} AS shapetranslate_lt ON shapetranslate_lt.term_id = shapetranslate_ltt.term_id
				INNER JOIN {$wpdb->term_relationships} AS shapetranslate_gr ON shapetranslate_gr.object_id = shapetranslate_lr.object_id
				INNER JOIN {$wpdb->term_taxonomy} AS shapetranslate_gtt ON shapetranslate_gtt.term_taxonomy_id = shapetranslate_gr.term_taxonomy_id AND shapetranslate_gtt.taxonomy = %s
				INNER JOIN {$wpdb->posts} AS post ON (post.ID = shapetranslate_lr.object_id)
				WHERE shapetranslate_lt.slug = %s
				AND post.post_status = 'publish'
				LIMIT 1",
				RelationshipTaxonomies::POST_LANGUAGE,
				RelationshipTaxonomies::POST_GROUP,
				(string) $language_id
			)
		);
	}
}
