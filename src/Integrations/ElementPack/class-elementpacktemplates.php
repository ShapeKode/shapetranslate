<?php
/**
 * Element Pack Template Builder language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\ElementPack;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Element Pack Template Builder state that names a language.
 *
 * Element Pack keeps every header, footer, single and archive template in a
 * `bdt-template-builder` post and decides once per request which header and
 * footer fill the page. Two things in that state belong to one language. The
 * template itself is written in a language, and a condition that limits a
 * template to particular pages names them by ID, so a copied condition keeps
 * pointing at the language it came from.
 *
 * Unlike most builders, whether a template is switched on is not kept with the
 * template. It is an option named after the template type and identifier —
 * `_bdthemes_builder_themes|header__42` — and a template without one is never
 * matched, whatever its conditions say. A translation therefore needs that
 * option as much as it needs its metadata.
 *
 * The builder is language-blind when it picks. This class answers which template
 * belongs to the language being read and what a copied condition means once it
 * moves. Deciding when to ask is ElementPackModule's job.
 */
final class ElementPackTemplates {

	/**
	 * Post type holding header, footer, single and archive templates.
	 */
	const POST_TYPE = 'bdt-template-builder';

	/**
	 * Metadata naming which location a template fills, as `themes|header`.
	 */
	const TYPE_META_KEY = '_bdthemes_builder_template_type';

	/**
	 * Metadata naming the editor a template is built with.
	 */
	const EDIT_WITH_META_KEY = '_bdthemes_builder_edit_with';

	/**
	 * Metadata naming the kind of condition a template carries.
	 */
	const CONDITION_META_KEY = '_bdthemes_builder_template_condition_a';

	/**
	 * Metadata naming what a singular condition narrows to.
	 */
	const SINGULAR_META_KEY = '_bdthemes_builder_template_condition_singular';

	/**
	 * Metadata listing the posts a singular condition names, comma separated.
	 */
	const SINGULAR_IDS_META_KEY = '_bdthemes_builder_template_condition_singular_id';

	/**
	 * Prefix of the option that switches one template on.
	 */
	const ENABLED_OPTION_PREFIX = '_bdthemes_builder_';

	/**
	 * Template types the builder prints around the page rather than as it.
	 *
	 * @var array<int, string>
	 */
	const THEME_TYPES = array( 'themes|header', 'themes|footer' );

	/**
	 * Object cache key holding the templates chosen for this request.
	 */
	const CACHE_KEY = 'bdthemes_template_builder_template_ids';

	/**
	 * Positions of the header and footer in the cached identifiers.
	 *
	 * @var array<int, int>
	 */
	const OUTER_SLOTS = array( 0, 1 );

	/**
	 * Constant defined as the builder's plugin file is read.
	 */
	const PLUGIN_CONSTANT = 'BDTEP_VER';

	/**
	 * Class that resolves and caches the header and footer identifiers.
	 */
	const ACTIVATOR_CLASS = '\\ElementPack\\Builder\\Activator';

	/**
	 * Elementor's generated stylesheet for one document.
	 */
	const POST_CSS_CLASS = '\\Elementor\\Core\\Files\\CSS\\Post';

	/**
	 * Elementor document language resolution.
	 *
	 * @var ElementorThemeBuilder
	 */
	private $documents;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Constructor.
	 *
	 * @param ElementorThemeBuilder  $documents         Elementor document language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct(
		ElementorThemeBuilder $documents,
		PostTranslationManager $post_translations
	) {
		$this->documents         = $documents;
		$this->post_translations = $post_translations;
	}

	/**
	 * Returns the metadata a translation needs to behave like its source.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys() {
		return array(
			self::TYPE_META_KEY,
			self::EDIT_WITH_META_KEY,
			self::CONDITION_META_KEY,
			self::SINGULAR_META_KEY,
			self::SINGULAR_IDS_META_KEY,
		);
	}

	/**
	 * Reports whether Element Pack is present.
	 *
	 * The constant, not the builder's classes. Element Pack loads its Template
	 * Builder once Elementor has initialized, long after ShapeTranslate composes its
	 * modules, so asking for a class here answers no on every site that has it.
	 * can_resolve() asks that later, when the answer is knowable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = defined( self::PLUGIN_CONSTANT );

		/**
		 * Filters whether the Element Pack integration is available.
		 *
		 * @param bool $available Whether the Element Pack plugin is present.
		 */
		return (bool) apply_filters( 'shapetranslate_elementpack_available', $available );
	}

	/**
	 * Reports whether the header and footer builder is loaded and answerable.
	 *
	 * Only an activator that already has its instance counts. The builder creates
	 * it as its file is read, and its constructor hooks the header and footer onto
	 * `wp`; creating one here would hook them a second time.
	 *
	 * @return bool
	 */
	public function can_resolve() {
		$class = self::ACTIVATOR_CLASS;

		return class_exists( $class )
			&& property_exists( $class, 'instance' )
			&& null !== $class::$instance
			&& method_exists( $class, 'template_ids' );
	}

	/**
	 * Returns the location a template fills.
	 *
	 * @param int $template_id Template post identifier.
	 * @return string Empty when the post names no location.
	 */
	public function get_template_type( $template_id ) {
		$type = get_post_meta( absint( $template_id ), self::TYPE_META_KEY, true );

		return is_string( $type ) ? $type : '';
	}

	/**
	 * Returns the published translation of one template.
	 *
	 * @param int    $template_id Template post identifier.
	 * @param string $language_id Language being rendered.
	 * @return int Zero when no published translation applies.
	 */
	public function translate_template_id( $template_id, $language_id ) {
		return $this->documents->translate_template_id( $template_id, $language_id );
	}

	/**
	 * Switches a translated header or footer on when its source is on.
	 *
	 * Only headers and footers. The builder picks those by matching conditions
	 * against every switched-on template, so a translation whose condition was
	 * rewritten to its own language's pages matches nothing without the option.
	 * Single and archive templates are picked by reading the option itself, and
	 * a second option there would let the builder hand one language's template to
	 * another; the source's option is enough, because the module translates
	 * whatever the builder read.
	 *
	 * @param int $source_id Source template identifier.
	 * @param int $target_id Translated template identifier.
	 * @return bool Whether the option was written.
	 */
	public function copy_enabled_state( $source_id, $target_id ) {
		$source_id = absint( $source_id );
		$target_id = absint( $target_id );
		$type      = $this->get_template_type( $source_id );

		if ( 0 === $source_id || 0 === $target_id || ! in_array( $type, self::THEME_TYPES, true ) ) {
			return false;
		}

		if ( ! get_option( $this->enabled_option_name( $type, $source_id ) ) ) {
			return false;
		}

		$target_option = $this->enabled_option_name( $type, $target_id );

		if ( false !== get_option( $target_option ) ) {
			return false;
		}

		return update_option( $target_option, $target_id );
	}

	/**
	 * Asks the builder which header and footer this request resolved to.
	 *
	 * The answer is cached under a key that says nothing about language, so the
	 * cache is dropped first. On a site with a persistent object cache the entry
	 * would otherwise survive the request it was written in, and the language
	 * resolved into it would be served to whoever asked next.
	 *
	 * @return array<int, mixed> The header identifier, the footer's, then the single template's.
	 */
	public function get_source_template_ids() {
		if ( ! $this->can_resolve() ) {
			return array();
		}

		wp_cache_delete( self::CACHE_KEY );

		$ids = call_user_func( array( self::ACTIVATOR_CLASS, 'template_ids' ) );

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Tells the builder which header and footer to render instead.
	 *
	 * The builder reads this cache on `wp` to construct the adapter it prints
	 * through — a bundled theme adapter keeps the identifiers it was handed, its
	 * own theme support asks again as it prints — so writing it before then
	 * reaches every path.
	 *
	 * @param array<int, mixed> $ids The header identifier, the footer's, then the single template's.
	 * @return void
	 */
	public function set_template_ids( array $ids ) {
		wp_cache_set( self::CACHE_KEY, $ids );
	}

	/**
	 * Enqueues the generated stylesheet of one template.
	 *
	 * The builder asks for this stylesheet only as it prints the template — by
	 * then the document head has been written and the rules arrive after the
	 * markup they style. Asking here is the same request made early enough to land
	 * in the head, and Elementor ignores the second one.
	 *
	 * @param int $template_id Template post identifier.
	 * @return void
	 */
	public function enqueue_template_css( $template_id ) {
		$template_id = absint( $template_id );

		if (
			0 === $template_id
			|| ! class_exists( self::POST_CSS_CLASS )
			|| ! method_exists( self::POST_CSS_CLASS, 'create' )
		) {
			return;
		}

		$css_file = call_user_func( array( self::POST_CSS_CLASS, 'create' ), $template_id );

		if ( is_object( $css_file ) && method_exists( $css_file, 'enqueue' ) ) {
			$css_file->enqueue();
		}
	}

	/**
	 * Announces a template to Elementor's atomic style pipeline.
	 *
	 * Elementor writes the per-element rules of a document only for documents it
	 * was told were rendering. A header printed from outside the page does not
	 * announce itself, and arrives with the shared base styles and none of its
	 * own. The announcement has to be made before Elementor enqueues post styles.
	 *
	 * @param int $template_id Template post identifier.
	 * @return void
	 */
	public function announce_atomic_styles( $template_id ) {
		$template_id = absint( $template_id );

		if ( 0 === $template_id || ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		// Elementor's own hook, called rather than declared: it is how a document
		// announces itself to that pipeline, so the name belongs to Elementor and
		// prefixing it would announce nothing.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'elementor/post/render', $template_id );
	}

	/**
	 * Rewrites a stored list of condition posts for one language.
	 *
	 * The list is comma separated because that is how the builder stores what its
	 * multi-select returned. An identifier naming nothing translatable is kept as
	 * it is: the original is a better answer than a dropped entry, which would
	 * quietly widen or narrow where a header appears.
	 *
	 * @param mixed  $ids         Stored identifiers, comma separated.
	 * @param string $language_id Language the condition is moving to.
	 * @return mixed Identifiers in the same order and shape.
	 */
	public function translate_singular_ids( $ids, $language_id ) {
		$language_id = (string) $language_id;

		if ( ! is_string( $ids ) || '' === trim( $ids ) || '' === $language_id ) {
			return $ids;
		}

		$translated = array();

		foreach ( explode( ',', $ids ) as $entry ) {
			$post_id = absint( trim( $entry ) );

			if ( 0 === $post_id ) {
				$translated[] = $entry;
				continue;
			}

			$target       = $this->translate_post( $post_id, $language_id );
			$translated[] = 0 === $target ? $entry : (string) $target;
		}

		return implode( ',', $translated );
	}

	/**
	 * Returns the option name that switches one template on.
	 *
	 * Lower-cased the way the builder writes and reads it.
	 *
	 * @param string $type        Template type, as `themes|header`.
	 * @param int    $template_id Template post identifier.
	 * @return string
	 */
	private function enabled_option_name( $type, $template_id ) {
		return strtolower( self::ENABLED_OPTION_PREFIX . $type ) . '__' . absint( $template_id );
	}

	/**
	 * Returns the translation of a post a condition names.
	 *
	 * @param int    $post_id     Post identifier stored in the condition.
	 * @param string $language_id Target language identifier.
	 * @return int Zero when the condition names nothing translatable.
	 */
	private function translate_post( $post_id, $language_id ) {
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) || ! $this->post_translations->supports_post_type( $post_type ) ) {
			return 0;
		}

		$target = absint( $this->post_translations->get_translation( $post_id, $language_id ) );

		return $target === $post_id ? 0 : $target;
	}
}
