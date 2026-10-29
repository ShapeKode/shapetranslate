<?php
/**
 * Elementor Header & Footer Builder language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\HeaderFooter;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Header & Footer Builder state that names a language.
 *
 * Elementor Header & Footer Builder keeps each header, footer, and before-footer
 * in an `elementor-hf` post, and chooses one per request by matching display
 * rules stored in metadata. Two things in that state belong to one language. The
 * template itself is written in a language, and a rule such as `post-42` names a
 * page that has a translation of its own.
 *
 * The builder fills a location with the first matching template of the requested
 * kind, so a site holding an English and a Bengali header on the same "Entire
 * Website" rule shows whichever was published last to every reader. This class
 * answers which of them belongs to the language being read, and what a copied
 * rule set means once it moves to another language. Deciding when to ask those
 * questions is HeaderFooterModule's job.
 */
final class HeaderFooterTemplates {

	/**
	 * Post type holding header, footer, and before-footer templates.
	 */
	const POST_TYPE = 'elementor-hf';

	/**
	 * Metadata naming which location a template fills.
	 */
	const TYPE_META_KEY = 'ehf_template_type';

	/**
	 * Metadata holding the rules that make a template apply.
	 */
	const INCLUDE_META_KEY = 'ehf_target_include_locations';

	/**
	 * Metadata holding the rules that take a template back.
	 */
	const EXCLUDE_META_KEY = 'ehf_target_exclude_locations';

	/**
	 * Metadata limiting a template to some user roles.
	 */
	const ROLES_META_KEY = 'ehf_target_user_roles';

	/**
	 * Metadata keeping a template on Elementor's canvas page template.
	 */
	const CANVAS_META_KEY = 'display-on-canvas-template';

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
	 * Copying an Elementor document alone produces a translated layout that fills
	 * no location: without the template type the builder does not know it is a
	 * header, and without the rules it never matches a request.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys() {
		return array(
			self::TYPE_META_KEY,
			self::INCLUDE_META_KEY,
			self::EXCLUDE_META_KEY,
			self::ROLES_META_KEY,
			self::CANVAS_META_KEY,
		);
	}

	/**
	 * Returns the metadata keys holding display rules.
	 *
	 * @return array<int, string>
	 */
	public static function location_meta_keys() {
		return array( self::INCLUDE_META_KEY, self::EXCLUDE_META_KEY );
	}

	/**
	 * Reports whether the Header & Footer Builder finished loading.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = defined( 'HFE_VER' ) && class_exists( 'Header_Footer_Elementor', false );

		/**
		 * Filters whether the Header & Footer Builder integration is available.
		 *
		 * @param bool $available Whether the builder completed loading.
		 */
		return (bool) apply_filters( 'shapetranslate_hfe_available', $available );
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
	 * Narrows matched templates to the ones written in the language being read.
	 *
	 * The builder hands over every template whose rules match the request, header
	 * and footer together, and then takes the first of each kind. Narrowing has to
	 * happen per kind for that reason: a site that has translated its header but
	 * not its footer must keep the untranslated footer rather than lose it, so a
	 * kind with no template in this language is left exactly as it arrived.
	 *
	 * @param array<int|string, mixed> $templates   Templates matched for this request.
	 * @param string                   $language_id Language being rendered.
	 * @return array<int|string, mixed> Templates in the order they arrived.
	 */
	public function select_for_language( array $templates, $language_id ) {
		$language_id = (string) $language_id;

		if ( '' === $language_id || 2 > count( $templates ) ) {
			return $templates;
		}

		$this->prime_templates( $templates );

		$by_type = array();

		foreach ( $templates as $key => $template ) {
			$template_id = is_array( $template ) && isset( $template['id'] ) ? absint( $template['id'] ) : 0;
			$type        = 0 === $template_id ? '' : $this->get_template_type( $template_id );

			if ( '' !== $type ) {
				$by_type[ $type ][ $key ] = $template_id;
			}
		}

		foreach ( $by_type as $candidates ) {
			$matching = array();

			foreach ( $candidates as $key => $template_id ) {
				if ( $language_id === $this->post_translations->get_post_language_id( $template_id ) ) {
					$matching[ $key ] = $template_id;
				}
			}

			if ( empty( $matching ) || count( $matching ) === count( $candidates ) ) {
				continue;
			}

			foreach ( $candidates as $key => $template_id ) {
				if ( ! isset( $matching[ $key ] ) ) {
					unset( $templates[ $key ] );
				}
			}
		}

		return $templates;
	}

	/**
	 * Rewrites a stored rule set for one language.
	 *
	 * @param mixed  $rules       Stored display rules.
	 * @param string $language_id Language the rules are moving to.
	 * @return mixed Rules in the same shape and order.
	 */
	public function translate_locations( $rules, $language_id ) {
		$language_id = (string) $language_id;

		if ( ! is_array( $rules ) || '' === $language_id ) {
			return $rules;
		}

		foreach ( array( 'rule', 'specific' ) as $group ) {
			if ( ! isset( $rules[ $group ] ) || ! is_array( $rules[ $group ] ) ) {
				continue;
			}

			foreach ( $rules[ $group ] as $index => $entry ) {
				if ( is_string( $entry ) ) {
					$rules[ $group ][ $index ] = $this->translate_location( $entry, $language_id );
				}
			}
		}

		return $rules;
	}

	/**
	 * Rewrites one rule for a language.
	 *
	 * A rule that names no object, or names one with no translation, comes back
	 * unchanged. The original identifier is a better answer than a dropped rule,
	 * which would either widen a template to the whole site or leave a location
	 * empty, and both are worse than a rule still pointing at the source language.
	 *
	 * @param string $entry       Stored rule.
	 * @param string $language_id Target language identifier.
	 * @return string
	 */
	private function translate_location( $entry, $language_id ) {
		$matches = array();

		if ( preg_match( '/^post-(\d+)$/', $entry, $matches ) ) {
			$translated = $this->translate_post( (int) $matches[1], $language_id );

			return 0 === $translated ? $entry : 'post-' . $translated;
		}

		if ( preg_match( '/^tax-(\d+)-single-([A-Za-z0-9_-]+)$/', $entry, $matches ) ) {
			$translated = $this->translate_term( (int) $matches[1], $matches[2], $language_id );

			return 0 === $translated ? $entry : 'tax-' . $translated . '-single-' . $matches[2];
		}

		if ( preg_match( '/^tax-(\d+)$/', $entry, $matches ) ) {
			$term       = get_term( (int) $matches[1] );
			$taxonomy   = $term instanceof WP_Term ? $term->taxonomy : '';
			$translated = '' === $taxonomy ? 0 : $this->translate_term( (int) $matches[1], $taxonomy, $language_id );

			return 0 === $translated ? $entry : 'tax-' . $translated;
		}

		return $entry;
	}

	/**
	 * Returns the translation of a post a rule names.
	 *
	 * @param int    $post_id     Post identifier stored in the rule.
	 * @param string $language_id Target language identifier.
	 * @return int Zero when the rule names nothing translatable.
	 */
	private function translate_post( $post_id, $language_id ) {
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) || ! $this->post_translations->supports_post_type( $post_type ) ) {
			return 0;
		}

		$translated = absint( $this->post_translations->get_translation( $post_id, $language_id ) );

		return $translated === $post_id ? 0 : $translated;
	}

	/**
	 * Returns the translation of a term a rule names.
	 *
	 * @param int    $term_id     Term identifier stored in the rule.
	 * @param string $taxonomy    Taxonomy the term belongs to.
	 * @param string $language_id Target language identifier.
	 * @return int Zero when the rule names nothing translatable.
	 */
	private function translate_term( $term_id, $taxonomy, $language_id ) {
		if ( ! $this->term_translations->supports_taxonomy( $taxonomy ) ) {
			return 0;
		}

		$translated = absint( $this->term_translations->get_translation( $term_id, $taxonomy, $language_id ) );

		return $translated === $term_id ? 0 : $translated;
	}

	/**
	 * Warms the metadata cache for the templates matched on this request.
	 *
	 * The builder finds them with one direct query that primes nothing, and every
	 * matched template is then asked for its type and its language. Reading them
	 * one post at a time is the difference between one query and a dozen.
	 *
	 * @param array<int|string, mixed> $templates Templates matched for this request.
	 * @return void
	 */
	private function prime_templates( array $templates ) {
		$ids = array();

		foreach ( $templates as $template ) {
			$template_id = is_array( $template ) && isset( $template['id'] ) ? absint( $template['id'] ) : 0;

			if ( 0 !== $template_id ) {
				$ids[] = $template_id;
			}
		}

		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', array_values( array_unique( $ids ) ) );
		}
	}
}
