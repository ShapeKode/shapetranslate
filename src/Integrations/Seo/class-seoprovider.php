<?php
/**
 * Shared behavior for SEO plugin providers.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Seo;

use ShapeTranslate\Contracts\SeoProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the two things every provider does the same way.
 *
 * Detecting the plugin, which is a constant lookup in all of them, and naming
 * the primary-term keys, which all of them build from a prefix and a taxonomy.
 * A concrete provider is then only its lists.
 */
abstract class SeoProvider implements SeoProviderInterface {

	/**
	 * Returns the constants that indicate the plugin is loaded.
	 *
	 * @return array<int, string>
	 */
	abstract protected function get_constants();

	/**
	 * {@inheritdoc}
	 */
	public function is_active() {
		foreach ( $this->get_constants() as $constant ) {
			if ( is_string( $constant ) && '' !== $constant && defined( $constant ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_copied_meta_keys() {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_primary_term_meta_keys() {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_option_declarations() {
		return array();
	}

	/**
	 * Builds one primary-term key per taxonomy a post can be filed under.
	 *
	 * Only hierarchical taxonomies are offered a primary term: the setting
	 * exists to choose between several categories one post sits in, and a tag
	 * has nothing to choose between.
	 *
	 * @param string $prefix Meta key prefix the plugin uses.
	 * @return array<string, string> Meta key mapped to taxonomy name.
	 */
	protected function primary_term_keys( $prefix ) {
		$keys = array();

		foreach (
			get_taxonomies(
				array(
					'public'       => true,
					'hierarchical' => true,
				)
			) as $taxonomy
		) {
			$keys[ $prefix . $taxonomy ] = $taxonomy;
		}

		return $keys;
	}
}
