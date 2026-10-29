<?php
/**
 * Database-backed registered string repository.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Infrastructure;

use ShapeTranslate\Contracts\StringRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Stores searchable string definitions and language-specific values.
 */
final class DatabaseStringRepository implements StringRepositoryInterface {
	// Direct queries are isolated here because this repository owns custom tables.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Registered definition table suffix.
	 *
	 * @var string
	 */
	const STRINGS_TABLE_SUFFIX = 'shapetranslate_strings';

	/**
	 * String translation table suffix.
	 *
	 * @var string
	 */
	const TRANSLATIONS_TABLE_SUFFIX = 'shapetranslate_string_translations';

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'shapetranslate_strings';

	/**
	 * Creates or upgrades registered-string tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$strings_table      = self::strings_table();
		$translations_table = self::translations_table();
		$charset_collate    = $wpdb->get_charset_collate();

		$strings_sql = "CREATE TABLE {$strings_table} (
			string_id char(64) NOT NULL,
			string_group varchar(100) NOT NULL,
			string_key varchar(191) NOT NULL,
			original_string longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (string_id),
			UNIQUE KEY group_key (string_group, string_key),
			KEY string_group (string_group)
		) {$charset_collate};";

		$translations_sql = "CREATE TABLE {$translations_table} (
			string_id char(64) NOT NULL,
			language_id varchar(64) NOT NULL,
			translation longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (string_id, language_id),
			KEY language_id (language_id)
		) {$charset_collate};";

		// Table names and collation are generated exclusively by WordPress.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		dbDelta( $strings_sql );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		dbDelta( $translations_sql );
	}

	/**
	 * Returns the site-specific registered-string table name.
	 *
	 * @return string
	 */
	public static function strings_table() {
		global $wpdb;

		return $wpdb->prefix . self::STRINGS_TABLE_SUFFIX;
	}

	/**
	 * Returns the site-specific string-translation table name.
	 *
	 * @return string
	 */
	public static function translations_table() {
		global $wpdb;

		return $wpdb->prefix . self::TRANSLATIONS_TABLE_SUFFIX;
	}

	/**
	 * Persists missing or changed string definitions.
	 *
	 * @param array<string, array<string, string>> $definitions Definitions keyed by deterministic ID.
	 * @return array<int, array<string, mixed>> Changed definitions with a change value.
	 */
	public function save_definitions( array $definitions ) {
		global $wpdb;

		if ( empty( $definitions ) ) {
			return array();
		}

		$existing = $this->get_definitions( array_keys( $definitions ) );
		$changed  = array();
		$now      = current_time( 'mysql', true );

		foreach ( $definitions as $string_id => $definition ) {
			if ( ! $this->is_valid_id( $string_id ) || ! $this->is_valid_definition( $definition ) ) {
				continue;
			}

			$current = isset( $existing[ $string_id ] ) ? $existing[ $string_id ] : null;

			if (
				is_array( $current )
				&& (
					$current['string_group'] !== $definition['string_group']
					|| $current['string_key'] !== $definition['string_key']
				)
			) {
				continue;
			}

			if ( is_array( $current ) && $current['original_string'] === $definition['original_string'] ) {
				$this->cache_definition( $current );
				continue;
			}

			if ( is_array( $current ) ) {
				$saved  = false !== $wpdb->update(
					self::strings_table(),
					array(
						'original_string' => $definition['original_string'],
						'updated_at'      => $now,
					),
					array( 'string_id' => $string_id ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				$record = array_merge(
					$current,
					array(
						'original_string' => $definition['original_string'],
						'updated_at'      => $now,
						'change'          => 'updated',
					)
				);
			} else {
				$saved  = false !== $wpdb->insert(
					self::strings_table(),
					array(
						'string_id'       => $string_id,
						'string_group'    => $definition['string_group'],
						'string_key'      => $definition['string_key'],
						'original_string' => $definition['original_string'],
						'created_at'      => $now,
						'updated_at'      => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				$record = array(
					'string_id'       => $string_id,
					'string_group'    => $definition['string_group'],
					'string_key'      => $definition['string_key'],
					'original_string' => $definition['original_string'],
					'created_at'      => $now,
					'updated_at'      => $now,
					'change'          => 'created',
				);
			}

			if ( $saved ) {
				$this->cache_definition( $record );
				$changed[] = $record;
			}
		}

		if ( ! empty( $changed ) ) {
			wp_cache_delete( 'groups', self::CACHE_GROUP );
		}

		return $changed;
	}

	/**
	 * Finds definitions by ID and primes definition caches.
	 *
	 * @param array<int, string> $string_ids Deterministic string IDs.
	 * @return array<string, array<string, mixed>> Definitions keyed by string ID.
	 */
	public function get_definitions( array $string_ids ) {
		global $wpdb;

		$string_ids = $this->normalize_ids( $string_ids );

		if ( empty( $string_ids ) ) {
			return array();
		}

		$definitions = array();
		$unresolved  = array();

		foreach ( $string_ids as $string_id ) {
			$cached = wp_cache_get( $this->definition_cache_key( $string_id ), self::CACHE_GROUP, false, $found );

			if ( $found && is_array( $cached ) && isset( $cached['found'] ) ) {
				if ( ! empty( $cached['found'] ) && isset( $cached['record'] ) && is_array( $cached['record'] ) ) {
					$definitions[ $string_id ] = $cached['record'];
				}
				continue;
			}

			$unresolved[] = $string_id;
		}

		foreach ( array_chunk( $unresolved, 100 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = "SELECT string_id, string_group, string_key, original_string, created_at, updated_at
				FROM %i WHERE string_id IN ({$placeholders})";
			$values       = array_merge( array( self::strings_table() ), $chunk );

			// The placeholder list is generated from validated IDs.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

			foreach ( $rows as $row ) {
				$definitions[ $row['string_id'] ] = $row;
				$this->cache_definition( $row );
			}

			foreach ( $chunk as $string_id ) {
				if ( ! isset( $definitions[ $string_id ] ) ) {
					wp_cache_set(
						$this->definition_cache_key( $string_id ),
						array( 'found' => false ),
						self::CACHE_GROUP
					);
				}
			}
		}

		return $definitions;
	}

	/**
	 * Finds one registered string definition.
	 *
	 * @param string $string_id Deterministic string ID.
	 * @return array<string, mixed>|null
	 */
	public function find_definition( $string_id ) {
		$definitions = $this->get_definitions( array( $string_id ) );

		return isset( $definitions[ $string_id ] ) ? $definitions[ $string_id ] : null;
	}

	/**
	 * Queries definitions for the admin string translation table.
	 *
	 * @param array<string, mixed> $args Validated reporting arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query_definitions( array $args ) {
		global $wpdb;

		$where  = array( '1 = 1' );
		$values = array( self::strings_table() );

		if ( '' !== $args['group'] ) {
			$where[]  = 'string_group = %s';
			$values[] = $args['group'];
		}

		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(original_string LIKE %s OR string_group LIKE %s OR string_key LIKE %s)';
			$values  = array_merge( $values, array( $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}";

		// SQL fragments contain fixed clauses and generated placeholders only.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );

		if ( 0 === $total ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$order_columns = array(
			'group'    => 'string_group',
			'original' => 'original_string',
			'updated'  => 'updated_at',
		);
		$orderby       = isset( $order_columns[ $args['orderby'] ] )
			? $order_columns[ $args['orderby'] ]
			: $order_columns['group'];
		$order         = 'DESC' === $args['order'] ? 'DESC' : 'ASC';
		$offset        = ( $args['page'] - 1 ) * $args['per_page'];
		$items_sql     = "SELECT string_id, string_group, string_key, original_string, created_at, updated_at
			FROM %i WHERE {$where_sql}
			ORDER BY {$orderby} {$order}, string_id {$order} LIMIT %d OFFSET %d";
		$item_values   = array_merge( $values, array( $args['per_page'], $offset ) );

		// ORDER BY is selected from the fixed map above; all values are prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, $item_values ), ARRAY_A );

		foreach ( $items as $item ) {
			$this->cache_definition( $item );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Returns registered group names in display order.
	 *
	 * @return array<int, string>
	 */
	public function get_groups() {
		global $wpdb;

		$cached = wp_cache_get( 'groups', self::CACHE_GROUP, false, $found );

		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		$sql = 'SELECT DISTINCT string_group FROM %i ORDER BY string_group ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$groups = $wpdb->get_col( $wpdb->prepare( $sql, self::strings_table() ) );
		$groups = array_values( array_filter( array_map( 'strval', $groups ) ) );

		wp_cache_set( 'groups', $groups, self::CACHE_GROUP );

		return $groups;
	}

	/**
	 * Bulk-loads translations for string and language combinations.
	 *
	 * @param array<int, string> $string_ids  Deterministic string IDs.
	 * @param array<int, string> $language_ids Stable language IDs.
	 * @return array<string, array<string, string>> Values keyed by string and language ID.
	 */
	public function get_translations( array $string_ids, array $language_ids ) {
		global $wpdb;

		$string_ids   = $this->normalize_ids( $string_ids );
		$language_ids = $this->normalize_language_ids( $language_ids );

		if ( empty( $string_ids ) || empty( $language_ids ) ) {
			return array();
		}

		$translations = array();
		$unresolved   = array();

		foreach ( $string_ids as $string_id ) {
			foreach ( $language_ids as $language_id ) {
				$cache_key = $this->translation_cache_key( $string_id, $language_id );
				$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

				if ( $found && is_array( $cached ) && array_key_exists( 'value', $cached ) ) {
					if ( null !== $cached['value'] ) {
						$translations[ $string_id ][ $language_id ] = (string) $cached['value'];
					}
					continue;
				}

				$unresolved[ $string_id ][ $language_id ] = true;
			}
		}

		if ( ! empty( $unresolved ) ) {
			$unresolved_string_ids   = array_keys( $unresolved );
			$unresolved_language_ids = array();

			foreach ( $unresolved as $languages ) {
				$unresolved_language_ids = array_merge( $unresolved_language_ids, array_keys( $languages ) );
			}

			$unresolved_language_ids = array_values( array_unique( $unresolved_language_ids ) );
			$string_placeholders     = implode( ', ', array_fill( 0, count( $unresolved_string_ids ), '%s' ) );
			$language_placeholders   = implode( ', ', array_fill( 0, count( $unresolved_language_ids ), '%s' ) );
			$sql                     = "SELECT string_id, language_id, translation FROM %i
				WHERE string_id IN ({$string_placeholders})
				AND language_id IN ({$language_placeholders})";
			$values                  = array_merge(
				array( self::translations_table() ),
				$unresolved_string_ids,
				$unresolved_language_ids
			);

			// Placeholder lists are generated from validated IDs.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

			foreach ( $rows as $row ) {
				$translations[ $row['string_id'] ][ $row['language_id'] ] = $row['translation'];
				unset( $unresolved[ $row['string_id'] ][ $row['language_id'] ] );
				wp_cache_set(
					$this->translation_cache_key( $row['string_id'], $row['language_id'] ),
					array( 'value' => $row['translation'] ),
					self::CACHE_GROUP
				);
			}

			foreach ( $unresolved as $string_id => $languages ) {
				foreach ( array_keys( $languages ) as $language_id ) {
					wp_cache_set(
						$this->translation_cache_key( $string_id, $language_id ),
						array( 'value' => null ),
						self::CACHE_GROUP
					);
				}
			}
		}

		return $translations;
	}

	/**
	 * Finds one translated value.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @return string|null
	 */
	public function find_translation( $string_id, $language_id ) {
		$translations = $this->get_translations( array( $string_id ), array( $language_id ) );

		return isset( $translations[ $string_id ][ $language_id ] )
			? $translations[ $string_id ][ $language_id ]
			: null;
	}

	/**
	 * Creates or updates one translated value.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @param string $translation Sanitized plain-text translation.
	 * @return bool
	 */
	public function save_translation( $string_id, $language_id, $translation ) {
		global $wpdb;

		$language_ids = $this->normalize_language_ids( array( $language_id ) );

		if ( ! $this->is_valid_id( $string_id ) || empty( $language_ids ) || ! is_string( $translation ) || '' === $translation ) {
			return false;
		}

		$language_id = $language_ids[0];

		$saved = false !== $wpdb->replace(
			self::translations_table(),
			array(
				'string_id'   => $string_id,
				'language_id' => $language_id,
				'translation' => $translation,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( $saved ) {
			wp_cache_set(
				$this->translation_cache_key( $string_id, $language_id ),
				array( 'value' => $translation ),
				self::CACHE_GROUP
			);
		}

		return $saved;
	}

	/**
	 * Deletes one translated value.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @return bool
	 */
	public function delete_translation( $string_id, $language_id ) {
		global $wpdb;

		$language_ids = $this->normalize_language_ids( array( $language_id ) );

		if ( ! $this->is_valid_id( $string_id ) || empty( $language_ids ) ) {
			return false;
		}

		$language_id = $language_ids[0];

		$deleted = false !== $wpdb->delete(
			self::translations_table(),
			array(
				'string_id'   => $string_id,
				'language_id' => $language_id,
			),
			array( '%s', '%s' )
		);

		if ( $deleted ) {
			wp_cache_set(
				$this->translation_cache_key( $string_id, $language_id ),
				array( 'value' => null ),
				self::CACHE_GROUP
			);
		}

		return $deleted;
	}

	/**
	 * Deletes every translated value for a removed language.
	 *
	 * @param string $language_id Stable language ID.
	 * @return int Number of deleted rows.
	 */
	public function delete_language_translations( $language_id ) {
		global $wpdb;

		$language_ids = $this->normalize_language_ids( array( $language_id ) );

		if ( empty( $language_ids ) ) {
			return 0;
		}

		$language_id = $language_ids[0];

		$sql = 'SELECT string_id FROM %i WHERE language_id = %s';
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is fixed and all values are prepared below.
		$string_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( $sql, self::translations_table(), $language_id )
		);
		$deleted = $wpdb->delete(
			self::translations_table(),
			array( 'language_id' => $language_id ),
			array( '%s' )
		);

		foreach ( $string_ids as $string_id ) {
			wp_cache_set(
				$this->translation_cache_key( $string_id, $language_id ),
				array( 'value' => null ),
				self::CACHE_GROUP
			);
		}

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Checks a deterministic string ID.
	 *
	 * @param mixed $string_id Candidate string ID.
	 * @return bool
	 */
	private function is_valid_id( $string_id ) {
		return is_string( $string_id ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $string_id );
	}

	/**
	 * Checks the minimum definition shape expected from the manager.
	 *
	 * @param mixed $definition Candidate definition.
	 * @return bool
	 */
	private function is_valid_definition( $definition ) {
		return is_array( $definition )
			&& isset( $definition['string_group'], $definition['string_key'], $definition['original_string'] )
			&& is_string( $definition['string_group'] )
			&& is_string( $definition['string_key'] )
			&& is_string( $definition['original_string'] );
	}

	/**
	 * Normalizes a list of deterministic string IDs.
	 *
	 * @param array<int, mixed> $string_ids Candidate IDs.
	 * @return array<int, string>
	 */
	private function normalize_ids( $string_ids ) {
		return array_values(
			array_unique(
				array_filter( $string_ids, array( $this, 'is_valid_id' ) )
			)
		);
	}

	/**
	 * Normalizes stable language IDs for storage and cache keys.
	 *
	 * @param array<int, mixed> $language_ids Candidate language IDs.
	 * @return array<int, string>
	 */
	private function normalize_language_ids( $language_ids ) {
		$normalized = array();

		foreach ( $language_ids as $language_id ) {
			if ( ! is_scalar( $language_id ) ) {
				continue;
			}

			$language_id = sanitize_text_field( (string) $language_id );

			if ( '' !== $language_id && 64 >= strlen( $language_id ) ) {
				$normalized[ $language_id ] = $language_id;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Stores a definition cache value.
	 *
	 * @param array<string, mixed> $record Definition record.
	 * @return void
	 */
	private function cache_definition( $record ) {
		unset( $record['change'] );

		wp_cache_set(
			$this->definition_cache_key( $record['string_id'] ),
			array(
				'found'  => true,
				'record' => $record,
			),
			self::CACHE_GROUP
		);
	}

	/**
	 * Builds a definition cache key.
	 *
	 * @param string $string_id Deterministic string ID.
	 * @return string
	 */
	private function definition_cache_key( $string_id ) {
		return 'definition_' . $string_id;
	}

	/**
	 * Builds a translation cache key.
	 *
	 * @param string $string_id   Deterministic string ID.
	 * @param string $language_id Stable language ID.
	 * @return string
	 */
	private function translation_cache_key( $string_id, $language_id ) {
		return 'translation_' . $string_id . '_' . md5( $language_id );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
