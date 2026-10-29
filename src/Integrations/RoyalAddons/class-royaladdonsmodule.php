<?php
/**
 * Royal Elementor Addons Theme Builder integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\RoyalAddons;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Royal Addons headers, footers and theme templates across languages.
 *
 * Royal answers every location from one option per kind: a map of template slugs
 * against the pages they claim. Everything the builder does with a location
 * reads that map — whether a header renders at all, which stylesheet is loaded,
 * which template is finally printed — so the map is the only thing that has to
 * be answered in the language being read, and it is answered where WordPress
 * hands the option over rather than anywhere inside the builder.
 *
 * That makes the rest of this module small. Templates have to be translatable,
 * a translation has to carry the metadata that says what kind of template it is,
 * and the builder's own lookup by slug has to be left alone by the language
 * filtering — a narrowed lookup would answer a language whose header nobody has
 * translated with no header at all, where the whole map arrangement answers with
 * the one the site already had.
 */
final class RoyalAddonsModule implements ModuleInterface {

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var RoyalAddonsTemplates
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
	 * Rewritten condition maps, keyed by language and option.
	 *
	 * @var array<string, mixed>
	 */
	private $rewritten = array();

	/**
	 * Whether a condition map is being rewritten right now.
	 *
	 * @var bool
	 */
	private $rewriting = false;

	/**
	 * Constructor.
	 *
	 * @param RoyalAddonsTemplates   $templates         Theme Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		RoyalAddonsTemplates $templates,
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
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_template_meta' ), 20, 2 );

		foreach ( RoyalAddonsTemplates::CONDITION_OPTIONS as $option ) {
			add_filter( 'option_' . $option, array( $this, 'translate_conditions' ) );
		}

		// Elementor's own hook, fired once the frontend stylesheet is enqueued and
		// before it collects the styles of the documents being rendered.
		add_action( 'elementor/frontend/after_enqueue_styles', array( $this, 'prepare_template_styles' ) );
	}

	/**
	 * Makes builder templates translatable alongside the site's own content.
	 *
	 * The post type is public and carries an administrative UI, so it already
	 * appears in the translatable types list. It is selected here rather than left
	 * for a site owner to tick, because until it is ticked the rest of this module
	 * has nothing to work with: templates carry no language, so a condition map
	 * has nothing to be rewritten into. The checkbox therefore reads as selected
	 * and stays that way; a site that would rather keep one set of headers and
	 * footers for every language says so through the filter below, which leaves
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

		if ( in_array( RoyalAddonsTemplates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = RoyalAddonsTemplates::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Leaves the builder's own template lookup unnarrowed.
	 *
	 * The builder checks that the slug its map named still belongs to a template,
	 * and treats a miss as "no template at all". Narrowing that check to the
	 * language being read would turn every untranslated header into a missing one,
	 * which is the opposite of what the rewritten map arranges.
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

		return array( RoyalAddonsTemplates::POST_TYPE ) === $post_types ? false : $filter;
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

		return array_merge( $meta_keys, RoyalAddonsTemplates::meta_keys() );
	}

	/**
	 * Gives a translation the metadata and the type that make it a template.
	 *
	 * The Elementor copy runs only for a post that already holds element data, so
	 * a template translated before anyone opened it in the editor would arrive
	 * with nothing saying what kind of template it is — invisible to the builder's
	 * own screens, and unable to answer a location. Keys already written are left
	 * alone, which is what makes this safe to run after that copy rather than
	 * instead of it.
	 *
	 * The kind is recorded twice by this builder, once in metadata and once as a
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

		foreach ( RoyalAddonsTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			update_post_meta( $target_post_id, $meta_key, get_post_meta( $source_post_id, $meta_key, true ) );
		}

		$this->copy_template_type( $target_post_id, $source_post_id );
	}

	/**
	 * Answers one stored condition map in the language being read.
	 *
	 * @param mixed $value Stored option value, a JSON encoded map.
	 * @return mixed A value of the same shape.
	 */
	public function translate_conditions( $value ) {
		if ( $this->rewriting || ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( ! $this->resolving_for_visitor() || ! $this->translating_templates() ) {
			return $value;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $value;
		}

		$cache_key = $language_id . ':' . md5( $value );

		if ( ! array_key_exists( $cache_key, $this->rewritten ) ) {
			$this->rewritten[ $cache_key ] = $this->rewrite_conditions( $value, $language_id );
		}

		return $this->rewritten[ $cache_key ];
	}

	/**
	 * Asks for the styles of every template this request will print.
	 *
	 * The builder loads the older per-post stylesheet of whichever templates its
	 * map named, which is most of the work and is why its headers are not
	 * completely unstyled. What it never does is tell Elementor those documents
	 * are rendering, and without that the per-element rules of a document are
	 * never written at all — what is left is a header with the shared base styles
	 * and none of its own, which reads as a layout that collapsed into a stack.
	 *
	 * Both are asked for here, where Elementor is still collecting, and for the
	 * template the rewritten map named rather than the one the builder read.
	 *
	 * @return void
	 */
	public function prepare_template_styles() {
		if ( ! $this->resolving_for_visitor() ) {
			return;
		}

		foreach ( $this->templates->get_rendered_template_ids() as $template_id ) {
			/**
			 * Filters whether a template's own styles are asked for early.
			 *
			 * Returning false leaves the builder's asset handling alone, for a site
			 * where something else already announces these templates.
			 *
			 * @param bool $prepare     Whether the styles are asked for.
			 * @param int  $template_id Template identifier that will print.
			 */
			if ( ! apply_filters( 'shapetranslate_royaladdons_template_styles', true, $template_id ) ) {
				continue;
			}

			$this->templates->announce_atomic_styles( $template_id );
			$this->templates->enqueue_template_css( $template_id );
		}
	}

	/**
	 * Rewrites one stored condition map for a language.
	 *
	 * The value is JSON because that is how the builder stores what its conditions
	 * screen returned, and it is handed back as JSON for the same reason. Anything
	 * that does not decode to a map is returned untouched rather than repaired.
	 *
	 * @param string $value       Stored option value.
	 * @param string $language_id Language being read.
	 * @return string
	 */
	private function rewrite_conditions( $value, $language_id ) {
		$conditions = json_decode( $value, true );

		if ( ! is_array( $conditions ) || empty( $conditions ) ) {
			return $value;
		}

		$this->rewriting = true;

		$translated = $this->templates->translate_conditions( $conditions, $language_id );

		$this->rewriting = false;

		/**
		 * Filters the condition map one language reads.
		 *
		 * @param array<string, mixed> $translated  Map after language resolution.
		 * @param array<string, mixed> $conditions  Map as the builder stored it.
		 * @param string               $language_id Language being read.
		 */
		$translated = apply_filters(
			'shapetranslate_royaladdons_conditions',
			$translated,
			$conditions,
			$language_id
		);

		if ( ! is_array( $translated ) || $translated === $conditions ) {
			return $value;
		}

		$encoded = wp_json_encode( $translated );

		return is_string( $encoded ) ? $encoded : $value;
	}

	/**
	 * Gives a translation the term that says what kind of template it is.
	 *
	 * @param int $target_post_id Target translated post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	private function copy_template_type( $target_post_id, $source_post_id ) {
		$taxonomy = RoyalAddonsTemplates::TYPE_TAXONOMY;

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
	 * Reports whether a post is a builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return RoyalAddonsTemplates::POST_TYPE === get_post_type( absint( $post_id ) );
	}

	/**
	 * Reports whether builder templates are translated at all.
	 *
	 * @return bool
	 */
	private function translating_templates() {
		/**
		 * Filters whether Royal Addons templates are translated.
		 *
		 * Returning false leaves the post type exactly as the settings screen
		 * configured it and every condition map exactly as it was stored, so a site
		 * can keep one set of headers and footers for every language.
		 *
		 * @param bool $translate Whether Royal Addons templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_royaladdons_translate_templates', true );
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
	 *
	 * The conditions screen reads the same options to describe them, and an editor
	 * asking which pages a template claims must be answered with what they saved,
	 * not with what another language makes of it.
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
