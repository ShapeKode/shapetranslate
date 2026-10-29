<?php
/**
 * SEO plugin field integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Seo;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Contracts\SeoProviderInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Settings\WorkflowSettings;
use ShapeTranslate\StringTranslation\OptionStringTranslator;
use ShapeTranslate\Taxonomy\TermTranslationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Carries an SEO plugin's per-post fields into a translation.
 *
 * ShapeTranslate copies public custom fields and leaves protected ones with the
 * post that owns them, which is the right default: a protected key usually
 * holds something about that one post. An SEO title is the exception. It is
 * written for a reader, it is different in every language, and every SEO plugin
 * stores it under a protected key — so a translator opening a new translation
 * finds the SEO panel blank and has nothing to translate from.
 *
 * This module names those fields rather than loosening the rule. It writes
 * through the two filters the copy engine already offers, so the engine keeps
 * deciding what happens and the protected-key default stays exactly as it was
 * for every key no provider claims.
 *
 * Three things follow from the fields being text rather than settings:
 *
 * - They are copied when the translation is created, so nobody starts blank.
 * - They are then left alone. Once a Bengali description exists, editing the
 *   English post must not overwrite it, so the synchronization phase actively
 *   removes them — which also protects the plugins storing their fields under
 *   unprefixed keys, where the generic custom-field synchronization would
 *   otherwise reach them.
 * - A field naming a term is resolved to that term's translation, because a
 *   primary category copied by identifier labels a Bengali post with an
 *   English category.
 */
final class SeoMetaModule implements ModuleInterface {

	/**
	 * Registered providers.
	 *
	 * @var array<int, SeoProviderInterface>
	 */
	private $providers;

	/**
	 * Term translation relationships.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Copy and synchronization settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Providers that reported their plugin loaded, resolved once per request.
	 *
	 * @var array<int, SeoProviderInterface>|null
	 */
	private $active_providers = null;

	/**
	 * Shared option string translator.
	 *
	 * @var OptionStringTranslator
	 */
	private $option_strings;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param array<int, SeoProviderInterface> $providers         Known providers.
	 * @param TermTranslationManager           $term_translations Term translation manager.
	 * @param WorkflowSettings                 $workflow_settings Copy and sync settings.
	 * @param OptionStringTranslator           $option_strings    Shared option translator.
	 * @param LanguageManager                  $languages         Language manager.
	 */
	public function __construct(
		array $providers,
		TermTranslationManager $term_translations,
		WorkflowSettings $workflow_settings,
		OptionStringTranslator $option_strings,
		LanguageManager $languages
	) {
		$this->providers         = $providers;
		$this->term_translations = $term_translations;
		$this->workflow_settings = $workflow_settings;
		$this->option_strings    = $option_strings;
		$this->languages         = $languages;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'shapetranslate_copy_post_meta_keys', array( $this, 'filter_meta_keys' ), 10, 5 );
		add_filter( 'shapetranslate_translate_post_meta_value', array( $this, 'translate_meta_value' ), 10, 3 );

		$this->register_option_strings();
	}

	/**
	 * Returns the providers whose plugin is loaded on this request.
	 *
	 * @return array<int, SeoProviderInterface>
	 */
	public function get_active_providers() {
		if ( null !== $this->active_providers ) {
			return $this->active_providers;
		}

		/**
		 * Filters the SEO plugin providers ShapeTranslate knows about.
		 *
		 * A provider is a list of field names implementing SeoProviderInterface.
		 * Adding one is how a site supports an SEO plugin ShapeTranslate does not
		 * ship a list for.
		 *
		 * @param array<int, SeoProviderInterface> $providers Known providers.
		 */
		$providers = apply_filters( 'shapetranslate_seo_providers', $this->providers );
		$active    = array();

		foreach ( is_array( $providers ) ? $providers : $this->providers as $provider ) {
			if ( $provider instanceof SeoProviderInterface && $provider->is_active() ) {
				$active[] = $provider;
			}
		}

		$this->active_providers = $active;

		return $this->active_providers;
	}

	/**
	 * Adds an SEO plugin's fields to a copy, and withdraws them from a sync.
	 *
	 * @param array<int, string>|mixed $keys        Meta keys the engine resolved.
	 * @param bool                     $sync        True during synchronization.
	 * @param int                      $source_id   Source post identifier.
	 * @param int                      $target_id   Target post identifier.
	 * @param string                   $language_id Target language identifier.
	 * @return array<int, string>|mixed
	 */
	public function filter_meta_keys( $keys, $sync, $source_id, $target_id, $language_id ) {
		unset( $source_id, $target_id );

		if ( ! is_array( $keys ) ) {
			return $keys;
		}

		$providers = $this->get_active_providers();

		if ( empty( $providers ) ) {
			return $keys;
		}

		$translatable = array();
		$carried      = array();
		$primary      = array();

		foreach ( $providers as $provider ) {
			$translatable = array_merge( $translatable, $this->strings( $provider->get_translatable_meta_keys() ) );
			$carried      = array_merge( $carried, $this->strings( $provider->get_copied_meta_keys() ) );
			$primary      = array_merge( $primary, array_keys( $this->translatable_primary_keys( $provider ) ) );
		}

		if ( $sync ) {
			/*
			 * A primary term is a relationship rather than text, so it follows
			 * the setting a site uses to keep terms aligned. Asked for, it is
			 * kept pointing at the translation of whatever the source names.
			 */
			$keys = $this->workflow_settings->is_sync_enabled( 'taxonomies' )
				? array_values( array_unique( array_merge( $keys, $primary ) ) )
				: $keys;

			// Translated text is withdrawn whether or not anything added it, so a
			// description somebody wrote in Bengali survives the next English edit.
			$keys = array_values( array_diff( $keys, $translatable ) );

			/** This filter is documented in src/Integrations/Seo/class-seometamodule.php */
			return apply_filters( 'shapetranslate_seo_meta_keys', $keys, $sync, $language_id, $providers );
		}

		// Deduplicated here rather than left to the engine, because a plugin
		// storing its fields under unprefixed keys has already had them listed
		// once as ordinary custom fields.
		$keys = array_values( array_unique( array_merge( $keys, $translatable, $carried, $primary ) ) );

		/**
		 * Filters the SEO plugin meta keys carried into a translation.
		 *
		 * @param array<int, string>               $keys        Meta keys after this module ran.
		 * @param bool                             $sync        True during synchronization.
		 * @param string                           $language_id Target language identifier.
		 * @param array<int, SeoProviderInterface> $providers   Active providers.
		 */
		return apply_filters( 'shapetranslate_seo_meta_keys', $keys, $sync, $language_id, $providers );
	}

	/**
	 * Resolves a copied primary term to the translation's own term.
	 *
	 * @param mixed  $value       Stored meta value.
	 * @param string $meta_key    Meta key.
	 * @param string $language_id Target language identifier.
	 * @return mixed
	 */
	public function translate_meta_value( $value, $meta_key, $language_id ) {
		if ( ! is_scalar( $value ) || ! is_string( $meta_key ) || '' === (string) $language_id ) {
			return $value;
		}

		$term_id = absint( $value );

		if ( 0 === $term_id ) {
			return $value;
		}

		foreach ( $this->get_active_providers() as $provider ) {
			$taxonomies = $this->translatable_primary_keys( $provider );

			if ( ! isset( $taxonomies[ $meta_key ] ) ) {
				continue;
			}

			$translated = absint(
				$this->term_translations->get_translation( $term_id, $taxonomies[ $meta_key ], $language_id )
			);

			/*
			 * An untranslated term keeps the identifier it had. Emptying it
			 * would drop the primary term altogether, which reads as a setting
			 * somebody cleared rather than one nobody has translated yet.
			 */
			return 0 < $translated ? $translated : $value;
		}

		return $value;
	}

	/**
	 * Registers each active plugin's own options as translatable strings.
	 *
	 * This is what reaches a title template — the pattern a post falls back to
	 * when it carries no title of its own, and the one place a site's SEO titles
	 * are written once rather than per post.
	 *
	 * @return void
	 */
	public function register_option_strings() {
		if ( ! $this->languages->has_languages() ) {
			return;
		}

		$declarations = array();

		foreach ( $this->get_active_providers() as $provider ) {
			foreach ( $provider->get_option_declarations() as $context => $options ) {
				if ( ! is_string( $context ) || '' === $context || ! is_array( $options ) ) {
					continue;
				}

				$declarations[ $context ] = isset( $declarations[ $context ] )
					? array_merge( $declarations[ $context ], $options )
					: $options;
			}
		}

		/**
		 * Filters the SEO plugin options registered as translatable strings.
		 *
		 * Return an empty array to leave an SEO plugin's own settings alone and
		 * translate only the per-post fields.
		 *
		 * @param array<string, array<string, mixed>> $declarations Declarations keyed by context.
		 */
		$declarations = apply_filters( 'shapetranslate_seo_option_strings', $declarations );

		if ( is_array( $declarations ) && ! empty( $declarations ) ) {
			$this->option_strings->register( $declarations );
		}
	}

	/**
	 * Returns a provider's primary-term keys for translated taxonomies only.
	 *
	 * A taxonomy the site does not translate has one set of terms shared by
	 * every language, so its identifier already means the same thing in all of
	 * them and there is nothing to resolve.
	 *
	 * @param SeoProviderInterface $provider Provider to read.
	 * @return array<string, string> Meta key mapped to taxonomy name.
	 */
	private function translatable_primary_keys( SeoProviderInterface $provider ) {
		$keys = array();

		foreach ( $provider->get_primary_term_meta_keys() as $meta_key => $taxonomy ) {
			if (
				is_string( $meta_key )
				&& '' !== $meta_key
				&& is_string( $taxonomy )
				&& $this->term_translations->supports_taxonomy( $taxonomy )
			) {
				$keys[ $meta_key ] = $taxonomy;
			}
		}

		return $keys;
	}

	/**
	 * Returns only the usable strings of a declared list.
	 *
	 * @param mixed $values Declared values.
	 * @return array<int, string>
	 */
	private function strings( $values ) {
		$strings = array();

		foreach ( is_array( $values ) ? $values : array() as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$strings[] = $value;
			}
		}

		return $strings;
	}
}
