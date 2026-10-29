<?php
/**
 * Rank Math field locations.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Names the Rank Math post meta and options that belong to one language.
 *
 * Rank Math stores its fields under unprefixed keys, so they are public custom
 * fields as far as WordPress is concerned. That matters twice over: the generic
 * custom-field copy already carries them, and the generic synchronization would
 * go on overwriting them afterwards. Declaring them here is what stops the
 * second from happening to a title somebody has translated.
 */
final class RankMathProvider extends SeoProvider {

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'rank-math';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_constants() {
		return array( 'RANK_MATH_VERSION', 'RANK_MATH_FILE' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_translatable_meta_keys() {
		return array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
			'rank_math_breadcrumb_title',
			'rank_math_facebook_title',
			'rank_math_facebook_description',
			'rank_math_twitter_title',
			'rank_math_twitter_description',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * `rank_math_canonical_url` is deliberately absent, for the reason every
	 * canonical is: it names one address, and a translation has its own.
	 */
	public function get_copied_meta_keys() {
		return array(
			'rank_math_facebook_image',
			'rank_math_facebook_image_id',
			'rank_math_twitter_image',
			'rank_math_twitter_image_id',
			'rank_math_twitter_use_facebook',
			'rank_math_twitter_card_type',
			'rank_math_robots',
			'rank_math_advanced_robots',
			'rank_math_pillar_content',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_primary_term_meta_keys() {
		return $this->primary_term_keys( 'rank_math_primary_' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_option_declarations() {
		return array(
			'Rank Math' => array(
				'rank-math-options-titles'  => array(
					'pt_*_title'                 => true,
					'pt_*_description'           => true,
					'tax_*_title'                => true,
					'tax_*_description'          => true,
					'author_archive_title'       => true,
					'author_archive_description' => true,
					'date_archive_title'         => true,
					'date_archive_description'   => true,
					'search_title'               => true,
					'404_title'                  => true,
				),
				'rank-math-options-general' => array(
					'breadcrumbs_separator'      => true,
					'breadcrumbs_home_label'     => true,
					'breadcrumbs_prefix'         => true,
					'breadcrumbs_archive_format' => true,
					'breadcrumbs_search_format'  => true,
					'breadcrumbs_404_label'      => true,
				),
			),
		);
	}
}
