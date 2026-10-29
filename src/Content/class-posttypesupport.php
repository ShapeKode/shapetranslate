<?php
/**
 * Translatable post type policy.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers public post types supported by the free translation engine.
 */
final class PostTypeSupport {

	/**
	 * Non-public editorial post types the engine can still handle.
	 *
	 * These are not offered on the settings screen, because the list there is
	 * limited to post types a site owner recognizes as their own content. Synced
	 * patterns are the notable case: they are stored as `wp_block` posts and
	 * referenced by ID, so a shared pattern carries one language's text into
	 * every translation unless the pattern itself is translated. A site that
	 * wants that adds the post type back through
	 * `shapetranslate_supported_post_types`.
	 *
	 * @var array<int, string>
	 */
	const INTERNAL_POST_TYPES = array( 'wp_block' );

	/**
	 * Returns the non-public post types the engine will accept.
	 *
	 * Page builders keep their headers, footers, and layout templates in post
	 * types registered as non-public, because nothing should reach them by URL.
	 * They are still authored content that needs translating, so an integration
	 * declares them here and then adds them through
	 * `shapetranslate_supported_post_types` as usual.
	 *
	 * @return array<int, string>
	 */
	public static function non_public_post_types() {
		/**
		 * Filters the non-public post types ShapeTranslate may translate.
		 *
		 * Adding a post type here only makes it eligible. It still has to be
		 * returned from `shapetranslate_supported_post_types` to be translated.
		 *
		 * A post type declared here is exempt from the administrative UI
		 * requirement the public types are held to. That requirement exists so a
		 * site owner can reach what ShapeTranslate offers to translate, and a type
		 * named here is vouched for by whoever named it: a block theme's template
		 * parts are edited in the Site Editor rather than through a list table,
		 * and `show_ui` is false for exactly that reason.
		 *
		 * Example, for a header and footer builder:
		 *
		 *     add_filter( 'shapetranslate_non_public_post_types', function ( $types ) {
		 *         $types[] = 'elementor-hf';
		 *         return $types;
		 *     } );
		 *     add_filter( 'shapetranslate_supported_post_types', function ( $types ) {
		 *         $types[] = 'elementor-hf';
		 *         return $types;
		 *     } );
		 *
		 * @param array<int, string> $post_types Non-public post type names.
		 */
		$filtered = apply_filters( 'shapetranslate_non_public_post_types', self::INTERNAL_POST_TYPES );

		if ( ! is_array( $filtered ) ) {
			return self::INTERNAL_POST_TYPES;
		}

		$types = array();

		foreach ( $filtered as $post_type ) {
			if ( is_scalar( $post_type ) && '' !== sanitize_key( $post_type ) ) {
				$types[] = sanitize_key( $post_type );
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param PluginSettings|null $settings Optional central settings service.
	 */
	public function __construct( ?PluginSettings $settings = null ) {
		$this->settings = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * Returns supported post type names.
	 *
	 * All public post types with an administrative UI are supported through the
	 * same core-field workflow. This includes product when WooCommerce registers
	 * it, but does not add product-specific data handling.
	 *
	 * @return array<int, string>
	 */
	public function get_post_types() {
		$post_types = $this->get_available_post_types();
		$content    = $this->settings->get_section( 'content' );

		if ( 'selected' === $content['post_types_mode'] ) {
			$post_types = array_values( array_intersect( $post_types, $content['post_types'] ) );
		}

		// Media has its own switch rather than a row in the post type list, because
		// enabling it changes what a media item is rather than which screens gain a
		// language control.
		if ( ! empty( $content['media_support'] ) && ! in_array( 'attachment', $post_types, true ) ) {
			$post_types[] = 'attachment';
		}

		return $post_types;
	}

	/**
	 * Returns all post types eligible for configuration.
	 *
	 * Only post types registered with `public => true` and an administrative UI
	 * are offered, so the settings list holds exactly the content a site owner
	 * publishes. Attachments are excluded as well: they are governed by the media
	 * support switch instead, so a site cannot end up with two controls
	 * disagreeing about whether media is translatable.
	 *
	 * @return array<int, string>
	 */
	public function get_available_post_types() {
		$objects    = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		$post_types = array_keys( $objects );
		$post_types = array_values( array_diff( $post_types, array( 'attachment' ) ) );

		/**
		 * Filters post types supported by the core translation engine.
		 *
		 * Returned post types must expose an administrative UI and cannot be
		 * attachments. Public post types are already present; this is where a site
		 * adds one of the editorial types ShapeTranslate does not offer on its own,
		 * such as `wp_block` for synced patterns. Product uses only generic post
		 * fields in Free.
		 *
		 * @param array<int, string>            $post_types Supported post type names.
		 * @param array<string, \WP_Post_Type> $objects    Public post type objects.
		 */
		$filtered   = apply_filters( 'shapetranslate_supported_post_types', $post_types, $objects );
		$filtered   = is_array( $filtered ) ? $filtered : $post_types;
		$non_public = self::non_public_post_types();
		$supported  = array();

		foreach ( $filtered as $post_type ) {
			if ( ! is_scalar( $post_type ) ) {
				continue;
			}

			$post_type = sanitize_key( $post_type );
			$object    = get_post_type_object( $post_type );
			$internal  = in_array( $post_type, $non_public, true );

			if (
				'attachment' === $post_type
				|| ! $object
				|| ( ! $object->public && ! $internal )
				|| ( ! $object->show_ui && ! $internal )
			) {
				continue;
			}

			$supported[] = $post_type;
		}

		return array_values( array_unique( $supported ) );
	}

	/**
	 * Reports whether a post type is supported.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function supports( $post_type ) {
		return in_array( $post_type, $this->get_post_types(), true );
	}
}
