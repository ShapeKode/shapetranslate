<?php
/**
 * Essential Addons Theme Builder integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\EssentialAddons;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Essential Addons Theme Builder templates across languages.
 *
 * The post type is registered non-public, so it has to be declared translatable
 * before ShapeTranslate will touch it at all. From there the work is the same shape
 * as every other builder: a translation has to carry the template type and the
 * display conditions or it fills no location, the conditions have to name the
 * target language's own pages, and the location has to be answered in the
 * language being read.
 *
 * One thing is particular to this builder. It finds its candidates with an
 * ordinary WP_Query and caches the result in a transient keyed by a language it
 * only knows how to read from Polylang and WPML — so for ShapeTranslate that key is
 * blank. Letting ShapeTranslate narrow that query by language would put one
 * language's templates in a cache every language then reads. The query is left
 * unfiltered for that reason, and the language is resolved afterwards, on the one
 * template the builder chose.
 */
final class EssentialAddonsModule implements ModuleInterface {

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var EssentialAddonsTemplates
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
	 * @param EssentialAddonsTemplates $templates         Theme Builder language resolution.
	 * @param PostTranslationManager   $post_translations Post translation manager.
	 * @param LanguageUrlManager       $url_manager       Language URL service.
	 */
	public function __construct(
		EssentialAddonsTemplates $templates,
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

		add_filter( 'shapetranslate_non_public_post_types', array( $this, 'declare_post_type' ) );
		add_filter( 'shapetranslate_supported_post_types', array( $this, 'declare_post_type' ) );
		add_filter( 'shapetranslate_settings', array( $this, 'enable_template_translation' ) );

		add_filter( 'shapetranslate_filter_secondary_query_by_language', array( $this, 'skip_template_query' ), 10, 2 );

		add_filter( 'shapetranslate_elementor_copy_meta_keys', array( $this, 'add_copy_meta_keys' ), 10, 2 );
		add_filter( 'shapetranslate_elementor_copy_meta_value', array( $this, 'translate_copied_conditions' ), 10, 4 );
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_template_meta' ), 20, 2 );

		add_filter( 'eael/theme_builder/active_template_id', array( $this, 'filter_template_id' ), 10, 2 );
	}

	/**
	 * Declares the Theme Builder post type translatable.
	 *
	 * The builder registers it non-public — nothing should reach a header
	 * fragment by URL — so it is invisible to ShapeTranslate until it is named on
	 * both lists: one says the engine may translate a non-public type, the other
	 * offers this one.
	 *
	 * @param mixed $post_types Post type names.
	 * @return mixed
	 */
	public function declare_post_type( $post_types ) {
		if ( ! is_array( $post_types ) || ! $this->translating_templates() ) {
			return $post_types;
		}

		if ( ! in_array( EssentialAddonsTemplates::POST_TYPE, $post_types, true ) ) {
			$post_types[] = EssentialAddonsTemplates::POST_TYPE;
		}

		return $post_types;
	}

	/**
	 * Selects the Theme Builder post type alongside the site's own content.
	 *
	 * It is selected here rather than left for a site owner to tick, because
	 * until it is ticked the rest of this module has nothing to work with:
	 * templates carry no language, so there is nothing to resolve and nothing to
	 * copy. The checkbox therefore reads as selected and stays that way; a site
	 * that would rather keep one set of headers and footers for every language
	 * says so through `shapetranslate_eael_translate_templates`, which leaves the
	 * rest of the module inert on its own.
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

		if ( in_array( EssentialAddonsTemplates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = EssentialAddonsTemplates::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Leaves the builder's own template query unnarrowed.
	 *
	 * The builder asks which templates exist for a location once and caches the
	 * answer for every language, so the answer has to be the same one every time.
	 * Narrowing it here would cache whichever language asked first and serve that
	 * to the rest — and on a language whose header is not translated yet, narrowing
	 * would answer with nothing at all rather than with the source language's
	 * header.
	 *
	 * @param mixed    $filter Whether language filtering should run.
	 * @param WP_Query $query  Secondary frontend query.
	 * @return mixed
	 */
	public function skip_template_query( $filter, $query ) {
		return $this->query_targets_templates( $query ) ? false : $filter;
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

		return array_merge( $meta_keys, EssentialAddonsTemplates::meta_keys() );
	}

	/**
	 * Points a copied condition set at the target language's own content.
	 *
	 * @param mixed  $meta_value     Metadata value about to be copied.
	 * @param string $meta_key       Metadata key.
	 * @param int    $source_post_id Source post identifier.
	 * @param int    $target_post_id Target post identifier.
	 * @return mixed
	 */
	public function translate_copied_conditions( $meta_value, $meta_key, $source_post_id, $target_post_id ) {
		if ( EssentialAddonsTemplates::CONDITIONS_META_KEY !== $meta_key || ! is_array( $meta_value ) ) {
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

		foreach ( EssentialAddonsTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value = get_post_meta( $source_post_id, $meta_key, true );

			if ( $translate && EssentialAddonsTemplates::CONDITIONS_META_KEY === $meta_key ) {
				$meta_value = $this->templates->translate_conditions( $meta_value, $language_id );
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );
		}

		$this->templates->flush_cache();
	}

	/**
	 * Answers a location with the template written in the language being read.
	 *
	 * @param mixed  $template_id Template identifier the builder chose.
	 * @param string $type        Template type slug.
	 * @return mixed
	 */
	public function filter_template_id( $template_id, $type ) {
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
		 * Filters the Theme Builder template one location renders.
		 *
		 * @param int    $resolved_id Template identifier after language resolution.
		 * @param int    $source_id   Template identifier the builder chose.
		 * @param string $type        Template type slug.
		 * @param string $language_id Language being rendered.
		 */
		$resolved_id = absint(
			apply_filters(
				'shapetranslate_eael_template_id',
				$this->resolved[ $cache_key ],
				$source_id,
				(string) $type,
				$language_id
			)
		);

		return 0 < $resolved_id ? $resolved_id : $template_id;
	}

	/**
	 * Reports whether a query asks for Theme Builder templates and nothing else.
	 *
	 * A query that mixes the template post type with ordinary content is left to
	 * the usual rules: exempting it would quietly unnarrow the content half too.
	 *
	 * @param mixed $query Query being prepared.
	 * @return bool
	 */
	private function query_targets_templates( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return false;
		}

		$post_types = $query->get( 'post_type' );
		$post_types = array_values( array_unique( array_filter( (array) $post_types, 'is_string' ) ) );

		return array( EssentialAddonsTemplates::POST_TYPE ) === $post_types;
	}

	/**
	 * Reports whether a post is a Theme Builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return EssentialAddonsTemplates::POST_TYPE === get_post_type( absint( $post_id ) );
	}

	/**
	 * Reports whether Theme Builder templates are translated at all.
	 *
	 * @return bool
	 */
	private function translating_templates() {
		/**
		 * Filters whether Theme Builder templates are translated.
		 *
		 * Returning false leaves the post type untranslatable, so a site can keep
		 * one set of headers and footers for every language.
		 *
		 * @param bool $translate Whether Theme Builder templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_eael_translate_templates', true );
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
			'shapetranslate_eael_translate_conditions',
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
