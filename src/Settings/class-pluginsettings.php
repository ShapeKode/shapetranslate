<?php
/**
 * ShapeTranslate plugin settings.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Settings;

use ShapeTranslate\Infrastructure\OptionsLanguageRepository;
use ShapeTranslate\Routing\LanguageHostResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and normalizes the versioned, portable plugin configuration.
 */
final class PluginSettings {

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'shapetranslate_settings';

	/**
	 * Current settings schema version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Request-local normalized settings cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private $settings;

	/**
	 * Site prefix associated with the request cache.
	 *
	 * @var string
	 */
	private $cache_prefix = '';

	/**
	 * Counter advanced whenever the stored configuration changes.
	 *
	 * @var int
	 */
	private $revision = 0;

	/**
	 * Adds the non-autoloaded settings option when it does not exist.
	 *
	 * Existing sites with registered languages are considered configured so an
	 * upgrade does not unexpectedly reopen the setup wizard.
	 *
	 * @return void
	 */
	public static function install() {
		$defaults  = self::default_settings();
		$registry  = get_option( OptionsLanguageRepository::OPTION_NAME, array() );
		$languages = is_array( $registry ) && isset( $registry['languages'] ) && is_array( $registry['languages'] )
			? $registry['languages']
			: array();

		if ( ! empty( $languages ) ) {
			$defaults['setup']['complete'] = true;
			$defaults['setup']['step']     = 5;
		}

		add_option( self::OPTION_NAME, $defaults, '', false );
	}

	/**
	 * Returns the unfiltered default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'url'            => array(
				'mode'           => 'directory',
				'prefix_default' => true,
				'detect_browser' => false,
			),

			/*
			 * Core content is translatable on a new site so the plugin is usable
			 * immediately, while custom post types and taxonomies are opt-in: a
			 * site owner decides which of their own content models carry a
			 * language, rather than every registered type gaining one silently.
			 */
			'content'        => array(
				'post_types_mode' => 'selected',
				'post_types'      => array( 'post', 'page' ),
				'taxonomies_mode' => 'selected',
				'taxonomies'      => array( 'category', 'post_tag' ),
				'media_support'   => false,
				'front_fallback'  => false,
				'default_terms'   => array(),
			),
			'switcher'       => array(
				'display'              => 'native_name',
				'layout'               => 'horizontal',
				'hide_current'         => false,
				'hide_missing'         => false,
				'unavailable_behavior' => 'hide',
				'show_flags'           => false,
				'show_disabled'        => false,
				'floater'              => self::floater_defaults(),
			),
			'seo'            => array(
				'hreflang_enabled'  => true,
				'x_default_enabled' => true,
				'split_sitemaps'    => true,
			),
			'advanced'       => array(
				'delete_data_on_uninstall' => false,
			),
			'setup'          => array(
				'complete' => false,
				'step'     => 1,
			),
		);
	}

	/**
	 * Returns the unfiltered floating switcher defaults.
	 *
	 * A site that has just registered its second language has placed no
	 * switcher anywhere yet: no shortcode, no block, no menu item. Until it
	 * does, that language is registered and routed and reachable by nobody
	 * reading the site. The floating switcher is the way in, which is why it
	 * starts on, and it is one checkbox away from gone the moment a theme
	 * carries a switcher of its own.
	 *
	 * @return array<string, mixed>
	 */
	public static function floater_defaults() {
		return array(
			'enabled'    => true,
			'position'   => 'middle-right',
			'layout'     => 'vertical',

			/*
			 * Flags are the floater's own answer rather than the site-wide one,
			 * and the answer is yes. Everywhere else a switcher sits in running
			 * text and a flag is decoration the site owner opts into; in a strip
			 * at the edge of the screen the flag is what identifies the language
			 * before the label is read at all, and on a phone the labels are
			 * dropped and the flags are the only thing left.
			 */
			'show_flags' => true,
		);
	}

	/**
	 * Returns the screen edges a floating switcher may be pinned to.
	 *
	 * The two middle edges come first because they are what the floater is for:
	 * a strip halfway down the side of the screen is in view wherever the reader
	 * has scrolled to, while a corner competes with the cookie notices, chat
	 * bubbles, and back-to-top buttons that every site already puts there.
	 *
	 * @return array<int, string>
	 */
	public static function floater_positions() {
		return array(
			'middle-right',
			'middle-left',
			'bottom-right',
			'bottom-left',
			'top-right',
			'top-left',
		);
	}

	/**
	 * Returns normalized plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get() {
		$settings = $this->get_stored_settings();

		/**
		 * Filters the normalized ShapeTranslate configuration.
		 *
		 * @param array<string, mixed> $settings Normalized settings.
		 */
		$filtered = apply_filters( 'shapetranslate_settings', $settings );

		return is_array( $filtered ) ? $this->normalize( $filtered ) : $settings;
	}

	/**
	 * Returns a counter that advances whenever these settings change.
	 *
	 * Reading a section is not free: the stored option is cached, but every read
	 * runs the filters and re-normalizes all six sections, which is the right
	 * answer for a caller that asks once and the wrong one for a caller asked
	 * hundreds of times per page. Such a caller can cache what it derived and
	 * ask this instead, which costs one property read behind the same site check
	 * get() makes, and tells it when what it cached stopped being true.
	 *
	 * @return int
	 */
	public function revision() {
		$this->maybe_reset_cache_for_site();

		return $this->revision;
	}

	/**
	 * Returns one normalized settings section.
	 *
	 * @param string $section Section key.
	 * @return array<string, mixed>
	 */
	public function get_section( $section ) {
		$settings = $this->get();
		$section  = sanitize_key( $section );

		return isset( $settings[ $section ] ) && is_array( $settings[ $section ] )
			? $settings[ $section ]
			: array();
	}

	/**
	 * Merges and stores one or more settings sections.
	 *
	 * @param array<string, mixed> $sections Settings sections.
	 * @return bool
	 */
	public function update_sections( array $sections ) {
		$current = $this->get_stored_settings();

		foreach ( array( 'url', 'content', 'switcher', 'seo', 'advanced', 'setup' ) as $section ) {
			if ( isset( $sections[ $section ] ) && is_array( $sections[ $section ] ) ) {
				$current[ $section ] = array_merge( $current[ $section ], $sections[ $section ] );
			}
		}

		return $this->update( $current );
	}

	/**
	 * Replaces only portable settings while preserving local setup state.
	 *
	 * @param array<string, mixed> $settings Validated portable settings.
	 * @return bool
	 */
	public function replace_portable( array $settings ) {
		$current     = $this->get_stored_settings();
		$local_terms = isset( $current['content']['default_terms'] ) ? $current['content']['default_terms'] : array();

		foreach ( array( 'url', 'content', 'switcher', 'seo', 'advanced' ) as $section ) {
			if ( isset( $settings[ $section ] ) && is_array( $settings[ $section ] ) ) {
				$current[ $section ] = $settings[ $section ];
			}
		}

		$current['content']['default_terms'] = $local_terms;

		return $this->update( $current );
	}

	/**
	 * Returns settings suitable for JSON transfer between sites.
	 *
	 * @return array<string, mixed>
	 */
	public function get_portable() {
		$settings = $this->get_stored_settings();

		unset( $settings['content']['default_terms'] );

		return array_intersect_key(
			$settings,
			array_fill_keys( array( 'url', 'content', 'switcher', 'seo', 'advanced' ), true )
		);
	}

	/**
	 * Stores a complete normalized settings document.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return bool
	 */
	public function update( array $settings ) {
		$this->maybe_reset_cache_for_site();
		$this->settings = $this->normalize( $settings );
		$result         = update_option( self::OPTION_NAME, $this->settings, false );
		++$this->revision;

		/**
		 * Fires after ShapeTranslate configuration has been stored.
		 *
		 * @param array<string, mixed> $settings Normalized settings.
		 */
		do_action( 'shapetranslate_settings_updated', $this->settings );

		return $result;
	}

	/**
	 * Reports whether the default language receives a URL prefix.
	 *
	 * @return bool
	 */
	public function should_prefix_default_language() {
		$url = $this->get_section( 'url' );

		return ! empty( $url['prefix_default'] );
	}

	/**
	 * Reports whether a first-time visitor is sent to their browser language.
	 *
	 * @return bool
	 */
	public function should_detect_browser_language() {
		$url = $this->get_section( 'url' );

		return ! empty( $url['detect_browser'] );
	}

	/**
	 * Reports whether media items carry their own language and translations.
	 *
	 * @return bool
	 */
	public function is_media_support_enabled() {
		$content = $this->get_section( 'content' );

		return ! empty( $content['media_support'] );
	}

	/**
	 * Reports whether an untranslated static front page shows the original.
	 *
	 * @return bool
	 */
	public function is_front_page_fallback_enabled() {
		$content = $this->get_section( 'content' );

		return ! empty( $content['front_fallback'] );
	}

	/**
	 * Returns the default term chosen for one taxonomy and language.
	 *
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $language_id Language identifier.
	 * @return int Term ID, or zero when the language has no chosen default.
	 */
	public function get_default_term( $taxonomy, $language_id ) {
		$content  = $this->get_section( 'content' );
		$taxonomy = sanitize_key( $taxonomy );
		$language = sanitize_key( $language_id );
		$map      = isset( $content['default_terms'] ) && is_array( $content['default_terms'] )
			? $content['default_terms']
			: array();

		return isset( $map[ $taxonomy ][ $language ] ) ? absint( $map[ $taxonomy ][ $language ] ) : 0;
	}

	/**
	 * Returns the configured switcher defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_switcher_defaults() {
		$switcher = $this->get_section( 'switcher' );

		/*
		 * The floater is a placement, not a rendering default: it says where one
		 * switcher goes, never how every switcher looks. Left in, it would ride
		 * into the arguments of every switcher on the site — shortcode, block,
		 * menu item, template tag — as a key no renderer reads and every
		 * argument filter would have to step around.
		 */
		unset( $switcher['floater'] );

		return $switcher;
	}

	/**
	 * Returns the normalized floating switcher configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_floater_settings() {
		$switcher = $this->get_section( 'switcher' );
		$floater  = isset( $switcher['floater'] ) && is_array( $switcher['floater'] )
			? $switcher['floater']
			: array();

		return array_merge( self::floater_defaults(), $floater );
	}

	/**
	 * Reports whether hreflang output is enabled.
	 *
	 * @return bool
	 */
	public function is_hreflang_enabled() {
		$seo = $this->get_section( 'seo' );

		return ! empty( $seo['hreflang_enabled'] );
	}

	/**
	 * Reports whether x-default output is enabled.
	 *
	 * @return bool
	 */
	public function is_x_default_enabled() {
		$seo = $this->get_section( 'seo' );

		return ! empty( $seo['x_default_enabled'] );
	}

	/**
	 * Reports whether the core sitemap is split into one file per language.
	 *
	 * @return bool
	 */
	public function is_sitemap_split_enabled() {
		$seo = $this->get_section( 'seo' );

		return ! empty( $seo['split_sitemaps'] );
	}

	/**
	 * Reports whether plugin data should be removed during uninstall.
	 *
	 * @return bool
	 */
	public function should_delete_data_on_uninstall() {
		$advanced = $this->get_section( 'advanced' );

		return ! empty( $advanced['delete_data_on_uninstall'] );
	}

	/**
	 * Returns whether setup has been completed.
	 *
	 * @return bool
	 */
	public function is_setup_complete() {
		$setup = $this->get_section( 'setup' );

		return ! empty( $setup['complete'] );
	}

	/**
	 * Returns the next setup step.
	 *
	 * @return int
	 */
	public function get_setup_step() {
		$setup = $this->get_section( 'setup' );

		return isset( $setup['step'] ) ? min( 5, max( 1, absint( $setup['step'] ) ) ) : 1;
	}

	/**
	 * Normalizes all supported settings and discards unknown keys.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	public function normalize( array $settings ) {
		$defaults = self::default_settings();

		/**
		 * Filters ShapeTranslate settings defaults before normalization.
		 *
		 * @param array<string, mixed> $defaults Default settings.
		 */
		$filtered_defaults = apply_filters( 'shapetranslate_settings_defaults', $defaults );

		if ( is_array( $filtered_defaults ) ) {
			foreach ( array( 'url', 'content', 'switcher', 'seo', 'advanced', 'setup' ) as $section ) {
				if ( isset( $filtered_defaults[ $section ] ) && is_array( $filtered_defaults[ $section ] ) ) {
					$defaults[ $section ] = array_merge( $defaults[ $section ], $filtered_defaults[ $section ] );
				}
			}
		}

		$url      = isset( $settings['url'] ) && is_array( $settings['url'] ) ? $settings['url'] : array();
		$content  = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$switcher = isset( $settings['switcher'] ) && is_array( $settings['switcher'] ) ? $settings['switcher'] : array();
		$seo      = isset( $settings['seo'] ) && is_array( $settings['seo'] ) ? $settings['seo'] : array();
		$advanced = isset( $settings['advanced'] ) && is_array( $settings['advanced'] ) ? $settings['advanced'] : array();
		$setup    = isset( $settings['setup'] ) && is_array( $settings['setup'] ) ? $settings['setup'] : array();

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'url'            => array(
				'mode'           => $this->enum_value( $url, 'mode', LanguageHostResolver::modes(), $defaults['url']['mode'] ),
				'prefix_default' => $this->boolean_value( $url, 'prefix_default', $defaults['url']['prefix_default'] ),
				'detect_browser' => $this->boolean_value( $url, 'detect_browser', $defaults['url']['detect_browser'] ),
			),
			'content'        => array(
				'post_types_mode' => $this->enum_value( $content, 'post_types_mode', array( 'all', 'selected' ), $defaults['content']['post_types_mode'] ),
				'post_types'      => $this->key_list( isset( $content['post_types'] ) ? $content['post_types'] : $defaults['content']['post_types'] ),
				'taxonomies_mode' => $this->enum_value( $content, 'taxonomies_mode', array( 'all', 'selected' ), $defaults['content']['taxonomies_mode'] ),
				'taxonomies'      => $this->key_list( isset( $content['taxonomies'] ) ? $content['taxonomies'] : $defaults['content']['taxonomies'] ),
				'media_support'   => $this->boolean_value( $content, 'media_support', $defaults['content']['media_support'] ),
				'front_fallback'  => $this->boolean_value( $content, 'front_fallback', $defaults['content']['front_fallback'] ),
				'default_terms'   => $this->term_map( isset( $content['default_terms'] ) ? $content['default_terms'] : $defaults['content']['default_terms'] ),
			),
			'switcher'       => array(
				'display'              => $this->enum_value( $switcher, 'display', array( 'name', 'native_name', 'language_code', 'flag' ), $defaults['switcher']['display'] ),
				'layout'               => $this->enum_value( $switcher, 'layout', array( 'dropdown', 'horizontal', 'vertical' ), $defaults['switcher']['layout'] ),
				'hide_current'         => $this->boolean_value( $switcher, 'hide_current', $defaults['switcher']['hide_current'] ),
				'hide_missing'         => $this->boolean_value( $switcher, 'hide_missing', $defaults['switcher']['hide_missing'] ),
				'unavailable_behavior' => $this->enum_value( $switcher, 'unavailable_behavior', array( 'disabled', 'hide', 'home', 'current' ), $defaults['switcher']['unavailable_behavior'] ),
				'show_flags'           => $this->boolean_value( $switcher, 'show_flags', $defaults['switcher']['show_flags'] ),
				'show_disabled'        => $this->boolean_value( $switcher, 'show_disabled', $defaults['switcher']['show_disabled'] ),
				'floater'              => $this->floater_values(
					isset( $switcher['floater'] ) && is_array( $switcher['floater'] ) ? $switcher['floater'] : array(),
					isset( $defaults['switcher']['floater'] ) ? $defaults['switcher']['floater'] : array()
				),
			),
			'seo'            => array(
				'hreflang_enabled'  => $this->boolean_value( $seo, 'hreflang_enabled', $defaults['seo']['hreflang_enabled'] ),
				'x_default_enabled' => $this->boolean_value( $seo, 'x_default_enabled', $defaults['seo']['x_default_enabled'] ),
				'split_sitemaps'    => $this->boolean_value( $seo, 'split_sitemaps', $defaults['seo']['split_sitemaps'] ),
			),
			'advanced'       => array(
				'delete_data_on_uninstall' => $this->boolean_value( $advanced, 'delete_data_on_uninstall', $defaults['advanced']['delete_data_on_uninstall'] ),
			),
			'setup'          => array(
				'complete' => $this->boolean_value( $setup, 'complete', $defaults['setup']['complete'] ),
				'step'     => min( 5, max( 1, isset( $setup['step'] ) ? absint( $setup['step'] ) : $defaults['setup']['step'] ) ),
			),
		);
	}

	/**
	 * Returns the normalized floating switcher configuration.
	 *
	 * The stored value predates this setting on any site upgrading into it, so
	 * a missing sub-array is the normal case rather than an error: every key
	 * falls back to the shipped default, which is what turns the floater on for
	 * a site that never saw the checkbox.
	 *
	 * @param array<string, mixed> $floater  Stored floater settings.
	 * @param mixed                $defaults Default floater settings.
	 * @return array<string, mixed>
	 */
	private function floater_values( array $floater, $defaults ) {
		$defaults = array_merge(
			self::floater_defaults(),
			is_array( $defaults ) ? $defaults : array()
		);

		return array(
			'enabled'    => $this->boolean_value( $floater, 'enabled', $defaults['enabled'] ),
			'position'   => $this->enum_value( $floater, 'position', self::floater_positions(), $defaults['position'] ),
			'layout'     => $this->enum_value( $floater, 'layout', array( 'dropdown', 'horizontal', 'vertical' ), $defaults['layout'] ),
			'show_flags' => $this->boolean_value( $floater, 'show_flags', $defaults['show_flags'] ),
		);
	}

	/**
	 * Returns a normalized enum value.
	 *
	 * @param array<string, mixed> $values  Source values.
	 * @param string               $key     Value key.
	 * @param array<int, string>   $allowed Allowed values.
	 * @param string               $default_value Default value.
	 * @return string
	 */
	private function enum_value( array $values, $key, array $allowed, $default_value ) {
		$default_value = is_scalar( $default_value ) ? sanitize_key( (string) $default_value ) : '';

		if ( ! in_array( $default_value, $allowed, true ) ) {
			$default_value = reset( $allowed );
		}

		$value = isset( $values[ $key ] ) && is_scalar( $values[ $key ] )
			? sanitize_key( (string) $values[ $key ] )
			: $default_value;

		return in_array( $value, $allowed, true ) ? $value : $default_value;
	}

	/**
	 * Returns a normalized boolean value.
	 *
	 * @param array<string, mixed> $values  Source values.
	 * @param string               $key     Value key.
	 * @param bool                 $default_value Default value.
	 * @return bool
	 */
	private function boolean_value( array $values, $key, $default_value ) {
		if ( ! array_key_exists( $key, $values ) ) {
			return (bool) $default_value;
		}

		$value = $values[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Normalizes an array of WordPress object keys.
	 *
	 * @param mixed $values Raw list.
	 * @return array<int, string>
	 */
	private function key_list( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$keys = array();

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$key = sanitize_key( (string) $value );

				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Normalizes the per-language default term map.
	 *
	 * Only the shape is enforced here. Whether a term still exists, belongs to
	 * the taxonomy, and carries the language is decided where the map is written
	 * and read, so a term deleted later degrades to the stored site default
	 * instead of blocking every save.
	 *
	 * @param mixed $values Raw map of taxonomy => language ID => term ID.
	 * @return array<string, array<string, int>>
	 */
	private function term_map( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$map = array();

		foreach ( $values as $taxonomy => $languages ) {
			$taxonomy = is_scalar( $taxonomy ) ? sanitize_key( (string) $taxonomy ) : '';

			if ( '' === $taxonomy || ! is_array( $languages ) ) {
				continue;
			}

			$entries = array();

			foreach ( $languages as $language_id => $term_id ) {
				$language_id = is_scalar( $language_id ) ? sanitize_key( (string) $language_id ) : '';
				$term_id     = is_scalar( $term_id ) ? absint( $term_id ) : 0;

				if ( '' !== $language_id && 0 < $term_id ) {
					$entries[ $language_id ] = $term_id;
				}
			}

			if ( ! empty( $entries ) ) {
				$map[ $taxonomy ] = $entries;
			}
		}

		return $map;
	}

	/**
	 * Returns normalized settings exactly as persisted for this site.
	 *
	 * Runtime filters are intentionally excluded so saving or exporting one
	 * section cannot persist another module's temporary configuration override.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stored_settings() {
		$this->maybe_reset_cache_for_site();

		if ( null === $this->settings ) {
			$stored         = get_option( self::OPTION_NAME, self::default_settings() );
			$this->settings = $this->normalize( is_array( $stored ) ? $stored : array() );
		}

		return $this->settings;
	}

	/**
	 * Clears the request cache after multisite blog switching.
	 *
	 * @return void
	 */
	private function maybe_reset_cache_for_site() {
		global $wpdb;

		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		if ( $this->cache_prefix !== $prefix ) {
			$this->cache_prefix = $prefix;
			$this->settings     = null;
			++$this->revision;
		}
	}
}
