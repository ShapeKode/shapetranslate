<?php
/**
 * Theme and plugin text kept in the string tables.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use ShapeTranslate\Contracts\StringRepositoryInterface;
use ShapeTranslate\Infrastructure\DatabaseStringRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads the text themes and plugins pass through gettext.
 *
 * A scanned string is an ordinary registered string: its group names the text
 * domain and its key is derived from the text and its context, so the editor,
 * the import and the per-language storage need nothing new. What is new is the
 * read side. gettext runs thousands of times a page, so one language's
 * translations are loaded in a single query into a map, and only the text
 * domains that have a translation at all are ever hooked.
 */
final class GettextStrings {

	/**
	 * Prefix of every string group holding gettext text.
	 *
	 * @var string
	 */
	const GROUP_PREFIX = 'gettext/';

	/**
	 * Autoloaded option listing the text domains that have a translation.
	 *
	 * @var string
	 */
	const DOMAINS_OPTION = 'shapetranslate_gettext_domains';

	/**
	 * Object cache group of the per-language maps.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'shapetranslate_gettext';

	/**
	 * Most characters of a context kept readable in a key.
	 *
	 * At four bytes a character this still leaves the key under the 191 bytes
	 * its column holds, with the hash after it.
	 *
	 * @var int
	 */
	const MAX_KEY_CONTEXT = 38;

	/**
	 * Definitions saved per batch while storing a scan.
	 *
	 * @var int
	 */
	const SAVE_BATCH = 200;

	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param StringRepositoryInterface $repository String repository.
	 */
	public function __construct( StringRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Returns the string group of a text domain.
	 *
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function group( $domain ) {
		return self::GROUP_PREFIX . $domain;
	}

	/**
	 * Reports whether a string group holds gettext text.
	 *
	 * @param mixed $group String group.
	 * @return bool
	 */
	public static function is_group( $group ) {
		return is_string( $group ) && 0 === strpos( $group, self::GROUP_PREFIX );
	}

	/**
	 * Returns the key a text and its context are stored under.
	 *
	 * The hash is what identifies the string; the context in front of it is
	 * only there so an editor can tell "Post" the noun from "Post" the verb.
	 *
	 * @param string $text    Original text.
	 * @param string $context Gettext context, empty for none.
	 * @return string
	 */
	public static function key( $text, $context = '' ) {
		$hash = md5( $context . "\x04" . $text );

		if ( '' === $context ) {
			return $hash;
		}

		$label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $context ) ) );
		// WordPress supplies mb_substr() where mbstring is missing; mb_strcut() it does not.
		$label = mb_substr( $label, 0, self::MAX_KEY_CONTEXT, 'UTF-8' );

		return '' === $label ? $hash : $label . ' | ' . $hash;
	}

	/**
	 * Stores scanned strings, keeping every translation already made.
	 *
	 * @param array<int, array{domain: string, context: string, text: string}> $entries Scanned strings.
	 * @return int Number of strings added or changed.
	 */
	public function save_scanned( array $entries ) {
		$definitions = array();

		foreach ( $entries as $entry ) {
			$group = self::group( $entry['domain'] );
			$key   = self::key( $entry['text'], $entry['context'] );
			$id    = StringManager::generate_string_id( $group, $key );

			$definitions[ $id ] = array(
				'string_group'    => $group,
				'string_key'      => $key,
				'original_string' => $entry['text'],
			);
		}

		$changed = 0;

		foreach ( array_chunk( $definitions, self::SAVE_BATCH, true ) as $batch ) {
			$changed += count( $this->repository->save_definitions( $batch ) );
		}

		return $changed;
	}

	/**
	 * Returns one language's translations, keyed by text domain and string key.
	 *
	 * @param string $language_id Stable language ID.
	 * @return array<string, array<string, string>>
	 */
	public function get_map( $language_id ) {
		global $wpdb;

		$cache_key = 'map:' . md5( (string) $language_id ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.string_group, s.string_key, t.translation
				FROM %i AS t
				INNER JOIN %i AS s ON s.string_id = t.string_id
				WHERE t.language_id = %s AND s.string_group LIKE %s',
				DatabaseStringRepository::translations_table(),
				DatabaseStringRepository::strings_table(),
				(string) $language_id,
				$wpdb->esc_like( self::GROUP_PREFIX ) . '%'
			),
			ARRAY_A
		);
		$map  = array();

		foreach ( (array) $rows as $row ) {
			$domain = substr( (string) $row['string_group'], strlen( self::GROUP_PREFIX ) );

			$map[ $domain ][ (string) $row['string_key'] ] = (string) $row['translation'];
		}

		wp_cache_set( $cache_key, $map, self::CACHE_GROUP );

		return $map;
	}

	/**
	 * Returns the text domains that have at least one translation.
	 *
	 * @return array<int, string>
	 */
	public static function get_domains() {
		$domains = get_option( self::DOMAINS_OPTION, array() );

		return is_array( $domains ) ? array_values( array_filter( $domains, 'is_string' ) ) : array();
	}

	/**
	 * Recounts the translated text domains and drops the cached maps.
	 *
	 * @return void
	 */
	public function refresh() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groups = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT s.string_group
				FROM %i AS s
				INNER JOIN %i AS t ON t.string_id = s.string_id
				WHERE s.string_group LIKE %s',
				DatabaseStringRepository::strings_table(),
				DatabaseStringRepository::translations_table(),
				$wpdb->esc_like( self::GROUP_PREFIX ) . '%'
			)
		);
		$domains = array();

		foreach ( (array) $groups as $group ) {
			$domains[] = substr( (string) $group, strlen( self::GROUP_PREFIX ) );
		}

		update_option( self::DOMAINS_OPTION, array_values( array_unique( $domains ) ), true );
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}
}
