<?php
/**
 * Block theme template translation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

defined( 'ABSPATH' ) || exit;

/**
 * The language state of the templates that answer a request.
 *
 * Translating the header gets a site most of the way, and then it meets the page
 * that has to be laid out differently in another language: a right-to-left
 * single, an archive that drops a column, a 404 that says something else. That
 * is not a part; it is the template itself.
 *
 * Everything about holding the language is the same as for a part, so it lives
 * in BlockTemplates. What differs is how WordPress arrives at one of these, and
 * that is not this class's business either: a template is chosen by walking a
 * hierarchy rather than by a slug someone wrote, so the language is applied to
 * the hierarchy — see TemplateResolver.
 *
 * What is left here is the type itself, and the sentences that name it.
 */
final class Templates extends BlockTemplates {

	/**
	 * Post type holding customized and site-authored templates.
	 */
	const POST_TYPE = 'wp_template';

	/**
	 * {@inheritdoc}
	 */
	public function post_type() {
		return self::POST_TYPE;
	}

	/**
	 * {@inheritdoc}
	 */
	public function route_base() {
		return 'templates';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function messages() {
		return array(
			'not_found'        => __( 'That template is not part of the active theme.', 'shapetranslate' ),
			'is_translation'   => __( 'That template is already a translation.', 'shapetranslate' ),
			'exists'           => __( 'That template already has a version in this language.', 'shapetranslate' ),
			'slug_taken'       => __( 'A template with that name already exists.', 'shapetranslate' ),
			'unreadable'       => __( 'The source template could not be read.', 'shapetranslate' ),
			'not_translated'   => __( 'That template has no version in this language.', 'shapetranslate' ),
			'from_theme'       => __( 'That version comes from the theme and cannot be deleted here.', 'shapetranslate' ),
			'link_self'        => __( 'A template cannot be its own translation.', 'shapetranslate' ),
			'link_default'     => __( 'The default language is the template you are already editing.', 'shapetranslate' ),
			'link_translation' => __( 'That template is already a translation of something else.', 'shapetranslate' ),
			'is_fallback'      => __( 'This is the version every other language falls back to, so it cannot be moved to another language while its translations exist.', 'shapetranslate' ),
		);
	}
}
