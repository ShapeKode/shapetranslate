<?php
/**
 * Multilingual navigation integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Switcher\NavigationMenuIntegration;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Selects language-specific menus and resolves translated menu-item links.
 */
final class NavigationModule implements ModuleInterface {

	/**
	 * Menu language manager.
	 *
	 * @var MenuLanguageManager
	 */
	private $menu_manager;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param MenuLanguageManager    $menu_manager      Menu language manager.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		MenuLanguageManager $menu_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations,
		LanguageUrlManager $url_manager
	) {
		$this->menu_manager      = $menu_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->url_manager       = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'wp_nav_menu_args', array( $this, 'select_language_menu' ), 20 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'translate_menu_items' ), 5, 2 );
		add_action( 'wp_delete_nav_menu', array( $this, 'remove_deleted_menu' ) );
		add_filter( 'shapetranslate_pre_delete_language', array( $this, 'prevent_language_deletion' ), 10, 3 );
	}

	/**
	 * Selects the configured menu for the current language and theme location.
	 *
	 * @param array<string, mixed> $args WordPress navigation menu arguments.
	 * @return array<string, mixed>
	 */
	public function select_language_menu( $args ) {
		if ( is_admin() || ! $this->url_manager->supports_language_prefixes() ) {
			return $args;
		}

		$location = isset( $args['theme_location'] ) && is_scalar( $args['theme_location'] )
			? sanitize_key( (string) $args['theme_location'] )
			: '';

		if ( '' === $location ) {
			return $args;
		}

		$has_explicit_menu = ! empty( $args['menu'] );

		/**
		 * Filters whether a language-specific location may replace an explicitly
		 * supplied wp_nav_menu() menu argument.
		 *
		 * @param bool                 $override Whether to replace an explicit menu.
		 * @param array<string, mixed> $args     Menu arguments.
		 */
		$override_explicit = (bool) apply_filters( 'shapetranslate_override_explicit_nav_menu', false, $args );

		if ( $has_explicit_menu && ! $override_explicit ) {
			return $args;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return $args;
		}

		$menu_id = $this->menu_manager->get_menu_for_location( $location, $language['id'] );

		if ( 0 < $menu_id ) {
			$args['menu'] = $menu_id;
		}

		return $args;
	}

	/**
	 * Resolves object menu items to the current language.
	 *
	 * @param array<int, WP_Post> $items Navigation menu items.
	 * @param object              $args  WordPress navigation menu arguments.
	 * @return array<int, WP_Post>
	 */
	public function translate_menu_items( $items, $args ) {
		if ( is_admin() || ! $this->url_manager->supports_language_prefixes() ) {
			return $items;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return $items;
		}

		/**
		 * Filters whether ShapeTranslate translates object links in one rendered menu.
		 *
		 * @param bool                 $translate Whether links should be translated.
		 * @param array<int, WP_Post>  $items     Menu items.
		 * @param object               $args      Menu arguments.
		 * @param array<string, mixed> $language  Current language.
		 */
		$translate = apply_filters( 'shapetranslate_translate_menu_links', true, $items, $args, $language );

		if ( ! $translate ) {
			return $items;
		}

		$this->prime_menu_translation_data( $items, $language['id'] );

		$behavior    = $this->get_missing_behavior( $args, $language );
		$removed_ids = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			$result = $this->translate_item( $item, $language, $behavior );

			if ( 'hide' === $result ) {
				$removed_ids[ $item->ID ] = true;
			}
		}

		$items = $this->remove_hidden_items_and_descendants( $items, $removed_ids );
		$this->refresh_current_classes( $items );
		$this->refresh_children_classes( $items );

		/**
		 * Filters translated navigation menu items before WordPress walks them.
		 *
		 * @param array<int, WP_Post>  $items    Translated menu items.
		 * @param object               $args     Menu arguments.
		 * @param array<string, mixed> $language Current language.
		 */
		$filtered = apply_filters( 'shapetranslate_translated_menu_items', $items, $args, $language );

		return is_array( $filtered ) ? $filtered : $items;
	}

	/**
	 * Removes a deleted menu from theme-location assignments.
	 *
	 * @param int $menu_id Deleted menu term identifier.
	 * @return void
	 */
	public function remove_deleted_menu( $menu_id ) {
		$this->menu_manager->remove_menu( $menu_id );
	}

	/**
	 * Prevents deletion of a language still assigned to navigation menus.
	 *
	 * @param true|WP_Error        $can_delete  Existing decision.
	 * @param string               $language_id Language identifier.
	 * @param array<string, mixed> $language    Language record.
	 * @return true|WP_Error
	 */
	public function prevent_language_deletion( $can_delete, $language_id, $language ) {
		unset( $language );

		if ( true !== $can_delete || ! $this->menu_manager->is_language_in_use( $language_id ) ) {
			return $can_delete;
		}

		return new WP_Error(
			'menu_language_in_use',
			__( 'This language is assigned to a navigation menu and cannot be deleted.', 'shapetranslate' )
		);
	}

	/**
	 * Bulk-primes relationship and object caches used while translating a menu.
	 *
	 * @param array<int, WP_Post> $items       Navigation menu items.
	 * @param string              $language_id Target language identifier.
	 * @return void
	 */
	private function prime_menu_translation_data( $items, $language_id ) {
		$post_ids             = array();
		$term_ids_by_taxonomy = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			if ( 'post_type' === $item->type ) {
				$post_ids[] = absint( $item->object_id );
			} elseif ( 'taxonomy' === $item->type ) {
				$taxonomy = sanitize_key( $item->object );

				if ( taxonomy_exists( $taxonomy ) ) {
					$term_ids_by_taxonomy[ $taxonomy ][] = absint( $item->object_id );
				}
			}
		}

		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

		if ( ! empty( $post_ids ) ) {
			$this->post_translations->prime_posts( $post_ids );
		}

		$source_terms      = $this->load_terms( $term_ids_by_taxonomy );
		$term_taxonomy_ids = array();

		foreach ( $source_terms as $term ) {
			$term_taxonomy_ids[] = $term->term_taxonomy_id;
		}

		if ( ! empty( $term_taxonomy_ids ) ) {
			$this->term_translations->prime_terms( $term_taxonomy_ids );
		}

		$target_post_ids = array();

		foreach ( $post_ids as $post_id ) {
			$target_post_ids[] = $this->post_translations->get_translation( $post_id, $language_id );
		}

		$target_post_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_post_ids ) ) ) );

		if ( ! empty( $target_post_ids ) ) {
			_prime_post_caches( $target_post_ids, false, false );
		}

		$target_term_ids_by_taxonomy = array();

		foreach ( $source_terms as $term ) {
			$target_id = $this->term_translations->get_translation( $term->term_id, $term->taxonomy, $language_id );

			if ( 0 < $target_id ) {
				$target_term_ids_by_taxonomy[ $term->taxonomy ][] = $target_id;
			}
		}

		$this->load_terms( $target_term_ids_by_taxonomy );
	}

	/**
	 * Loads term objects in one query per taxonomy and primes WordPress caches.
	 *
	 * @param array<string, array<int, int>> $term_ids_by_taxonomy Term IDs keyed by taxonomy.
	 * @return array<int, WP_Term>
	 */
	private function load_terms( $term_ids_by_taxonomy ) {
		$loaded = array();

		foreach ( $term_ids_by_taxonomy as $taxonomy => $term_ids ) {
			$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

			if ( empty( $term_ids ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'               => $taxonomy,
					'include'                => $term_ids,
					'hide_empty'             => false,
					'number'                 => count( $term_ids ),
					'update_term_meta_cache' => false,
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				$loaded = array_merge( $loaded, $terms );
			}
		}

		return $loaded;
	}

	/**
	 * Translates one menu item in place.
	 *
	 * @param WP_Post              $item     Menu item.
	 * @param array<string, mixed> $language Current language.
	 * @param string               $behavior Missing-translation behavior.
	 * @return string Empty on success, or hide when the item must be removed.
	 */
	private function translate_item( WP_Post $item, $language, $behavior ) {
		if ( 'custom' === $item->type ) {
			if ( NavigationMenuIntegration::MENU_ITEM_URL !== $item->url && $this->is_internal_url( $item->url ) ) {
				$url_parts = wp_parse_url( $item->url );
				$url       = is_array( $url_parts ) && ! isset( $url_parts['host'] ) && '/' === substr( $item->url, 0, 1 )
					? home_url( $item->url )
					: $item->url;
				$item->url = $this->url_manager->prefix_url( $url, $language );
			}

			return '';
		}

		$target_id = 0;
		$url       = '';

		if ( 'post_type' === $item->type ) {
			$target_id   = $this->post_translations->get_translation( $item->object_id, $language['id'] );
			$target_post = 0 < $target_id ? get_post( $target_id ) : null;

			if ( $target_post instanceof WP_Post && $this->is_post_available( $target_post, $item, $language ) ) {
				$url = $this->url_manager->get_translation_url( $item->object_id, $language );
			}
		} elseif ( 'taxonomy' === $item->type ) {
			$target_id   = $this->term_translations->get_translation( $item->object_id, $item->object, $language['id'] );
			$target_term = 0 < $target_id ? get_term( $target_id, $item->object ) : null;

			if ( $target_term instanceof WP_Term ) {
				$url = $this->url_manager->get_translation_url( $item->object_id, $language, $item->object );
			}
		} else {
			return '';
		}

		if ( '' === $url ) {
			return $this->apply_missing_behavior( $item, $language, $behavior );
		}

		$item->_shapetranslate_original_object_id   = absint( $item->object_id );
		$item->_shapetranslate_translated_object_id = $target_id;
		$item->object_id                         = (string) $target_id;
		$item->url                               = $url;

		/**
		 * Fires after one object menu item is resolved to a translation.
		 *
		 * @param WP_Post              $item      Translated menu item.
		 * @param int                  $target_id Target post or term identifier.
		 * @param array<string, mixed> $language  Current language.
		 */
		do_action( 'shapetranslate_menu_item_translated', $item, $target_id, $language );

		return '';
	}

	/**
	 * Reports whether a translated post may be linked from public navigation.
	 *
	 * @param WP_Post              $post     Translated post.
	 * @param WP_Post              $item     Menu item.
	 * @param array<string, mixed> $language Current language.
	 * @return bool
	 */
	private function is_post_available( WP_Post $post, WP_Post $item, $language ) {
		$available = is_post_publicly_viewable( $post );

		/**
		 * Filters whether a translated post is available in navigation.
		 *
		 * @param bool                 $available Whether the post may be linked.
		 * @param WP_Post              $post      Translated post.
		 * @param WP_Post              $item      Menu item.
		 * @param array<string, mixed> $language  Current language.
		 */
		return (bool) apply_filters( 'shapetranslate_menu_post_translation_available', $available, $post, $item, $language );
	}

	/**
	 * Applies the configured behavior for an unavailable object translation.
	 *
	 * @param WP_Post              $item     Menu item.
	 * @param array<string, mixed> $language Current language.
	 * @param string               $behavior Missing behavior.
	 * @return string Empty to retain the item, or hide to remove it.
	 */
	private function apply_missing_behavior( WP_Post $item, $language, $behavior ) {
		if ( 'hide' === $behavior ) {
			return 'hide';
		}

		if ( 'home' === $behavior ) {
			$item->url = $this->url_manager->get_language_home_url( $language );
		} elseif ( 'current' === $behavior ) {
			$item->url = $this->url_manager->get_current_request_url();
		}

		$item->classes   = is_array( $item->classes ) ? $item->classes : array();
		$item->classes[] = 'shapetranslate-menu-item-unavailable';

		return '';
	}

	/**
	 * Returns an allowlisted missing-translation behavior.
	 *
	 * One menu serving several languages is a menu an editor built once and
	 * expects to see whole. Dropping the entries whose page has not been
	 * translated yet leaves a visitor who switched language looking at a shorter
	 * menu than the one they were just reading, with no way to tell whether the
	 * section is gone or was never there. Keeping the entry says the true thing:
	 * the page exists, and this is the language it is written in so far.
	 *
	 * So an untranslated entry stays and keeps pointing at the page it names,
	 * carrying `shapetranslate-menu-item-unavailable` for a theme that wants to mark
	 * it. A site that would rather hide those entries says so through the filter,
	 * and a site that gives each language its own menu never reaches this at all.
	 *
	 * @param object               $args     Menu arguments.
	 * @param array<string, mixed> $language Current language.
	 * @return string
	 */
	private function get_missing_behavior( $args, $language ) {
		/**
		 * Filters unavailable object behavior in translated menus.
		 *
		 * Accepts hide, home, current, or preserve.
		 *
		 * @param string               $behavior Missing behavior.
		 * @param object               $args     Menu arguments.
		 * @param array<string, mixed> $language Current language.
		 */
		$behavior = apply_filters( 'shapetranslate_menu_missing_translation_behavior', 'preserve', $args, $language );
		$behavior = is_scalar( $behavior ) ? sanitize_key( (string) $behavior ) : '';

		return in_array( $behavior, array( 'hide', 'home', 'current', 'preserve' ), true )
			? $behavior
			: 'preserve';
	}

	/**
	 * Removes hidden items and every descendant of a hidden item.
	 *
	 * @param array<int, WP_Post> $items       Menu items.
	 * @param array<int, bool>    $removed_ids Directly hidden menu item IDs.
	 * @return array<int, WP_Post>
	 */
	private function remove_hidden_items_and_descendants( $items, $removed_ids ) {
		$changed = true;

		while ( $changed ) {
			$changed = false;

			foreach ( $items as $item ) {
				$parent_id = $item instanceof WP_Post ? absint( $item->menu_item_parent ) : 0;

				if ( 0 < $parent_id && isset( $removed_ids[ $parent_id ] ) && ! isset( $removed_ids[ $item->ID ] ) ) {
					$removed_ids[ $item->ID ] = true;
					$changed                  = true;
				}
			}
		}

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $removed_ids ) {
					return $item instanceof WP_Post && ! isset( $removed_ids[ $item->ID ] );
				}
			)
		);
	}

	/**
	 * Refreshes current-item and translated ancestor classes.
	 *
	 * @param array<int, WP_Post> $items Menu items.
	 * @return void
	 */
	private function refresh_current_classes( $items ) {
		$queried     = get_queried_object();
		$items_by_id = array();
		$current_ids = array();

		foreach ( $items as $item ) {
			$items_by_id[ $item->ID ] = $item;

			if ( isset( $item->_shapetranslate_translated_object_id ) ) {
				$item->classes = array_values(
					array_diff(
						(array) $item->classes,
						array( 'current-menu-item', 'current_page_item' )
					)
				);

				if ( $this->is_current_translated_item( $item, $queried ) ) {
					$item->classes[] = 'current-menu-item';
					$item->classes[] = 'current_page_item';
					$current_ids[]   = $item->ID;
				}
			}
		}

		foreach ( $current_ids as $current_id ) {
			$parent_id = isset( $items_by_id[ $current_id ] )
				? absint( $items_by_id[ $current_id ]->menu_item_parent )
				: 0;
			$immediate = true;
			$visited   = array();

			while ( 0 < $parent_id && isset( $items_by_id[ $parent_id ] ) && ! isset( $visited[ $parent_id ] ) ) {
				$visited[ $parent_id ] = true;
				$parent                = $items_by_id[ $parent_id ];
				$parent->classes       = is_array( $parent->classes ) ? $parent->classes : array();
				$parent->classes[]     = 'current-menu-ancestor';
				$parent->classes[]     = 'current_page_ancestor';

				if ( $immediate ) {
					$parent->classes[] = 'current-menu-parent';
					$parent->classes[] = 'current_page_parent';
				}

				$parent->classes = array_values( array_unique( $parent->classes ) );
				$parent_id       = absint( $parent->menu_item_parent );
				$immediate       = false;
			}
		}
	}

	/**
	 * Reports whether a translated item matches the current queried object.
	 *
	 * @param WP_Post               $item    Translated menu item.
	 * @param WP_Post|WP_Term|mixed $queried Current queried object.
	 * @return bool
	 */
	private function is_current_translated_item( WP_Post $item, $queried ) {
		$target_id = absint( $item->_shapetranslate_translated_object_id );

		if ( 'post_type' === $item->type ) {
			return $queried instanceof WP_Post && $target_id === $queried->ID;
		}

		return 'taxonomy' === $item->type
			&& $queried instanceof WP_Term
			&& $target_id === $queried->term_id
			&& $item->object === $queried->taxonomy;
	}

	/**
	 * Recomputes the core has-children class after unavailable items are removed.
	 *
	 * @param array<int, WP_Post> $items Menu items.
	 * @return void
	 */
	private function refresh_children_classes( $items ) {
		$parents = array();

		foreach ( $items as $item ) {
			if ( 0 < absint( $item->menu_item_parent ) ) {
				$parents[ absint( $item->menu_item_parent ) ] = true;
			}
		}

		foreach ( $items as $item ) {
			$item->classes = array_values( array_diff( (array) $item->classes, array( 'menu-item-has-children' ) ) );

			if ( isset( $parents[ $item->ID ] ) ) {
				$item->classes[] = 'menu-item-has-children';
			}
		}
	}

	/**
	 * Reports whether a custom menu URL points to this WordPress site.
	 *
	 * @param string $url Menu item URL.
	 * @return bool
	 */
	private function is_internal_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || '#' === substr( $url, 0, 1 ) ) {
			return false;
		}

		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );

		if ( false === $parts || false === $home_parts ) {
			return false;
		}

		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		return ! isset( $parts['host'] )
			|| ( isset( $home_parts['host'] ) && strtolower( $parts['host'] ) === strtolower( $home_parts['host'] ) );
	}
}
