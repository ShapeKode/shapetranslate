<?php
/**
 * String translation admin query service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Contracts\StringRepositoryInterface;
use ShapeTranslate\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Validates string table filters and bulk-loads the selected languages' values.
 */
final class StringTranslationQuery {

	/**
	 * Language filter value that selects every enabled language at once.
	 *
	 * Translating one language at a time keeps the table narrow, but a site with
	 * a handful of languages usually wants to fill a string in all of them while
	 * its meaning is fresh. Both views load their values in one query.
	 *
	 * @var string
	 */
	const ALL_LANGUAGES = 'all';

	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface
	 */
	private $repository;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Constructor.
	 *
	 * @param StringRepositoryInterface $repository       String repository.
	 * @param LanguageManager           $language_manager Language manager.
	 */
	public function __construct( StringRepositoryInterface $repository, LanguageManager $language_manager ) {
		$this->repository       = $repository;
		$this->language_manager = $language_manager;
	}

	/**
	 * Returns enabled languages available to string editors.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_languages() {
		$languages = $this->language_manager->get_languages( true );

		/**
		 * Filters enabled languages available in the string translation editor.
		 *
		 * @param array<int, array<string, mixed>> $languages Enabled language records.
		 */
		$filtered = apply_filters( 'shapetranslate_string_translation_languages', $languages );

		if ( ! is_array( $filtered ) ) {
			return $languages;
		}

		$registered = array();
		$validated  = array();
		$seen       = array();

		foreach ( $languages as $language ) {
			$registered[ $language['id'] ] = $language;
		}

		foreach ( $filtered as $language ) {
			if (
				is_array( $language )
				&& isset( $language['id'], $registered[ $language['id'] ] )
				&& ! isset( $seen[ $language['id'] ] )
			) {
				$validated[]             = $registered[ $language['id'] ];
				$seen[ $language['id'] ] = true;
			}
		}

		return $validated;
	}

	/**
	 * Returns registered groups available as filters.
	 *
	 * @return array<int, string>
	 */
	public function get_groups() {
		$groups = $this->repository->get_groups();

		/**
		 * Filters group names available in the string translation editor.
		 *
		 * @param array<int, string> $groups Registered group names.
		 */
		$filtered = apply_filters( 'shapetranslate_string_translation_groups', $groups );

		if ( ! is_array( $filtered ) ) {
			return $groups;
		}

		$allowed   = array_fill_keys( $groups, true );
		$validated = array();

		foreach ( $filtered as $group ) {
			$group = is_scalar( $group ) ? sanitize_text_field( (string) $group ) : '';

			if ( '' !== $group && isset( $allowed[ $group ] ) ) {
				$validated[ $group ] = $group;
			}
		}

		return array_values( $validated );
	}

	/**
	 * Runs a paginated definition query and bulk-loads selected translations.
	 *
	 * @param array<string, mixed> $args Raw query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, args: array<string, mixed>}
	 */
	public function query( array $args = array() ) {
		$args = $this->normalize_args( $args );

		/**
		 * Filters normalized string translation query arguments.
		 *
		 * Values are normalized again after this filter.
		 *
		 * @param array<string, mixed> $args Normalized query arguments.
		 */
		$filtered_args = apply_filters( 'shapetranslate_string_translation_query_args', $args );
		$args          = $this->normalize_args( is_array( $filtered_args ) ? $filtered_args : $args );
		$result        = $this->repository->query_definitions( $args );
		$items         = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$string_ids    = array_values( wp_list_pluck( $items, 'string_id' ) );
		$selected      = $this->selected_language_ids( $args['language_id'] );
		$translations  = empty( $selected )
			? array()
			: $this->repository->get_translations( $string_ids, $selected );

		foreach ( $items as &$item ) {
			$string_id = $item['string_id'];
			$values    = array();

			foreach ( $selected as $language_id ) {
				$values[ $language_id ] = isset( $translations[ $string_id ][ $language_id ] )
					? $translations[ $string_id ][ $language_id ]
					: '';
			}

			$item['translations'] = $values;

			// Kept for the single-language view and for anything filtering rows,
			// which has one language in mind by definition.
			$item['translation'] = isset( $values[ $args['language_id'] ] ) ? $values[ $args['language_id'] ] : '';
		}
		unset( $item );

		/**
		 * Filters prepared string translation rows after bulk loading.
		 *
		 * @param array<int, array<string, mixed>> $items Prepared rows.
		 * @param array<string, mixed>             $args  Normalized query arguments.
		 */
		$filtered_items = apply_filters( 'shapetranslate_string_translation_items', $items, $args );

		return array(
			'items' => is_array( $filtered_items ) ? array_values( $filtered_items ) : $items,
			'total' => isset( $result['total'] ) ? absint( $result['total'] ) : 0,
			'args'  => $args,
		);
	}

	/**
	 * Returns the enabled language IDs one filter value selects.
	 *
	 * @param string $language_id Normalized language filter.
	 * @return array<int, string>
	 */
	public function selected_language_ids( $language_id ) {
		if ( self::ALL_LANGUAGES === $language_id ) {
			return array_values( wp_list_pluck( $this->get_languages(), 'id' ) );
		}

		return '' === $language_id ? array() : array( $language_id );
	}

	/**
	 * Normalizes all query arguments against current language and group registries.
	 *
	 * @param array<string, mixed> $args Raw query arguments.
	 * @return array<string, mixed>
	 */
	private function normalize_args( $args ) {
		$languages    = $this->get_languages();
		$groups       = $this->get_groups();
		$language_ids = array_values( wp_list_pluck( $languages, 'id' ) );
		$default_id   = $this->language_manager->get_default_id();
		$default_id   = in_array( $default_id, $language_ids, true )
			? $default_id
			: ( isset( $language_ids[0] ) ? $language_ids[0] : '' );
		$language_id  = isset( $args['language_id'] ) && is_scalar( $args['language_id'] )
			? sanitize_text_field( (string) $args['language_id'] )
			: $default_id;
		$group        = isset( $args['group'] ) && is_scalar( $args['group'] )
			? sanitize_text_field( (string) $args['group'] )
			: '';
		$search       = isset( $args['search'] ) && is_scalar( $args['search'] )
			? sanitize_text_field( (string) $args['search'] )
			: '';
		$orderby      = isset( $args['orderby'] ) && is_scalar( $args['orderby'] )
			? sanitize_key( (string) $args['orderby'] )
			: 'group';
		$order        = isset( $args['order'] ) && is_scalar( $args['order'] )
			? strtoupper( sanitize_text_field( (string) $args['order'] ) )
			: 'ASC';
		$per_page     = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;

		if ( self::ALL_LANGUAGES === $language_id ) {
			// Nothing to show every language of, so fall back to the normal view.
			$language_id = empty( $language_ids ) ? $default_id : self::ALL_LANGUAGES;
		} elseif ( ! in_array( $language_id, $language_ids, true ) ) {
			$language_id = $default_id;
		}

		if ( ! in_array( $group, $groups, true ) ) {
			$group = '';
		}

		if ( ! in_array( $orderby, array( 'group', 'original', 'updated' ), true ) ) {
			$orderby = 'group';
		}

		$per_page = min( 100, max( 1, $per_page ) );

		if ( self::ALL_LANGUAGES === $language_id ) {
			$per_page = $this->bounded_per_page( $per_page, count( $language_ids ) );
		}

		return array(
			'language_id' => $language_id,
			'group'       => $group,
			'search'      => $search,
			'page'        => max( 1, isset( $args['page'] ) ? absint( $args['page'] ) : 1 ),
			'per_page'    => $per_page,
			'orderby'     => $orderby,
			'order'       => 'DESC' === $order ? 'DESC' : 'ASC',
		);
	}

	/**
	 * Caps a page so its form stays inside the PHP input variable limit.
	 *
	 * The all-languages view posts one field per string per language. PHP drops
	 * everything past `max_input_vars` without raising anything an editor would
	 * see, so a page that would exceed it loses translations silently on save.
	 * Showing fewer rows is visible and recoverable; losing a save is not.
	 *
	 * @param int $per_page       Requested rows per page.
	 * @param int $language_count Number of enabled languages.
	 * @return int
	 */
	private function bounded_per_page( $per_page, $language_count ) {
		if ( $language_count < 2 ) {
			return $per_page;
		}

		$limit = (int) ini_get( 'max_input_vars' );
		$limit = $limit > 0 ? $limit : 1000;

		// A fifth of the budget is left for the nonce, the preserved filters,
		// pagination, and whatever else the screen posts alongside the fields.
		$rows = (int) floor( ( $limit * 0.8 ) / $language_count );

		return max( 5, min( $per_page, $rows ) );
	}
}
