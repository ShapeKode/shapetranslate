<?php
/**
 * ElementsKit header and footer integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\ElementsKit;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries ElementsKit headers and footers across languages.
 *
 * The builder resolves its two locations once, on `wp`, and hands the pair of
 * identifiers straight to whichever theme adapter will print them — most of them
 * keep the pair rather than ask again, so by the time anything renders there is
 * nothing left to filter. What it does do before handing them over is cache
 * them, and the cache is the one place the pair can still be reached. So the
 * resolution is redone a step earlier: the builder is asked what it would
 * choose, each answer is replaced by its translation, and the pair it is about
 * to read is the translated one.
 *
 * Its candidate query is left unnarrowed for the same reason the cache is
 * rewritten rather than filtered. Narrowing it would answer a language whose
 * header nobody has translated yet with no header at all, where leaving it whole
 * answers with the one the site already had.
 */
final class ElementsKitModule implements ModuleInterface {

	/**
	 * Page template on which the builder prints nothing.
	 */
	const CANVAS_TEMPLATE = 'elementor_canvas';

	/**
	 * Header and footer language resolution service.
	 *
	 * @var ElementsKitTemplates
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
	 * @param ElementsKitTemplates   $templates         Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		ElementsKitTemplates $templates,
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
	}

	/**
	 * Selects the template post type alongside the site's own content.
	 *
	 * It is selected here rather than left for a site owner to tick, because
	 * until it is ticked the rest of this module has nothing to work with:
	 * templates carry no language, so there is nothing to resolve and nothing to
	 * copy. The checkbox therefore reads as selected and stays that way; a site
	 * that would rather keep one header and footer for every language says so
	 * through the filter below, which leaves the rest of the module inert.
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

		if ( in_array( ElementsKitTemplates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = ElementsKitTemplates::POST_TYPE;
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

		return array( ElementsKitTemplates::POST_TYPE ) === $post_types ? false : $filter;
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

		return array_merge( $meta_keys, ElementsKitTemplates::meta_keys() );
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
		if ( ElementsKitTemplates::SINGULAR_IDS_META_KEY !== $meta_key || ! $this->is_template( $source_post_id ) ) {
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
	 * Copies builder metadata a translation did not receive from Elementor.
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

		foreach ( ElementsKitTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value = get_post_meta( $source_post_id, $meta_key, true );

			if ( $translate && ElementsKitTemplates::SINGULAR_IDS_META_KEY === $meta_key ) {
				$meta_value = $this->templates->translate_singular_ids( $meta_value, $language_id );
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );
		}
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

		foreach ( $source as $slot => $template_id ) {
			$source_id = absint( $template_id );

			if ( 0 === $source_id ) {
				continue;
			}

			$rendered_id = $source_id;

			if ( '' !== $language_id ) {
				/**
				 * Filters the template one ElementsKit location renders.
				 *
				 * @param int        $resolved_id Template identifier after language resolution.
				 * @param int        $source_id   Template identifier the builder chose.
				 * @param int|string $slot        Position in the pair: 0 is the header, 1 the footer.
				 * @param string     $language_id Language being rendered.
				 */
				$resolved_id = absint(
					apply_filters(
						'shapetranslate_elementskit_template_id',
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

					$this->templates->render_css( $resolved_id );
				}
			}

			$this->announce_atomic_styles( $rendered_id );
		}

		if ( $changed ) {
			$this->templates->set_template_ids( $resolved );
		}
	}

	/**
	 * Tells Elementor that a template is about to be rendered.
	 *
	 * Said for whichever template ends up rendering, translated or not. Saying it
	 * only for a translation would leave a site that has not translated its
	 * header yet looking worse on every page than a site with no languages at
	 * all, and the identifier this module resolved is the same one either way.
	 *
	 * @param int $template_id Template post identifier.
	 * @return void
	 */
	private function announce_atomic_styles( $template_id ) {
		/**
		 * Filters whether a resolved template is announced to Elementor.
		 *
		 * Returning false leaves the builder's own asset handling alone, for a
		 * site where something else already announces these templates.
		 *
		 * @param bool $announce    Whether the template is announced.
		 * @param int  $template_id Template post identifier.
		 */
		$announce = apply_filters(
			'shapetranslate_elementskit_atomic_styles',
			true,
			absint( $template_id )
		);

		if ( $announce ) {
			$this->templates->announce_atomic_styles( $template_id );
		}
	}

	/**
	 * Reports whether a post is a builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return ElementsKitTemplates::POST_TYPE === get_post_type( absint( $post_id ) );
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
		 * Filters whether ElementsKit templates are translated.
		 *
		 * Returning false leaves the post type as the settings screen configured
		 * it, so a site can keep one header and footer for every language.
		 *
		 * @param bool $translate Whether ElementsKit templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_elementskit_translate_templates', true );
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
			'shapetranslate_elementskit_translate_conditions',
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
