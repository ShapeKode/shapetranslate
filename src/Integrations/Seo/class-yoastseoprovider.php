<?php
/**
 * Yoast SEO field locations.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Names the Yoast SEO post meta and options that belong to one language.
 */
final class YoastSeoProvider extends SeoProvider {

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'yoast';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_constants() {
		return array( 'WPSEO_VERSION', 'WPSEO_PREMIUM_VERSION', 'WPSEO_FILE' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_translatable_meta_keys() {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_bctitle',
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * `_yoast_wpseo_canonical` is deliberately absent. A canonical names one
	 * address, and a translation has an address of its own; copying it would
	 * point every language at the source and remove them all from the index.
	 * The analysis scores are absent for the same kind of reason: they describe
	 * text that is about to be rewritten.
	 */
	public function get_copied_meta_keys() {
		return array(
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-image',
			'_yoast_wpseo_twitter-image-id',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_meta-robots-adv',
			'_yoast_wpseo_is_cornerstone',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_primary_term_meta_keys() {
		return $this->primary_term_keys( '_yoast_wpseo_primary_' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_option_declarations() {
		return array(
			'Yoast SEO' => array(
				'wpseo_titles' => array(
					'title-*'                   => true,
					'metadesc-*'                => true,
					'bctitle-*'                 => true,
					'social-title-*'            => true,
					'social-description-*'      => true,
					'breadcrumbs-sep'           => true,
					'breadcrumbs-home'          => true,
					'breadcrumbs-prefix'        => true,
					'breadcrumbs-archiveprefix' => true,
					'breadcrumbs-searchprefix'  => true,
					'breadcrumbs-404crumb'      => true,
					'company_name'              => true,
					'rssbefore'                 => true,
					'rssafter'                  => true,
				),
				'wpseo_social' => array(
					'og_frontpage_title' => true,
					'og_frontpage_desc'  => true,
				),
			),
		);
	}
}
