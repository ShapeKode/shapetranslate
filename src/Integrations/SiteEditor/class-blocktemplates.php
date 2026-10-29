<?php
/**
 * Language state shared by every block theme template type.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\LanguageManager;
use WP_Block_Template;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the state that says which language a block template is in.
 *
 * A block theme holds two kinds of thing that render: the templates that answer
 * a request, and the parts a template names inside itself. They differ in how
 * WordPress picks one — a template is chosen by walking a hierarchy, a part is
 * chosen by the slug written into the block — but they are the same thing to
 * translate: a post of a theme-scoped type, chosen by name, that a site may have
 * only as a file until someone edits it.
 *
 * So the language lives in the same two places for both. It is an assignment,
 * the way a post's language is, and it is written into the slug as well, because
 * the slug is the only thing either mechanism looks at — see TemplateSlug for
 * what that buys. A template in the default language keeps the name the theme
 * gave it and every other language suffixes it, which is what lets a language
 * nobody has translated it for fall back to what the site already had.
 *
 * Two consequences of a theme being allowed to ship one of these as a file. A
 * file has no post, so there is nothing to hold a language and nothing for a
 * translation to point back at; ensure_post() turns the file into the post the
 * group needs, which is what the Site Editor does the first time anyone edits
 * it. And a translation is published rather than drafted, because WordPress
 * renders published templates only: a drafted translation would render nothing
 * where a fallback was wanted.
 *
 * Subclasses say which post type they are, where their REST routes live, and how
 * to tell an editor in their own words that something cannot be done. Everything
 * else is here.
 */
abstract class BlockTemplates {

	/**
	 * Taxonomy recording which theme a template belongs to.
	 */
	const THEME_TAXONOMY = 'wp_theme';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	protected $language_manager;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	protected $post_translations;

	/**
	 * Templates of the active theme, keyed by slug.
	 *
	 * @var array<string, WP_Block_Template>|null
	 */
	private $templates;

	/**
	 * Template posts found one slug at a time, including the misses.
	 *
	 * @var array<string, WP_Post|null>
	 */
	private $posts = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct( LanguageManager $language_manager, PostTranslationManager $post_translations ) {
		$this->language_manager  = $language_manager;
		$this->post_translations = $post_translations;
	}

	/**
	 * Returns the post type holding this kind of template.
	 *
	 * @return string
	 */
	abstract public function post_type();

	/**
	 * Returns the REST path segment this kind of template answers on.
	 *
	 * @return string
	 */
	abstract public function route_base();

	/**
	 * Returns the sentences an editor is shown when something cannot be done.
	 *
	 * Whole sentences rather than a noun dropped into a template, because the
	 * words around a noun change with the language a site is translated into, and
	 * a translator handed half a sentence cannot fix that.
	 *
	 * @return array<string, string>
	 */
	abstract protected function messages();

	/**
	 * Returns one of this type's messages.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public function message( $key ) {
		$messages = $this->messages();

		return isset( $messages[ $key ] ) ? $messages[ $key ] : '';
	}

	/**
	 * Reads whatever a subclass keeps about a template outside its post row.
	 *
	 * Read before the new post is written rather than after, because writing one
	 * is what makes the source stop being the thing that slug names. A part
	 * created from a theme's file would otherwise be asked about its area once
	 * the post had taken the file's place, and be told about the post.
	 *
	 * @param string $source_slug Slug the template is being made from.
	 * @return array<string, mixed>
	 */
	protected function carry_from( $source_slug ) {
		unset( $source_slug );

		return array();
	}

	/**
	 * Gives a written template whatever was read from the one it came from.
	 *
	 * @param int                  $post_id Template post identifier.
	 * @param array<string, mixed> $carried What carry_from() read.
	 * @return void
	 */
	protected function apply_carried( $post_id, array $carried ) {
		unset( $post_id, $carried );
	}

	/**
	 * Reports whether the active theme renders through the Site Editor.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		/**
		 * Filters whether the Site Editor integration is available.
		 *
		 * A hybrid theme that registers template parts without being a block
		 * theme can turn this on, and a block theme that would rather keep one
		 * header for every language can turn it off.
		 *
		 * @param bool $available Whether the active theme is a block theme.
		 */
		return (bool) apply_filters( 'shapetranslate_site_editor_available', $available );
	}

	/**
	 * Returns the stylesheet templates are namespaced to.
	 *
	 * @return string
	 */
	public function theme() {
		return (string) get_stylesheet();
	}

	/**
	 * Returns the identifiers of every language the site registers.
	 *
	 * @return array<int, string>
	 */
	public function language_ids() {
		$ids = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( isset( $language['id'] ) && '' !== (string) $language['id'] ) {
				$ids[] = (string) $language['id'];
			}
		}

		return $ids;
	}

	/**
	 * Returns the identifier of the site's default language.
	 *
	 * @return string
	 */
	public function default_language_id() {
		return (string) $this->language_manager->get_default_id();
	}

	/**
	 * Reads one slug's language and base name.
	 *
	 * @param string $slug Template slug.
	 * @return TemplateSlug
	 */
	public function read_slug( $slug ) {
		return new TemplateSlug( $slug, $this->language_ids() );
	}

	/**
	 * Returns the slug a template would carry in one language.
	 *
	 * @param string $slug        Template slug, translated or not.
	 * @param string $language_id Target language identifier.
	 * @return string
	 */
	public function slug_in_language( $slug, $language_id ) {
		return $this->read_slug( $slug )->in_language( $language_id, $this->default_language_id() );
	}

	/**
	 * Returns the template post one slug names in the active theme.
	 *
	 * Asked one slug at a time, because the frontend asks it on every page that
	 * renders a header and listing every template means a query plus a scan of
	 * the theme's directory to answer about one of them.
	 *
	 * @param string $slug Template slug.
	 * @return WP_Post|null
	 */
	public function find_post( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug ) {
			return null;
		}

		if ( ! array_key_exists( $slug, $this->posts ) ) {
			$query = new WP_Query(
				array(
					'post_type'                        => $this->post_type(),
					'post_status'                      => array( 'publish', 'draft' ),
					'post_name__in'                    => array( $slug ),
					'posts_per_page'                   => 1,
					'no_found_rows'                    => true,
					'ignore_sticky_posts'              => true,
					'lazy_load_term_meta'              => false,
					'shapetranslate_skip_language_filter' => true,
					/*
					 * This asks about the template with exactly this name. Without
					 * the marker the resolver would widen it to the language being
					 * read, and a question about `header` would be answered with
					 * `header___bn` — which is how a rename decides the wrong part
					 * is in its way.
					 */
					'shapetranslate_exact_part'           => true,
					'tax_query'                        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => self::THEME_TAXONOMY,
							'field'    => 'name',
							'terms'    => $this->theme(),
						),
					),
				)
			);

			$found                = $query->have_posts() ? $query->posts[0] : null;
			$this->posts[ $slug ] = $found instanceof WP_Post ? $found : null;
		}

		return $this->posts[ $slug ];
	}

	/**
	 * Returns every template of this type the active theme can render.
	 *
	 * Both the ones the theme ships as files and the ones the site authored or
	 * customized, because either may be what a request is answered with.
	 *
	 * @return array<string, WP_Block_Template>
	 */
	public function get_all() {
		if ( null !== $this->templates ) {
			return $this->templates;
		}

		$this->templates = array();

		if ( ! function_exists( 'get_block_templates' ) ) {
			return $this->templates;
		}

		foreach ( get_block_templates( array(), $this->post_type() ) as $template ) {
			if ( ! $template instanceof WP_Block_Template ) {
				continue;
			}

			$slug = $this->sanitize_slug( isset( $template->slug ) ? $template->slug : '' );

			if ( '' !== $slug && ! isset( $this->templates[ $slug ] ) ) {
				$this->templates[ $slug ] = $template;
			}
		}

		return $this->templates;
	}

	/**
	 * Returns the templates that are not themselves translations.
	 *
	 * A translation is not something a template or a site names — the slug that
	 * gets written stays the untranslated one — so listing it as a source would
	 * invite a site to translate a translation.
	 *
	 * @return array<string, WP_Block_Template>
	 */
	public function get_sources() {
		$sources = array();

		foreach ( $this->get_all() as $slug => $template ) {
			if ( ! $this->read_slug( $slug )->has_language() ) {
				$sources[ $slug ] = $template;
			}
		}

		return $sources;
	}

	/**
	 * Returns one template of the active theme, file or post.
	 *
	 * @param string $slug Template slug.
	 * @return WP_Block_Template|null
	 */
	public function find( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug ) {
			return null;
		}

		if ( null !== $this->templates ) {
			return isset( $this->templates[ $slug ] ) ? $this->templates[ $slug ] : null;
		}

		$template = function_exists( 'get_block_template' )
			? get_block_template( $this->theme() . '//' . $slug, $this->post_type() )
			: null;

		return $template instanceof WP_Block_Template ? $template : null;
	}

	/**
	 * Reports whether the active theme can render a template.
	 *
	 * @param string $slug Template slug.
	 * @return bool
	 */
	public function exists( $slug ) {
		return null !== $this->find( $slug );
	}

	/**
	 * Returns the title the theme or the site gave one template.
	 *
	 * @param string $slug Template slug.
	 * @return string The slug itself when nothing better is recorded.
	 */
	public function get_title( $slug ) {
		$template = $this->find( $slug );

		if ( null === $template || empty( $template->title ) ) {
			return (string) $slug;
		}

		return (string) $template->title;
	}

	/**
	 * Reports whether the theme ships this slug as a file of its own.
	 *
	 * The question matters whenever a slug is about to be renamed away. A file
	 * goes on answering for the name after the post has left it, and a name with
	 * nothing behind it renders nothing at all.
	 *
	 * @param string $slug Template slug.
	 * @return bool
	 */
	public function has_theme_file( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug || ! function_exists( 'get_block_theme_folders' ) ) {
			return false;
		}

		$folders = get_block_theme_folders();
		$folder  = isset( $folders[ $this->post_type() ] ) ? (string) $folders[ $this->post_type() ] : '';

		if ( '' === $folder ) {
			return false;
		}

		$directories = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );

		foreach ( $directories as $directory ) {
			if ( file_exists( $directory . '/' . $folder . '/' . $slug . '.html' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the language one template belongs to.
	 *
	 * The assignment is the answer where there is one. A template the Site Editor
	 * created a moment ago may not have been given one yet, and the slug still
	 * says what it is, so the name is read as a second opinion rather than
	 * treated as nothing.
	 *
	 * @param string $slug Template slug.
	 * @return string Empty when the template is unknown.
	 */
	public function get_language_id( $slug ) {
		$slug = $this->sanitize_slug( $slug );
		$post = $this->find_post( $slug );

		if ( $post instanceof WP_Post ) {
			$assigned = $this->post_translations->get_post_language_id( $post->ID );

			if ( '' !== $assigned ) {
				return $assigned;
			}
		}

		$read = $this->read_slug( $slug );

		if ( $read->has_language() ) {
			return $read->language();
		}

		return '' === $slug ? '' : $this->default_language_id();
	}

	/**
	 * Returns every language's version of one template, keyed by language.
	 *
	 * @param string $slug Template slug, translated or not.
	 * @return array<string, string> Slugs by language identifier.
	 */
	public function get_translations( $slug ) {
		$base         = $this->read_slug( $slug )->base();
		$translations = array();

		if ( '' === $base ) {
			return $translations;
		}

		$post = $this->find_post( $base );

		if ( $post instanceof WP_Post ) {
			foreach ( $this->post_translations->get_translations( $post->ID ) as $language_id => $post_id ) {
				$translated = get_post( absint( $post_id ) );

				if ( $translated instanceof WP_Post && $this->post_type() === $translated->post_type ) {
					$translations[ (string) $language_id ] = (string) $translated->post_name;
				}
			}
		}

		/*
		 * The group answers for everything it holds, and the theme's own file
		 * answers for the default language when no post has been made for it yet.
		 * Without that a site would be told its header has no English version,
		 * which is untrue of every block theme ever shipped.
		 */
		$default = $this->default_language_id();

		if ( '' !== $default && ! isset( $translations[ $default ] ) && $this->exists( $base ) ) {
			$translations[ $default ] = $base;
		}

		foreach ( $this->language_ids() as $language_id ) {
			if ( isset( $translations[ $language_id ] ) ) {
				continue;
			}

			$candidate = $this->slug_in_language( $base, $language_id );

			if ( $candidate !== $base && $this->exists( $candidate ) ) {
				$translations[ $language_id ] = $candidate;
			}
		}

		return $translations;
	}

	/**
	 * Returns the post behind one slug, writing it from the theme's file if need be.
	 *
	 * A theme's own file is not a post, so there is nothing to attach a language
	 * to and nothing for a translation to point back at. Creating the post from
	 * the file is what the Site Editor does the first time someone edits a
	 * template, and the content is copied verbatim, so the site renders exactly
	 * what it rendered before.
	 *
	 * No language is assigned here. Every caller knows which language it means,
	 * and some of them mean one other than the default.
	 *
	 * @param string $slug Template slug.
	 * @return int|WP_Error Post identifier.
	 */
	public function ensure_post( $slug ) {
		$slug = $this->sanitize_slug( $slug );
		$post = $this->find_post( $slug );

		if ( $post instanceof WP_Post ) {
			return $post->ID;
		}

		$template = $this->find( $slug );

		if ( null === $template ) {
			return new WP_Error( 'shapetranslate_part_not_found', $this->message( 'not_found' ) );
		}

		return $this->insert_template(
			$slug,
			isset( $template->title ) ? (string) $template->title : $slug,
			isset( $template->content ) ? (string) $template->content : '',
			isset( $template->description ) ? (string) $template->description : '',
			$slug
		);
	}

	/**
	 * Returns the post a translation group should be built from.
	 *
	 * @param string $slug Template slug in the default language.
	 * @return int|WP_Error Post identifier.
	 */
	public function ensure_source_post( $slug ) {
		$post_id = $this->ensure_post( $slug );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->assign_default_language( $post_id );

		return $post_id;
	}

	/**
	 * Creates one language's version of a template.
	 *
	 * @param string $slug        Template slug in the default language.
	 * @param string $language_id Target language identifier.
	 * @return int|WP_Error Created post identifier.
	 */
	public function create_translation( $slug, $language_id ) {
		$slug        = $this->sanitize_slug( $slug );
		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';
		$read        = $this->read_slug( $slug );

		if ( $read->has_language() ) {
			return new WP_Error( 'shapetranslate_part_is_translation', $this->message( 'is_translation' ) );
		}

		if ( null === $this->language_manager->find( $language_id ) ) {
			return new WP_Error(
				'shapetranslate_language_not_found',
				__( 'The selected language is not registered.', 'shapetranslate' )
			);
		}

		$source_id = $this->ensure_source_post( $slug );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		if ( $language_id === $this->default_language_id() ) {
			return $source_id;
		}

		$existing = absint( $this->post_translations->get_translation( $source_id, $language_id ) );

		if ( 0 !== $existing && $existing !== $source_id ) {
			return new WP_Error( 'shapetranslate_part_exists', $this->message( 'exists' ) );
		}

		$source = get_post( $source_id );

		if ( ! $source instanceof WP_Post ) {
			return new WP_Error( 'shapetranslate_part_not_found', $this->message( 'unreadable' ) );
		}

		$target_slug = $this->slug_in_language( $slug, $language_id );

		if ( $this->exists( $target_slug ) ) {
			return new WP_Error( 'shapetranslate_part_slug_taken', $this->message( 'slug_taken' ) );
		}

		/**
		 * Filters the content a language's version of a template starts from.
		 *
		 * The source's content, so the first thing an editor sees is the header
		 * the site already has with only the words left to change.
		 *
		 * @param string  $content     Block markup the translation starts with.
		 * @param WP_Post $source      Source template.
		 * @param string  $language_id Target language identifier.
		 */
		$content = (string) apply_filters(
			'shapetranslate_template_part_content',
			(string) $source->post_content,
			$source,
			$language_id
		);

		$post_id = $this->insert_template(
			$target_slug,
			$this->translation_title( $source, $language_id ),
			$content,
			(string) $source->post_excerpt,
			$slug
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$linked = $this->join_group( $source_id, $post_id, $language_id );

		if ( is_wp_error( $linked ) ) {
			wp_delete_post( $post_id, true );

			return $linked;
		}

		$this->forget();

		/**
		 * Fires after one language's version of a template has been created.
		 *
		 * @param int    $post_id     Created template identifier.
		 * @param int    $source_id   Template it was created from.
		 * @param string $language_id Language it belongs to.
		 */
		do_action( 'shapetranslate_template_part_created', $post_id, $source_id, $language_id );

		return $post_id;
	}

	/**
	 * Adopts a template the site already has as one language's version of another.
	 *
	 * The counterpart to creating a translation, for the site that got here
	 * first: a second header was built by hand, or duplicated in the Site Editor,
	 * and the only thing missing is the sentence saying it is the Bengali one.
	 * That sentence is the group, and once it is written the slug is made to
	 * agree with it — which renames the adopted template, because the slug is the
	 * only thing the renderer reads.
	 *
	 * @param string $slug        Slug of the template being translated.
	 * @param string $target_slug Slug of the template to adopt.
	 * @param string $language_id Language the adopted template is in.
	 * @return array<string, string>|WP_Error The adopted template's new slug.
	 */
	public function link_existing( $slug, $target_slug, $language_id ) {
		$base        = $this->read_slug( $slug )->base();
		$target_slug = $this->sanitize_slug( $target_slug );
		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';

		if ( '' === $base || '' === $target_slug ) {
			return new WP_Error( 'shapetranslate_part_not_found', $this->message( 'not_found' ) );
		}

		if ( $base === $target_slug ) {
			return new WP_Error( 'shapetranslate_link_self', $this->message( 'link_self' ) );
		}

		if ( null === $this->language_manager->find( $language_id ) ) {
			return new WP_Error(
				'shapetranslate_language_not_found',
				__( 'The selected language is not registered.', 'shapetranslate' )
			);
		}

		if ( ! $this->exists( $target_slug ) ) {
			return new WP_Error( 'shapetranslate_part_not_found', $this->message( 'not_found' ) );
		}

		if ( $this->read_slug( $target_slug )->has_language() ) {
			return new WP_Error( 'shapetranslate_part_is_translation', $this->message( 'link_translation' ) );
		}

		$source_id = $this->ensure_source_post( $base );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		$existing = absint( $this->post_translations->get_translation( $source_id, $language_id ) );

		if ( 0 !== $existing && $existing !== $source_id ) {
			return new WP_Error( 'shapetranslate_part_exists', $this->message( 'exists' ) );
		}

		$target_id = $this->ensure_post( $target_slug );

		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		$linked = $this->join_group( $source_id, $target_id, $language_id );

		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		/*
		 * The group is written; the name has to catch up. Renaming is what makes
		 * the adoption take effect, because nothing that renders a template reads
		 * the group — see TemplateSlug.
		 */
		$renamed = $this->rename_for_language( $target_id, $language_id );
		$this->forget();

		/** This action is documented in src/Integrations/SiteEditor/class-blocktemplates.php */
		do_action( 'shapetranslate_template_part_created', $target_id, $source_id, $language_id );

		return array(
			'slug'     => '' === $renamed ? $target_slug : $renamed,
			'language' => $language_id,
		);
	}

	/**
	 * Moves a template into another language.
	 *
	 * The assignment is changed and the slug follows it, which is the whole of
	 * the operation: the lifecycle module renames on the event this raises.
	 *
	 * One move is refused. The version in the default language is what every
	 * other language falls back to, so moving it away would leave its own
	 * translations pointing at a name nothing answers to — unless the theme ships
	 * a file under that name, which goes on answering after the post has left it.
	 *
	 * @param string $slug        Slug of the template to move.
	 * @param string $language_id Language to move it into.
	 * @return array<string, string>|WP_Error The template's new slug.
	 */
	public function change_language( $slug, $language_id ) {
		$slug        = $this->sanitize_slug( $slug );
		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';

		if ( null === $this->language_manager->find( $language_id ) ) {
			return new WP_Error(
				'shapetranslate_language_not_found',
				__( 'The selected language is not registered.', 'shapetranslate' )
			);
		}

		if ( ! $this->exists( $slug ) ) {
			return new WP_Error( 'shapetranslate_part_not_found', $this->message( 'not_found' ) );
		}

		if ( $language_id === $this->get_language_id( $slug ) ) {
			return array(
				'slug'     => $slug,
				'language' => $language_id,
			);
		}

		$target_slug = $this->slug_in_language( $slug, $language_id );

		if ( $target_slug !== $slug && $this->exists( $target_slug ) ) {
			return new WP_Error( 'shapetranslate_part_slug_taken', $this->message( 'slug_taken' ) );
		}

		$post_id = $this->ensure_post( $slug );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$allowed = $this->refuse_stranding_translations( $post_id, $slug );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$assigned = $this->post_translations->set_post_language( $post_id, $language_id );

		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		/*
		 * The lifecycle renames on the event a changed assignment raises, and a
		 * template that had no assignment at all raises none: it was given its
		 * first one rather than moved. Asked again here, because a slug that does
		 * not name the language is the one thing this operation cannot leave
		 * behind, and asking twice costs a comparison that already matches.
		 */
		$this->rename_for_language( $post_id, $language_id );
		$this->forget();

		$post = get_post( $post_id );

		return array(
			'slug'     => $post instanceof WP_Post ? (string) $post->post_name : $target_slug,
			'language' => $language_id,
		);
	}

	/**
	 * Reports whether a template may be moved into another language at all.
	 *
	 * Asked by the panel before it offers the choice, so a move that would be
	 * refused is not offered in the first place.
	 *
	 * @param string $slug Template slug.
	 * @return bool
	 */
	public function can_change_language( $slug ) {
		$post = $this->find_post( $slug );

		if ( ! $post instanceof WP_Post ) {
			return true;
		}

		return true === $this->refuse_stranding_translations( $post->ID, $slug );
	}

	/**
	 * Returns the templates that could be adopted as a translation.
	 *
	 * Everything of this type the theme can render, minus the translations, minus
	 * whatever this group already holds, and minus anything another group has
	 * already claimed — a template can only be one language's version of one
	 * thing.
	 *
	 * @param string $slug Slug of the template being translated.
	 * @return array<int, array<string, string>>
	 */
	public function link_candidates( $slug ) {
		$base = $this->read_slug( $slug )->base();

		if ( '' === $base ) {
			return array();
		}

		$taken      = array_flip( $this->get_translations( $base ) );
		$candidates = array();

		foreach ( $this->get_sources() as $candidate_slug => $template ) {
			if ( $candidate_slug === $base || isset( $taken[ $candidate_slug ] ) ) {
				continue;
			}

			if ( $this->belongs_to_a_group( $candidate_slug ) ) {
				continue;
			}

			$candidates[] = array(
				'slug'  => $candidate_slug,
				'title' => isset( $template->title ) && '' !== (string) $template->title
					? (string) $template->title
					: $candidate_slug,
			);
		}

		usort(
			$candidates,
			static function ( $first, $second ) {
				return strnatcasecmp( $first['title'], $second['title'] );
			}
		);

		return $candidates;
	}

	/**
	 * Renames a template so its slug names the language it belongs to.
	 *
	 * @param int    $post_id     Template identifier.
	 * @param string $language_id Language the template belongs to.
	 * @return string The slug the template now carries.
	 */
	public function rename_for_language( $post_id, $language_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof WP_Post || $this->post_type() !== $post->post_type ) {
			return '';
		}

		$target = $this->slug_in_language( $post->post_name, $language_id );

		if ( '' === $target || $target === $post->post_name ) {
			return (string) $post->post_name;
		}

		$conflict = $this->find_post( $target );

		if ( $conflict instanceof WP_Post && $conflict->ID !== $post->ID ) {
			return (string) $post->post_name;
		}

		wp_update_post(
			array(
				'ID'        => $post->ID,
				'post_name' => $target,
			)
		);

		$this->forget();

		return $target;
	}

	/**
	 * Reports whether the Site Editor is the screen being served.
	 *
	 * @return bool
	 */
	public function is_site_editor() {
		return isset( $GLOBALS['pagenow'] ) && 'site-editor.php' === $GLOBALS['pagenow'];
	}

	/**
	 * Returns the slug of this type the Site Editor was opened on.
	 *
	 * WordPress 6.8 replaced the `postType` and `postId` pair with a single `p`
	 * path, and both forms are still reached — a bookmarked address, or a link
	 * written by a plugin that has not caught up. Neither is trusted beyond its
	 * shape, and a template belonging to another theme is not this theme's to
	 * answer for.
	 *
	 * @return string Empty when the Site Editor is not on this type.
	 */
	public function get_edited_slug() {
		if ( ! $this->is_site_editor() ) {
			return '';
		}

		$template_id = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, and validated by shape below.
		if ( isset( $_GET['p'] ) && is_string( $_GET['p'] ) ) {
			$matches = array();

			if ( preg_match( '#^/' . $this->post_type() . '/(.+)$#', sanitize_text_field( wp_unslash( $_GET['p'] ) ), $matches ) ) {
				$template_id = $matches[1];
			}
		} elseif (
			isset( $_GET['postType'], $_GET['postId'] )
			&& $this->post_type() === sanitize_key( wp_unslash( $_GET['postType'] ) )
			&& is_string( $_GET['postId'] )
		) {
			$template_id = sanitize_text_field( wp_unslash( $_GET['postId'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$components = '' === $template_id ? array() : explode( '//', $template_id, 2 );

		if ( 2 !== count( $components ) || $components[0] !== $this->theme() ) {
			return '';
		}

		return $this->sanitize_slug( $components[1] );
	}

	/**
	 * Returns the Site Editor address of one template.
	 *
	 * @param string $slug Template slug.
	 * @return string Empty when the template cannot be edited.
	 */
	public function editor_url( $slug ) {
		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug || ! current_user_can( 'edit_theme_options' ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'p'      => '/' . $this->post_type() . '/' . rawurlencode( $this->theme() . '//' . $slug ),
				'canvas' => 'edit',
			),
			admin_url( 'site-editor.php' )
		);
	}

	/**
	 * Forgets what was read about the theme's templates.
	 *
	 * @return void
	 */
	public function forget() {
		$this->templates = null;
		$this->posts     = array();
	}

	/**
	 * Returns a value usable as a template slug.
	 *
	 * @param mixed $slug Candidate slug.
	 * @return string
	 */
	public function sanitize_slug( $slug ) {
		if ( ! is_scalar( $slug ) ) {
			return '';
		}

		$slug = sanitize_title( (string) $slug );

		return 0 === validate_file( $slug ) ? $slug : '';
	}

	/**
	 * Reports whether one slug's template already answers for another language.
	 *
	 * @param string $slug Template slug.
	 * @return bool
	 */
	protected function belongs_to_a_group( $slug ) {
		$post = $this->find_post( $slug );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return count( $this->post_translations->get_translations( $post->ID ) ) > 1;
	}

	/**
	 * Puts one template into another's translation group.
	 *
	 * @param int    $source_id   Template the group belongs to.
	 * @param int    $post_id     Template joining it.
	 * @param string $language_id Language the joining template is in.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function join_group( $source_id, $post_id, $language_id ) {
		/*
		 * The language the source actually holds, not the default. They are the
		 * same on every site that has not moved a template between languages, and
		 * on the one that has, linking against the default would be refused for
		 * contradicting the assignment the source already carries.
		 */
		$source_language = $this->post_translations->get_post_language_id( $source_id );

		if ( '' === $source_language ) {
			$source_language = $this->default_language_id();
		}

		if ( $source_language === $language_id ) {
			return new WP_Error( 'shapetranslate_link_default', $this->message( 'link_default' ) );
		}

		return $this->post_translations->link_translations(
			array(
				$source_language => $source_id,
				$language_id     => $post_id,
			),
			$source_id
		);
	}

	/**
	 * Refuses a move that would leave a group's translations unreachable.
	 *
	 * @param int    $post_id Template being moved.
	 * @param string $slug    Slug it currently carries.
	 * @return true|WP_Error
	 */
	protected function refuse_stranding_translations( $post_id, $slug ) {
		if ( $this->default_language_id() !== $this->post_translations->get_post_language_id( $post_id ) ) {
			return true;
		}

		$group = $this->post_translations->get_translations( $post_id );

		if ( count( $group ) < 2 || $this->has_theme_file( $this->read_slug( $slug )->base() ) ) {
			return true;
		}

		return new WP_Error( 'shapetranslate_part_is_fallback', $this->message( 'is_fallback' ) );
	}

	/**
	 * Writes one template post for the active theme.
	 *
	 * Published rather than drafted. WordPress renders published templates only,
	 * so a drafted translation would leave the location empty instead of falling
	 * back to what the site already had, which is worse than not translating at
	 * all.
	 *
	 * @param string $slug        Slug to write.
	 * @param string $title       Template title.
	 * @param string $content     Block markup.
	 * @param string $description Template description.
	 * @param string $source_slug Slug the template was made from.
	 * @return int|WP_Error
	 */
	protected function insert_template( $slug, $title, $content, $description, $source_slug ) {
		/*
		 * Saving a supported post type hands it the default language, which is
		 * right for everything an editor writes and wrong for exactly this: a
		 * template being written for another language would take the default's
		 * slot before it could be linked, and the link would then be refused for
		 * disagreeing with an assignment this made a moment earlier. Every caller
		 * says which language it means straight afterwards.
		 */
		$carried = $this->carry_from( (string) $source_slug );

		$suppress = static function () {
			return false;
		};

		add_filter( 'shapetranslate_auto_assign_default_post_language', $suppress, 99 );

		$post_id = wp_insert_post(
			array(
				'post_type'    => $this->post_type(),
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $description,
			),
			true
		);

		remove_filter( 'shapetranslate_auto_assign_default_post_language', $suppress, 99 );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_post_terms( $post_id, array( $this->theme() ), self::THEME_TAXONOMY );

		$this->apply_carried( absint( $post_id ), $carried );
		$this->forget();

		return absint( $post_id );
	}

	/**
	 * Gives a template the default language when it holds none.
	 *
	 * @param int $post_id Template identifier.
	 * @return void
	 */
	protected function assign_default_language( $post_id ) {
		if ( '' === $this->post_translations->get_post_language_id( absint( $post_id ) ) ) {
			$this->post_translations->assign_default_language( absint( $post_id ) );
		}
	}

	/**
	 * Returns the title one language's version of a template is created with.
	 *
	 * @param WP_Post $source      Source template.
	 * @param string  $language_id Target language identifier.
	 * @return string
	 */
	protected function translation_title( WP_Post $source, $language_id ) {
		$language = $this->language_manager->find( $language_id );
		$title    = '' !== (string) $source->post_title ? (string) $source->post_title : (string) $source->post_name;
		$label    = $language_id;

		if ( is_array( $language ) && ! empty( $language['native_name'] ) ) {
			$label = (string) $language['native_name'];
		}

		return sprintf(
			/* translators: 1: template title, 2: language name. */
			__( '%1$s (%2$s)', 'shapetranslate' ),
			$title,
			$label
		);
	}
}
