<?php
/**
 * Happy Elementor Addons Theme Builder integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\HappyAddons;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Happy Addons headers, footers and theme templates across languages.
 *
 * Three parts that only work together. Templates have to be translatable at all,
 * or a site has one header and no way to write a second. A translation has to
 * carry the template type and the display conditions — and be recorded where the
 * builder matches conditions from — or the copy is a layout that fills no
 * location. And the location has to be answered in the language being read, or
 * the builder keeps handing every reader the same header.
 *
 * The builder offers no hook over the templates a location matched, but it does
 * ask one question before it renders any of them: it puts the identifier through
 * `wpml_object_id`, the filter every multilingual plugin is expected to answer.
 * Every path it renders through — the theme-support header and footer, each
 * bundled theme adapter, the single and archive documents — passes through that
 * one call, so answering it is enough, and answering it for nothing but a
 * `ha_library` post is what keeps the answer from reaching anybody else's
 * content.
 */
final class HappyAddonsModule implements ModuleInterface {

	/**
	 * Page template on which the builder prints no header or footer.
	 */
	const CANVAS_TEMPLATE = 'elementor_canvas';

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var HappyAddonsTemplates
	 */
	private $templates;

	/**
	 * Post translation relationships.
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
	 * Resolved template identifiers, keyed by language and source template.
	 *
	 * @var array<string, int>
	 */
	private $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param HappyAddonsTemplates   $templates         Theme Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		HappyAddonsTemplates $templates,
		PostTranslationManager $post_translations,
		LanguageUrlManager $url_manager
	) {
		$this->templates         = $templates;
		$this->post_translations = $post_translations;
		$this->url_manager       = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() ) {
			return;
		}

		add_filter( 'shapetranslate_settings', array( $this, 'enable_template_translation' ) );
		add_filter( 'shapetranslate_filter_secondary_query_by_language', array( $this, 'skip_template_query' ), 10, 2 );

		add_filter( 'shapetranslate_elementor_copy_meta_keys', array( $this, 'add_copy_meta_keys' ), 10, 2 );
		add_filter( 'shapetranslate_elementor_copy_meta_value', array( $this, 'translate_copied_conditions' ), 10, 4 );
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_template_meta' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'record_published_template' ), 10, 3 );

		// Behind anything WPML itself would answer, so a site running both is left
		// with whichever identifier WPML resolved rather than two plugins
		// translating the same one in turn.
		add_filter( 'wpml_object_id', array( $this, 'filter_template_id' ), 20, 2 );

		// Elementor's own hook, fired once the frontend stylesheet is enqueued and
		// before it collects the styles of the documents being rendered. Both
		// halves of a template's styling are asked for there because that is the
		// last moment either one still reaches the document head.
		add_action( 'elementor/frontend/after_enqueue_styles', array( $this, 'prepare_template_styles' ) );
	}

	/**
	 * Makes builder templates translatable alongside the site's own content.
	 *
	 * The post type is public and carries an administrative UI, so it already
	 * appears in the translatable types list. It is selected here rather than left
	 * for a site owner to tick, because until it is ticked the rest of this module
	 * has nothing to work with: templates carry no language, so there is nothing
	 * to resolve and nothing to copy. The checkbox therefore reads as selected and
	 * stays that way; a site that would rather keep one set of headers and footers
	 * for every language says so through the filter below, which leaves the rest
	 * of the module inert on its own.
	 *
	 * @param mixed $settings Normalized ShapeTranslate configuration.
	 * @return mixed
	 */
	public function enable_template_translation( $settings ) {
		if ( ! is_array( $settings ) || ! $this->translating_templates() ) {
			return $settings;
		}

		$content    = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$post_types = isset( $content['post_types'] ) && is_array( $content['post_types'] ) ? $content['post_types'] : array();

		if ( in_array( HappyAddonsTemplates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = HappyAddonsTemplates::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Leaves the builder's own template queries unnarrowed.
	 *
	 * The builder reads every template at once, both to decide what a location
	 * may hold and to rebuild the option its conditions are matched from. Handing
	 * either of those one language's templates would write that language into a
	 * store every language then reads.
	 *
	 * @param mixed    $filter Whether language filtering should run.
	 * @param WP_Query $query  Secondary frontend query.
	 * @return mixed
	 */
	public function skip_template_query( $filter, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $filter;
		}

		$post_types = $query->get( 'post_type' );
		$post_types = array_values( array_unique( array_filter( (array) $post_types, 'is_string' ) ) );

		return array( HappyAddonsTemplates::POST_TYPE ) === $post_types ? false : $filter;
	}

	/**
	 * Adds the builder metadata a translated template needs.
	 *
	 * @param mixed $meta_keys      Elementor metadata keys about to be copied.
	 * @param int   $source_post_id Source post identifier.
	 * @return mixed
	 */
	public function add_copy_meta_keys( $meta_keys, $source_post_id ) {
		if ( ! is_array( $meta_keys ) || ! $this->is_template( $source_post_id ) ) {
			return $meta_keys;
		}

		return array_merge( $meta_keys, HappyAddonsTemplates::meta_keys() );
	}

	/**
	 * Points a copied condition at the target language's own content.
	 *
	 * A condition that names a page names it by ID, so the copy would hold the
	 * source language's page. The translation of that page is the same condition
	 * expressed in the language the template now belongs to.
	 *
	 * @param mixed  $meta_value     Metadata value about to be copied.
	 * @param string $meta_key       Metadata key.
	 * @param int    $source_post_id Source post identifier.
	 * @param int    $target_post_id Target post identifier.
	 * @return mixed
	 */
	public function translate_copied_conditions( $meta_value, $meta_key, $source_post_id, $target_post_id ) {
		if ( ! in_array( $meta_key, HappyAddonsTemplates::condition_meta_keys(), true ) ) {
			return $meta_value;
		}

		if ( ! $this->is_template( $source_post_id ) ) {
			return $meta_value;
		}

		$target_post_id = absint( $target_post_id );
		$language_id    = $this->post_translations->get_post_language_id( $target_post_id );

		if ( '' === $language_id || ! $this->translating_conditions( $target_post_id, $language_id ) ) {
			return $meta_value;
		}

		return $this->templates->translate_conditions( $meta_value, $language_id );
	}

	/**
	 * Copies builder metadata a translation did not receive from Elementor, then
	 * records the copy where the builder matches conditions from.
	 *
	 * The Elementor copy runs only for a post that already holds element data, so
	 * a template translated before anyone opened it in the editor would arrive
	 * with no type and no conditions — a header the builder never recognizes as
	 * one. Keys already written are left alone, which is what makes this safe to
	 * run after that copy rather than instead of it.
	 *
	 * @param int $target_post_id Target translated post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	public function copy_template_meta( $target_post_id, $source_post_id ) {
		$target_post_id = absint( $target_post_id );
		$source_post_id = absint( $source_post_id );

		if ( ! $this->is_template( $source_post_id ) || 0 === $target_post_id ) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $target_post_id );
		$translate   = '' !== $language_id && $this->translating_conditions( $target_post_id, $language_id );

		foreach ( HappyAddonsTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value = get_post_meta( $source_post_id, $meta_key, true );

			if ( $translate && in_array( $meta_key, HappyAddonsTemplates::condition_meta_keys(), true ) ) {
				$meta_value = $this->templates->translate_conditions( $meta_value, $language_id );
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );
		}

		$this->record_conditions( $target_post_id );
	}

	/**
	 * Records a template's conditions again as it is published.
	 *
	 * The builder rebuilds its condition store from published templates only, so
	 * a translation recorded while it was still a draft loses its entry the next
	 * time anyone saves conditions anywhere on the site. Saying it again at the
	 * moment of publication is what keeps that from mattering, and says nothing
	 * the builder's own rebuild would not have said.
	 *
	 * @param string  $new_status Status the post is moving to.
	 * @param string  $old_status Status the post is leaving.
	 * @param WP_Post $post       Post being transitioned.
	 * @return void
	 */
	public function record_published_template( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || $new_status === $old_status ) {
			return;
		}

		if ( ! $post instanceof WP_Post || HappyAddonsTemplates::POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->record_conditions( $post->ID );
	}

	/**
	 * Asks for the styles of every template this request will print.
	 *
	 * The builder prints its header and footer from `get_header` and `get_footer`,
	 * which is after the document head has been written, and it asks Elementor for
	 * their styles at that same moment. Elementor answers what it can — the rules
	 * arrive late, after the markup they style — and cannot answer the rest at all:
	 * the per-element rules of a document are only written for documents Elementor
	 * was told were rendering, and the builder never says so. What is left is a
	 * header with the shared base styles and none of its own, which reads as a
	 * layout that collapsed into a stack.
	 *
	 * So both are asked for here instead, where Elementor is still collecting. The
	 * template asked about is the one that will actually print, which is why the
	 * language resolution runs first rather than being left for the render.
	 *
	 * @return void
	 */
	public function prepare_template_styles() {
		if ( ! $this->resolving_for_visitor() ) {
			return;
		}

		$template_ids = array();

		// The builder prints no header or footer on a canvas page, so asking for
		// their styles there would enqueue a stylesheet for markup nobody sees.
		if ( ! $this->is_canvas_request() ) {
			foreach ( HappyAddonsTemplates::OUTER_LOCATIONS as $location ) {
				$template_ids[] = $this->templates->get_location_template_id( $location );
			}
		}

		$template_ids[] = $this->templates->get_singular_template_id();
		$template_ids   = array_unique( array_filter( array_map( 'absint', $template_ids ) ) );

		foreach ( $template_ids as $source_id ) {
			$this->prepare_styles_for( $source_id );
		}
	}

	/**
	 * Asks for the styles of the template one location will print.
	 *
	 * Asked for whichever template ends up printing, translated or not. Asking
	 * only for a translation would leave a site that has not translated its header
	 * yet looking worse on every page than a site with no languages at all, and
	 * the identifier this module resolved is the same one either way.
	 *
	 * @param int $source_id Template identifier the builder chose.
	 * @return void
	 */
	private function prepare_styles_for( $source_id ) {
		$rendered_id = absint( $this->filter_template_id( $source_id ) );

		if ( 0 === $rendered_id ) {
			return;
		}

		/**
		 * Filters whether a template's own styles are asked for early.
		 *
		 * Returning false leaves the builder's asset handling alone, for a site
		 * where something else already announces these templates.
		 *
		 * @param bool $prepare     Whether the styles are asked for.
		 * @param int  $rendered_id Template identifier that will print.
		 * @param int  $source_id   Template identifier the builder chose.
		 */
		if ( ! apply_filters( 'shapetranslate_happyaddons_template_styles', true, $rendered_id, absint( $source_id ) ) ) {
			return;
		}

		$this->templates->announce_atomic_styles( $rendered_id );
		$this->templates->enqueue_template_css( $rendered_id );
	}

	/**
	 * Answers a location with the template written in the language being read.
	 *
	 * The filter is the whole multilingual surface the builder offers, and other
	 * plugins ask it about their own content, so everything that is not one of
	 * this builder's templates is handed straight back.
	 *
	 * @param mixed  $object_id    Identifier the caller wants translated.
	 * @param string $element_type Element type the caller named.
	 * @return mixed
	 */
	public function filter_template_id( $object_id, $element_type = '' ) {
		$source_id = absint( $object_id );

		if ( 0 === $source_id || ! $this->resolving_for_visitor() ) {
			return $object_id;
		}

		if ( HappyAddonsTemplates::POST_TYPE !== get_post_type( $source_id ) ) {
			return $object_id;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $object_id;
		}

		$cache_key = $language_id . ':' . $source_id;

		if ( ! isset( $this->resolved[ $cache_key ] ) ) {
			$this->resolved[ $cache_key ] = $this->resolve_template_id( $source_id, $language_id );
		}

		/**
		 * Filters the builder template one location renders.
		 *
		 * @param int    $resolved_id Template identifier after language resolution.
		 * @param int    $source_id   Template identifier the builder chose.
		 * @param string $language_id Language being rendered.
		 */
		$resolved_id = absint(
			apply_filters(
				'shapetranslate_happyaddons_template_id',
				$this->resolved[ $cache_key ],
				$source_id,
				$language_id
			)
		);

		return 0 < $resolved_id ? $resolved_id : $object_id;
	}

	/**
	 * Returns the template the language being read should render instead.
	 *
	 * Three answers in order of how much they are worth. A template already in
	 * this language is the answer. Its published translation is the next, because
	 * it is the one a site owner wrote for exactly this. A template of the same
	 * location that happens to be in this language is the last, and is only looked
	 * for on a site whose templates carry languages at all — where they do not,
	 * there is nothing to choose between and a scan would be pure cost.
	 *
	 * Zero means the builder's own choice stands.
	 *
	 * @param int    $source_id   Template identifier the builder chose.
	 * @param string $language_id Language being rendered.
	 * @return int Zero when nothing better than the builder's choice was found.
	 */
	private function resolve_template_id( $source_id, $language_id ) {
		$source_language = $this->templates->get_template_language_id( $source_id );

		if ( $language_id === $source_language ) {
			return $source_id;
		}

		$translated = absint( $this->templates->translate_template_id( $source_id, $language_id ) );

		if ( 0 < $translated ) {
			return $translated;
		}

		if ( '' === $source_language || ! $this->matching_untranslated_templates() ) {
			return 0;
		}

		return absint(
			$this->templates->find_for_language(
				$this->templates->get_template_type( $source_id ),
				$language_id
			)
		);
	}

	/**
	 * Records one template's conditions for the builder to match.
	 *
	 * @param int $post_id Template post identifier.
	 * @return void
	 */
	private function record_conditions( $post_id ) {
		$post_id  = absint( $post_id );
		$location = $this->templates->get_template_type( $post_id );

		if ( '' === $location ) {
			return;
		}

		/**
		 * Filters whether a template is recorded as displayable.
		 *
		 * Returning false leaves the builder's condition store untouched, which
		 * keeps a translation out of every location until someone saves its
		 * conditions through the builder's own editor.
		 *
		 * @param bool $record  Whether the conditions are recorded.
		 * @param int  $post_id Template post identifier.
		 */
		if ( ! apply_filters( 'shapetranslate_happyaddons_record_conditions', true, $post_id ) ) {
			return;
		}

		$conditions = get_post_meta( $post_id, HappyAddonsTemplates::CONDITIONS_META_KEY, true );

		$this->templates->register_conditions(
			$post_id,
			$location,
			is_array( $conditions ) ? $conditions : array()
		);
	}

	/**
	 * Reports whether a post is a builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return HappyAddonsTemplates::POST_TYPE === get_post_type( absint( $post_id ) );
	}

	/**
	 * Reports whether builder templates are translated at all.
	 *
	 * @return bool
	 */
	private function translating_templates() {
		/**
		 * Filters whether Happy Addons templates are translated.
		 *
		 * Returning false leaves the post type exactly as the settings screen
		 * configured it, so a site can keep one set of headers and footers for
		 * every language.
		 *
		 * @param bool $translate Whether Happy Addons templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_happyaddons_translate_templates', true );
	}

	/**
	 * Reports whether copied conditions are rewritten for the target language.
	 *
	 * @param int    $target_post_id Target post identifier.
	 * @param string $language_id    Target language identifier.
	 * @return bool
	 */
	private function translating_conditions( $target_post_id, $language_id ) {
		/**
		 * Filters whether copied display conditions are rewritten for the target.
		 *
		 * Returning false copies the conditions verbatim, which is what a site
		 * wants when a translated template should keep targeting exactly the pages
		 * the source named.
		 *
		 * @param bool   $translate      Whether condition identifiers are rewritten.
		 * @param int    $target_post_id Target post identifier.
		 * @param string $language_id    Target language identifier.
		 */
		return (bool) apply_filters(
			'shapetranslate_happyaddons_translate_conditions',
			true,
			absint( $target_post_id ),
			(string) $language_id
		);
	}

	/**
	 * Reports whether an unlinked template may answer for its own language.
	 *
	 * @return bool
	 */
	private function matching_untranslated_templates() {
		/**
		 * Filters whether a template unlinked from the one the builder chose may
		 * still answer a location because it is in the language being read.
		 *
		 * Returning false leaves the builder's choice standing whenever the chosen
		 * template has no translation, so only templates ShapeTranslate links are ever
		 * swapped.
		 *
		 * @param bool $match Whether an unlinked same-language template may answer.
		 */
		return (bool) apply_filters( 'shapetranslate_happyaddons_match_untranslated', true );
	}

	/**
	 * Reports whether the request is one the builder prints no header on.
	 *
	 * @return bool
	 */
	private function is_canvas_request() {
		return self::CANVAS_TEMPLATE === basename( (string) get_page_template_slug() );
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
	 *
	 * The administration resolves locations to describe them — the template list,
	 * the conditions column — and an editor asking which template holds a
	 * condition must be answered with the template that holds it.
	 *
	 * @return bool
	 */
	private function resolving_for_visitor() {
		return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
	}

	/**
	 * Returns the language identifier of the current request.
	 *
	 * @return string
	 */
	private function current_language_id() {
		$language = $this->url_manager->get_current_language();

		return null === $language ? '' : (string) $language['id'];
	}
}
