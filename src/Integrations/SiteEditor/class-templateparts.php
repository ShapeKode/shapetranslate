<?php
/**
 * Block theme template part translation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use WP_Block_Template;

defined( 'ABSPATH' ) || exit;

/**
 * The language state of the parts a template names inside itself.
 *
 * A block theme builds every page from a template, and a template names its
 * header and footer through `core/template-part` blocks that carry a slug. The
 * slug is the only thing that decides which part renders, and nothing about it
 * belongs to a language, so a site with an English and a Bengali header has no
 * way to say which of them a Bengali page should show.
 *
 * BlockTemplates holds the answer, because it is the same answer full templates
 * need. What is left here is what only a part has: an area — header, footer,
 * general — which is how the Site Editor groups them and how the theme decides
 * where one may be inserted. A translation that lost its area would appear in
 * the wrong list and be offered in the wrong place, so it is carried across.
 */
final class TemplateParts extends BlockTemplates {

	/**
	 * Post type holding customized and site-authored template parts.
	 */
	const POST_TYPE = 'wp_template_part';

	/**
	 * Taxonomy recording which area a part fills.
	 */
	const AREA_TAXONOMY = 'wp_template_part_area';

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
		return 'template-parts';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function messages() {
		return array(
			'not_found'        => __( 'That template part is not part of the active theme.', 'shapetranslate' ),
			'is_translation'   => __( 'That template part is already a translation.', 'shapetranslate' ),
			'exists'           => __( 'That template part already has a version in this language.', 'shapetranslate' ),
			'slug_taken'       => __( 'A template part with that name already exists.', 'shapetranslate' ),
			'unreadable'       => __( 'The source template part could not be read.', 'shapetranslate' ),
			'not_translated'   => __( 'That template part has no version in this language.', 'shapetranslate' ),
			'from_theme'       => __( 'That version comes from the theme and cannot be deleted here.', 'shapetranslate' ),
			'link_self'        => __( 'A template part cannot be its own translation.', 'shapetranslate' ),
			'link_default'     => __( 'The default language is the template part you are already editing.', 'shapetranslate' ),
			'link_translation' => __( 'That template part is already a translation of something else.', 'shapetranslate' ),
			'is_fallback'      => __( 'This is the version every other language falls back to, so it cannot be moved to another language while its translations exist.', 'shapetranslate' ),
		);
	}

	/**
	 * Returns every template part the active theme can render, keyed by slug.
	 *
	 * @return array<string, WP_Block_Template>
	 */
	public function get_parts() {
		return $this->get_all();
	}

	/**
	 * Returns the parts a template names, without the translations.
	 *
	 * @return array<string, WP_Block_Template>
	 */
	public function get_source_parts() {
		return $this->get_sources();
	}

	/**
	 * Returns the area a part fills.
	 *
	 * @param string $slug Template part slug.
	 * @return string Empty when the part is unknown.
	 */
	public function get_area( $slug ) {
		$part = $this->find( $slug );

		if ( null === $part ) {
			return '';
		}

		return isset( $part->area ) && is_string( $part->area ) ? (string) $part->area : '';
	}

	/**
	 * Reads the area the part being copied fills.
	 *
	 * @param string $source_slug Slug the part is being made from.
	 * @return array<string, mixed>
	 */
	protected function carry_from( $source_slug ) {
		return array( 'area' => $this->get_area( $source_slug ) );
	}

	/**
	 * Gives a written part the area the part it came from fills.
	 *
	 * @param int                  $post_id Template part identifier.
	 * @param array<string, mixed> $carried What carry_from() read.
	 * @return void
	 */
	protected function apply_carried( $post_id, array $carried ) {
		$area = isset( $carried['area'] ) ? (string) $carried['area'] : '';

		if ( '' !== $area ) {
			wp_set_post_terms( $post_id, array( $area ), self::AREA_TAXONOMY );
		}
	}
}
