<?php
/**
 * Compatibility module for wpml-config.xml.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Wpml;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Settings\WorkflowSettings;
use ShapeTranslate\StringTranslation\OptionStringTranslator;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the declarations plugins and themes ship in wpml-config.xml.
 *
 * The file is the one place where an author has already written down what a
 * multilingual plugin should do with their content, and most of the ecosystem
 * ships one. Reading it turns that existing work into ShapeTranslate support with
 * no code on either side.
 *
 * What a declaration is allowed to decide is deliberately narrower than what
 * the format allows:
 *
 * - `custom-type` and `taxonomy` decide what a site *may* translate. A
 *   `translate="0"` declaration removes the object entirely, because the author
 *   is saying its content is not editorial text. A `translate="1"` declaration
 *   makes the object available, including the non-public types page builders
 *   use for headers and templates, but it does not tick a box on the settings
 *   screen: which of the available objects a site translates stays the site
 *   owner's decision.
 * - `custom-field` and `custom-term-field` decide *which* fields travel into a
 *   translation, which is exactly the question ShapeTranslate has no way to answer
 *   on its own for a third-party field. Whether ongoing synchronization runs at
 *   all stays the site's own switch: a declaration cannot turn it back on.
 * - `admin-texts` options are registered as translatable strings and
 *   substituted on the front end.
 *
 * Rules the engine has no consumer for yet, such as `custom-term-fields` while
 * term meta has no synchronization phase, are still parsed and exposed through
 * `shapetranslate_wpml_config_rules`.
 */
final class WpmlConfigModule implements ModuleInterface {

	/**
	 * Configuration reader.
	 *
	 * @var WpmlConfigReader
	 */
	private $reader;

	/**
	 * Option translator.
	 *
	 * @var OptionStringTranslator
	 */
	private $options;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow;

	/**
	 * Constructor.
	 *
	 * @param WpmlConfigReader       $reader    Configuration reader.
	 * @param OptionStringTranslator $options   Shared option translator.
	 * @param LanguageManager        $languages Language manager.
	 * @param WorkflowSettings       $workflow  Translation workflow settings.
	 */
	public function __construct(
		WpmlConfigReader $reader,
		OptionStringTranslator $options,
		LanguageManager $languages,
		WorkflowSettings $workflow
	) {
		$this->reader    = $reader;
		$this->options   = $options;
		$this->languages = $languages;
		$this->workflow  = $workflow;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		// The cache is keyed on file modification times, so it is invalidated by
		// anything that ships a new file. These events change which files exist
		// at all, which no fingerprint of the previous set can see.
		add_action( 'activated_plugin', array( $this, 'flush' ) );
		add_action( 'deactivated_plugin', array( $this, 'flush' ) );
		add_action( 'switch_theme', array( $this, 'flush' ) );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'shapetranslate_supported_post_types', array( $this, 'filter_post_types' ) );
		add_filter( 'shapetranslate_non_public_post_types', array( $this, 'filter_non_public_post_types' ) );
		add_filter( 'shapetranslate_supported_taxonomies', array( $this, 'filter_taxonomies' ) );
		add_filter( 'shapetranslate_copy_post_meta_keys', array( $this, 'filter_post_meta_keys' ), 10, 2 );

		$rules = $this->reader->get_rules();

		// Options have to be claimed before anything reads them, so this is the
		// one group of rules resolved during registration rather than on demand.
		$this->options->register( $rules['admin_texts'] );
	}

	/**
	 * Discards the parsed rules.
	 *
	 * @return void
	 */
	public function flush() {
		$this->reader->flush();
	}

	/**
	 * Applies post type declarations to the configurable post types.
	 *
	 * @param array<int, string> $post_types Post type names offered for translation.
	 * @return array<int, string>
	 */
	public function filter_post_types( $post_types ) {
		return $this->apply_objects( (array) $post_types, $this->reader->get_rules()['post_types'] );
	}

	/**
	 * Declares translatable non-public post types as eligible.
	 *
	 * Page builders register their headers, footers, and templates as non-public
	 * so nothing reaches them by URL. They are still authored content, and a
	 * builder that declares one here has said so itself.
	 *
	 * @param array<int, string> $post_types Non-public post type names.
	 * @return array<int, string>
	 */
	public function filter_non_public_post_types( $post_types ) {
		$post_types = (array) $post_types;

		foreach ( $this->reader->get_rules()['post_types'] as $post_type => $translate ) {
			if ( $translate ) {
				$post_types[] = $post_type;
			}
		}

		return array_values( array_unique( $post_types ) );
	}

	/**
	 * Applies taxonomy declarations to the configurable taxonomies.
	 *
	 * @param array<int, string> $taxonomies Taxonomy names offered for translation.
	 * @return array<int, string>
	 */
	public function filter_taxonomies( $taxonomies ) {
		return $this->apply_objects( (array) $taxonomies, $this->reader->get_rules()['taxonomies'] );
	}

	/**
	 * Applies custom field declarations to the copied and synchronized metas.
	 *
	 * `copy` keeps a field aligned in both phases. `copy-once` and `translate`
	 * seed a new translation and are then left alone, so an editor's work is
	 * never overwritten by a later save of the source. Anything else is removed,
	 * including fields ShapeTranslate would otherwise have copied on its own.
	 *
	 * A declaration never starts the synchronization phase for a site that turned
	 * custom fields off. Removals still apply in both phases, because excluding a
	 * field an author called untranslatable is always safe.
	 *
	 * @param array<int, string> $keys Meta keys to copy or synchronize.
	 * @param bool               $sync True during the ongoing synchronization phase.
	 * @return array<int, string>
	 */
	public function filter_post_meta_keys( $keys, $sync = false ) {
		$keys      = (array) $keys;
		$exclude   = array();
		$may_align = ! $sync || $this->workflow->is_sync_enabled( 'post_meta' );

		foreach ( $this->reader->get_rules()['post_meta'] as $meta_key => $action ) {
			$aligned = 'copy' === $action && $may_align;
			$seeding = ! $sync && in_array( $action, array( 'copy-once', 'translate' ), true );

			if ( $aligned || $seeding ) {
				$keys[] = $meta_key;

				continue;
			}

			$exclude[] = $meta_key;
		}

		return array_values( array_diff( array_unique( $keys ), $exclude ) );
	}

	/**
	 * Adds and removes object names according to their declarations.
	 *
	 * @param array<int, string>  $names        Object names.
	 * @param array<string, bool> $declarations Declared names and whether to translate them.
	 * @return array<int, string>
	 */
	private function apply_objects( array $names, array $declarations ) {
		$exclude = array();

		foreach ( $declarations as $name => $translate ) {
			if ( $translate ) {
				$names[] = $name;

				continue;
			}

			$exclude[] = $name;
		}

		return array_values( array_diff( array_unique( $names ), $exclude ) );
	}

	/**
	 * Reports whether declarations should be applied on this site.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		if ( defined( 'SHAPETRANSLATE_WPML_CONFIG' ) && ! SHAPETRANSLATE_WPML_CONFIG ) {
			return false;
		}

		if ( ! $this->languages->has_languages() ) {
			return false;
		}

		/**
		 * Filters whether wpml-config.xml declarations are applied.
		 *
		 * @param bool $enabled Whether the compatibility layer is active.
		 */
		return (bool) apply_filters( 'shapetranslate_wpml_config_enabled', true );
	}
}
