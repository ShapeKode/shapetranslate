<?php
/**
 * Jeg Kit for Elementor header and footer language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\JegKit;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Jeg Kit header and footer state that names a language.
 *
 * Jeg Kit keeps every header in a `jkit-header` post and every footer in a
 * `jkit-footer` post, and fills each location by walking the published templates
 * of that post type in their saved order until one of them answers yes to its
 * display conditions. Two things in that state belong to one language. The
 * template itself is written in a language, and a condition that limits a
 * template to particular pages, terms or products names them by ID, so a copied
 * condition keeps pointing at the language it came from.
 *
 * The walk never asks which language it is rendering. A site holding an English
 * and a Bengali header that both claim the whole site therefore hands whichever
 * was saved first to every reader. This class answers which template belongs to
 * the language being read and what a copied condition means once it moves.
 * Deciding when to ask those questions is JegKitModule's job.
 */
final class JegKitTemplates {

	/**
	 * Post type holding header templates.
	 */
	const HEADER_POST_TYPE = 'jkit-header';

	/**
	 * Post type holding footer templates.
	 */
	const FOOTER_POST_TYPE = 'jkit-footer';

	/**
	 * Metadata holding the display conditions that make a template apply.
	 */
	const CONDITION_META_KEY = 'jkit-condition';

	/**
	 * Constant defined as the builder's plugin file is read.
	 */
	const PLUGIN_CONSTANT = 'JEG_ELEMENTOR_KIT_VERSION';

	/**
	 * Class that walks the templates and matches their display conditions.
	 */
	const TEMPLATE_CLASS = '\\Jeg\\Elementor_Kit\\Templates\\Template';

	/**
	 * Condition fields listing posts, comma separated.
	 *
	 * @var array<int, string>
	 */
	const POST_FIELDS = array( 'singular_post', 'product' );

	/**
	 * Condition fields listing terms, comma separated.
	 *
	 * The taxonomy is not read from the field name. Four of these name one and
	 * two do not — `singular_taxonomy` accepts terms of every taxonomy at once,
	 * and `archive_taxonomy` belongs to whichever taxonomy the row above it
	 * chose — so the term itself is asked which taxonomy it is in, which answers
	 * for all six.
	 *
	 * @var array<int, string>
	 */
	const TERM_FIELDS = array(
		'category',
		'post_tag',
		'product_cat',
		'product_tag',
		'singular_taxonomy',
		'archive_taxonomy',
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
	 * Term translation relationships.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Published templates per post type, in the order the builder walks them.
	 *
	 * @var array<string, array<int, int>>
	 */
	private $candidates = array();

	/**
	 * Constructor.
	 *
	 * @param ElementorThemeBuilder  $documents         Elementor document language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 */
	public function __construct(
		ElementorThemeBuilder $documents,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations
	) {
		$this->documents         = $documents;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
	}

	/**
	 * Returns the post types the header and footer builder answers from.
	 *
	 * @return array<int, string>
	 */
	public static function post_types() {
		return array( self::HEADER_POST_TYPE, self::FOOTER_POST_TYPE );
	}

	/**
	 * Returns the metadata a translation needs to behave like its source.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys() {
		return array( self::CONDITION_META_KEY );
	}

	/**
	 * Reports whether Jeg Kit is loaded.
	 *
	 * @return bool
	 */
	public function is_available() {
		/*
		 * The constant, not the builder's own class. Jeg Kit registers its
		 * autoloader and builds the header and footer service on `plugins_loaded`
		 * at priority 99, long after ShapeTranslate composes its modules, so asking
		 * for a class of the builder's here answers no on every site that has it.
		 * The constant is defined as the plugin file is read, which is before any
		 * of this; what it cannot promise is that the builder loaded, and
		 * can_resolve() asks that later, when the answer is knowable.
		 */
		$available = defined( self::PLUGIN_CONSTANT );

		/**
		 * Filters whether the Jeg Kit integration is available.
		 *
		 * @param bool $available Whether the Jeg Kit plugin is present.
		 */
		return (bool) apply_filters( 'shapetranslate_jegkit_available', $available );
	}

	/**
	 * Reports whether the builder is loaded and can answer about a template.
	 *
	 * @return bool
	 */
	public function can_resolve() {
		return class_exists( self::TEMPLATE_CLASS )
			&& method_exists( self::TEMPLATE_CLASS, 'instance' )
			&& method_exists( self::TEMPLATE_CLASS, 'check_conditions' );
	}

	/**
	 * Reports whether a post type is one the builder fills a location from.
	 *
	 * @param mixed $post_type Post type name.
	 * @return bool
	 */
	public function is_template_post_type( $post_type ) {
		return is_string( $post_type ) && in_array( $post_type, self::post_types(), true );
	}

	/**
	 * Returns the published templates of one location, in the builder's order.
	 *
	 * The arguments mirror the builder's own lookup — published only, ordered by
	 * the sequence its dashboard writes — because the answer has to be the list
	 * the builder is walking rather than a list of this module's own. It is asked
	 * for separately rather than read out of the builder because the builder
	 * keeps the list private, and because the question is asked from inside its
	 * walk: the builder's own lookup resets the post being rendered when it is
	 * finished, which is harmless where it calls it and is not harmless halfway
	 * through rendering a page.
	 *
	 * @param string $post_type Template post type.
	 * @return array<int, int> Template identifiers.
	 */
	public function get_templates( $post_type ) {
		if ( ! $this->is_template_post_type( $post_type ) ) {
			return array();
		}

		if ( isset( $this->candidates[ $post_type ] ) ) {
			return $this->candidates[ $post_type ];
		}

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'orderby'                => 'menu_order',
				'order'                  => 'ASC',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$this->candidates[ $post_type ] = is_array( $posts ) ? array_map( 'absint', $posts ) : array();

		return $this->candidates[ $post_type ];
	}

	/**
	 * Asks the builder whether one template claims the page being rendered.
	 *
	 * @param int $post_id     Post the request resolved to.
	 * @param int $template_id Template post identifier.
	 * @return bool
	 */
	public function conditions_match( $post_id, $template_id ) {
		if ( ! $this->can_resolve() ) {
			return false;
		}

		$builder = call_user_func( array( self::TEMPLATE_CLASS, 'instance' ) );

		if ( ! is_object( $builder ) ) {
			return false;
		}

		return (bool) $builder->check_conditions( absint( $post_id ), absint( $template_id ) );
	}

	/**
	 * Returns the language a template was written in.
	 *
	 * @param int $template_id Template post identifier.
	 * @return string Empty when the template carries no language.
	 */
	public function get_template_language_id( $template_id ) {
		return (string) $this->post_translations->get_post_language_id( absint( $template_id ) );
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
	 * Rewrites a stored condition set for one language.
	 *
	 * @param mixed  $conditions  Stored conditions, one row per rule.
	 * @param string $language_id Language the conditions are moving to.
	 * @return mixed Conditions in the same order and shape.
	 */
	public function translate_conditions( $conditions, $language_id ) {
		$language_id = (string) $language_id;

		if ( ! is_array( $conditions ) || '' === $language_id ) {
			return $conditions;
		}

		$translated = array();

		foreach ( $conditions as $key => $condition ) {
			$translated[ $key ] = is_array( $condition )
				? $this->translate_condition( $condition, $language_id )
				: $condition;
		}

		return $translated;
	}

	/**
	 * Rewrites one condition row for a language.
	 *
	 * Authors are left alone: a user account is the same person in every
	 * language, so a rule naming one means the same thing wherever it moves.
	 *
	 * @param array<string, mixed> $condition   Stored condition row.
	 * @param string               $language_id Target language identifier.
	 * @return array<string, mixed>
	 */
	private function translate_condition( array $condition, $language_id ) {
		foreach ( self::POST_FIELDS as $field ) {
			if ( isset( $condition[ $field ] ) ) {
				$condition[ $field ] = $this->translate_ids( $condition[ $field ], $language_id, 'post' );
			}
		}

		foreach ( self::TERM_FIELDS as $field ) {
			if ( isset( $condition[ $field ] ) ) {
				$condition[ $field ] = $this->translate_ids( $condition[ $field ], $language_id, 'term' );
			}
		}

		return $condition;
	}

	/**
	 * Rewrites a stored list of identifiers for one language.
	 *
	 * The list is comma separated because that is how the builder stores what its
	 * multi-selects return, and it is handed back the same way. An identifier
	 * naming nothing translatable is kept as it is: the original is a better
	 * answer than a dropped entry, which would quietly widen or narrow where a
	 * header appears.
	 *
	 * @param mixed  $ids         Stored identifiers.
	 * @param string $language_id Target language identifier.
	 * @param string $kind        Whether the identifiers name posts or terms.
	 * @return mixed Identifiers in the same order and shape.
	 */
	private function translate_ids( $ids, $language_id, $kind ) {
		if ( is_array( $ids ) ) {
			$translated = array();

			foreach ( $ids as $key => $entry ) {
				$translated[ $key ] = $this->translate_entry( $entry, $language_id, $kind );
			}

			return $translated;
		}

		if ( ! is_string( $ids ) || '' === trim( $ids ) ) {
			return $ids;
		}

		$translated = array();

		foreach ( explode( ',', $ids ) as $entry ) {
			$translated[] = $this->translate_entry( $entry, $language_id, $kind );
		}

		return implode( ',', $translated );
	}

	/**
	 * Returns what one stored identifier becomes in another language.
	 *
	 * @param mixed  $entry       Stored identifier.
	 * @param string $language_id Target language identifier.
	 * @param string $kind        Whether the identifier names a post or a term.
	 * @return mixed The entry unchanged when it names nothing translatable.
	 */
	private function translate_entry( $entry, $language_id, $kind ) {
		if ( ! is_scalar( $entry ) ) {
			return $entry;
		}

		$object_id = absint( trim( (string) $entry ) );

		if ( 0 === $object_id ) {
			return $entry;
		}

		$target = 'term' === $kind
			? $this->translate_term( $object_id, $language_id )
			: $this->translate_post( $object_id, $language_id );

		if ( 0 === $target ) {
			return $entry;
		}

		return is_int( $entry ) ? $target : (string) $target;
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

	/**
	 * Returns the translation of a term a condition names.
	 *
	 * @param int    $term_id     Term identifier stored in the condition.
	 * @param string $language_id Target language identifier.
	 * @return int Zero when the condition names nothing translatable.
	 */
	private function translate_term( $term_id, $language_id ) {
		$term = get_term( $term_id );

		if ( ! $term instanceof WP_Term || ! $this->term_translations->supports_taxonomy( $term->taxonomy ) ) {
			return 0;
		}

		$target = absint( $this->term_translations->get_translation( $term_id, $term->taxonomy, $language_id ) );

		return $target === $term_id ? 0 : $target;
	}
}
