<?php
/**
 * Royal Elementor Addons Theme Builder language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\RoyalAddons;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Royal Addons state that names a language.
 *
 * Royal keeps every header, footer, archive, single and popup in a
 * `wpr_templates` post, and unlike the other builders it keeps the display
 * conditions nowhere near them. Each kind of location has one option holding a
 * map — a template's slug against the pages it claims, `{"user-header-abc":
 * ["global"]}` — and the builder answers a location by walking that map, taking
 * the slug that matched, and looking the post up by it.
 *
 * Two things in that map belong to one language. The slug names a template that
 * is written in a language, and a rule such as `single/pages/42` names a page
 * that has a translation of its own. So the map is the whole of what has to be
 * answered: rewrite it for the language being read and every part of the builder
 * that consults it — whether a location renders at all, which stylesheet is
 * loaded, what is finally printed — follows without being touched.
 *
 * This class answers what that map means in one language. Deciding when to ask
 * is RoyalAddonsModule's job.
 */
final class RoyalAddonsTemplates {

	/**
	 * Post type holding every builder template.
	 */
	const POST_TYPE = 'wpr_templates';

	/**
	 * Taxonomy naming which location a template fills.
	 */
	const TYPE_TAXONOMY = 'wpr_template_type';

	/**
	 * Metadata naming which location a template fills.
	 */
	const TYPE_META_KEY = '_wpr_template_type';

	/**
	 * Metadata keeping a header on Elementor's canvas page template.
	 */
	const HEADER_CANVAS_META_KEY = 'wpr_header_show_on_canvas';

	/**
	 * Metadata keeping a footer on Elementor's canvas page template.
	 */
	const FOOTER_CANVAS_META_KEY = 'wpr_footer_show_on_canvas';

	/**
	 * Constant defined as the builder's plugin file is read.
	 */
	const PLUGIN_CONSTANT = 'WPR_ADDONS_VERSION';

	/**
	 * Class that matches display conditions and answers a location.
	 */
	const CONDITIONS_MANAGER_CLASS = '\\WprAddons\\Admin\\Includes\\WPR_Conditions_Manager';

	/**
	 * Elementor's generated stylesheet for one document.
	 */
	const POST_CSS_CLASS = '\\Elementor\\Core\\Files\\CSS\\Post';

	/**
	 * Options holding a map of template slugs against display conditions.
	 *
	 * @var array<int, string>
	 */
	const CONDITION_OPTIONS = array(
		'wpr_header_conditions',
		'wpr_footer_conditions',
		'wpr_archive_conditions',
		'wpr_single_conditions',
		'wpr_product_archive_conditions',
		'wpr_product_single_conditions',
		'wpr_popup_conditions',
	);

	/**
	 * Options answered by the header and footer locations.
	 *
	 * @var array<int, string>
	 */
	const HEADER_FOOTER_OPTIONS = array(
		'wpr_header_conditions',
		'wpr_footer_conditions',
	);

	/**
	 * Rule groups whose identifier names a post.
	 *
	 * @var array<int, string>
	 */
	const POST_RULE_GROUPS = array( 'single', 'product_single' );

	/**
	 * Rule groups whose identifier names a term.
	 *
	 * @var array<int, string>
	 */
	const TERM_RULE_GROUPS = array( 'archive', 'product_archive' );

	/**
	 * Taxonomies the builder names by its own label.
	 *
	 * @var array<string, string>
	 */
	const RULE_TAXONOMIES = array(
		'categories' => 'category',
		'tags'       => 'post_tag',
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
	 * Template identifiers already looked up by slug.
	 *
	 * @var array<string, int>
	 */
	private $ids_by_slug = array();

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
	 * Returns the metadata a translation needs to behave like its source.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys() {
		return array(
			self::TYPE_META_KEY,
			self::HEADER_CANVAS_META_KEY,
			self::FOOTER_CANVAS_META_KEY,
		);
	}

	/**
	 * Reports whether Royal Elementor Addons is present.
	 *
	 * The constant, not one of the builder's classes, because the builder loads
	 * its own on `plugins_loaded` — the hook ShapeTranslate composes its modules on.
	 * The constant is defined as the plugin file is read, which is before any of
	 * this; can_resolve() asks the later question, when it is knowable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = defined( self::PLUGIN_CONSTANT );

		/**
		 * Filters whether the Royal Elementor Addons integration is available.
		 *
		 * @param bool $available Whether the builder is present.
		 */
		return (bool) apply_filters( 'shapetranslate_royaladdons_available', $available );
	}

	/**
	 * Reports whether the builder's condition matching is loaded and answerable.
	 *
	 * @return bool
	 */
	public function can_resolve() {
		return class_exists( self::CONDITIONS_MANAGER_CLASS )
			&& method_exists( self::CONDITIONS_MANAGER_CLASS, 'header_footer_display_conditions' );
	}

	/**
	 * Returns the template one slug names.
	 *
	 * Looked up by path rather than queried, which is how the builder does it,
	 * and matters here for a second reason: a query would be narrowed to the
	 * language being read, and the whole point of this lookup is to ask about
	 * templates belonging to another one.
	 *
	 * @param string $slug Template slug.
	 * @return int Zero when no template carries that slug.
	 */
	public function get_template_id_by_slug( $slug ) {
		$slug = (string) $slug;

		if ( '' === $slug ) {
			return 0;
		}

		if ( ! isset( $this->ids_by_slug[ $slug ] ) ) {
			$template = get_page_by_path( $slug, OBJECT, self::POST_TYPE );

			$this->ids_by_slug[ $slug ] = $template instanceof WP_Post ? absint( $template->ID ) : 0;
		}

		return $this->ids_by_slug[ $slug ];
	}

	/**
	 * Returns the slug one template is named by.
	 *
	 * @param int $template_id Template post identifier.
	 * @return string Empty when the post carries no slug.
	 */
	public function get_template_slug( $template_id ) {
		$template = get_post( absint( $template_id ) );

		return $template instanceof WP_Post ? (string) $template->post_name : '';
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
	 * Rewrites one stored condition map for the language being read.
	 *
	 * Entries already written in this language are kept exactly as they are and
	 * keep their slug, because a site owner who gave this language's template its
	 * own conditions meant them. Every other entry is offered to this language:
	 * its slug becomes its translation's where there is one, and its rules are
	 * re-expressed so a rule naming a page names that page's translation.
	 *
	 * An entry with no translation keeps its own slug and still has its rules
	 * rewritten, which is what makes an untranslated header keep appearing on the
	 * translated pages of whatever it was pinned to rather than quietly vanishing.
	 *
	 * The entries this language owns are written last on purpose. The builder
	 * keeps walking after a match and takes whichever entry it saw last, so being
	 * last is what makes a header written in this language beat one that merely
	 * carried over from another.
	 *
	 * @param array<string, mixed> $conditions  Stored condition map.
	 * @param string               $language_id Language being read.
	 * @return array<string, mixed> A map of the same shape.
	 */
	public function translate_conditions( array $conditions, $language_id ) {
		$language_id = (string) $language_id;

		if ( '' === $language_id ) {
			return $conditions;
		}

		$native   = array();
		$foreign  = array();
		$rewrites = array();

		foreach ( $conditions as $slug => $rules ) {
			$template_id = $this->get_template_id_by_slug( $slug );
			$language    = 0 === $template_id ? '' : $this->get_template_language_id( $template_id );

			if ( 0 === $template_id || '' === $language || $language_id === $language ) {
				$native[ $slug ] = $rules;

				continue;
			}

			$foreign[ $slug ]  = $rules;
			$rewrites[ $slug ] = $template_id;
		}

		if ( empty( $foreign ) ) {
			return $conditions;
		}

		$translated_map = array();

		foreach ( $foreign as $slug => $rules ) {
			$translated = absint( $this->translate_template_id( $rewrites[ $slug ], $language_id ) );
			$key        = 0 === $translated ? (string) $slug : $this->get_template_slug( $translated );

			if ( '' === $key ) {
				continue;
			}

			$translated_map[ $key ] = is_array( $rules )
				? $this->translate_rules( $rules, $language_id )
				: $rules;
		}

		foreach ( $native as $slug => $rules ) {
			$translated_map[ $slug ] = $rules;
		}

		return $translated_map;
	}

	/**
	 * Rewrites a stored rule list for one language.
	 *
	 * @param array<int, mixed> $rules       Stored display rules.
	 * @param string            $language_id Language the rules are moving to.
	 * @return array<int, mixed> Rules in the same order.
	 */
	public function translate_rules( array $rules, $language_id ) {
		foreach ( $rules as $index => $rule ) {
			if ( is_string( $rule ) ) {
				$rules[ $index ] = $this->translate_rule( $rule, $language_id );
			}
		}

		return $rules;
	}

	/**
	 * Rewrites one display rule for a language.
	 *
	 * A rule naming no object, or one with no translation, comes back unchanged.
	 * The original is a better answer than a dropped rule, which would either
	 * widen a template to the whole site or leave a location empty.
	 *
	 * @param string $rule        Stored rule, `group/sub` and an optional target.
	 * @param string $language_id Target language identifier.
	 * @return string
	 */
	public function translate_rule( $rule, $language_id ) {
		$parts = explode( '/', (string) $rule );
		$count = count( $parts );

		if ( 3 !== $count && 4 !== $count ) {
			return $rule;
		}

		$target = $parts[ $count - 1 ];

		if ( ! is_numeric( $target ) ) {
			return $rule;
		}

		if ( 4 === $count ) {
			$translated = taxonomy_exists( $parts[2] )
				? $this->translate_term( $target, $parts[2], $language_id )
				: 0;
		} elseif ( in_array( $parts[0], self::POST_RULE_GROUPS, true ) ) {
			$translated = $this->translate_post( $target, $language_id );
		} elseif ( in_array( $parts[0], self::TERM_RULE_GROUPS, true ) ) {
			$translated = $this->translate_term( $target, $this->rule_taxonomy( $parts[1] ), $language_id );
		} else {
			$translated = 0;
		}

		if ( 0 === $translated ) {
			return $rule;
		}

		$parts[ $count - 1 ] = (string) $translated;

		return implode( '/', $parts );
	}

	/**
	 * Returns every template this request will print, after language resolution.
	 *
	 * The builder is asked rather than second-guessed: it has already narrowed the
	 * map this module rewrote, so what it names is what will appear. Asking costs
	 * a walk over conditions it has cached.
	 *
	 * @return array<int, int>
	 */
	public function get_rendered_template_ids() {
		if ( ! $this->can_resolve() ) {
			return array();
		}

		$slugs = array();

		foreach ( self::HEADER_FOOTER_OPTIONS as $option ) {
			$conditions = json_decode( (string) get_option( $option, '[]' ), true );

			if ( ! is_array( $conditions ) || empty( $conditions ) ) {
				continue;
			}

			$slugs[] = call_user_func(
				array( self::CONDITIONS_MANAGER_CLASS, 'header_footer_display_conditions' ),
				$conditions
			);
		}

		if ( method_exists( self::CONDITIONS_MANAGER_CLASS, 'canvas_page_content_display_conditions' ) ) {
			$slugs[] = call_user_func(
				array( self::CONDITIONS_MANAGER_CLASS, 'canvas_page_content_display_conditions' )
			);
		}

		$ids = array();

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}

			$template_id = $this->get_template_id_by_slug( $slug );

			if ( 0 !== $template_id ) {
				$ids[] = $template_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Announces a template to Elementor's atomic style pipeline.
	 *
	 * Elementor gives each element of a document a generated class and writes
	 * those rules per document, but only for the documents it was told were
	 * rendering. A page's own document announces itself; a header the builder
	 * prints from somewhere else does not, and arrives with the shared base styles
	 * and none of its own — the layout collapses to plain stacked content. The
	 * builder loads the older per-post stylesheet for its templates and stops
	 * there, so this is the half that is missing.
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
	 * The builder asks for this too, from the template its own unrewritten map
	 * named. Asking again for the one that will actually print costs nothing when
	 * they are the same template, and is the difference between a styled header
	 * and an unstyled one when they are not.
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
	 * Returns the taxonomy a rule's sub-group names.
	 *
	 * @param string $sub_group Sub-group stored in the rule.
	 * @return string Empty when the sub-group names no taxonomy.
	 */
	private function rule_taxonomy( $sub_group ) {
		$sub_group = (string) $sub_group;

		if ( isset( self::RULE_TAXONOMIES[ $sub_group ] ) ) {
			return self::RULE_TAXONOMIES[ $sub_group ];
		}

		return taxonomy_exists( $sub_group ) ? $sub_group : '';
	}

	/**
	 * Returns the translation of a post a rule names.
	 *
	 * @param string|int $post_id     Identifier stored in the rule.
	 * @param string     $language_id Target language identifier.
	 * @return int Zero when the rule names nothing translatable.
	 */
	private function translate_post( $post_id, $language_id ) {
		$post_id   = absint( $post_id );
		$post_type = 0 === $post_id ? '' : get_post_type( $post_id );

		if ( ! is_string( $post_type ) || ! $this->post_translations->supports_post_type( $post_type ) ) {
			return 0;
		}

		$translated = absint( $this->post_translations->get_translation( $post_id, $language_id ) );

		return $translated === $post_id ? 0 : $translated;
	}

	/**
	 * Returns the translation of a term a rule names.
	 *
	 * @param string|int $term_id     Identifier stored in the rule.
	 * @param string     $taxonomy    Taxonomy the term belongs to.
	 * @param string     $language_id Target language identifier.
	 * @return int Zero when the rule names nothing translatable.
	 */
	private function translate_term( $term_id, $taxonomy, $language_id ) {
		$term_id  = absint( $term_id );
		$taxonomy = (string) $taxonomy;

		if ( 0 === $term_id || '' === $taxonomy || ! $this->term_translations->supports_taxonomy( $taxonomy ) ) {
			return 0;
		}

		$translated = absint( $this->term_translations->get_translation( $term_id, $taxonomy, $language_id ) );

		return $translated === $term_id ? 0 : $translated;
	}
}
