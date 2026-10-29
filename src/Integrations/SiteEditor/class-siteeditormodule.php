<?php
/**
 * WordPress Site Editor integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Block_Type;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a block theme's header and footer in the language being read.
 *
 * A block theme names its header inside the template, as
 * `<!-- wp:template-part {"slug":"header"} /-->`, and WordPress turns that slug
 * into a part with one query. That query is where a language can be applied,
 * because it is the moment the choice is made and the only moment at which both
 * candidates are still available.
 *
 * So the query is asked for both: the slug in the language being read and the
 * slug as the template wrote it, ordered so the translation wins. A language
 * nobody has written this part for therefore renders the part the site already
 * had, in one query, with no second lookup and nothing to cache.
 *
 * Swapping the part swaps everything inside it at once, which is why the header
 * is the only thing that needs translating: the navigation, the logo, and the
 * switcher inside it come along.
 */
final class SiteEditorModule implements ModuleInterface {

	/**
	 * Template part language resolution.
	 *
	 * @var TemplateParts
	 */
	private $parts;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param TemplateParts      $parts       Template part translation service.
	 * @param LanguageUrlManager $url_manager Language URL service.
	 */
	public function __construct( TemplateParts $parts, LanguageUrlManager $url_manager ) {
		$this->parts       = $parts;
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->parts->is_available() ) {
			return;
		}

		add_filter( 'shapetranslate_non_public_post_types', array( $this, 'declare_post_type' ) );
		add_filter( 'shapetranslate_supported_post_types', array( $this, 'support_post_type' ) );
		add_filter( 'shapetranslate_settings', array( $this, 'enable_part_translation' ) );

		add_action( 'pre_get_posts', array( $this, 'resolve_template_part_query' ), 10000 );
		add_filter( 'shapetranslate_language_rest_routes', array( $this, 'keep_parts_unfiltered' ) );
		add_filter( 'shapetranslate_editor_language_id', array( $this, 'answer_editor_language' ), 10, 2 );
		add_filter( 'get_block_type_variations', array( $this, 'hide_translated_variations' ), 10, 2 );
	}

	/**
	 * Declares the template part post type eligible for translation.
	 *
	 * @param mixed $post_types Post types ShapeTranslate may translate.
	 * @return mixed
	 */
	public function declare_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		$post_types[] = TemplateParts::POST_TYPE;

		return $post_types;
	}

	/**
	 * Adds the template part post type to the translatable list.
	 *
	 * @param mixed $post_types Supported post type names.
	 * @return mixed
	 */
	public function support_post_type( $post_types ) {
		if ( ! is_array( $post_types ) || ! $this->translating_parts() ) {
			return $post_types;
		}

		$post_types[] = TemplateParts::POST_TYPE;

		return $post_types;
	}

	/**
	 * Selects template parts in the translatable types a site has chosen.
	 *
	 * The type is offered on the settings screen like any other, and a site that
	 * would rather keep one header for every language unticks it there. It starts
	 * selected because until it is, nothing else in this module has anything to
	 * work with: parts carry no language, so there is nothing to resolve.
	 *
	 * @param mixed $settings Normalized ShapeTranslate configuration.
	 * @return mixed
	 */
	public function enable_part_translation( $settings ) {
		if ( ! is_array( $settings ) || ! $this->translating_parts() ) {
			return $settings;
		}

		$content    = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$post_types = isset( $content['post_types'] ) && is_array( $content['post_types'] ) ? $content['post_types'] : array();

		if ( in_array( TemplateParts::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = TemplateParts::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Asks a template part query for this language's part and the original.
	 *
	 * WordPress looks a part up by name, so the language is applied by widening
	 * the names asked for rather than by narrowing the result. `post_name__in`
	 * ordering is what makes the fallback free: the translation is listed first
	 * and the query takes one row, so a part that has no translation returns the
	 * one the template named without a second query ever running.
	 *
	 * The language constraint ShapeTranslate applies to ordinary content is turned
	 * off for this query. It would answer with nothing at all for a part in
	 * another language, and nothing is a missing header rather than a fallback.
	 *
	 * @param WP_Query $query Query about to run.
	 * @return void
	 */
	public function resolve_template_part_query( $query ) {
		if ( ! $query instanceof WP_Query || ! $this->is_part_name_query( $query ) ) {
			return;
		}

		// Whatever this query answers, it answers about one named part, so the
		// language must never be allowed to remove it.
		$query->set( 'shapetranslate_skip_language_filter', true );

		if ( $query->get( 'shapetranslate_exact_part' ) ) {
			return;
		}

		$language_id = $this->rendering_language_id();
		$default_id  = $this->parts->default_language_id();

		if ( '' === $language_id || $language_id === $default_id ) {
			return;
		}

		$slugs     = array();
		$widened   = false;
		$requested = (array) $query->get( 'post_name__in' );

		foreach ( $requested as $slug ) {
			$slug = (string) $slug;

			if ( '' === $slug ) {
				continue;
			}

			$translated = $this->parts->slug_in_language( $slug, $language_id );

			if ( $translated !== $slug ) {
				$slugs[] = $translated;
				$widened = true;
			}

			$slugs[] = $slug;
		}

		if ( ! $widened ) {
			return;
		}

		/**
		 * Filters the template part names one request may be answered with.
		 *
		 * @param array<int, string> $slugs       Names in preference order.
		 * @param array<int, string> $requested   Names the template asked for.
		 * @param string             $language_id Language being rendered.
		 */
		$slugs = apply_filters( 'shapetranslate_template_part_slugs', $slugs, $requested, $language_id );

		if ( ! is_array( $slugs ) || empty( $slugs ) ) {
			return;
		}

		$query->set( 'post_name__in', array_values( array_unique( $slugs ) ) );
		$query->set( 'orderby', 'post_name__in' );
	}

	/**
	 * Keeps the Site Editor's own part listing whole.
	 *
	 * Every other translatable type is narrowed to one language in the editor,
	 * which is what stops an editor being offered another language's pages. A
	 * template part listing is the opposite case: it is the screen on which a
	 * site manages all of its languages at once, so narrowing it would hide the
	 * translations from the only place they can be reached.
	 *
	 * @param mixed $routes REST routes the editor tags with a language.
	 * @return mixed
	 */
	public function keep_parts_unfiltered( $routes ) {
		if ( ! is_array( $routes ) ) {
			return $routes;
		}

		$object = get_post_type_object( TemplateParts::POST_TYPE );
		$base   = is_object( $object ) && ! empty( $object->rest_base )
			? (string) $object->rest_base
			: TemplateParts::POST_TYPE;

		return array_values( array_diff( $routes, array( 'wp/v2/' . $base ) ) );
	}

	/**
	 * Tells the editor which language the open template part belongs to.
	 *
	 * Without this the Site Editor has no language at all, and every list it
	 * offers — the pages a link may point at, the terms in a panel — arrives
	 * holding every language at once. Someone editing the Bengali header is then
	 * offered the English pages beside the Bengali ones, with nothing on screen
	 * saying which is which.
	 *
	 * @param mixed $language_id Language the editor reported so far.
	 * @param mixed $context     Block editor context, where one exists.
	 * @return mixed
	 */
	public function answer_editor_language( $language_id, $context = null ) {
		unset( $context );

		if ( is_string( $language_id ) && '' !== $language_id ) {
			return $language_id;
		}

		$slug = $this->parts->get_edited_slug();

		return '' === $slug ? $language_id : $this->parts->get_language_id( $slug );
	}

	/**
	 * Offers the Site Editor's listings what has not been translated yet.
	 *
	 * Not hooked up. It is what `shapetranslate_editor_language_fallback` does when
	 * a site turns it on, and it is kept here because the reasoning is worth
	 * keeping with the code that would act on it:
	 *
	 *     add_filter( 'shapetranslate_editor_language_fallback', array(
	 *         $site_editor_module, 'offer_untranslated_originals'
	 *     ) );
	 *
	 * The editor lists one language, the way the post editor does, so a Bengali
	 * menu is built out of Bengali pages and nothing else. Offering the English
	 * ones beside them reads as help and is not: an editor picks one, and the
	 * Bengali header now carries an English page, which nothing later corrects.
	 * Where a language has nothing yet, the list is empty and says so, and the
	 * answer to that is to write the page, not to be handed another language's.
	 *
	 * The front end is the opposite case and keeps its own fallback: a reader on
	 * a Bengali page is not choosing anything, and a header that came back empty
	 * is not a header with nothing to say — it is a page with no way off it.
	 *
	 * @param mixed $fallback Whether the originals are offered so far.
	 * @return bool
	 */
	public function offer_untranslated_originals( $fallback ) {
		return $fallback || $this->parts->is_site_editor();
	}

	/**
	 * Hides translated parts from the template part block's variations.
	 *
	 * WordPress offers every template part as its own variation in the inserter,
	 * so a site with three languages would offer `header`, `header___bn`, and
	 * `header___es` as three things to insert. Two of them are the same header,
	 * and inserting one writes a language into the template, which is the one
	 * place the language must not be written: the template is shared.
	 *
	 * Only the instances are dropped. The area variations — header, footer,
	 * general — are how a part is inserted at all, and they stay.
	 *
	 * @param mixed $variations Registered block variations.
	 * @param mixed $block_type Block type being described.
	 * @return mixed
	 */
	public function hide_translated_variations( $variations, $block_type ) {
		if (
			! is_array( $variations )
			|| ! $block_type instanceof WP_Block_Type
			|| 'core/template-part' !== $block_type->name
		) {
			return $variations;
		}

		foreach ( $variations as $index => $variation ) {
			if ( ! isset( $variation['attributes']['slug'], $variation['attributes']['theme'] ) ) {
				continue;
			}

			if ( $this->parts->read_slug( $variation['attributes']['slug'] )->has_language() ) {
				unset( $variations[ $index ] );
			}
		}

		return array_values( $variations );
	}

	/**
	 * Reports whether a query looks up template parts by name.
	 *
	 * @param WP_Query $query Query about to run.
	 * @return bool
	 */
	private function is_part_name_query( WP_Query $query ) {
		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) && 1 === count( $post_type ) ) {
			$post_type = reset( $post_type );
		}

		if ( TemplateParts::POST_TYPE !== $post_type ) {
			return false;
		}

		$names = $query->get( 'post_name__in' );

		return is_array( $names ) && ! empty( $names );
	}

	/**
	 * Returns the language a part is being rendered for.
	 *
	 * The Site Editor renders parts to edit them, and an editor who opened
	 * `header` has to be shown `header`; the editor says which language it is
	 * working in through its own address, not through the language being read.
	 *
	 * @return string
	 */
	private function rendering_language_id() {
		if ( $this->parts->is_site_editor() ) {
			return '';
		}

		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}

		$language = $this->url_manager->get_current_language();

		/**
		 * Filters the language a template part is rendered for.
		 *
		 * @param string $language_id Language identifier, or an empty string.
		 */
		$filtered = apply_filters(
			'shapetranslate_template_part_language',
			null === $language ? '' : (string) $language['id']
		);

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Reports whether template parts are translated on this site.
	 *
	 * @return bool
	 */
	private function translating_parts() {
		/**
		 * Filters whether block theme template parts are translated.
		 *
		 * Returning false leaves the post type untranslatable, so a site keeps one
		 * header and footer for every language.
		 *
		 * @param bool $translate Whether template parts are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_translate_template_parts', true );
	}
}
