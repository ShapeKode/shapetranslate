<?php
/**
 * ShapeTranslate settings export and import.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Settings;

use ShapeTranslate\Content\PostTypeSupport;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Routing\LanguageHostResolver;
use ShapeTranslate\Taxonomy\TaxonomySupport;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds portable JSON and validates it before applying any changes.
 */
final class SettingsTransfer {

	/**
	 * Portable document format identifier.
	 *
	 * @var string
	 */
	const FORMAT = 'shapetranslate-settings';

	/**
	 * Maximum accepted JSON size in bytes.
	 *
	 * @var int
	 */
	const MAX_JSON_BYTES = 1048576;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Post type policy.
	 *
	 * @var PostTypeSupport
	 */
	private $post_type_support;

	/**
	 * Taxonomy policy.
	 *
	 * @var TaxonomySupport
	 */
	private $taxonomy_support;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager  $language_manager  Language manager.
	 * @param PluginSettings   $settings          Central settings.
	 * @param WorkflowSettings $workflow_settings Translation workflow settings.
	 * @param PostTypeSupport  $post_type_support Post type policy.
	 * @param TaxonomySupport  $taxonomy_support  Taxonomy policy.
	 */
	public function __construct(
		LanguageManager $language_manager,
		PluginSettings $settings,
		WorkflowSettings $workflow_settings,
		PostTypeSupport $post_type_support,
		TaxonomySupport $taxonomy_support
	) {
		$this->language_manager  = $language_manager;
		$this->settings          = $settings;
		$this->workflow_settings = $workflow_settings;
		$this->post_type_support = $post_type_support;
		$this->taxonomy_support  = $taxonomy_support;
	}

	/**
	 * Returns a portable export document.
	 *
	 * Stable locales are used instead of site-specific language UUIDs. Menu and
	 * theme-location assignments are intentionally excluded because their IDs are
	 * not portable between WordPress installations.
	 *
	 * @return array<string, mixed>
	 */
	public function export() {
		$languages      = $this->language_manager->get_languages();
		$default_id     = $this->language_manager->get_default_id();
		$default_locale = '';
		$enabled        = array();

		foreach ( $languages as $language ) {
			if ( $language['id'] === $default_id ) {
				$default_locale = $language['locale'];
			}

			if ( ! empty( $language['enabled'] ) ) {
				$enabled[] = $language['locale'];
			}
		}

		$document = array(
			'format'         => self::FORMAT,
			'schema_version' => PluginSettings::SCHEMA_VERSION,
			'plugin_version' => SHAPETRANSLATE_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'general'        => array(
				'default_locale'  => $default_locale,
				'enabled_locales' => array_values( $enabled ),
			),
			'settings'       => $this->settings->get_portable(),
			'workflow'       => $this->workflow_settings->get(),
		);

		/**
		 * Filters a ShapeTranslate settings export document.
		 *
		 * Consumers must preserve the format and schema version fields.
		 *
		 * @param array<string, mixed> $document Export document.
		 */
		$filtered = apply_filters( 'shapetranslate_settings_export', $document );

		return is_array( $filtered ) ? $filtered : $document;
	}

	/**
	 * Validates and imports one JSON settings document.
	 *
	 * @param string $json JSON document.
	 * @return array<string, bool>|WP_Error Import result.
	 */
	public function import_json( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return new WP_Error( 'empty_import', __( 'Choose a settings file or paste JSON to import.', 'shapetranslate' ) );
		}

		if ( self::MAX_JSON_BYTES < strlen( $json ) ) {
			return new WP_Error( 'import_too_large', __( 'The settings file is larger than 1 MB.', 'shapetranslate' ) );
		}

		$document = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $document ) ) {
			return new WP_Error( 'invalid_json', __( 'The settings file does not contain valid JSON.', 'shapetranslate' ) );
		}

		$validated = $this->validate_document( $document );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		/**
		 * Filters a fully validated import document before it is applied.
		 *
		 * Returning a WP_Error prevents the import without changing settings.
		 *
		 * @param array<string, mixed>|WP_Error $validated Validated document.
		 * @param array<string, mixed>          $document  Decoded source document.
		 */
		$filtered = apply_filters( 'shapetranslate_validated_settings_import', $validated, $document );

		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}

		if ( ! is_array( $filtered ) ) {
			return new WP_Error( 'invalid_filtered_import', __( 'A settings import filter returned invalid data.', 'shapetranslate' ) );
		}

		if (
			! isset( $filtered['general'], $filtered['settings'], $filtered['workflow'] )
			|| ! is_array( $filtered['general'] )
			|| ! is_array( $filtered['settings'] )
			|| ! is_array( $filtered['workflow'] )
		) {
			return new WP_Error( 'invalid_filtered_import', __( 'A settings import filter removed required data.', 'shapetranslate' ) );
		}

		$general  = $this->validate_general( $filtered['general'] );
		$settings = $this->validate_settings( $filtered['settings'] );
		$workflow = $this->validate_workflow( $filtered['workflow'] );

		if ( is_wp_error( $general ) || is_wp_error( $settings ) || is_wp_error( $workflow ) ) {
			return new WP_Error( 'invalid_filtered_import', __( 'A settings import filter returned data that failed validation.', 'shapetranslate' ) );
		}

		$validated             = $filtered;
		$validated['general']  = $general;
		$validated['settings'] = $settings;
		$validated['workflow'] = $workflow;
		$old_url               = $this->settings->get_section( 'url' );
		$language_id           = $this->apply_languages( $validated['general'] );

		if ( is_wp_error( $language_id ) ) {
			return $language_id;
		}

		$this->settings->replace_portable( $validated['settings'] );
		$this->workflow_settings->update( $validated['workflow'] );
		$new_url     = $this->settings->get_section( 'url' );
		$url_changed = $old_url !== $new_url;
		$result      = array( 'url_changed' => $url_changed );

		/**
		 * Fires after a validated settings document has been imported.
		 *
		 * @param array<string, bool>  $result    Import result.
		 * @param array<string, mixed> $validated Validated document.
		 */
		do_action( 'shapetranslate_settings_imported', $result, $validated );

		return $result;
	}

	/**
	 * Validates and normalizes one decoded document.
	 *
	 * @param array<string, mixed> $document Decoded document.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_document( array $document ) {
		if ( ! isset( $document['format'] ) || self::FORMAT !== $document['format'] ) {
			return new WP_Error( 'invalid_import_format', __( 'This is not a ShapeTranslate settings export.', 'shapetranslate' ) );
		}

		if ( ! isset( $document['schema_version'] ) || PluginSettings::SCHEMA_VERSION !== absint( $document['schema_version'] ) ) {
			return new WP_Error( 'unsupported_import_schema', __( 'This settings schema version is not supported.', 'shapetranslate' ) );
		}

		if (
			! isset( $document['general'], $document['settings'], $document['workflow'] )
			|| ! is_array( $document['general'] )
			|| ! is_array( $document['settings'] )
			|| ! is_array( $document['workflow'] )
		) {
			return new WP_Error( 'incomplete_import', __( 'The settings export is missing required sections.', 'shapetranslate' ) );
		}

		$general = $this->validate_general( $document['general'] );

		if ( is_wp_error( $general ) ) {
			return $general;
		}

		$settings = $this->validate_settings( $document['settings'] );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$workflow = $this->validate_workflow( $document['workflow'] );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		return array(
			'general'  => $general,
			'settings' => $settings,
			'workflow' => $workflow,
		);
	}

	/**
	 * Validates locale-based language configuration.
	 *
	 * @param array<string, mixed> $general General section.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_general( array $general ) {
		if (
			! isset( $general['default_locale'], $general['enabled_locales'] )
			|| ! is_string( $general['default_locale'] )
			|| ! is_array( $general['enabled_locales'] )
		) {
			return new WP_Error( 'invalid_import_languages', __( 'The imported language configuration is invalid.', 'shapetranslate' ) );
		}

		$by_locale = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			$by_locale[ $language['locale'] ] = $language;
		}

		$enabled_locales = array();

		foreach ( $general['enabled_locales'] as $locale ) {
			if ( ! is_string( $locale ) || ! isset( $by_locale[ $locale ] ) ) {
				return new WP_Error(
					'missing_import_language',
					__( 'Register every language from the export before importing its settings.', 'shapetranslate' )
				);
			}

			$enabled_locales[] = $locale;
		}

		$enabled_locales = array_values( array_unique( $enabled_locales ) );

		if (
			empty( $enabled_locales )
			|| ! isset( $by_locale[ $general['default_locale'] ] )
			|| ! in_array( $general['default_locale'], $enabled_locales, true )
		) {
			return new WP_Error( 'invalid_import_default', __( 'The imported default language must be registered and enabled.', 'shapetranslate' ) );
		}

		return array(
			'default_locale'  => $general['default_locale'],
			'enabled_locales' => $enabled_locales,
			'languages'       => $by_locale,
		);
	}

	/**
	 * Validates portable plugin settings.
	 *
	 * @param array<string, mixed> $settings Settings section.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_settings( array $settings ) {
		$required = array( 'url', 'content', 'switcher', 'seo', 'advanced' );

		foreach ( $required as $section ) {
			if ( ! isset( $settings[ $section ] ) || ! is_array( $settings[ $section ] ) ) {
				return new WP_Error( 'invalid_import_settings', __( 'The imported plugin settings are incomplete.', 'shapetranslate' ) );
			}
		}

		$url      = $settings['url'];
		$content  = $settings['content'];
		$switcher = $settings['switcher'];
		$seo      = $settings['seo'];
		$advanced = $settings['advanced'];

		if (
			! isset( $url['mode'], $url['prefix_default'] )
			|| ! in_array( $url['mode'], LanguageHostResolver::modes(), true )
			|| ! is_bool( $url['prefix_default'] )
		) {
			return new WP_Error( 'invalid_import_url', __( 'The imported URL settings are invalid or unsupported.', 'shapetranslate' ) );
		}

		$content_result = $this->validate_content_settings( $content );

		if ( is_wp_error( $content_result ) ) {
			return $content_result;
		}

		if (
			! $this->valid_enum( $switcher, 'display', array( 'name', 'native_name', 'language_code', 'flag' ) )
			|| ! $this->valid_enum( $switcher, 'layout', array( 'dropdown', 'horizontal', 'vertical' ) )
			|| ! $this->valid_enum( $switcher, 'unavailable_behavior', array( 'disabled', 'hide', 'home', 'current' ) )
			|| ! $this->boolean_keys( $switcher, array( 'hide_current', 'hide_missing', 'show_flags', 'show_disabled' ) )
		) {
			return new WP_Error( 'invalid_import_switcher', __( 'The imported switcher settings are invalid.', 'shapetranslate' ) );
		}

		/*
		 * An export taken before the floating switcher existed carries no
		 * floater at all, and that is not an incomplete file: the importing site
		 * fills it from its own defaults the way a fresh install does. Only a
		 * floater that is present and wrong is rejected.
		 */
		if ( isset( $switcher['floater'] ) ) {
			if ( ! is_array( $switcher['floater'] ) ) {
				return new WP_Error( 'invalid_import_switcher', __( 'The imported switcher settings are invalid.', 'shapetranslate' ) );
			}

			$floater = $switcher['floater'];

			if (
				! $this->boolean_keys( $floater, array( 'enabled', 'show_flags' ) )
				|| ! $this->valid_enum( $floater, 'position', PluginSettings::floater_positions() )
				|| ! $this->valid_enum( $floater, 'layout', array( 'dropdown', 'horizontal', 'vertical' ) )
			) {
				return new WP_Error( 'invalid_import_switcher', __( 'The imported switcher settings are invalid.', 'shapetranslate' ) );
			}
		}

		if ( ! $this->boolean_keys( $seo, array( 'hreflang_enabled', 'x_default_enabled' ) ) ) {
			return new WP_Error( 'invalid_import_seo', __( 'The imported SEO settings are invalid.', 'shapetranslate' ) );
		}

		if ( ! $this->boolean_keys( $advanced, array( 'delete_data_on_uninstall' ) ) ) {
			return new WP_Error( 'invalid_import_advanced', __( 'The imported advanced settings are invalid.', 'shapetranslate' ) );
		}

		return array(
			'url'      => $url,
			'content'  => $content_result,
			'switcher' => $switcher,
			'seo'      => $seo,
			'advanced' => $advanced,
		);
	}

	/**
	 * Validates translatable content policies against objects on this site.
	 *
	 * @param array<string, mixed> $content Content settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_content_settings( array $content ) {
		if (
			! $this->valid_enum( $content, 'post_types_mode', array( 'all', 'selected' ) )
			|| ! $this->valid_enum( $content, 'taxonomies_mode', array( 'all', 'selected' ) )
			|| ! isset( $content['post_types'], $content['taxonomies'] )
			|| ! is_array( $content['post_types'] )
			|| ! is_array( $content['taxonomies'] )
		) {
			return new WP_Error( 'invalid_import_content', __( 'The imported content settings are invalid.', 'shapetranslate' ) );
		}

		// Exports written before media translation existed simply keep it off.
		if (
			( isset( $content['media_support'] ) && ! is_bool( $content['media_support'] ) )
			|| ( isset( $content['front_fallback'] ) && ! is_bool( $content['front_fallback'] ) )
		) {
			return new WP_Error( 'invalid_import_content', __( 'The imported content settings are invalid.', 'shapetranslate' ) );
		}

		$post_types = $this->strict_key_list( $content['post_types'] );
		$taxonomies = $this->strict_key_list( $content['taxonomies'] );

		if ( false === $post_types || false === $taxonomies ) {
			return new WP_Error( 'invalid_import_content', __( 'The imported content settings contain invalid object names.', 'shapetranslate' ) );
		}

		if (
			! empty( array_diff( $post_types, $this->post_type_support->get_available_post_types() ) )
			|| ! empty( array_diff( $taxonomies, $this->taxonomy_support->get_available_taxonomies() ) )
		) {
			return new WP_Error( 'missing_import_content_type', __( 'A selected post type or taxonomy is not available on this site.', 'shapetranslate' ) );
		}

		return array(
			'post_types_mode' => $content['post_types_mode'],
			'post_types'      => $post_types,
			'taxonomies_mode' => $content['taxonomies_mode'],
			'taxonomies'      => $taxonomies,
			'media_support'   => ! empty( $content['media_support'] ),
			'front_fallback'  => ! empty( $content['front_fallback'] ),
		);
	}

	/**
	 * Validates translation workflow settings.
	 *
	 * @param array<string, mixed> $workflow Workflow settings.
	 * @return array<string, bool>|WP_Error
	 */
	private function validate_workflow( array $workflow ) {
		if ( ! $this->boolean_keys( $workflow, array_keys( WorkflowSettings::DEFAULTS ) ) ) {
			return new WP_Error( 'invalid_import_workflow', __( 'The imported translation workflow settings are invalid.', 'shapetranslate' ) );
		}

		$validated = array();

		// Exports written before an item existed simply fall back to its default.
		foreach ( WorkflowSettings::defaults() as $key => $default ) {
			if ( array_key_exists( $key, $workflow ) && ! is_bool( $workflow[ $key ] ) ) {
				return new WP_Error( 'invalid_import_workflow', __( 'The imported translation workflow settings are invalid.', 'shapetranslate' ) );
			}

			$validated[ $key ] = array_key_exists( $key, $workflow ) ? $workflow[ $key ] : $default;
		}

		return $validated;
	}

	/**
	 * Applies validated language state after all validation has completed.
	 *
	 * @param array<string, mixed> $general Validated general settings.
	 * @return string|WP_Error Default language identifier.
	 */
	private function apply_languages( array $general ) {
		$languages       = $general['languages'];
		$enabled_locales = $general['enabled_locales'];
		$default_id      = $languages[ $general['default_locale'] ]['id'];

		foreach ( $enabled_locales as $locale ) {
			$language = $this->language_manager->find( $languages[ $locale ]['id'] );

			if ( is_array( $language ) && empty( $language['enabled'] ) ) {
				$result = $this->language_manager->toggle( $language['id'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$result = $this->language_manager->set_default( $default_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( ! empty( $language['enabled'] ) && ! in_array( $language['locale'], $enabled_locales, true ) ) {
				$result = $this->language_manager->toggle( $language['id'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return $default_id;
	}

	/**
	 * Checks one scalar enum field.
	 *
	 * @param array<string, mixed> $values  Source values.
	 * @param string               $key     Field key.
	 * @param array<int, string>   $allowed Allowed values.
	 * @return bool
	 */
	private function valid_enum( array $values, $key, array $allowed ) {
		return isset( $values[ $key ] )
			&& is_string( $values[ $key ] )
			&& in_array( $values[ $key ], $allowed, true );
	}

	/**
	 * Checks that every required key contains a JSON boolean.
	 *
	 * @param array<string, mixed> $values Source values.
	 * @param array<int, string>   $keys   Required keys.
	 * @return bool
	 */
	private function boolean_keys( array $values, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $values ) || ! is_bool( $values[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes a list only when every item is a canonical WordPress key.
	 *
	 * @param array<int, mixed> $values Raw values.
	 * @return array<int, string>|false
	 */
	private function strict_key_list( array $values ) {
		$keys = array();

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value || sanitize_key( $value ) !== $value ) {
				return false;
			}

			$keys[] = $value;
		}

		return array_values( array_unique( $keys ) );
	}
}
