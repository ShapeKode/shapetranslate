<?php
/**
 * Synchronization item catalog.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Describes every item ShapeTranslate can keep synchronized across translations.
 *
 * Creating a translation always copies what an editor needs to start from, so
 * the copy phase has no options. This catalog therefore describes the ongoing
 * synchronization phase only: one entry produces the stored option key, the
 * settings-screen row, and the engine's behavior.
 *
 * The schema carries no translated strings. The engine reads it on any post-meta
 * write, which can happen before WordPress has loaded a text domain, so labels
 * live in a separate map that only the settings screen resolves.
 */
final class SyncCatalog {

	/**
	 * Option key prefix for the ongoing synchronization phase.
	 *
	 * @var string
	 */
	const SYNC_PREFIX = 'sync_';

	/**
	 * Option key prefix for the programmatic copy overrides.
	 *
	 * @var string
	 */
	const COPY_PREFIX = 'copy_';

	/**
	 * Returns the catalog of synchronizable items, in settings-screen order.
	 *
	 * Synchronization stays off until a site opts in, so every item defaults to
	 * disabled.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function items() {
		$items = array(
			'taxonomies',
			'post_meta',
			'comment_status',
			'ping_status',
			'sticky',
			'post_date',
			'post_format',
			'post_parent',
			'page_template',
			'menu_order',
			'featured_image',
		);
		$built = array();

		foreach ( $items as $id ) {
			$built[ $id ] = array( 'sync_default' => false );
		}

		/**
		 * Filters the catalog of synchronizable items.
		 *
		 * Addons can describe their own item here to receive an option key, a
		 * settings-screen row, and the engine's synchronization event. Labels come
		 * from `shapetranslate_sync_catalog_labels`.
		 *
		 * @param array<string, array<string, mixed>> $built Catalog items keyed by identifier.
		 */
		$filtered = apply_filters( 'shapetranslate_sync_catalog', $built );

		return self::normalize_items( is_array( $filtered ) ? $filtered : $built );
	}

	/**
	 * Returns the catalog with translated labels for the settings screen.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function items_with_labels() {
		$labels = self::labels();
		$items  = array();

		foreach ( self::items() as $id => $item ) {
			$item['label']       = isset( $labels[ $id ]['label'] ) ? $labels[ $id ]['label'] : $id;
			$item['description'] = isset( $labels[ $id ]['description'] ) ? $labels[ $id ]['description'] : '';
			$items[ $id ]        = $item;
		}

		return $items;
	}

	/**
	 * Returns translated labels and descriptions keyed by item identifier.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function labels() {
		$labels = array(
			'taxonomies'     => array(
				'label'       => __( 'Taxonomies', 'shapetranslate' ),
				'description' => __( 'Terms of translatable taxonomies are mapped to their translations; other taxonomies are shared as stored.', 'shapetranslate' ),
			),
			'post_meta'      => array(
				'label'       => __( 'Custom fields', 'shapetranslate' ),
				'description' => __( 'Public custom fields only. Protected keys stay with their own post unless a filter adds them.', 'shapetranslate' ),
			),
			'comment_status' => array( 'label' => __( 'Comment status', 'shapetranslate' ) ),
			'ping_status'    => array( 'label' => __( 'Ping status', 'shapetranslate' ) ),
			'sticky'         => array( 'label' => __( 'Sticky status', 'shapetranslate' ) ),
			'post_date'      => array(
				'label'       => __( 'Published date', 'shapetranslate' ),
				'description' => __( 'Also decides whether a new translation starts with the source date instead of the date it was created.', 'shapetranslate' ),
			),
			'post_format'    => array( 'label' => __( 'Post format', 'shapetranslate' ) ),
			'post_parent'    => array(
				'label'       => __( 'Page parent', 'shapetranslate' ),
				'description' => __( 'Uses the parent’s translation when one exists, otherwise the source parent.', 'shapetranslate' ),
			),
			'page_template'  => array( 'label' => __( 'Page template', 'shapetranslate' ) ),
			'menu_order'     => array( 'label' => __( 'Page order', 'shapetranslate' ) ),
			'featured_image' => array(
				'label'       => __( 'Featured image', 'shapetranslate' ),
				'description' => __( 'Translations reuse the source attachment; the file is never duplicated.', 'shapetranslate' ),
			),
		);

		/**
		 * Filters the labels shown for catalog items.
		 *
		 * @param array<string, array<string, string>> $labels Labels keyed by item identifier.
		 */
		$filtered = apply_filters( 'shapetranslate_sync_catalog_labels', $labels );

		return is_array( $filtered ) ? $filtered : $labels;
	}

	/**
	 * Returns every stored option key with its default value.
	 *
	 * @return array<string, bool>
	 */
	public static function option_defaults() {
		$defaults = array();

		foreach ( self::items() as $id => $item ) {
			$defaults[ self::sync_key( $id ) ] = (bool) $item['sync_default'];
		}

		return $defaults;
	}

	/**
	 * Returns the option key holding an item's synchronization state.
	 *
	 * @param string $item_id Catalog item identifier.
	 * @return string
	 */
	public static function sync_key( $item_id ) {
		return self::SYNC_PREFIX . sanitize_key( (string) $item_id );
	}

	/**
	 * Returns the option key holding an item's programmatic copy override.
	 *
	 * @param string $item_id Catalog item identifier.
	 * @return string
	 */
	public static function copy_key( $item_id ) {
		return self::COPY_PREFIX . sanitize_key( (string) $item_id );
	}

	/**
	 * Normalizes catalog items so a filter cannot produce an unusable entry.
	 *
	 * @param array<string, mixed> $items Raw catalog items.
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_items( array $items ) {
		$normalized = array();

		foreach ( $items as $id => $item ) {
			$id = sanitize_key( (string) $id );

			if ( '' === $id || ! is_array( $item ) ) {
				continue;
			}

			$normalized[ $id ] = array( 'sync_default' => ! empty( $item['sync_default'] ) );
		}

		return $normalized;
	}
}
