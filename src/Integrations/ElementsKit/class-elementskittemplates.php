<?php
/**
 * ElementsKit header and footer language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\ElementsKit;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the ElementsKit header and footer state that names a language.
 *
 * ElementsKit keeps each header and footer in an `elementskit_template` post and
 * decides once per request which one fills each of the two locations. Two things
 * in that state belong to one language. The template itself is written in a
 * language, and a condition that limits a template to particular pages names
 * them by ID, so a copied condition keeps pointing at the language it came from.
 *
 * The builder is language-blind when it picks — with two headers claiming the
 * whole site it keeps the last one it walks past, for every reader. This class
 * answers which template belongs to the language being read and what a copied
 * condition means once it moves. Deciding when to ask is ElementsKitModule's job.
 */
final class ElementsKitTemplates {

	/**
	 * Post type holding header and footer templates.
	 */
	const POST_TYPE = 'elementskit_template';

	/**
	 * Metadata naming which location a template fills.
	 */
	const TYPE_META_KEY = 'elementskit_template_type';

	/**
	 * Metadata switching a template on without unpublishing it.
	 */
	const ACTIVATION_META_KEY = 'elementskit_template_activation';

	/**
	 * Metadata naming the kind of condition a template carries.
	 */
	const CONDITION_META_KEY = 'elementskit_template_condition_a';

	/**
	 * Metadata naming the post type a singular condition narrows to.
	 */
	const SINGULAR_META_KEY = 'elementskit_template_condition_singular';

	/**
	 * Metadata listing the posts a singular condition names, comma separated.
	 */
	const SINGULAR_IDS_META_KEY = 'elementskit_template_condition_singular_id';

	/**
	 * Object cache key holding the header and footer chosen for this request.
	 */
	const CACHE_KEY = 'elementskit_template_ids';

	/**
	 * The builder's own entry class, defined as its plugin file is read.
	 */
	const PLUGIN_CLASS = 'ElementsKit_Lite';

	/**
	 * Classes that resolve and cache the two template identifiers, each mapped to
	 * the class that enqueues a template's generated Elementor stylesheet.
	 *
	 * ElementsKit Pro replaces the free header and footer module with its own,
	 * so on a site running both only the Pro activator is live. It is listed
	 * first so that it is the one asked.
	 */
	const ACTIVATOR_CLASSES = array(
		'\\ElementsKit\\Modules\\Header_Footer\\Activator'      => '\\ElementsKit\\Utils',
		'\\ElementsKit_Lite\\Modules\\Header_Footer\\Activator' => '\\ElementsKit_Lite\\Utils',
	);

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
			self::ACTIVATION_META_KEY,
			self::CONDITION_META_KEY,
			self::SINGULAR_META_KEY,
			self::SINGULAR_IDS_META_KEY,
		);
	}

	/**
	 * Reports whether the ElementsKit header and footer builder is loaded.
	 *
	 * @return bool
	 */
	public function is_available() {
		/*
		 * The entry class, not the header and footer builder itself. The builder
		 * registers its autoloader on `plugins_loaded` at priority 100, long after
		 * ShapeTranslate composes its modules, so asking for a class of the builder's
		 * here answers no on every site that has it. The entry class is defined as
		 * the plugin file is read, which is before any of this; what it cannot
		 * promise is that the builder loaded, and can_resolve() asks that later,
		 * when the answer is knowable.
		 */
		$available = class_exists( self::PLUGIN_CLASS, false );

		/**
		 * Filters whether the ElementsKit integration is available.
		 *
		 * @param bool $available Whether the ElementsKit plugin is present.
		 */
		return (bool) apply_filters( 'shapetranslate_elementskit_available', $available );
	}

	/**
	 * Reports whether the header and footer builder is loaded and answerable.
	 *
	 * @return bool
	 */
	public function can_resolve() {
		return '' !== $this->activator_class();
	}

	/**
	 * Returns the activator the builder is actually running.
	 *
	 * Only an activator that already has its instance counts. Asking one that
	 * does not would make it create that instance, and its constructor hooks
	 * the header and footer onto `wp` a second time — with Pro active the free
	 * activator was never started, and starting it here printed every header
	 * and footer twice.
	 *
	 * @return string Empty when the header and footer module is not running.
	 */
	private function activator_class() {
		foreach ( array_keys( self::ACTIVATOR_CLASSES ) as $class ) {
			if (
				class_exists( $class )
				&& property_exists( $class, 'instance' )
				&& null !== $class::$instance
				&& method_exists( $class, 'template_ids' )
			) {
				return $class;
			}
		}

		return '';
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
	 * Asks the builder which header and footer this request resolved to.
	 *
	 * The answer is cached under a key that says nothing about language, so the
	 * cache is dropped first. On a site with a persistent object cache the entry
	 * would otherwise survive the request it was written in, and the language
	 * resolved into it would be served to whoever asked next.
	 *
	 * @return array<int, mixed> The header identifier, then the footer's.
	 */
	public function get_source_template_ids() {
		$activator = $this->activator_class();

		if ( '' === $activator ) {
			return array();
		}

		wp_cache_delete( self::CACHE_KEY );

		$ids = call_user_func( array( $activator, 'template_ids' ) );

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Tells the builder which header and footer to render instead.
	 *
	 * @param array<int, mixed> $ids The header identifier, then the footer's.
	 * @return void
	 */
	public function set_template_ids( array $ids ) {
		wp_cache_set( self::CACHE_KEY, $ids );
	}

	/**
	 * Enqueues the generated stylesheet of one template.
	 *
	 * The builder renders the stylesheet of the template it resolved, which is
	 * the source language's. A translation is a document of its own with a
	 * stylesheet of its own, so the one being rendered has to be asked for.
	 *
	 * @param int $template_id Template post identifier.
	 * @return void
	 */
	public function render_css( $template_id ) {
		$template_id = absint( $template_id );
		$activator   = $this->activator_class();

		if ( 0 === $template_id || '' === $activator ) {
			return;
		}

		$utils = self::ACTIVATOR_CLASSES[ $activator ];

		if ( class_exists( $utils ) && method_exists( $utils, 'render_elementor_content_css' ) ) {
			call_user_func( array( $utils, 'render_elementor_content_css' ), $template_id );
		}
	}

	/**
	 * Announces a template to Elementor's atomic style pipeline.
	 *
	 * Elementor 4 gives each element of a document a generated class and writes
	 * those rules to a file per document, but only for the documents it was told
	 * were rendering. A page's own document announces itself; a header the theme
	 * printed from somewhere else does not, and arrives with the shared base
	 * styles and none of its own — the layout collapses to plain stacked
	 * content. The announcement has to be made before Elementor enqueues post
	 * styles, which is why this is said while the template is being resolved
	 * rather than while it is being printed.
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
