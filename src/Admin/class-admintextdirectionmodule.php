<?php
/**
 * Admin text direction module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Language\LanguageTag;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Types a translation in the direction the translation is written in.
 *
 * An administration screen runs in the site's own locale, which is correct:
 * the menus, the toolbar, and the help text belong to the person editing, not
 * to the post. The body of the post does not. Writing Arabic into a field that
 * lays out left to right puts the caret, the punctuation, and any Latin word
 * or number in the wrong place, and the writer has to fight the field for
 * every line.
 *
 * So the screen keeps its own direction and the edited content gets the one
 * its language was configured with.
 */
final class AdminTextDirectionModule implements ModuleInterface {

	/**
	 * Post translation API.
	 *
	 * @var PostTranslationManager
	 */
	private $posts;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Language resolved for this screen, or false before the first lookup.
	 *
	 * @var array<string, mixed>|null|false
	 */
	private $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $posts     Post translation API.
	 * @param LanguageManager        $languages Language manager.
	 */
	public function __construct( PostTranslationManager $posts, LanguageManager $languages ) {
		$this->posts     = $posts;
		$this->languages = $languages;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'admin_body_class', array( $this, 'filter_admin_body_class' ) );
		add_filter( 'tiny_mce_before_init', array( $this, 'filter_editor_direction' ) );
	}

	/**
	 * Names the edited content's direction on the admin body element.
	 *
	 * Printed on every admin screen that edits a translatable post so a theme,
	 * a page builder, or a stylesheet of the site's own can lay a field out to
	 * match. The class names the content's direction, never the screen's.
	 *
	 * @param string|mixed $classes Existing body classes.
	 * @return string|mixed
	 */
	public function filter_admin_body_class( $classes ) {
		$language = $this->get_screen_language();

		if ( null === $language || ! is_string( $classes ) ) {
			return $classes;
		}

		$direction = LanguageTag::direction( $language );

		return trim( $classes . ' shapetranslate-dir-' . $direction );
	}

	/**
	 * Lays the classic editor out in the direction of the post's language.
	 *
	 * @param array<string, mixed>|mixed $settings TinyMCE settings.
	 * @return array<string, mixed>|mixed
	 */
	public function filter_editor_direction( $settings ) {
		$language = $this->get_screen_language();

		if ( null === $language || ! is_array( $settings ) ) {
			return $settings;
		}

		$settings['directionality'] = LanguageTag::direction( $language );

		return $settings;
	}

	/**
	 * Returns the language of the post this screen is editing.
	 *
	 * Resolved once: both filters run on the same screen, and
	 * `tiny_mce_before_init` fires once per editor instance on a page that may
	 * hold several.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_screen_language() {
		if ( false !== $this->resolved ) {
			return $this->resolved;
		}

		$this->resolved = null;

		$post = $this->get_edited_post();

		if ( ! $post instanceof WP_Post || ! $this->posts->supports_post_type( $post->post_type ) ) {
			return null;
		}

		$language = $this->posts->get_post_language( $post->ID );

		/*
		 * A post with no language yet is one being created, and the metabox
		 * starts it in the default language. Reading the same default here is
		 * what keeps the field and the selector beside it agreeing before
		 * anything has been saved.
		 */
		if ( ! is_array( $language ) ) {
			/** This filter is documented in src/Admin/class-translationmetabox.php */
			$new_language_id = apply_filters(
				'shapetranslate_new_post_language_id',
				$this->languages->get_default_id(),
				$post
			);

			$language = $this->languages->find( (string) $new_language_id );
		}

		$this->resolved = is_array( $language ) ? $language : null;

		return $this->resolved;
	}

	/**
	 * Returns the post the current screen is editing, if it is editing one.
	 *
	 * @return WP_Post|null
	 */
	private function get_edited_post() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}

		$screen = get_current_screen();

		// Only the single post editor. A list table edits nothing, and its rows
		// are not all in one language.
		if ( null === $screen || 'post' !== $screen->base ) {
			return null;
		}

		$post = get_post();

		return $post instanceof WP_Post ? $post : null;
	}
}
