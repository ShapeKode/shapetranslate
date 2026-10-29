<?php
/**
 * Translation workflow settings.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Settings;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and resolves translated-copy and synchronization behavior.
 */
final class WorkflowSettings {

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'shapetranslate_workflow_settings';

	/**
	 * Copy overrides that have no settings screen.
	 *
	 * Creating a translation copies everything an editor needs to start from, so
	 * the copy phase is not configurable. These two remain as programmatic
	 * overrides for `create_translation()` and the copy-options filter, which the
	 * Elementor integration uses to open a translated document with an empty
	 * canvas.
	 *
	 * @var array<string, bool>
	 */
	const DEFAULTS = array(
		'copy_content'        => true,
		'copy_featured_image' => true,
	);

	/**
	 * Items whose copy behavior follows their synchronization setting.
	 *
	 * A translation is normally created now and therefore carries today's date.
	 * Sites that keep dates aligned across languages want the source date from
	 * the start, so one setting governs both phases.
	 *
	 * @var array<int, string>
	 */
	const SYNC_GATED_COPY_ITEMS = array( 'post_date' );

	/**
	 * Returns every supported setting with its default value.
	 *
	 * @return array<string, bool>
	 */
	public static function defaults() {
		return array_merge( self::DEFAULTS, SyncCatalog::option_defaults() );
	}

	/**
	 * Adds the non-autoloaded settings option when it does not exist.
	 *
	 * @return void
	 */
	public static function install() {
		add_option( self::OPTION_NAME, self::defaults(), '', false );
	}

	/**
	 * Returns normalized workflow settings.
	 *
	 * @return array<string, bool>
	 */
	public function get() {
		$defaults = self::defaults();
		$settings = get_option( self::OPTION_NAME, $defaults );
		$settings = is_array( $settings ) ? $this->normalize( $settings ) : $defaults;

		/**
		 * Filters stored translation workflow settings.
		 *
		 * @param array<string, bool> $settings Normalized settings.
		 */
		$filtered = apply_filters( 'shapetranslate_workflow_settings', $settings );

		return is_array( $filtered ) ? $this->normalize( $filtered ) : $settings;
	}

	/**
	 * Stores normalized workflow settings.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return bool
	 */
	public function update( array $settings ) {
		return update_option( self::OPTION_NAME, $this->normalize( $settings ), false );
	}

	/**
	 * Reports whether an item is copied into a new translation.
	 *
	 * Copying is unconditional. Only the two programmatic overrides and the
	 * sync-gated items can turn it off.
	 *
	 * @param string                   $item_id Catalog item identifier.
	 * @param array<string, bool>|null $options Resolved copy options, or null to read stored settings.
	 * @return bool
	 */
	public function is_copy_enabled( $item_id, ?array $options = null ) {
		$settings     = null === $options ? $this->get() : $options;
		$override_key = SyncCatalog::copy_key( $item_id );

		if ( array_key_exists( $override_key, self::DEFAULTS ) ) {
			return ! empty( $settings[ $override_key ] );
		}

		if ( in_array( $item_id, self::SYNC_GATED_COPY_ITEMS, true ) ) {
			return ! empty( $settings[ SyncCatalog::sync_key( $item_id ) ] );
		}

		return true;
	}

	/**
	 * Reports whether a catalog item stays synchronized after creation.
	 *
	 * @param string $item_id Catalog item identifier.
	 * @return bool
	 */
	public function is_sync_enabled( $item_id ) {
		$settings = $this->get();
		$key      = SyncCatalog::sync_key( $item_id );

		return ! empty( $settings[ $key ] );
	}

	/**
	 * Reports whether any catalog item stays synchronized after creation.
	 *
	 * @return bool
	 */
	public function has_enabled_sync_items() {
		$settings = $this->get();

		foreach ( array_keys( SyncCatalog::items() ) as $item_id ) {
			if ( ! empty( $settings[ SyncCatalog::sync_key( $item_id ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves copy behavior for one translated draft.
	 *
	 * Explicit API options override stored defaults before developer filters run.
	 *
	 * @param WP_Post              $source   Source post.
	 * @param array<string, mixed> $language Target language record.
	 * @param array<string, mixed> $options  Explicit copy options.
	 * @return array<string, bool>
	 */
	public function resolve_copy_options( WP_Post $source, array $language, array $options = array() ) {
		$resolved = $this->normalize( wp_parse_args( $options, $this->get() ) );

		/**
		 * Filters translated-draft copy behavior.
		 *
		 * @param array<string, bool>  $resolved Copy options.
		 * @param WP_Post             $source   Source post.
		 * @param array<string, mixed> $language Target language record.
		 */
		$filtered = apply_filters( 'shapetranslate_translation_copy_options', $resolved, $source, $language );

		return is_array( $filtered ) ? $this->normalize( $filtered ) : $resolved;
	}

	/**
	 * Normalizes supported boolean settings.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, bool>
	 */
	private function normalize( array $settings ) {
		$normalized = array();

		foreach ( self::defaults() as $key => $default ) {
			$normalized[ $key ] = array_key_exists( $key, $settings )
				? $this->normalize_boolean( $settings[ $key ] )
				: $default;
		}

		return $normalized;
	}

	/**
	 * Normalizes one checkbox or API boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function normalize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
