<?php
/**
 * Block theme navigation language integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a block theme's header a menu in the language it is being read in.
 *
 * A classic theme names its menu through a theme location, and NavigationModule
 * answers that. A block theme does not: `core/navigation` points at a
 * `wp_navigation` post by identifier, and an identifier names one menu in one
 * language. So the menu is translated the way everything else here is — the post
 * is translatable, and the reference is resolved against the language being read
 * rather than against the template that wrote it.
 *
 * Where nobody has written a menu in that language, the site's own menu renders
 * instead. That is the second half of this module, and the larger one: a
 * navigation block with no menu at all falls back to `core/page-list`, and a page
 * list narrowed to a language nothing has been translated into yet comes back
 * empty. An empty archive says "this language has nothing here", which is true
 * and useful. An empty header says the same thing about the whole site, which is
 * neither: it is a page with no way off it.
 *
 * So while a navigation block renders, the listing underneath it is widened by
 * one step — this language's pages, and the originals of the pages this language
 * does not have yet. A page that has been translated is still shown once.
 */
final class NavigationBlockModule implements ModuleInterface {

	/**
	 * Post type holding a block theme's menus.
	 */
	const POST_TYPE = 'wp_navigation';

	/**
	 * Blocks whose listings are navigation rather than content.
	 *
	 * @var array<int, string>
	 */
	const LISTING_BLOCKS = array( 'core/navigation', 'core/page-list' );

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Navigation blocks currently rendering, innermost last.
	 *
	 * @var array<int, string>
	 */
	private $listing_scope = array();

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct( PostTranslationManager $post_translations, LanguageUrlManager $url_manager ) {
		$this->post_translations = $post_translations;
		$this->url_manager       = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'render_block_data', array( $this, 'open_listing_scope' ), 20 );
		add_filter( 'render_block', array( $this, 'close_listing_scope' ), 20, 2 );
		add_filter( 'shapetranslate_language_query_fallback', array( $this, 'answer_listing_fallback' ), 10, 2 );

		if ( ! $this->translating_menus() ) {
			return;
		}

		add_filter( 'shapetranslate_non_public_post_types', array( $this, 'declare_post_type' ) );
		add_filter( 'shapetranslate_supported_post_types', array( $this, 'support_post_type' ) );
		add_filter( 'shapetranslate_settings', array( $this, 'enable_menu_translation' ) );
		add_filter( 'get_edit_post_link', array( $this, 'filter_menu_edit_link' ), 10, 2 );

		// Runs before TranslationLifecycleModule::assign_default_language() at priority 20.
		add_action( 'wp_after_insert_post', array( $this, 'assign_editor_language' ), 15, 2 );
	}

	/**
	 * Declares the menu post type eligible for translation.
	 *
	 * @param mixed $post_types Post types ShapeTranslate may translate.
	 * @return mixed
	 */
	public function declare_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		$post_types[] = self::POST_TYPE;

		return $post_types;
	}

	/**
	 * Adds the menu post type to the translatable list.
	 *
	 * @param mixed $post_types Supported post type names.
	 * @return mixed
	 */
	public function support_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		$post_types[] = self::POST_TYPE;

		return $post_types;
	}

	/**
	 * Selects menus in the translatable types a site has chosen.
	 *
	 * A site that keeps one menu for every language turns the whole module off
	 * through `shapetranslate_translate_navigation_menus` rather than by unticking a
	 * row, because the type is not offered on the settings screen: it holds no
	 * content a site owner recognizes as theirs, only the menus the Site Editor
	 * writes for them.
	 *
	 * @param mixed $settings Normalized ShapeTranslate configuration.
	 * @return mixed
	 */
	public function enable_menu_translation( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$content    = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$post_types = isset( $content['post_types'] ) && is_array( $content['post_types'] ) ? $content['post_types'] : array();

		if ( in_array( self::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = self::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Opens a navigation scope and points the block at this language's menu.
	 *
	 * @param mixed $parsed_block Block about to render.
	 * @return mixed
	 */
	public function open_listing_scope( $parsed_block ) {
		$name = $this->block_name( $parsed_block );

		if ( ! in_array( $name, self::LISTING_BLOCKS, true ) ) {
			return $parsed_block;
		}

		$this->listing_scope[] = $name;

		if ( 'core/navigation' === $name ) {
			$parsed_block = $this->translate_menu_reference( $parsed_block );
		}

		return $parsed_block;
	}

	/**
	 * Closes the scope the finished block opened.
	 *
	 * The name is matched rather than counted. A navigation block renders its own
	 * inner blocks directly, so the page list inside one reaches `render_block`
	 * without ever having reached `render_block_data`, and a plain counter would
	 * close the navigation scope while the navigation is still inside it.
	 *
	 * @param mixed $block_content Rendered block markup.
	 * @param mixed $parsed_block  Block that finished rendering.
	 * @return mixed
	 */
	public function close_listing_scope( $block_content, $parsed_block ) {
		$name = $this->block_name( $parsed_block );

		if ( '' !== $name && ! empty( $this->listing_scope ) && end( $this->listing_scope ) === $name ) {
			array_pop( $this->listing_scope );
		}

		return $block_content;
	}

	/**
	 * Lets a listing inside a navigation block fall back to the originals.
	 *
	 * @param mixed $fallback Whether the query falls back so far.
	 * @param mixed $query    Query being constrained.
	 * @return bool
	 */
	public function answer_listing_fallback( $fallback, $query ) {
		if ( $fallback || empty( $this->listing_scope ) || ! $query instanceof WP_Query ) {
			return (bool) $fallback;
		}

		/**
		 * Filters whether a navigation listing falls back to untranslated originals.
		 *
		 * Returning false leaves a header's page list showing only what this
		 * language has, which is the exact behavior for a site that would rather
		 * show a short menu than a mixed one.
		 *
		 * @param bool     $fallback Whether the originals are included.
		 * @param WP_Query $query    Listing being constrained.
		 */
		return (bool) apply_filters( 'shapetranslate_navigation_listing_fallback', true, $query );
	}

	/**
	 * Starts a menu written in the Site Editor in the language it was written in.
	 *
	 * Without this every menu would begin in the default language, including the
	 * one somebody just created while editing the Bengali header, and it would
	 * then have to be moved by hand before it could be used where it was made.
	 *
	 * @param int   $post_id Saved post identifier.
	 * @param mixed $post    Saved post.
	 * @return void
	 */
	public function assign_editor_language( $post_id, $post ) {
		if (
			! $post instanceof WP_Post
			|| self::POST_TYPE !== $post->post_type
			|| 'auto-draft' === $post->post_status
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| $this->post_translations->is_creating_translation()
			|| ! $this->post_translations->supports_post_type( $post->post_type )
			|| '' !== $this->post_translations->get_group_id( $post_id )
		) {
			return;
		}

		$language_id = $this->url_manager->background()->resolve_language_id();

		if ( '' === $language_id ) {
			/*
			 * A menu written on the front end, which is WordPress rather than an
			 * editor: a language with no menu of its own makes core build one
			 * while the page renders. Left unassigned it belongs to the default
			 * language, so the reader it was built for does not find it, and the
			 * next request builds another — a menu for every page view. It
			 * belongs to the language that needed it.
			 */
			$language = $this->url_manager->get_current_language();
			$language_id = is_array( $language ) && isset( $language['id'] ) ? (string) $language['id'] : '';
		}

		if ( '' !== $language_id ) {
			$this->post_translations->set_post_language( $post_id, $language_id );
		}
	}

	/**
	 * Sends a menu's edit link to the Site Editor, which is where menus are edited.
	 *
	 * The post type has no administrative screen of its own, so core answers with
	 * nothing and every ShapeTranslate list that offers to open a menu offers a link
	 * that goes nowhere.
	 *
	 * @param mixed $link    Edit link core produced.
	 * @param mixed $post_id Post the link points at.
	 * @return mixed
	 */
	public function filter_menu_edit_link( $link, $post_id ) {
		$post_id = absint( $post_id );

		if ( '' !== (string) $link || 0 === $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $link;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $link;
		}

		return admin_url(
			'site-editor.php?' . http_build_query(
				array(
					'postType' => self::POST_TYPE,
					'postId'   => $post_id,
					'canvas'   => 'edit',
				)
			)
		);
	}

	/**
	 * Points a navigation block at this language's version of its menu.
	 *
	 * @param array<string, mixed> $parsed_block Navigation block about to render.
	 * @return array<string, mixed>
	 */
	private function translate_menu_reference( array $parsed_block ) {
		if ( ! $this->translating_menus() || ! $this->is_rendering_for_readers() ) {
			return $parsed_block;
		}

		$reference = isset( $parsed_block['attrs']['ref'] ) && is_numeric( $parsed_block['attrs']['ref'] )
			? absint( $parsed_block['attrs']['ref'] )
			: 0;

		if ( 0 === $reference ) {
			return $parsed_block;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language || ! isset( $language['id'] ) ) {
			return $parsed_block;
		}

		$translation = absint( $this->post_translations->get_translation( $reference, (string) $language['id'] ) );

		/**
		 * Filters the menu a navigation block renders in one language.
		 *
		 * Zero, or the reference it was given, leaves the block pointing at the
		 * menu the template named.
		 *
		 * @param int    $translation Menu chosen for this language.
		 * @param int    $reference   Menu the template named.
		 * @param string $language_id Language being read.
		 */
		$translation = (int) apply_filters(
			'shapetranslate_navigation_menu_id',
			$translation,
			$reference,
			(string) $language['id']
		);

		if ( $translation <= 0 || $translation === $reference || 'publish' !== get_post_status( $translation ) ) {
			return $parsed_block;
		}

		$parsed_block['attrs']['ref'] = $translation;

		return $parsed_block;
	}

	/**
	 * Reports whether this render is the one a reader sees.
	 *
	 * The Site Editor renders a navigation block to edit it, and an editor who
	 * opened one menu has to be shown that menu rather than whichever one the
	 * language would have chosen for them.
	 *
	 * @return bool
	 */
	private function is_rendering_for_readers() {
		return ! is_admin()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Returns the name of a parsed block.
	 *
	 * @param mixed $parsed_block Parsed block.
	 * @return string
	 */
	private function block_name( $parsed_block ) {
		return is_array( $parsed_block ) && isset( $parsed_block['blockName'] ) && is_string( $parsed_block['blockName'] )
			? $parsed_block['blockName']
			: '';
	}

	/**
	 * Reports whether a block theme's menus are translated on this site.
	 *
	 * @return bool
	 */
	private function translating_menus() {
		/**
		 * Filters whether block theme navigation menus are translated.
		 *
		 * Returning false leaves one menu for every language. The page list
		 * fallback stays in place either way, because a header that leads nowhere
		 * is a problem a shared menu does not solve.
		 *
		 * @param bool $translate Whether navigation menus are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_translate_navigation_menus', true );
	}
}
