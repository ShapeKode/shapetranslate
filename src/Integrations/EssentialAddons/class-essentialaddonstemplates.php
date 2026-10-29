<?php
/**
 * Essential Addons Theme Builder language resolution.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\EssentialAddons;

use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the Essential Addons Theme Builder state that names a language.
 *
 * Essential Addons keeps each header and footer in an `ea_theme_builder` post and
 * chooses one per request by scoring display conditions. A condition row is the
 * same shape Elementor's own Theme Builder uses — a name, a sub-name, and the ID
 * of the object the rule is about — so a rule written against an English page
 * names that page and no other. A template copied into another language without
 * rewriting those IDs keeps pointing at the language it came from.
 *
 * The builder is also language-blind when it picks: with two headers claiming the
 * whole site it takes the newest, so every reader gets the same one. This class
 * answers which template belongs to the language being read, and what a copied
 * condition set means once it moves. Deciding when to ask is
 * EssentialAddonsModule's job.
 */
final class EssentialAddonsTemplates {

	/**
	 * Post type holding Theme Builder templates.
	 */
	const POST_TYPE = 'ea_theme_builder';

	/**
	 * Metadata naming which location a template fills.
	 */
	const TYPE_META_KEY = '_ea_template_type';

	/**
	 * Metadata holding the display conditions.
	 */
	const CONDITIONS_META_KEY = '_ea_template_conditions';

	/**
	 * Metadata breaking a tie between equally specific templates.
	 */
	const PRIORITY_META_KEY = '_ea_template_priority';

	/**
	 * Metadata switching a template off without unpublishing it.
	 */
	const ACTIVE_META_KEY = '_ea_template_active';

	/**
	 * Metadata naming the platform a template was built for.
	 */
	const PLATFORM_META_KEY = '_ea_template_platform';

	/**
	 * Theme Builder cache class, flushed when conditions change outside the builder.
	 */
	const CACHE_CLASS = '\\Essential_Addons_Elementor\\Theme_Builder\\Core\\Template_Cache';

	/**
	 * Post type class, present whenever the module can run.
	 */
	const POST_TYPE_CLASS = '\\Essential_Addons_Elementor\\Theme_Builder\\Core\\Post_Type';

	/**
	 * Elementor document language resolution.
	 *
	 * @var ElementorThemeBuilder
	 */
	private $documents;

	/**
	 * Constructor.
	 *
	 * @param ElementorThemeBuilder $documents Elementor document language resolution.
	 */
	public function __construct( ElementorThemeBuilder $documents ) {
		$this->documents = $documents;
	}

	/**
	 * Returns the metadata a translation needs to behave like its source.
	 *
	 * The stored status is left out on purpose: the builder mirrors `post_status`
	 * into it on every save, and a translated draft is a draft. Copying the
	 * source's status would claim the copy is published before anyone published
	 * it.
	 *
	 * @return array<int, string>
	 */
	public static function meta_keys() {
		return array(
			self::TYPE_META_KEY,
			self::CONDITIONS_META_KEY,
			self::PRIORITY_META_KEY,
			self::ACTIVE_META_KEY,
			self::PLATFORM_META_KEY,
		);
	}

	/**
	 * Reports whether the Essential Addons Theme Builder is loaded.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = defined( 'EAEL_PLUGIN_VERSION' ) && class_exists( self::POST_TYPE_CLASS );

		/**
		 * Filters whether the Essential Addons Theme Builder integration is available.
		 *
		 * @param bool $available Whether the Theme Builder module is loaded.
		 */
		return (bool) apply_filters( 'shapetranslate_eael_available', $available );
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
	 * A row that names no object, or names one with no translation, is left
	 * exactly as it was. The original identifier is a better answer than a
	 * dropped rule, which would either widen a header to the whole site or leave
	 * a location empty.
	 *
	 * @param mixed  $conditions  Stored condition rows.
	 * @param string $language_id Language the conditions are moving to.
	 * @return mixed Rows in the same shape and order.
	 */
	public function translate_conditions( $conditions, $language_id ) {
		$language_id = (string) $language_id;

		if ( ! is_array( $conditions ) || '' === $language_id ) {
			return $conditions;
		}

		foreach ( $conditions as $index => $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$sub_id = isset( $condition['sub_id'] ) ? absint( $condition['sub_id'] ) : 0;

			if ( 0 === $sub_id ) {
				continue;
			}

			$translated = $this->documents->translate_object_id(
				$sub_id,
				isset( $condition['name'] ) ? (string) $condition['name'] : '',
				isset( $condition['sub_name'] ) ? (string) $condition['sub_name'] : '',
				$language_id
			);

			if ( 0 < $translated ) {
				$conditions[ $index ]['sub_id'] = $translated;
			}
		}

		return $conditions;
	}

	/**
	 * Discards the builder's cached template lists.
	 *
	 * The builder caches which templates exist per location in a transient and
	 * rebuilds it when a template is saved through its own screens. A translation
	 * written outside them never triggers that, so the copied conditions would
	 * sit in metadata the cache does not know about until the next save.
	 *
	 * @return void
	 */
	public function flush_cache() {
		if ( ! class_exists( self::CACHE_CLASS ) || ! method_exists( self::CACHE_CLASS, 'flush' ) ) {
			return;
		}

		call_user_func( array( self::CACHE_CLASS, 'flush' ) );
	}
}
