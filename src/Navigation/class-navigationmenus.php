<?php
/**
 * Language management for a block theme's navigation menus.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\LanguageManager;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Answers for a menu the way the template services answer for a template.
 *
 * The Site Editor edits three things that belong to a language: a template, a
 * part of one, and the menu a part points at. The first two are files with
 * slugs, and TemplateParts and Templates answer for them. A menu is an ordinary
 * post with an identifier, translated through the same relationships as every
 * other post, so nothing here stores anything of its own — it reads and writes
 * through PostTranslationManager and exists to put a menu's language behind the
 * same four questions the panel asks about everything else: what language is
 * this, which languages have one, start one, and take it away.
 *
 * Menus are named the way posts are, by identifier rather than by slug: two
 * menus called "Navigation" are ordinary, and a slug that has quietly become
 * `navigation-2` names nothing an editor would recognise.
 */
final class NavigationMenus {

	/**
	 * Post type holding a block theme's menus.
	 */
	const POST_TYPE = 'wp_navigation';

	/**
	 * REST path segment these answer on.
	 */
	const ROUTE_BASE = 'navigation-menus';

	/**
	 * Block that names a menu.
	 */
	const NAVIGATION_BLOCK = 'core/navigation';

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Link rewriter used on a freshly copied menu.
	 *
	 * @var NavigationLinkTranslator
	 */
	private $links;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager   $post_translations Post translation manager.
	 * @param LanguageManager          $language_manager  Language manager.
	 * @param NavigationLinkTranslator $links             Link rewriter.
	 */
	public function __construct(
		PostTranslationManager $post_translations,
		LanguageManager $language_manager,
		NavigationLinkTranslator $links
	) {
		$this->post_translations = $post_translations;
		$this->language_manager  = $language_manager;
		$this->links             = $links;
	}

	/**
	 * Returns the post type menus are stored in.
	 *
	 * @return string
	 */
	public function post_type() {
		return self::POST_TYPE;
	}

	/**
	 * Returns the REST path segment menus answer on.
	 *
	 * @return string
	 */
	public function route_base() {
		return self::ROUTE_BASE;
	}

	/**
	 * Reports whether menus are translated on this site at all.
	 *
	 * @return bool
	 */
	public function is_available() {
		return post_type_exists( self::POST_TYPE )
			&& $this->post_translations->supports_post_type( self::POST_TYPE )
			&& $this->language_manager->has_languages();
	}

	/**
	 * Returns the default language's identifier.
	 *
	 * @return string
	 */
	public function default_language_id() {
		return (string) $this->language_manager->get_default_id();
	}

	/**
	 * Reports whether one identifier names a menu.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return bool
	 */
	public function exists( $menu_id ) {
		return null !== $this->find( $menu_id );
	}

	/**
	 * Returns one menu, when the identifier names one.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return WP_Post|null
	 */
	public function find( $menu_id ) {
		$post = get_post( absint( $menu_id ) );

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		return $post;
	}

	/**
	 * Returns the name a menu goes by.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return string
	 */
	public function get_title( $menu_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return '';
		}

		$title = (string) $post->post_title;

		return '' !== $title ? $title : __( 'Navigation', 'shapetranslate' );
	}

	/**
	 * Returns the language a menu belongs to.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return string Empty when nothing has claimed it yet.
	 */
	public function get_language_id( $menu_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return '';
		}

		return (string) $this->post_translations->get_post_language_id( $post->ID );
	}

	/**
	 * Returns every language's version of a menu, by language.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return array<string, int>
	 */
	public function get_translations( $menu_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return array();
		}

		$translations = array();

		foreach ( (array) $this->post_translations->get_translations( $post->ID ) as $language_id => $translation_id ) {
			$translation_id = absint( $translation_id );

			if ( '' !== (string) $language_id && null !== $this->find( $translation_id ) ) {
				$translations[ (string) $language_id ] = $translation_id;
			}
		}

		return $translations;
	}

	/**
	 * Returns the address the Site Editor edits one menu at.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return string
	 */
	public function editor_url( $menu_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return '';
		}

		return admin_url(
			'site-editor.php?' . http_build_query(
				array(
					'postType' => self::POST_TYPE,
					'postId'   => $post->ID,
					'canvas'   => 'edit',
				)
			)
		);
	}

	/**
	 * Reports whether a menu's own language may be changed.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return bool
	 */
	public function can_change_language( $menu_id ) {
		return null !== $this->find( $menu_id );
	}

	/**
	 * Returns the sentence shown when a menu's language is fixed.
	 *
	 * Nothing fixes it, so there is nothing to say. The panel asks all three
	 * services the same questions and this is the answer that means "no reason".
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public function message( $key ) {
		unset( $key );

		return '';
	}

	/**
	 * Starts one language's version of a menu.
	 *
	 * Translated from whichever menu the group was written from, not from the
	 * one that happens to be open: a Bengali menu started from the Danish one
	 * would inherit the Danish links, and the group already knows which menu
	 * everything else came from.
	 *
	 * The copy's links are then read in the new language, so a menu translated
	 * into Bengali opens with the Bengali pages rather than with links that
	 * carry a reader back out of the language they are reading in.
	 *
	 * @param mixed  $menu_id     Menu identifier.
	 * @param string $language_id Language to write.
	 * @return int|WP_Error Created menu identifier.
	 */
	public function create_translation( $menu_id, $language_id ) {
		$source = $this->source_of( $menu_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$created = $this->post_translations->create_translation( $source->ID, (string) $language_id );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$this->translate_links( $created, (string) $language_id );

		return $created;
	}

	/**
	 * Rewrites one menu's links to name what they name in its own language.
	 *
	 * Saved only when something actually changed, so a menu of custom links —
	 * which nothing here can translate — is not given a revision and a modified
	 * date for a copy that came out identical.
	 *
	 * @param int    $menu_id     Menu to rewrite.
	 * @param string $language_id Language the menu is written in.
	 * @return void
	 */
	private function translate_links( $menu_id, $language_id ) {
		$menu = $this->find( $menu_id );

		if ( null === $menu ) {
			return;
		}

		$content = $this->links->translate_content( $menu->post_content, $language_id );

		if ( $content === $menu->post_content ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $menu->ID,
				'post_content' => wp_slash( $content ),
			)
		);
	}

	/**
	 * Adopts a menu the site already has as one language's version.
	 *
	 * @param mixed  $menu_id     Menu identifier.
	 * @param mixed  $target_id   Menu being adopted.
	 * @param string $language_id Language the adopted menu is in.
	 * @return array<string, mixed>|WP_Error
	 */
	public function link_existing( $menu_id, $target_id, $language_id ) {
		$source = $this->source_of( $menu_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$target = $this->find( $target_id );

		if ( null === $target ) {
			return new WP_Error(
				'shapetranslate_menu_not_found',
				__( 'That menu no longer exists.', 'shapetranslate' ),
				array( 'status' => 404 )
			);
		}

		if ( $target->ID === $source->ID ) {
			return new WP_Error(
				'shapetranslate_link_self',
				__( 'A menu cannot be its own translation.', 'shapetranslate' ),
				array( 'status' => 400 )
			);
		}

		$translations = $this->get_translations( $source->ID );

		if ( isset( $translations[ (string) $language_id ] ) ) {
			return new WP_Error(
				'shapetranslate_menu_exists',
				__( 'That language already has a version of this menu.', 'shapetranslate' ),
				array( 'status' => 409 )
			);
		}

		$map = $translations;
		$map[ (string) $this->language_of( $source ) ] = $source->ID;
		$map[ (string) $language_id ]                  = $target->ID;

		$linked = $this->post_translations->link_translations( $map, $source->ID );

		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return array(
			'menu'     => $target->ID,
			'language' => (string) $language_id,
		);
	}

	/**
	 * Moves a menu into another language.
	 *
	 * @param mixed  $menu_id     Menu identifier.
	 * @param string $language_id Language to move it into.
	 * @return array<string, mixed>|WP_Error
	 */
	public function change_language( $menu_id, $language_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return new WP_Error(
				'shapetranslate_menu_not_found',
				__( 'That menu no longer exists.', 'shapetranslate' ),
				array( 'status' => 404 )
			);
		}

		$moved = $this->post_translations->set_post_language( $post->ID, (string) $language_id );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		return array(
			'menu'     => $post->ID,
			'language' => (string) $language_id,
		);
	}

	/**
	 * Deletes one language's version of a menu.
	 *
	 * @param mixed  $menu_id     Menu identifier.
	 * @param string $language_id Language to remove.
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete_translation( $menu_id, $language_id ) {
		$source = $this->source_of( $menu_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$translations = $this->get_translations( $source->ID );
		$language_id  = (string) $language_id;

		if ( ! isset( $translations[ $language_id ] ) ) {
			return new WP_Error(
				'shapetranslate_menu_not_translated',
				__( 'That language has no version of this menu.', 'shapetranslate' ),
				array( 'status' => 404 )
			);
		}

		$deleted = $translations[ $language_id ];

		$this->post_translations->remove_post( $deleted );
		wp_delete_post( $deleted, true );

		return array(
			'menu'     => $source->ID === $deleted ? 0 : $source->ID,
			'language' => $language_id,
		);
	}

	/**
	 * Lists the menus that are free to be adopted as a translation.
	 *
	 * A menu already spoken for by this group, or by another one, is not
	 * offered: adopting it would take it away from wherever it belongs.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return array<int, array<string, string>>
	 */
	public function link_candidates( $menu_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return array();
		}

		$taken = $this->get_translations( $post->ID );
		$taken[] = $post->ID;

		/*
		 * This list has to reach every menu, in every language, or a menu could
		 * never be adopted as the translation of another. What it must not do is
		 * silence every other plugin on the site to get there, which is what
		 * `suppress_filters` amounts to — and get_posts() turns it on by default,
		 * so it is turned off here rather than left unsaid.
		 *
		 * Only this plugin's own language scoping is declined, by the query
		 * argument the routing module reads for exactly that. Nothing constrains
		 * `wp_navigation` today, since it is not a translated post type; saying so
		 * is what keeps this list whole if that ever changes.
		 */
		$menus = get_posts(
			array(
				'post_type'                        => self::POST_TYPE,
				'post_status'                      => array( 'publish', 'draft' ),
				'numberposts'                      => 100,
				'orderby'                          => 'title',
				'order'                            => 'ASC',
				'suppress_filters'                 => false,
				'shapetranslate_skip_language_filter' => true,
			)
		);

		$candidates = array();

		foreach ( $menus as $menu ) {
			if ( in_array( $menu->ID, array_map( 'absint', $taken ), true ) ) {
				continue;
			}

			// Already the translation of something else, which is not ours to move.
			if ( '' !== (string) $this->post_translations->get_group_id( $menu->ID ) && ! $this->stands_alone( $menu->ID ) ) {
				continue;
			}

			$candidates[] = array(
				'slug'  => (string) $menu->ID,
				'title' => $this->get_title( $menu->ID ),
			);
		}

		return $candidates;
	}

	/**
	 * Returns the menu a group was written from.
	 *
	 * @param mixed $menu_id Menu identifier.
	 * @return WP_Post|WP_Error
	 */
	private function source_of( $menu_id ) {
		$post = $this->find( $menu_id );

		if ( null === $post ) {
			return new WP_Error(
				'shapetranslate_menu_not_found',
				__( 'That menu no longer exists.', 'shapetranslate' ),
				array( 'status' => 404 )
			);
		}

		$source = $this->find( $this->post_translations->get_source_post_id( $post->ID ) );

		return null === $source ? $post : $source;
	}

	/**
	 * Reports whether a menu is the only one in its group.
	 *
	 * @param int $menu_id Menu identifier.
	 * @return bool
	 */
	private function stands_alone( $menu_id ) {
		return array() === $this->get_translations( $menu_id );
	}

	/**
	 * Returns the language a menu is in, falling back to the site's own.
	 *
	 * @param WP_Post $post Menu.
	 * @return string
	 */
	private function language_of( WP_Post $post ) {
		$language_id = (string) $this->post_translations->get_post_language_id( $post->ID );

		return '' !== $language_id ? $language_id : $this->default_language_id();
	}
}
