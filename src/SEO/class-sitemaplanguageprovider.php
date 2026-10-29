<?php
/**
 * Language-aware wrapper around a core sitemap provider.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\SEO;

use WP_Sitemaps_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Splits one core sitemap into a file per language.
 *
 * WordPress asks a provider two questions to build the index: which sitemaps
 * exist, and what address each one has. Everything else — how many pages a
 * sitemap needs, which URLs it holds — the provider answers from a query. So a
 * provider that reports one sitemap per language, and scopes each count to that
 * language, produces a per-language index without any of the listing code
 * knowing that languages exist.
 *
 * Only the post and taxonomy providers are wrapped. They are the two whose
 * queries ShapeTranslate can scope; wrapping one it cannot would advertise several
 * sitemaps that all answer with the same URLs.
 */
final class SitemapLanguageProvider extends WP_Sitemaps_Provider {

	/**
	 * Separator carrying a language inside a sitemap name.
	 *
	 * The name travels from get_sitemap_type_data() to get_sitemap_url() and
	 * nowhere else, so this never reaches a URL. It is spelled to be
	 * unmistakable rather than short: a post type may contain a dash and so may
	 * a language slug, and splitting on either would take the wrong half.
	 */
	const SEPARATOR = '--shapetranslate-language--';

	/**
	 * Wrapped core provider.
	 *
	 * @var WP_Sitemaps_Provider
	 */
	private $provider;

	/**
	 * Sitemap module owning language scope and query constraints.
	 *
	 * @var SitemapModule
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param WP_Sitemaps_Provider $provider Core provider being wrapped.
	 * @param SitemapModule        $module   Sitemap language policy.
	 */
	public function __construct( WP_Sitemaps_Provider $provider, SitemapModule $module ) {
		$this->provider    = $provider;
		$this->module      = $module;
		$this->name        = $provider->name;
		$this->object_type = $provider->object_type;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int    $page_num       Page of results.
	 * @param string $object_subtype Object subtype name.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		return $this->provider->get_url_list( $page_num, $object_subtype );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $object_subtype Object subtype name.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return $this->provider->get_max_num_pages( $object_subtype );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, object>
	 */
	public function get_object_subtypes() {
		return $this->provider->get_object_subtypes();
	}

	/**
	 * Reports one sitemap per language for every translatable subtype.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_sitemap_type_data() {

		$subtypes = $this->get_object_subtypes();

		if ( empty( $subtypes ) ) {
			return $this->provider->get_sitemap_type_data();
		}

		$languages = $this->module->get_sitemap_language_ids();

		if ( empty( $languages ) ) {
			return $this->provider->get_sitemap_type_data();
		}

		$data = array();

		foreach ( array_keys( $subtypes ) as $subtype ) {
			$subtype = (string) $subtype;

			if ( ! $this->module->is_translatable_subtype( $this->name, $subtype ) ) {

				$data[] = array(
					'name'  => $subtype,
					'pages' => $this->module->with_language(
						'',
						function () use ( $subtype ) {
							return $this->get_max_num_pages( $subtype );
						}
					),
				);

				continue;
			}

			foreach ( $languages as $language_id ) {
				$pages = $this->module->with_language(
					$language_id,
					function () use ( $subtype ) {
						return $this->get_max_num_pages( $subtype );
					}
				);

				if ( 1 > (int) $pages ) {
					continue;
				}

				$data[] = array(
					'name'  => $subtype . self::SEPARATOR . $language_id,
					'pages' => (int) $pages,
				);
			}
		}

		return $data;
	}

	/**
	 * Returns the address of one sitemap, in the language it was built for.
	 *
	 * @param string $name Sitemap name, possibly carrying a language.
	 * @param int    $page Page number.
	 * @return string
	 */
	public function get_sitemap_url( $name, $page ) {
		$name        = (string) $name;
		$language_id = '';
		$position    = strrpos( $name, self::SEPARATOR );

		if ( false !== $position ) {
			$language_id = substr( $name, $position + strlen( self::SEPARATOR ) );
			$name        = substr( $name, 0, $position );
		}

		$url = $this->provider->get_sitemap_url( $name, $page );

		return '' === $language_id ? $url : $this->module->localize_sitemap_url( $url, $language_id );
	}
}
