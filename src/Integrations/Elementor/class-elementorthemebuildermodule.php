<?php
/**
 * Elementor Theme Builder integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Elementor;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Theme Builder templates and popups across languages.
 *
 * Three parts that only work together. Templates have to be translatable at all,
 * or a site has one header and no way to write a second. Copying a template's
 * display conditions gives the translation rules of its own, and on its own that
 * is worse than copying nothing, because Elementor ranks the templates a
 * location matched by how specific their conditions are and by nothing else. An
 * English and a Bengali header both claiming the whole site therefore tie, and
 * the location answers with whichever the tie fell to, for every reader —
 * except at a location that renders all of its matches, a popup being the one
 * Elementor ships, where the reader gets the same popup once per language it was
 * written in. Resolving the matched template to the language being read is what
 * turns those two rules into one answer per language.
 *
 * Which is why this covers every location rather than headers and footers.
 * Single posts, single pages, archives, search results, and the 404 page are
 * chosen the same way, through the same ranking, from the same index — a 404
 * page written in one language is the case that shows it, being a page of
 * nothing but words.
 *
 * Nothing here reaches into Elementor Pro. Every hook is one Elementor documents
 * and applies itself, so a template ShapeTranslate never touched behaves exactly as
 * it did before.
 */
final class ElementorThemeBuilderModule implements ModuleInterface {

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var ElementorThemeBuilder
	 */
	private $theme_builder;

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
	 * @param ElementorThemeBuilder  $theme_builder     Theme Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		ElementorThemeBuilder $theme_builder,
		PostTranslationManager $post_translations,
		LanguageUrlManager $url_manager
	) {
		$this->theme_builder     = $theme_builder;
		$this->post_translations = $post_translations;
		$this->url_manager       = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->theme_builder->is_available() ) {
			return;
		}

		add_filter( 'shapetranslate_settings', array( $this, 'enable_template_translation' ) );
		add_filter( 'shapetranslate_filter_secondary_query_by_language', array( $this, 'skip_template_query' ), 10, 2 );
		add_filter(
			'elementor/theme/conditions/cache/regenerate/query_args',
			array( $this, 'unscope_conditions_cache_query' )
		);

		add_filter( 'shapetranslate_elementor_copy_meta_value', array( $this, 'translate_copied_conditions' ), 10, 4 );
		add_action( 'shapetranslate_elementor_document_copied', array( $this, 'refresh_conditions_cache' ), 10, 2 );
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_template_meta' ), 20, 2 );

		add_filter(
			'elementor/theme/get_location_templates/template_id',
			array( $this, 'filter_location_template_id' ),
			10,
			2
		);
		add_filter(
			'elementor/theme/get_location_templates/condition_sub_id',
			array( $this, 'filter_condition_sub_id' ),
			10,
			2
		);
	}

	/**
	 * Makes Theme Builder templates translatable alongside the site's own content.
	 *
	 * The post type is public and carries an administrative UI, so it already
	 * appears in the translatable types list. It is selected here rather than left
	 * for a site owner to tick, because until it is ticked the rest of this module
	 * has nothing to work with: templates carry no language, so there is nothing
	 * to resolve and nothing to copy. The checkbox therefore reads as selected and
	 * stays that way; a site that would rather keep one set of headers, footers,
	 * and popups for every language says so through the filter below, which leaves
	 * the rest of the module inert on its own.
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

		if ( in_array( ElementorThemeBuilder::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = ElementorThemeBuilder::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Leaves Elementor's own template lookups unnarrowed.
	 *
	 * Elementor asks for a template by identifier and reads an empty answer as
	 * "no template at all" — a Template widget renders nothing, a location falls
	 * through to the theme. Narrowing those lookups to the language being read
	 * would turn every untranslated template into a missing one, which is the
	 * opposite of what the resolution below arranges.
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

		return array( ElementorThemeBuilder::POST_TYPE ) === $post_types ? false : $filter;
	}

	/**
	 * Leaves the condition index counting every language's templates.
	 *
	 * The index is the list Elementor matches a location against, and it is built
	 * once and read on every request after that. Building it in one language would
	 * write that language's answer into a cache every language then reads, so a
	 * reader gets whichever language happened to be current when the index was
	 * last regenerated.
	 *
	 * Said through the query itself rather than the post types it names: the list
	 * is assembled from whichever document types declare they support conditions,
	 * so a post type this does not recognize can be in it.
	 *
	 * @param mixed $query_args Query arguments Elementor rebuilds the index with.
	 * @return mixed
	 */
	public function unscope_conditions_cache_query( $query_args ) {
		if ( ! is_array( $query_args ) ) {
			return $query_args;
		}

		$query_args['shapetranslate_skip_language_filter'] = true;

		return $query_args;
	}

	/**
	 * Gives a translation the metadata and the type that make it a template.
	 *
	 * The Elementor copy runs only for a post that already holds element data, so
	 * a template translated before anyone opened it in the editor would arrive
	 * with nothing saying what kind of document it is — invisible to the Theme
	 * Builder's own screens, and unable to answer a location. Keys already written
	 * are left alone, which is what makes this safe to run after that copy rather
	 * than instead of it.
	 *
	 * The kind is recorded twice by Elementor, once in metadata and once as a
	 * term, and the term is only restored when nothing put one there: a site that
	 * copies taxonomies on translation has already done it, and did it while
	 * knowing things about the site that this does not.
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

		$copied_conditions = false;

		foreach ( ElementorThemeBuilder::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value   = get_post_meta( $source_post_id, $meta_key, true );
			$is_condition = ElementorThemeBuilder::CONDITIONS_META_KEY === $meta_key;

			if ( $is_condition ) {
				$meta_value = $this->translate_copied_conditions(
					$meta_value,
					$meta_key,
					$source_post_id,
					$target_post_id
				);
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );

			$copied_conditions = $copied_conditions || $is_condition;
		}

		$this->copy_template_type( $target_post_id, $source_post_id );

		if ( $copied_conditions ) {
			$this->refresh_conditions_cache( $target_post_id, $source_post_id );
		}
	}

	/**
	 * Points a copied condition list at the target language's own content.
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
		unset( $source_post_id );

		if ( ElementorThemeBuilder::CONDITIONS_META_KEY !== $meta_key || ! is_array( $meta_value ) ) {
			return $meta_value;
		}

		$language_id = $this->post_translations->get_post_language_id( absint( $target_post_id ) );

		if ( '' === $language_id ) {
			return $meta_value;
		}

		/**
		 * Filters whether copied display conditions are rewritten for the target.
		 *
		 * Returning false copies the conditions verbatim, which is what a site
		 * wants when a translated template should keep targeting exactly the
		 * objects the source named.
		 *
		 * @param bool   $translate      Whether condition identifiers are rewritten.
		 * @param int    $target_post_id Target post identifier.
		 * @param string $language_id    Target language identifier.
		 */
		$translate = apply_filters(
			'shapetranslate_elementor_translate_conditions',
			true,
			absint( $target_post_id ),
			$language_id
		);

		if ( ! $translate ) {
			return $meta_value;
		}

		return $this->theme_builder->translate_conditions( $meta_value, $language_id );
	}

	/**
	 * Rebuilds Elementor's condition index after a document is copied.
	 *
	 * Elementor answers a location from an option it regenerates when conditions
	 * are saved through its own editor. A translation created outside the editor
	 * never triggers that, so the copied conditions would sit in metadata that
	 * nothing reads until the next time someone opened and saved the template.
	 *
	 * @param int $target_post_id Target translated post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	public function refresh_conditions_cache( $target_post_id, $source_post_id ) {
		unset( $source_post_id );

		if ( ! metadata_exists( 'post', absint( $target_post_id ), ElementorThemeBuilder::CONDITIONS_META_KEY ) ) {
			return;
		}

		$cache = $this->get_conditions_cache();

		if ( null !== $cache ) {
			$cache->regenerate();
		}
	}

	/**
	 * Answers a theme location with the template written in the read language.
	 *
	 * Every candidate of one translation group answers with the same identifier,
	 * which is what collapses a template and its translations into one answer.
	 * A language with no published template of its own is answered with the
	 * template the group was translated from, so a half-translated site keeps the
	 * header, the archive, or the 404 page it had rather than losing it.
	 *
	 * Elementor asks this for every location it has: the header and footer, the
	 * single and archive locations that carry single posts, single pages,
	 * archives, search results, and the 404 page, and the popup location.
	 *
	 * @param int    $template_id Template identifier Elementor matched.
	 * @param string $location    Theme location name.
	 * @return int
	 */
	public function filter_location_template_id( $template_id, $location ) {
		$template_id = absint( $template_id );

		if ( ! $this->resolving_for_visitor() ) {
			return $template_id;
		}

		$language_id = $this->current_language_id();
		$translated  = '' === $language_id
			? 0
			: $this->theme_builder->resolve_template_id( $template_id, $language_id );

		/**
		 * Filters the Theme Builder template one location renders.
		 *
		 * @param int    $resolved_id Template identifier after language resolution.
		 * @param int    $template_id Template identifier Elementor matched.
		 * @param string $location    Theme location name.
		 * @param string $language_id Language being rendered.
		 */
		$resolved_id = apply_filters(
			'shapetranslate_elementor_location_template_id',
			0 < $translated ? $translated : $template_id,
			$template_id,
			$location,
			$language_id
		);

		return 0 < absint( $resolved_id ) ? absint( $resolved_id ) : $template_id;
	}

	/**
	 * Reads a condition's object identifier in the language being rendered.
	 *
	 * This is what lets one set of conditions serve every language. A template
	 * limited to the English "About" page also applies on that page's Bengali
	 * translation, so a site can leave the rules on the source template and let
	 * the location resolution above pick the translated template to render.
	 *
	 * @param string|int           $sub_id           Identifier stored in the condition.
	 * @param array<string, mixed> $parsed_condition Condition split into its segments.
	 * @return string|int
	 */
	public function filter_condition_sub_id( $sub_id, $parsed_condition ) {
		if ( ! is_array( $parsed_condition ) || ! $this->resolving_for_visitor() ) {
			return $sub_id;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $sub_id;
		}

		/**
		 * Filters whether a condition identifier follows the language being read.
		 *
		 * Returning false keeps a condition matching only the exact object it
		 * names, so a rule written against one language's page stops applying to
		 * that page's translations.
		 *
		 * @param bool                 $translate        Whether the identifier is resolved.
		 * @param array<string, mixed> $parsed_condition Condition segments.
		 * @param string               $language_id      Language being rendered.
		 */
		$translate = apply_filters(
			'shapetranslate_elementor_translate_condition_sub_id',
			true,
			$parsed_condition,
			$language_id
		);

		if ( ! $translate ) {
			return $sub_id;
		}

		$name       = isset( $parsed_condition['name'] ) ? (string) $parsed_condition['name'] : '';
		$sub_name   = isset( $parsed_condition['sub_name'] ) ? (string) $parsed_condition['sub_name'] : '';
		$translated = $this->theme_builder->translate_object_id( $sub_id, $name, $sub_name, $language_id );

		return 0 < $translated ? $translated : $sub_id;
	}

	/**
	 * Gives a translation the term that says what kind of template it is.
	 *
	 * @param int $target_post_id Target translated post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	private function copy_template_type( $target_post_id, $source_post_id ) {
		$taxonomy = ElementorThemeBuilder::TYPE_TAXONOMY;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$assigned = wp_get_object_terms( $target_post_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $assigned ) || ! empty( $assigned ) ) {
			return;
		}

		$terms = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		wp_set_object_terms( $target_post_id, array_map( 'absint', $terms ), $taxonomy );
	}

	/**
	 * Reports whether a post is a Theme Builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return ElementorThemeBuilder::POST_TYPE === get_post_type( absint( $post_id ) );
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
		 * Returning false leaves the post type exactly as the settings screen
		 * configured it, so a site can keep one set of headers, footers, and popups
		 * for every language.
		 *
		 * @param bool $translate Whether Theme Builder templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_elementor_translate_templates', true );
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
	 *
	 * The administration resolves locations to describe them — the Theme Builder
	 * listing, the conditions conflict notice — and an editor asking which
	 * template holds a rule must be answered with the template that holds it.
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

	/**
	 * Returns Elementor Pro's condition cache when the Theme Builder is loaded.
	 *
	 * Reached through Elementor's own module registry rather than the module's
	 * static accessor, which would construct a second Theme Builder on a site
	 * where Elementor Pro is inactive.
	 *
	 * @return object|null
	 */
	private function get_conditions_cache() {
		if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			return null;
		}

		$plugin = \ElementorPro\Plugin::instance();

		if ( ! is_object( $plugin ) || ! isset( $plugin->modules_manager ) || ! is_object( $plugin->modules_manager ) ) {
			return null;
		}

		$theme_builder = $plugin->modules_manager->get_modules( 'theme-builder' );

		if ( ! is_object( $theme_builder ) || ! method_exists( $theme_builder, 'get_conditions_manager' ) ) {
			return null;
		}

		$conditions = $theme_builder->get_conditions_manager();

		if ( ! is_object( $conditions ) || ! method_exists( $conditions, 'get_cache' ) ) {
			return null;
		}

		$cache = $conditions->get_cache();

		return is_object( $cache ) && method_exists( $cache, 'regenerate' ) ? $cache : null;
	}
}
