<?php
/**
 * Elementor Header & Footer Builder integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\HeaderFooter;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Header & Footer Builder templates across languages.
 *
 * Three parts that only work together. Templates have to be translatable at all,
 * or a site has one header and no way to write a second. A translation has to
 * carry the template type and the display rules, or the copy is a layout that
 * fills no location. And the location has to be answered in the language being
 * read, or the builder keeps handing every reader whichever header was published
 * last.
 *
 * Nothing here reaches inside the builder. Every hook is one the builder or its
 * rules library applies itself, so a site that never translates a template
 * behaves exactly as it did before.
 */
final class HeaderFooterModule implements ModuleInterface {

	/**
	 * Header & Footer Builder language resolution service.
	 *
	 * @var HeaderFooterTemplates
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
	 * @param HeaderFooterTemplates  $templates         Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		HeaderFooterTemplates $templates,
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

		add_filter( 'shapetranslate_elementor_copy_meta_keys', array( $this, 'add_copy_meta_keys' ), 10, 2 );
		add_filter( 'shapetranslate_elementor_copy_meta_value', array( $this, 'translate_copied_locations' ), 10, 4 );
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_template_meta' ), 20, 2 );

		add_filter( 'astra_get_display_posts_by_conditions', array( $this, 'filter_matched_templates' ), 10, 2 );

		foreach ( array( 'type_header', 'type_footer', 'type_before_footer' ) as $setting ) {
			add_filter( 'hfe_get_settings_' . $setting, array( $this, 'filter_template_id' ) );
		}

		add_filter( 'hfe_render_template_id', array( $this, 'filter_template_id' ) );
	}

	/**
	 * Makes builder templates translatable alongside the site's own content.
	 *
	 * The post type is public and carries an administrative UI, so it already
	 * appears in the translatable types list. It is selected here rather than
	 * left for a site owner to tick, because until it is ticked the rest of this
	 * module has nothing to work with: templates carry no language, so there is
	 * nothing to resolve and nothing to copy. The checkbox therefore reads as
	 * selected and stays that way; a site that would rather keep one set of
	 * headers and footers for every language says so through the filter below,
	 * which leaves the rest of the module inert on its own.
	 *
	 * @param mixed $settings Normalized ShapeTranslate configuration.
	 * @return mixed
	 */
	public function enable_template_translation( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		/**
		 * Filters whether builder templates are translated.
		 *
		 * Returning false leaves the post type exactly as the settings screen
		 * configured it, so a site can keep one set of headers and footers for
		 * every language.
		 *
		 * @param bool $translate Whether builder templates are translatable.
		 */
		if ( ! apply_filters( 'shapetranslate_hfe_translate_templates', true ) ) {
			return $settings;
		}

		$content    = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$post_types = isset( $content['post_types'] ) && is_array( $content['post_types'] ) ? $content['post_types'] : array();

		if ( in_array( HeaderFooterTemplates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = HeaderFooterTemplates::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
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

		return array_merge( $meta_keys, HeaderFooterTemplates::meta_keys() );
	}

	/**
	 * Points a copied rule set at the target language's own content.
	 *
	 * A rule that names a page names it by ID, so the copy would hold the source
	 * language's page. The translation of that page is the same rule expressed in
	 * the language the template now belongs to.
	 *
	 * @param mixed  $meta_value     Metadata value about to be copied.
	 * @param string $meta_key       Metadata key.
	 * @param int    $source_post_id Source post identifier.
	 * @param int    $target_post_id Target post identifier.
	 * @return mixed
	 */
	public function translate_copied_locations( $meta_value, $meta_key, $source_post_id, $target_post_id ) {
		if ( ! in_array( $meta_key, HeaderFooterTemplates::location_meta_keys(), true ) || ! is_array( $meta_value ) ) {
			return $meta_value;
		}

		if ( ! $this->is_template( $source_post_id ) ) {
			return $meta_value;
		}

		$target_post_id = absint( $target_post_id );
		$language_id    = $this->post_translations->get_post_language_id( $target_post_id );

		if ( '' === $language_id || ! $this->translating_locations( $target_post_id, $language_id ) ) {
			return $meta_value;
		}

		return $this->templates->translate_locations( $meta_value, $language_id );
	}

	/**
	 * Copies builder metadata a translation did not receive from Elementor.
	 *
	 * The Elementor copy runs only for a post that already holds element data, so
	 * a template translated before anyone opened it in the editor would arrive
	 * with no type and no rules — a header the builder never recognizes as one.
	 * Keys already written are left alone, which is what makes this safe to run
	 * after that copy rather than instead of it.
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
		$translate   = '' !== $language_id && $this->translating_locations( $target_post_id, $language_id );

		foreach ( HeaderFooterTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value = get_post_meta( $source_post_id, $meta_key, true );

			if ( $translate && in_array( $meta_key, HeaderFooterTemplates::location_meta_keys(), true ) ) {
				$meta_value = $this->templates->translate_locations( $meta_value, $language_id );
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );
		}
	}

	/**
	 * Narrows the templates matched for a request to the language being read.
	 *
	 * @param mixed  $templates Templates the rules library matched.
	 * @param string $post_type Post type the rules library was asked about.
	 * @return mixed
	 */
	public function filter_matched_templates( $templates, $post_type ) {
		if ( HeaderFooterTemplates::POST_TYPE !== $post_type || ! is_array( $templates ) ) {
			return $templates;
		}

		if ( ! $this->resolving_for_visitor() ) {
			return $templates;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $templates;
		}

		/**
		 * Filters the templates a location may choose from in one language.
		 *
		 * @param array<int|string, mixed> $selected    Templates after language narrowing.
		 * @param array<int|string, mixed> $templates   Templates the rules library matched.
		 * @param string                   $language_id Language being rendered.
		 */
		$selected = apply_filters(
			'shapetranslate_hfe_matched_templates',
			$this->templates->select_for_language( $templates, $language_id ),
			$templates,
			$language_id
		);

		return is_array( $selected ) ? $selected : $templates;
	}

	/**
	 * Answers a location with the template written in the language being read.
	 *
	 * The value arrives as an empty string when no template applies, and the
	 * builder reads that emptiness to decide whether the location renders at all.
	 * It is returned untouched for that reason: an integer zero would read as a
	 * header that exists and has no content.
	 *
	 * @param mixed $template_id Template identifier the builder chose.
	 * @return mixed
	 */
	public function filter_template_id( $template_id ) {
		$source_id = absint( $template_id );

		if ( 0 === $source_id || ! $this->resolving_for_visitor() ) {
			return $template_id;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $template_id;
		}

		$cache_key = $language_id . ':' . $source_id;

		if ( ! isset( $this->resolved[ $cache_key ] ) ) {
			$this->resolved[ $cache_key ] = absint(
				$this->templates->translate_template_id( $source_id, $language_id )
			);
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
				'shapetranslate_hfe_template_id',
				$this->resolved[ $cache_key ],
				$source_id,
				$language_id
			)
		);

		return 0 < $resolved_id ? $resolved_id : $template_id;
	}

	/**
	 * Reports whether a post is a builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return HeaderFooterTemplates::POST_TYPE === get_post_type( absint( $post_id ) );
	}

	/**
	 * Reports whether copied rules are rewritten for the target language.
	 *
	 * @param int    $target_post_id Target post identifier.
	 * @param string $language_id    Target language identifier.
	 * @return bool
	 */
	private function translating_locations( $target_post_id, $language_id ) {
		/**
		 * Filters whether copied display rules are rewritten for the target.
		 *
		 * Returning false copies the rules verbatim, which is what a site wants
		 * when a translated template should keep targeting exactly the pages the
		 * source named.
		 *
		 * @param bool   $translate      Whether rule identifiers are rewritten.
		 * @param int    $target_post_id Target post identifier.
		 * @param string $language_id    Target language identifier.
		 */
		return (bool) apply_filters(
			'shapetranslate_hfe_translate_locations',
			true,
			absint( $target_post_id ),
			(string) $language_id
		);
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
	 *
	 * The administration resolves locations to describe them — the template list,
	 * the rules conflict notice — and an editor asking which template holds a rule
	 * must be answered with the template that holds it.
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
