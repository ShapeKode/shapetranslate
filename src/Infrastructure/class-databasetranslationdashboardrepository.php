<?php
/**
 * Database-backed translation dashboard reporting.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Infrastructure;

use ShapeTranslate\Contracts\TranslationDashboardRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Runs bounded, indexed reporting queries against posts and translation groups.
 */
final class DatabaseTranslationDashboardRepository implements TranslationDashboardRepositoryInterface {
	// Reporting queries are isolated here because this repository owns their SQL.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Queries source post IDs for the translation dashboard.
	 *
	 * @param array<string, mixed> $args Validated dashboard query arguments.
	 * @return array{items: array<int, int>, total: int}
	 */
	public function query_dashboard( array $args ) {
		global $wpdb;

		$query_parts = $this->build_query_parts( $args );
		$from        = 'FROM %i AS source_post
			LEFT JOIN ' . DatabaseTranslationRepository::assignments_sql() . ' AS source_assignment ON source_assignment.post_id = source_post.ID
			LEFT JOIN ' . DatabaseTranslationRepository::groups_sql() . ' AS translation_group ON translation_group.group_id = source_assignment.group_id';
		$values      = array( $wpdb->posts );
		$values      = array_merge( $values, $query_parts['values'] );
		$where       = implode( ' AND ', $query_parts['where'] );
		$count_sql   = "SELECT COUNT(*) {$from} WHERE {$where}";

		// SQL fragments contain only fixed clauses and generated placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );

		if ( 0 === $total ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$order_columns = array(
			'modified'  => 'source_post.post_modified',
			'post_type' => 'source_post.post_type',
			'title'     => 'source_post.post_title',
		);
		$orderby       = isset( $order_columns[ $args['orderby'] ] )
			? $order_columns[ $args['orderby'] ]
			: $order_columns['title'];
		$order         = 'DESC' === $args['order'] ? 'DESC' : 'ASC';
		$offset        = ( $args['page'] - 1 ) * $args['per_page'];
		$items_sql     = "SELECT source_post.ID {$from} WHERE {$where}
			ORDER BY {$orderby} {$order}, source_post.ID {$order} LIMIT %d OFFSET %d";
		$item_values   = array_merge( $values, array( $args['per_page'], $offset ) );

		// ORDER BY is selected from the fixed map above; all values are prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$items = $wpdb->get_col( $wpdb->prepare( $items_sql, $item_values ) );

		return array(
			'items' => array_values( array_filter( array_map( 'absint', $items ) ) ),
			'total' => $total,
		);
	}

	/**
	 * Builds the WHERE clauses and prepared values for one dashboard query.
	 *
	 * @param array<string, mixed> $args Validated query arguments.
	 * @return array{where: array<int, string>, values: array<int, mixed>}
	 */
	private function build_query_parts( $args ) {
		$post_type_placeholders = implode( ', ', array_fill( 0, count( $args['post_types'] ), '%s' ) );
		$status_placeholders    = implode( ', ', array_fill( 0, count( $args['source_statuses'] ), '%s' ) );
		$where                  = array(
			"source_post.post_type IN ({$post_type_placeholders})",
			"source_post.post_status IN ({$status_placeholders})",
			'(source_assignment.post_id IS NULL OR translation_group.source_post_id = source_post.ID)',
		);
		$values                 = array_merge( $args['post_types'], $args['source_statuses'] );

		if ( '' !== $args['post_type'] ) {
			$where[]  = 'source_post.post_type = %s';
			$values[] = $args['post_type'];
		}

		if ( ! empty( $args['own_post_types'] ) ) {
			$own_type_placeholders = implode( ', ', array_fill( 0, count( $args['own_post_types'] ), '%s' ) );
			$where[]               = "(source_post.post_type NOT IN ({$own_type_placeholders}) OR source_post.post_author = %d)";
			$values                = array_merge( $values, $args['own_post_types'], array( $args['current_user_id'] ) );
		}

		if ( '' !== $args['search'] ) {
			$search_clause = $this->build_search_clause( $args['search'] );
			$where[]       = $search_clause['sql'];
			$values        = array_merge( $values, $search_clause['values'] );
		}

		if ( 'all' !== $args['status'] || '' !== $args['language_id'] ) {
			$status_clause = '' === $args['language_id']
				? $this->build_group_status_clause( $args )
				: $this->build_language_status_clause( $args );
			$where[]       = $status_clause['sql'];
			$values        = array_merge( $values, $status_clause['values'] );
		}

		return array(
			'where'  => $where,
			'values' => $values,
		);
	}

	/**
	 * Builds a title search across the source and every translation member.
	 *
	 * @param string $search Search term.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private function build_search_clause( $search ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		return array(
			'sql'    => '(source_post.post_title LIKE %s OR (
				source_assignment.group_id IS NOT NULL AND EXISTS (
					SELECT 1 FROM ' . DatabaseTranslationRepository::assignments_sql() . ' AS search_assignment
					INNER JOIN %i AS search_post ON search_post.ID = search_assignment.post_id
					WHERE search_assignment.group_id = source_assignment.group_id
					AND search_post.post_title LIKE %s
				)
			))',
			'values' => array(
				$like,
				$wpdb->posts,
				$like,
			),
		);
	}

	/**
	 * Builds a status condition across all enabled languages.
	 *
	 * @param array<string, mixed> $args Validated query arguments.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private function build_group_status_clause( $args ) {
		global $wpdb;

		$language_placeholders = implode( ', ', array_fill( 0, count( $args['language_ids'] ), '%s' ) );
		$language_count        = count( $args['language_ids'] );
		$assignments           = DatabaseTranslationRepository::assignments_sql();
		$default_is_enabled    = in_array( $args['default_language_id'], $args['language_ids'], true );

		if ( 'missing' === $args['status'] ) {
			$count_sql          = "SELECT COUNT(DISTINCT status_assignment.language_id)
				FROM {$assignments} AS status_assignment
				INNER JOIN %i AS status_post ON status_post.ID = status_assignment.post_id
				WHERE status_assignment.group_id = source_assignment.group_id
				AND status_assignment.language_id IN ({$language_placeholders})";
			$unassigned_missing = $default_is_enabled && 1 === $language_count ? '1 = 0' : '1 = 1';

			return array(
				'sql'    => "((source_assignment.post_id IS NULL AND {$unassigned_missing}) OR
					(source_assignment.post_id IS NOT NULL AND ({$count_sql}) < %d))",
				'values' => array_merge(
					array( $wpdb->posts ),
					$args['language_ids'],
					array( $language_count )
				),
			);
		}

		$status_key        = 'completed' === $args['status'] ? 'complete_statuses' : 'draft_statuses';
		$post_statuses     = $args[ $status_key ];
		$post_placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );

		if ( 'completed' === $args['status'] ) {
			$count_sql           = "SELECT COUNT(DISTINCT status_assignment.language_id)
				FROM {$assignments} AS status_assignment
				INNER JOIN %i AS status_post ON status_post.ID = status_assignment.post_id
				WHERE status_assignment.group_id = source_assignment.group_id
				AND status_assignment.language_id IN ({$language_placeholders})
				AND status_post.post_status IN ({$post_placeholders})";
			$unassigned_complete = $default_is_enabled && 1 === $language_count
				? "source_post.post_status IN ({$post_placeholders})"
				: '1 = 0';
			$values              = array();

			if ( $default_is_enabled && 1 === $language_count ) {
				$values = array_merge( $values, $post_statuses );
			}

			$values = array_merge(
				$values,
				array( $wpdb->posts ),
				$args['language_ids'],
				$post_statuses,
				array( $language_count )
			);

			return array(
				'sql'    => "((source_assignment.post_id IS NULL AND {$unassigned_complete}) OR
					(source_assignment.post_id IS NOT NULL AND ({$count_sql}) = %d))",
				'values' => $values,
			);
		}

		$exists_sql       = "EXISTS (
			SELECT 1 FROM {$assignments} AS status_assignment
			INNER JOIN %i AS status_post ON status_post.ID = status_assignment.post_id
			WHERE status_assignment.group_id = source_assignment.group_id
			AND status_assignment.language_id IN ({$language_placeholders})
			AND status_post.post_status IN ({$post_placeholders})
		)";
		$unassigned_draft = $default_is_enabled
			? "source_post.post_status IN ({$post_placeholders})"
			: '1 = 0';
		$values           = array();

		if ( $default_is_enabled ) {
			$values = array_merge( $values, $post_statuses );
		}

		$values = array_merge(
			$values,
			array( $wpdb->posts ),
			$args['language_ids'],
			$post_statuses
		);

		return array(
			'sql'    => "((source_assignment.post_id IS NULL AND {$unassigned_draft}) OR
				(source_assignment.post_id IS NOT NULL AND {$exists_sql}))",
			'values' => $values,
		);
	}

	/**
	 * Builds a status condition for one selected language.
	 *
	 * @param array<string, mixed> $args Validated query arguments.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private function build_language_status_clause( $args ) {
		global $wpdb;

		$post_statuses = array();

		if ( 'completed' === $args['status'] ) {
			$post_statuses = $args['complete_statuses'];
		} elseif ( 'draft' === $args['status'] ) {
			$post_statuses = $args['draft_statuses'];
		}

		$status_sql    = '';
		$status_values = array();

		if ( ! empty( $post_statuses ) ) {
			$placeholders  = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
			$status_sql    = " AND language_post.post_status IN ({$placeholders})";
			$status_values = $post_statuses;
		}

		$assignments = DatabaseTranslationRepository::assignments_sql();
		$exists_sql  = "EXISTS (
			SELECT 1 FROM {$assignments} AS language_assignment
			INNER JOIN %i AS language_post ON language_post.ID = language_assignment.post_id
			WHERE language_assignment.group_id = source_assignment.group_id
			AND language_assignment.language_id = %s{$status_sql}
		)";
		$is_default = $args['language_id'] === $args['default_language_id'];
		$unassigned = $is_default ? 'source_assignment.post_id IS NULL' : '1 = 0';
		$values     = array();

		if ( $is_default && ! empty( $post_statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
			$unassigned  .= " AND source_post.post_status IN ({$placeholders})";
			$values       = array_merge( $values, $post_statuses );
		}

		$values        = array_merge(
			$values,
			array(
				$wpdb->posts,
				$args['language_id'],
			),
			$status_values
		);
		$available_sql = "(({$unassigned}) OR
			(source_assignment.post_id IS NOT NULL AND {$exists_sql}))";

		return array(
			'sql'    => 'missing' === $args['status'] ? "NOT {$available_sql}" : $available_sql,
			'values' => $values,
		);
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
