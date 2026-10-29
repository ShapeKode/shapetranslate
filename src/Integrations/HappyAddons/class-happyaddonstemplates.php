<?php
/**
 * Happy Elementor Addons Theme Builder language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\HappyAddons;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Happy Addons Theme Builder state that names a language.
 *
 * Happy Addons keeps every header, footer, single and archive template in a
 * `ha_library` post, and decides which one fills a location by matching display
 * conditions written as paths — `include/general`, `include/singular/page/42`.
 * Two things in that state belong to one language. The template itself is
 * written in a language, and `42` is a page that has a translation of its own.
 *
 * The builder matches those conditions without ever asking which language it is
 * rendering, then fills each location with the first template that matched. So a
 * site holding an English and a Bengali header on the same "Entire Site"
 * condition hands whichever was recorded first to every reader. This class
 * answers which template belongs to the language being read, and what a copied
 * condition means once it moves to another language. Deciding when to ask those
 * questions is HappyAddonsModule's job.
 *
 * The condition path is Elementor's own shape, which is why rewriting one is
 * delegated rather than repeated: the builder inherited the format, so a
 * condition naming a page means here exactly what it means there.
 */
final class HappyAddonsTemplates {

	/**
	 * Post type holding header, footer, single and archive templates.
	 */
	const POST_TYPE = 'ha_library';

	/**
	 * Metadata naming which location a template fills.
	 */
	const TYPE_META_KEY = '_ha_library_type';

	/**
	 * Metadata holding the conditions that make a template apply.
	 */
	const CONDITIONS_META_KEY = '_ha_display_cond';

	/**
	 * Metadata switching a template on without unpublishing it.
	 */
	const ACTIVE_META_KEY = '_ha_template_active';

	/**
	 * Metadata naming the page template a singular document prints through.
	 */
	const PAGE_TEMPLATE_META_KEY = '_wp_page_template';

	/**
	 * Constant defined as the builder's plugin file is read.
	 */
	const PLUGIN_CONSTANT = 'HAPPY_ADDONS_VERSION';

	/**
	 * Class that matches display conditions and answers a location.
	 */
	const CONDITION_MANAGER_CLASS = '\\Happy_Addons\\Elementor\\Classes\\Condition_Manager';

	/**
	 * Class holding the option the builder matches its conditions from.
	 */
	const CONDITIONS_CACHE_CLASS = '\\Happy_Addons\\Elementor\\Classes\\Conditions_Cache';

	/**
	 * Class holding the single and archive template chosen for a request.
	 */
	const THEME_BUILDER_CLASS = '\\Happy_Addons\\Elementor\\Classes\\Theme_Builder';

	/**
	 * Elementor's generated stylesheet for one document.
	 */
	const POST_CSS_CLASS = '\\Elementor\\Core\\Files\\CSS\\Post';

	/**
	 * Locations the builder fills from outside the page's own template.
	 *
	 * @var array<int, string>
	 */
	const OUTER_LOCATIONS = array( 'header', 'footer' );

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
	 * Copying an Elementor document alone produces a translated layout that fills
	 * no location: without the template type the builder does not know it is a
	 * header, and without the conditions it never matches a request.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys() {
		return array(
			self::TYPE_META_KEY,
			self::CONDITIONS_META_KEY,
			self::ACTIVE_META_KEY,
			self::PAGE_TEMPLATE_META_KEY,
		);
	}

	/**
	 * Returns the metadata keys holding display conditions.
	 *
	 * @return array<int, string>
	 */
	public static function condition_meta_keys() {
		return array( self::CONDITIONS_META_KEY );
	}

	/**
	 * Reports whether Happy Elementor Addons is present.
	 *
	 * The constant, not one of the builder's classes. The builder registers its
	 * autoloader on `plugins_loaded`, the same hook ShapeTranslate composes its
	 * modules on, so asking for a class here answers no on whichever sites load
	 * the two in the wrong order. The constant is defined as the plugin file is
	 * read, which is before any of this; what it cannot promise is that the Theme
	 * Builder finished loading, and can_resolve() asks that later, when the answer
	 * is knowable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = defined( self::PLUGIN_CONSTANT );

		/**
		 * Filters whether the Happy Elementor Addons integration is available.
		 *
		 * @param bool $available Whether the builder is present.
		 */
		return (bool) apply_filters( 'shapetranslate_happyaddons_available', $available );
	}

	/**
	 * Reports whether the Theme Builder is loaded and answerable.
	 *
	 * @return bool
	 */
	public function can_resolve() {
		return class_exists( self::CONDITION_MANAGER_CLASS )
			&& method_exists( self::CONDITION_MANAGER_CLASS, 'instance' )
			&& method_exists( self::CONDITION_MANAGER_CLASS, 'get_documents_for_location' );
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
	 * Returns the language a template is written in.
	 *
	 * @param int $template_id Template post identifier.
	 * @return string Empty when the template carries no language.
	 */
	public function get_template_language_id( $template_id ) {
		return (string) $this->post_translations->get_post_language_id( absint( $template_id ) );
	}

	/**
	 * Rewrites a stored condition list for one language.
	 *
	 * @param mixed  $conditions  Stored display conditions.
	 * @param string $language_id Language the conditions are moving to.
	 * @return mixed Conditions in the same shape and order.
	 */
	public function translate_conditions( $conditions, $language_id ) {
		if ( ! is_array( $conditions ) ) {
			return $conditions;
		}

		return $this->documents->translate_conditions( $conditions, (string) $language_id );
	}

	/**
	 * Returns the template one location holds in a language of its own.
	 *
	 * Asked only once a template has been matched and found to belong to another
	 * language, and only on a site whose templates carry languages at all. It
	 * covers the header someone wrote directly in Bengali rather than translating
	 * the English one: nothing links the two, so there is no translation to
	 * resolve, but the location has still been answered in the wrong language.
	 *
	 * The builder's own order is kept, so the template that wins here is the one
	 * whose conditions the builder itself judged most specific.
	 *
	 * @param string $location    Location being filled.
	 * @param string $language_id Language being rendered.
	 * @return int Zero when the location holds nothing in that language.
	 */
	public function find_for_language( $location, $language_id ) {
		$language_id = (string) $language_id;

		if ( '' === $language_id ) {
			return 0;
		}

		$documents = $this->get_location_documents( $location );

		if ( empty( $documents ) ) {
			return 0;
		}

		$this->prime_templates( $documents );

		foreach ( $documents as $document ) {
			$template_id = absint( $document );

			if ( 0 === $template_id || $language_id !== $this->get_template_language_id( $template_id ) ) {
				continue;
			}

			if ( 'publish' === get_post_status( $template_id ) ) {
				return $template_id;
			}
		}

		return 0;
	}

	/**
	 * Returns the template the builder would fill one location with.
	 *
	 * The builder takes the first of everything a location matched, having sorted
	 * them so the most specific conditions come first, and that is the one asked
	 * for here.
	 *
	 * @param string $location Location being filled.
	 * @return int Zero when the location matched nothing.
	 */
	public function get_location_template_id( $location ) {
		$documents = $this->get_location_documents( $location );

		return empty( $documents ) ? 0 : absint( reset( $documents ) );
	}

	/**
	 * Returns the single or archive template chosen for this request.
	 *
	 * Read off the builder rather than worked out again, because whether a
	 * location is filled at all depends on questions only the builder has asked by
	 * now — whether the page was built in Elementor already, whether the theme is
	 * printing an archive. It settles on one template while choosing the page
	 * template, which is early enough for anything that has to be enqueued.
	 *
	 * @return int Zero before the builder has chosen, or when it chose nothing.
	 */
	public function get_singular_template_id() {
		if (
			! class_exists( self::THEME_BUILDER_CLASS )
			|| ! method_exists( self::THEME_BUILDER_CLASS, 'instance' )
		) {
			return 0;
		}

		$builder = call_user_func( array( self::THEME_BUILDER_CLASS, 'instance' ) );

		return is_object( $builder ) && isset( $builder->singular_template )
			? absint( $builder->singular_template )
			: 0;
	}

	/**
	 * Announces a template to Elementor's atomic style pipeline.
	 *
	 * Elementor gives each element of a document a generated class and writes
	 * those rules per document, but only for the documents it was told were
	 * rendering. A page's own document announces itself; a header the builder
	 * prints from somewhere else does not, and arrives with the shared base styles
	 * and none of its own — the layout collapses to plain stacked content. The
	 * announcement has to be made before Elementor enqueues post styles, which is
	 * why this is said while styles are being collected rather than while the
	 * template is being printed.
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
	 * Enqueues the generated stylesheet of one template.
	 *
	 * The builder asks for this stylesheet too, but only as it prints the
	 * template — by then the document head has been written and the rules arrive
	 * after the markup they style. Asking here is the same request made early
	 * enough to land in the head, and Elementor ignores the second one.
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
	 * Returns every template one location matched, in the builder's own order.
	 *
	 * @param string $location Location being filled.
	 * @return array<int, mixed> Empty when the builder cannot answer.
	 */
	private function get_location_documents( $location ) {
		$location = (string) $location;

		if ( '' === $location || ! $this->can_resolve() ) {
			return array();
		}

		$manager = call_user_func( array( self::CONDITION_MANAGER_CLASS, 'instance' ) );

		if ( ! is_object( $manager ) ) {
			return array();
		}

		$documents = $manager->get_documents_for_location( $location );

		return is_array( $documents ) ? $documents : array();
	}

	/**
	 * Records a template's conditions where the builder matches them from.
	 *
	 * The builder does not read conditions from post metadata while rendering. It
	 * reads them from one option it rebuilds whenever conditions are saved through
	 * its own editor, and a translation is never saved that way — so a translated
	 * header carrying a perfectly good condition would be matched by nothing, and
	 * the language it was written for would render with no header at all. Writing
	 * the entry is what makes the copied condition count.
	 *
	 * The option is re-read first because the builder holds it in a singleton
	 * built early in the request, and saving a stale copy would drop whatever was
	 * recorded in between.
	 *
	 * @param int                $template_id Template post identifier.
	 * @param string             $location    Location the template fills.
	 * @param array<int, string> $conditions  Display conditions to record.
	 * @return bool Whether the entry was written.
	 */
	public function register_conditions( $template_id, $location, array $conditions ) {
		$template_id = absint( $template_id );
		$location    = (string) $location;

		if ( 0 === $template_id || '' === $location ) {
			return false;
		}

		if (
			! class_exists( self::CONDITIONS_CACHE_CLASS )
			|| ! method_exists( self::CONDITIONS_CACHE_CLASS, 'instance' )
		) {
			return false;
		}

		$cache = call_user_func( array( self::CONDITIONS_CACHE_CLASS, 'instance' ) );

		if (
			! is_object( $cache )
			|| ! method_exists( $cache, 'refresh' )
			|| ! method_exists( $cache, 'update' )
			|| ! method_exists( $cache, 'save' )
		) {
			return false;
		}

		$cache->refresh();
		$cache->update( $location, $template_id, $conditions );
		$cache->save();

		return true;
	}

	/**
	 * Warms the metadata cache for the templates one location matched.
	 *
	 * The builder collects them from an option rather than a query, so nothing
	 * primed them, and each one is then asked for its language. Reading them one
	 * post at a time is the difference between one query and a handful.
	 *
	 * @param array<int|string, mixed> $documents Templates matched for a location.
	 * @return void
	 */
	private function prime_templates( array $documents ) {
		$ids = array();

		foreach ( $documents as $document ) {
			$template_id = absint( $document );

			if ( 0 !== $template_id ) {
				$ids[] = $template_id;
			}
		}

		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', array_values( array_unique( $ids ) ) );
		}
	}
}
