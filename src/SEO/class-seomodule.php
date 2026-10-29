<?php
/**
 * Multilingual SEO integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\SEO;

use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress core and optional SEO-provider hooks.
 */
final class SeoModule implements ModuleInterface {

	/**
	 * SEO metadata service.
	 *
	 * @var SeoMetadata
	 */
	private $metadata;

	/**
	 * Constructor.
	 *
	 * @param SeoMetadata $metadata SEO metadata service.
	 */
	public function __construct( SeoMetadata $metadata ) {
		$this->metadata = $metadata;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'language_attributes', array( $this->metadata, 'filter_language_attributes' ), 20, 2 );
		add_filter( 'body_class', array( $this->metadata, 'filter_body_class' ), 20 );
		add_filter( 'get_canonical_url', array( $this->metadata, 'filter_provider_canonical' ), 20 );

		foreach ( $this->canonical_filters() as $hook ) {
			add_filter( $hook, array( $this->metadata, 'filter_provider_canonical' ), 20 );
		}

		add_filter( 'wp_robots', array( $this->metadata, 'filter_wordpress_robots' ), 20 );
		add_action( 'wp_head', array( $this, 'render_head_links' ), 2 );
	}

	/**
	 * Outputs alternate links and a core archive canonical where needed.
	 *
	 * @return void
	 */
	public function render_head_links() {
		foreach ( $this->metadata->get_alternate_urls() as $tag => $url ) {
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $tag ),
				esc_url( $url )
			);
		}

		if ( is_singular() || $this->has_canonical_provider() ) {
			return;
		}

		$canonical = $this->metadata->get_canonical_url();

		/**
		 * Filters whether ShapeTranslate should output its archive canonical link.
		 *
		 * WordPress core handles singular canonicals. Yoast SEO and Rank Math own
		 * all canonical output while active, so ShapeTranslate does not duplicate it.
		 *
		 * @param bool   $output    Whether to output the canonical.
		 * @param string $canonical Canonical URL.
		 */
		$output = apply_filters( 'shapetranslate_output_canonical', '' !== $canonical, $canonical );

		if ( $output && '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}

	/**
	 * Returns the canonical filters of every supported SEO plugin.
	 *
	 * Registering a filter a plugin never fires costs nothing, so the list is
	 * applied unconditionally rather than behind per-plugin detection. Each hook
	 * receives the provider's own canonical and returns it carrying the current
	 * language prefix.
	 *
	 * @return array<int, string>
	 */
	private function canonical_filters() {
		$filters = array(
			'wpseo_canonical',
			'rank_math/frontend/canonical',
			'seopress_titles_canonical',
			'aioseo_canonical_url',
			'slim_seo_canonical_url',
			'the_seo_framework_rel_canonical_output',
		);

		/**
		 * Filters the SEO provider canonical hooks ShapeTranslate makes language-aware.
		 *
		 * @param array<int, string> $filters Canonical filter hook names.
		 */
		$filtered = apply_filters( 'shapetranslate_provider_canonical_filters', $filters );

		return is_array( $filtered ) ? array_values( array_unique( array_filter( $filtered, 'is_string' ) ) ) : $filters;
	}

	/**
	 * Reports whether a supported SEO plugin owns canonical output.
	 *
	 * Detection is by loaded constant only. No third-party class is loaded or
	 * called, so an inactive plugin's files are never touched.
	 *
	 * @return bool
	 */
	private function has_canonical_provider() {
		$constants = array(
			'WPSEO_VERSION',
			'WPSEO_PREMIUM_VERSION',
			'RANK_MATH_VERSION',
			'RANK_MATH_FILE',
			'SEOPRESS_VERSION',
			'AIOSEO_VERSION',
			'AIOSEOP_VERSION',
			'SLIM_SEO_VER',
			'THE_SEO_FRAMEWORK_VERSION',
			'SMARTCRAWL_VERSION',
			'SQ_VERSION',
		);

		/**
		 * Filters the constants that indicate an SEO plugin owns canonical output.
		 *
		 * @param array<int, string> $constants Provider constant names.
		 */
		$filtered  = apply_filters( 'shapetranslate_canonical_provider_constants', $constants );
		$constants = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : $constants;

		$has_provider = false;

		foreach ( $constants as $constant ) {
			if ( '' !== $constant && defined( $constant ) ) {
				$has_provider = true;
				break;
			}
		}

		/**
		 * Filters whether another integration owns canonical link output.
		 *
		 * @param bool $has_provider Whether an SEO provider was detected.
		 */
		return (bool) apply_filters( 'shapetranslate_has_canonical_provider', $has_provider );
	}
}
