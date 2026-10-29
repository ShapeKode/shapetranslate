<?php
/**
 * SEOPress field locations.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Names the SEOPress post meta and options that belong to one language.
 */
final class SeoPressProvider extends SeoProvider {

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'seopress';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_constants() {
		return array( 'SEOPRESS_VERSION', 'SEOPRESS_PRO_VERSION' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_translatable_meta_keys() {
		return array(
			'_seopress_titles_title',
			'_seopress_titles_desc',
			'_seopress_social_fb_title',
			'_seopress_social_fb_desc',
			'_seopress_social_twitter_title',
			'_seopress_social_twitter_desc',
			'_seopress_analysis_target_kw',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * `_seopress_robots_canonical` is deliberately absent, for the reason every
	 * canonical is: it names one address, and a translation has its own.
	 */
	public function get_copied_meta_keys() {
		return array(
			'_seopress_social_fb_img',
			'_seopress_social_fb_img_attachment_id',
			'_seopress_social_twitter_img',
			'_seopress_social_twitter_img_attachment_id',
			'_seopress_robots_index',
			'_seopress_robots_follow',
			'_seopress_robots_imageindex',
			'_seopress_robots_archive',
			'_seopress_robots_snippet',
			'_seopress_robots_odp',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * SEOPress offers one primary term, for the built-in category taxonomy, so
	 * the key is named outright rather than built per taxonomy.
	 */
	public function get_primary_term_meta_keys() {
		return taxonomy_exists( 'category' )
			? array( '_seopress_robots_primary_cat' => 'category' )
			: array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_option_declarations() {
		return array(
			'SEOPress' => array(
				'seopress_titles_option_name' => array(
					'seopress_titles_home_site_title' => true,
					'seopress_titles_home_site_desc'  => true,
					'seopress_titles_archives_*'      => true,
					'seopress_titles_single_titles'   => array(
						'*' => array(
							'title'       => true,
							'description' => true,
						),
					),
					'seopress_titles_tax_titles'      => array(
						'*' => array(
							'title'       => true,
							'description' => true,
						),
					),
				),
				'seopress_social_option_name' => array(
					'seopress_social_knowledge_name' => true,
				),
			),
		);
	}
}
