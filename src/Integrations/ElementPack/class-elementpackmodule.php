<?php
/**
 * Element Pack Template Builder integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\ElementPack;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Element Pack headers, footers and page templates across languages.
 *
 * The builder resolves its header and footer once, on `wp`, caches the pair and
 * hands it to whichever theme adapter will print them. The cache is the one place
 * the pair can still be reached, so the resolution is redone a step earlier: the
 * builder is asked what it would choose, each answer is replaced by its
 * translation, and the pair it is about to read is the translated one.
 *
 * Single, archive, 404 and search templates take another path: the builder reads
 * one identifier from an option while choosing the page template, and passes it
 * through a filter of its own on the way. Answering that filter is enough.
 *
 * Its candidate query is left unnarrowed. Narrowing it would answer a language
 * whose header nobody has translated yet with no header at all, where leaving it
 * whole answers with the one the site already had.
 */
final class ElementPackModule implements ModuleInterface {

	/**
	 * Page template on which the builder prints no header or footer.
	 */
	const CANVAS_TEMPLATE = 'elementor_canvas';

	/**
	 * Template Builder language resolution service.
	 *
	 * @var ElementPackTemplates
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
	 * Constructor.
	 *
	 * @param ElementPackTemplates   $templates         Template Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		ElementPackTemplates $templates,
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

		// Ahead of the builder's own resolution, which runs on `wp` at the default
		// priority and reads the cache this leaves behind.
		add_action( 'wp', array( $this, 'resolve_templates' ), 9 );

		// The builder's own filter, answered rather than declared.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'bdthemes-templates-builder/custom-template', array( $this, 'filter_page_template_id' ) );
	}

	/**
	 * Selects the template post type alongside the site's own content.
	 *
	 * It is selected here rather than left for a site owner to tick, because
	 * until it is ticked the rest of this module has nothing to work with:
	 * templates carry no language, so there is nothing to resolve and nothing to
	 * copy. A site that would rather keep one header and footer for every
	 * language says so through the filter below, which leaves the module inert.
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

		if ( in_array( ElementPackTemplates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = ElementPackTemplates::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Leaves the builder's own template query unnarrowed.
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

		return array( ElementPackTemplates::POST_TYPE ) === $post_types ? false : $filter;
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

		return array_merge( $meta_keys, ElementPackTemplates::meta_keys() );
	}

	/**
	 * Points a copied condition at the target language's own content.
	 *
	 * @param mixed  $meta_value     Metadata value about to be copied.
	 * @param string $meta_key       Metadata key.
	 * @param int    $source_post_id Source post identifier.
	 * @param int    $target_post_id Target post identifier.
	 * @return mixed
	 */
	public function translate_copied_conditions( $meta_value, $meta_key, $source_post_id, $target_post_id ) {
		if ( ElementPackTemplates::SINGULAR_IDS_META_KEY !== $meta_key || ! $this->is_template( $source_post_id ) ) {
			return $meta_value;
		}

		$target_post_id = absint( $target_post_id );
		$language_id    = $this->post_translations->get_post_language_id( $target_post_id );

		if ( '' === $language_id || ! $this->translating_conditions( $target_post_id, $language_id ) ) {
			return $meta_value;
		}

		return $this->templates->translate_singular_ids( $meta_value, $language_id );
	}

	/**
	 * Copies builder state a translation did not receive from Elementor.
	 *
	 * The Elementor copy runs only for a post that already holds element data, so
	 * a template translated before anyone opened it in the editor would arrive
	 * with no type and no conditions — a header the builder never recognizes as
	 * one. Keys already written are left alone, which is what makes this safe to
	 * run after that copy rather than instead of it. The switch that turns the
	 * template on lives in an option, not in metadata, and is copied last.
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

		foreach ( ElementPackTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value = get_post_meta( $source_post_id, $meta_key, true );

			if ( $translate && ElementPackTemplates::SINGULAR_IDS_META_KEY === $meta_key ) {
				$meta_value = $this->templates->translate_singular_ids( $meta_value, $language_id );
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );
		}

		$this->templates->copy_enabled_state( $source_post_id, $target_post_id );
	}

	/**
	 * Replaces the header and footer the builder chose with their translations.
	 *
	 * @return void
	 */
	public function resolve_templates() {
		if ( ! $this->resolving_for_visitor() || $this->is_canvas_request() ) {
			return;
		}

		$language_id = $this->current_language_id();
		$source      = $this->templates->get_source_template_ids();
		$resolved    = $source;
		$changed     = false;

		foreach ( ElementPackTemplates::OUTER_SLOTS as $slot ) {
			$source_id = isset( $source[ $slot ] ) ? absint( $source[ $slot ] ) : 0;

			if ( 0 === $source_id ) {
				continue;
			}

			$rendered_id = $source_id;

			if ( '' !== $language_id ) {
				/**
				 * Filters the template one Element Pack location renders.
				 *
				 * @param int    $resolved_id Template identifier after language resolution.
				 * @param int    $source_id   Template identifier the builder chose.
				 * @param int    $slot        Position in the pair: 0 is the header, 1 the footer.
				 * @param string $language_id Language being rendered.
				 */
				$resolved_id = absint(
					apply_filters(
						'shapetranslate_elementpack_template_id',
						absint( $this->templates->translate_template_id( $source_id, $language_id ) ),
						$source_id,
						$slot,
						$language_id
					)
				);

				if ( 0 < $resolved_id && $resolved_id !== $source_id ) {
					$resolved[ $slot ] = $resolved_id;
					$rendered_id       = $resolved_id;
					$changed           = true;
				}
			}

			$this->prepare_styles( $rendered_id );
		}

		if ( $changed ) {
			$this->templates->set_template_ids( $resolved );
		}
	}

	/**
	 * Answers a single, archive, 404 or search template in the language being read.
	 *
	 * @param mixed $template_id Template identifier the builder read from its option.
	 * @return mixed
	 */
	public function filter_page_template_id( $template_id ) {
		$source_id = absint( $template_id );

		if ( 0 === $source_id || ! $this->resolving_for_visitor() || ! $this->is_template( $source_id ) ) {
			return $template_id;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $template_id;
		}

		/**
		 * Filters the single or archive template Element Pack renders.
		 *
		 * @param int    $resolved_id Template identifier after language resolution.
		 * @param int    $source_id   Template identifier the builder chose.
		 * @param string $language_id Language being rendered.
		 */
		$resolved_id = absint(
			apply_filters(
				'shapetranslate_elementpack_page_template_id',
				absint( $this->templates->translate_template_id( $source_id, $language_id ) ),
				$source_id,
				$language_id
			)
		);

		return 0 < $resolved_id ? $resolved_id : $template_id;
	}

	/**
	 * Asks for the styles of a header or footer while the head is still open.
	 *
	 * Asked for whichever template ends up printing, translated or not. Asking
	 * only for a translation would leave a site that has not translated its
	 * header yet looking worse on every page than a site with no languages at all.
	 *
	 * @param int $template_id Template post identifier.
	 * @return void
	 */
	private function prepare_styles( $template_id ) {
		/**
		 * Filters whether a resolved template's styles are asked for early.
		 *
		 * Returning false leaves the builder's own asset handling alone, for a
		 * site where something else already announces these templates.
		 *
		 * @param bool $prepare     Whether the styles are asked for.
		 * @param int  $template_id Template post identifier.
		 */
		if ( ! apply_filters( 'shapetranslate_elementpack_template_styles', true, absint( $template_id ) ) ) {
			return;
		}

		$this->templates->announce_atomic_styles( $template_id );
		$this->templates->enqueue_template_css( $template_id );
	}

	/**
	 * Reports whether a post is a builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return ElementPackTemplates::POST_TYPE === get_post_type( absint( $post_id ) );
	}

	/**
	 * Reports whether the request is one the builder prints nothing on.
	 *
	 * @return bool
	 */
	private function is_canvas_request() {
		return self::CANVAS_TEMPLATE === basename( (string) get_page_template_slug() );
	}

	/**
	 * Reports whether builder templates are translated at all.
	 *
	 * @return bool
	 */
	private function translating_templates() {
		/**
		 * Filters whether Element Pack templates are translated.
		 *
		 * Returning false leaves the post type as the settings screen configured
		 * it, so a site can keep one header and footer for every language.
		 *
		 * @param bool $translate Whether Element Pack templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_elementpack_translate_templates', true );
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
		 * wants when a translated template should keep targeting exactly the
		 * pages the source named.
		 *
		 * @param bool   $translate      Whether condition identifiers are rewritten.
		 * @param int    $target_post_id Target post identifier.
		 * @param string $language_id    Target language identifier.
		 */
		return (bool) apply_filters(
			'shapetranslate_elementpack_translate_conditions',
			true,
			absint( $target_post_id ),
			(string) $language_id
		);
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
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
