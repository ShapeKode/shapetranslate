<?php
/**
 * Translation dashboard query service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\TranslationDashboardRepositoryInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Validates dashboard filters and bulk-primes result objects.
 */
final class TranslationDashboardQuery {

	/**
	 * Dashboard reporting repository.
	 *
	 * @var TranslationDashboardRepositoryInterface|null
	 */
	private $repository;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translation_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Constructor.
	 *
	 * @param TranslationDashboardRepositoryInterface|null $repository          Reporting repository.
	 * @param PostTranslationManager                       $translation_manager Translation manager.
	 * @param LanguageManager                              $language_manager    Language manager.
	 */
	public function __construct(
		?TranslationDashboardRepositoryInterface $repository,
		PostTranslationManager $translation_manager,
		LanguageManager $language_manager
	) {
		$this->repository          = $repository;
		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
	}

	/**
	 * Reports whether the active translation storage supports dashboard queries.
	 *
	 * @return bool
	 */
	public function is_available() {
		return null !== $this->repository;
	}

	/**
	 * Returns post types visible to the current dashboard user.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public function get_post_types() {
		$post_types = array();

		foreach ( $this->translation_manager->get_supported_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( $object && current_user_can( $object->cap->edit_posts ) ) {
				$post_types[ $post_type ] = $object;
			}
		}

		/**
		 * Filters post type objects visible in the translation dashboard.
		 *
		 * @param array<string, \WP_Post_Type> $post_types Visible supported post types.
		 */
		$filtered = apply_filters( 'shapetranslate_translation_dashboard_post_types', $post_types );

		return is_array( $filtered ) ? $this->validate_post_types( $filtered ) : $post_types;
	}

	/**
	 * Returns enabled languages displayed in the dashboard matrix.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_languages() {
		$languages = $this->language_manager->get_languages( true );

		/**
		 * Filters enabled languages displayed in the translation dashboard.
		 *
		 * @param array<int, array<string, mixed>> $languages Enabled language records.
		 */
		$filtered = apply_filters( 'shapetranslate_translation_dashboard_languages', $languages );

		if ( ! is_array( $filtered ) ) {
			return $languages;
		}

		$registered = array();

		foreach ( $languages as $language ) {
			$registered[ $language['id'] ] = $language;
		}

		$validated = array();
		$seen      = array();

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
	 * Runs a paginated dashboard query and primes every relationship and post.
	 *
	 * @param array<string, mixed> $args Raw query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, args: array<string, mixed>}
	 */
	public function query( array $args = array() ) {
		$args = $this->normalize_args( $args );

		if ( null === $this->repository || empty( $args['post_types'] ) || empty( $args['language_ids'] ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'args'  => $args,
			);
		}

		/**
		 * Filters normalized dashboard query arguments before repository execution.
		 *
		 * Values are normalized again after this filter.
		 *
		 * @param array<string, mixed> $args Normalized query arguments.
		 */
		$filtered_args = apply_filters( 'shapetranslate_translation_dashboard_query_args', $args );
		$args          = $this->normalize_args( is_array( $filtered_args ) ? $filtered_args : $args );
		$result        = $this->repository->query_dashboard( $args );
		$source_ids    = isset( $result['items'] ) && is_array( $result['items'] )
			? array_values( array_filter( array_map( 'absint', $result['items'] ) ) )
			: array();

		if ( ! empty( $source_ids ) ) {
			_prime_post_caches( $source_ids, false, false );
			$this->translation_manager->prime_posts( $source_ids );
		}

		$items           = array();
		$translation_ids = array();

		foreach ( $source_ids as $source_id ) {
			$source = get_post( $source_id );

			if ( ! $source instanceof WP_Post ) {
				continue;
			}

			$translations    = $this->translation_manager->get_translations( $source_id );
			$translation_ids = array_merge( $translation_ids, array_values( $translations ) );
			$items[]         = array(
				'source_post_id' => $source_id,
				'group_id'       => $this->translation_manager->get_group_id( $source_id ),
				'translations'   => $translations,
			);
		}

		$translation_ids = array_values( array_diff( array_unique( array_map( 'absint', $translation_ids ) ), $source_ids ) );

		if ( ! empty( $translation_ids ) ) {
			_prime_post_caches( $translation_ids, false, false );
		}

		/**
		 * Filters prepared dashboard rows after relationship and post cache priming.
		 *
		 * @param array<int, array<string, mixed>> $items Prepared rows.
		 * @param array<string, mixed>             $args  Normalized query arguments.
		 */
		$filtered_items = apply_filters( 'shapetranslate_translation_dashboard_items', $items, $args );

		return array(
			'items' => is_array( $filtered_items ) ? array_values( $filtered_items ) : $items,
			'total' => isset( $result['total'] ) ? absint( $result['total'] ) : 0,
			'args'  => $args,
		);
	}

	/**
	 * Normalizes all dashboard query arguments against current registries.
	 *
	 * @param array<string, mixed> $args Raw query arguments.
	 * @return array<string, mixed>
	 */
	private function normalize_args( $args ) {
		$post_types     = $this->get_post_types();
		$languages      = $this->get_languages();
		$post_type_ids  = array_keys( $post_types );
		$own_post_types = array();
		$language_ids   = array_values( wp_list_pluck( $languages, 'id' ) );
		$post_type      = isset( $args['post_type'] ) && is_scalar( $args['post_type'] )
			? sanitize_key( (string) $args['post_type'] )
			: '';
		$language_id    = isset( $args['language_id'] ) && is_scalar( $args['language_id'] )
			? sanitize_text_field( (string) $args['language_id'] )
			: '';
		$status         = isset( $args['status'] ) && is_scalar( $args['status'] )
			? sanitize_key( (string) $args['status'] )
			: 'all';
		$orderby        = isset( $args['orderby'] ) && is_scalar( $args['orderby'] )
			? sanitize_key( (string) $args['orderby'] )
			: 'title';
		$order          = isset( $args['order'] ) && is_scalar( $args['order'] )
			? strtoupper( sanitize_text_field( (string) $args['order'] ) )
			: 'ASC';
		$search         = isset( $args['search'] ) && is_scalar( $args['search'] )
			? sanitize_text_field( (string) $args['search'] )
			: '';
		$per_page       = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;

		if ( ! in_array( $post_type, $post_type_ids, true ) ) {
			$post_type = '';
		}

		if ( ! in_array( $language_id, $language_ids, true ) ) {
			$language_id = '';
		}

		if ( ! in_array( $status, array( 'all', 'missing', 'completed', 'draft' ), true ) ) {
			$status = 'all';
		}

		if ( ! in_array( $orderby, array( 'title', 'post_type', 'modified' ), true ) ) {
			$orderby = 'title';
		}

		$complete_statuses = get_post_stati( array( 'public' => true ) );

		foreach ( $post_types as $post_type_id => $post_type_object ) {
			$edit_others_capability = isset( $post_type_object->cap->edit_others_posts )
				? $post_type_object->cap->edit_others_posts
				: '';

			if ( '' === $edit_others_capability || ! current_user_can( $edit_others_capability ) ) {
				$own_post_types[] = $post_type_id;
			}
		}

		/**
		 * Filters post statuses treated as completed translations.
		 *
		 * @param array<int, string> $complete_statuses Public post statuses.
		 */
		$complete_statuses = apply_filters( 'shapetranslate_translation_dashboard_complete_statuses', $complete_statuses );

		/**
		 * Filters post statuses included by the Draft translations view.
		 *
		 * @param array<int, string> $draft_statuses Draft post statuses.
		 */
		$draft_statuses = apply_filters( 'shapetranslate_translation_dashboard_draft_statuses', array( 'draft' ) );

		return array(
			'post_types'          => $post_type_ids,
			'own_post_types'      => $own_post_types,
			'current_user_id'     => get_current_user_id(),
			'post_type'           => $post_type,
			'language_ids'        => $language_ids,
			'default_language_id' => $this->language_manager->get_default_id(),
			'language_id'         => $language_id,
			'status'              => $status,
			'search'              => $search,
			'page'                => max( 1, isset( $args['page'] ) ? absint( $args['page'] ) : 1 ),
			'per_page'            => min( 100, max( 1, $per_page ) ),
			'orderby'             => $orderby,
			'order'               => 'DESC' === $order ? 'DESC' : 'ASC',
			'source_statuses'     => $this->normalize_statuses( get_post_stati( array( 'show_in_admin_all_list' => true ) ) ),
			'complete_statuses'   => $this->normalize_statuses( $complete_statuses, array( 'publish' ) ),
			'draft_statuses'      => $this->normalize_statuses( $draft_statuses, array( 'draft' ) ),
		);
	}

	/**
	 * Revalidates filtered post type objects and capabilities.
	 *
	 * @param array<mixed, mixed> $post_types Filtered post type objects.
	 * @return array<string, \WP_Post_Type>
	 */
	private function validate_post_types( $post_types ) {
		$validated = array();
		$supported = $this->translation_manager->get_supported_post_types();

		foreach ( $post_types as $post_type => $object ) {
			$post_type = is_scalar( $post_type ) ? sanitize_key( (string) $post_type ) : '';
			$object    = get_post_type_object( $post_type );

			if ( $object && in_array( $post_type, $supported, true ) && current_user_can( $object->cap->edit_posts ) ) {
				$validated[ $post_type ] = $object;
			}
		}

		return $validated;
	}

	/**
	 * Normalizes filtered post status lists.
	 *
	 * @param mixed              $statuses Filtered statuses.
	 * @param array<int, string> $fallback Fallback statuses.
	 * @return array<int, string>
	 */
	private function normalize_statuses( $statuses, $fallback = array( 'publish', 'future', 'draft', 'pending', 'private' ) ) {
		if ( ! is_array( $statuses ) ) {
			return $fallback;
		}

		$statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', $statuses ) ) ) );

		return empty( $statuses ) ? $fallback : $statuses;
	}
}
