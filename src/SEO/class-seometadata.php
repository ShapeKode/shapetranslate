<?php
/**
 * Multilingual SEO metadata service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\SEO;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Language\LanguageTag;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves document attributes, alternate URLs, and canonical URLs.
 */
final class SeoMetadata {

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Post translation service.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation service.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Language tag formatter.
	 *
	 * @var LanguageTag
	 */
	private $language_tag;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Alternate URL result cached for this request.
	 *
	 * @var array<string, string>|null
	 */
	private $alternate_urls;

	/**
	 * Public post URLs keyed by language ID for the current object context.
	 *
	 * @var array<string, string>|null
	 */
	private $public_post_urls;

	/**
	 * Public term URLs keyed by language ID for the current object context.
	 *
	 * @var array<string, string>|null
	 */
	private $public_term_urls;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 * @param PostTranslationManager $post_translations Post translation service.
	 * @param TermTranslationManager $term_translations Term translation service.
	 * @param LanguageTag            $language_tag      Language tag formatter.
	 * @param PluginSettings|null    $settings          Optional central settings service.
	 */
	public function __construct(
		LanguageManager $language_manager,
		LanguageUrlManager $url_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations,
		LanguageTag $language_tag,
		?PluginSettings $settings = null
	) {
		$this->language_manager  = $language_manager;
		$this->url_manager       = $url_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->language_tag      = $language_tag;
		$this->settings          = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * Replaces the document language and direction with request-language data.
	 *
	 * @param string $output  Existing language attributes.
	 * @param string $doctype Document type.
	 * @return string
	 */
	public function filter_language_attributes( $output, $doctype = 'html' ) {
		if ( ! $this->is_public_html_request() ) {
			return $output;
		}

		$language = $this->url_manager->get_current_language();
		$tag      = $this->language_tag->format( $language );

		if ( null === $language || '' === $tag ) {
			return $output;
		}

		$preserved  = preg_replace(
			'/\s(?:lang|xml:lang|dir)\s*=\s*(?:"[^"]*"|\'[^\']*\')/i',
			'',
			' ' . trim( (string) $output )
		);
		$attributes = array( 'lang="' . esc_attr( $tag ) . '"' );

		if ( 'xhtml' === strtolower( (string) $doctype ) ) {
			$attributes[] = 'xml:lang="' . esc_attr( $tag ) . '"';
		}

		$attributes[] = 'dir="' . LanguageTag::direction( $language ) . '"';
		$attributes[] = trim( (string) $preserved );
		$attributes   = implode( ' ', array_filter( $attributes ) );

		/**
		 * Filters final frontend document language attributes.
		 *
		 * @param string               $attributes Final attributes.
		 * @param array<string, mixed> $language   Current language record.
		 * @param string               $doctype    Document type.
		 */
		$filtered = apply_filters( 'shapetranslate_language_attributes', $attributes, $language, $doctype );

		return is_string( $filtered ) ? $filtered : $attributes;
	}

	/**
	 * Keeps the WordPress RTL body class aligned with the request language.
	 *
	 * @param array<int, string> $classes Existing body classes.
	 * @return array<int, string>
	 */
	public function filter_body_class( $classes ) {
		if ( ! is_array( $classes ) || ! $this->is_public_html_request() ) {
			return $classes;
		}

		$language = $this->url_manager->get_current_language();
		$classes  = array_values( array_diff( $classes, array( 'rtl' ) ) );

		if ( is_array( $language ) && ! empty( $language['is_rtl'] ) ) {
			$classes[] = 'rtl';
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Returns alternate URLs keyed by hreflang value.
	 *
	 * Singular content and term archives include only public translations.
	 * Shared archives include every enabled language because their routes exist
	 * independently of one translation group.
	 *
	 * @return array<string, string>
	 */
	public function get_alternate_urls() {
		if ( null !== $this->alternate_urls ) {
			return $this->alternate_urls;
		}

		$this->alternate_urls = array();

		if ( ! $this->settings->is_hreflang_enabled() || ! $this->is_indexable_request() ) {
			return $this->alternate_urls;
		}

		$languages = $this->language_manager->get_languages( true );

		/**
		 * Filters enabled languages considered for frontend alternate URLs.
		 *
		 * @param array<int, array<string, mixed>> $languages Enabled languages.
		 */
		$filtered_languages = apply_filters( 'shapetranslate_hreflang_languages', $languages );
		$languages          = is_array( $filtered_languages ) ? $filtered_languages : $languages;
		$alternates         = array();
		$seen_tags          = array();

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || empty( $language['id'] ) || empty( $language['enabled'] ) ) {
				continue;
			}

			$tag = $this->language_tag->format( $language );
			$url = $this->get_context_url( $language );

			if ( '' === $tag || '' === $url || isset( $seen_tags[ strtolower( $tag ) ] ) ) {
				continue;
			}

			if ( ! $this->is_absolute_web_url( $url ) ) {
				continue;
			}

			$seen_tags[ strtolower( $tag ) ] = true;
			$alternates[ $tag ]              = $url;
		}

		if ( count( $alternates ) < 2 ) {
			return $this->alternate_urls;
		}

		$default     = $this->url_manager->get_default_language();
		$default_tag = $this->language_tag->format( $default );
		$x_default   = isset( $alternates[ $default_tag ] ) ? $alternates[ $default_tag ] : '';

		/**
		 * Filters the optional x-default URL.
		 *
		 * Return an empty string to omit x-default for the current request.
		 *
		 * @param string                       $x_default Default-language alternate URL.
		 * @param array<string, string>        $alternates Language alternates without x-default.
		 * @param array<string, mixed>|null    $default    Default language record.
		 */
		$filtered_default = $this->settings->is_x_default_enabled()
			? apply_filters( 'shapetranslate_x_default_url', $x_default, $alternates, $default )
			: '';

		if ( is_string( $filtered_default ) && $this->is_absolute_web_url( $filtered_default ) ) {
			$alternates['x-default'] = $filtered_default;
		}

		/**
		 * Filters all alternate URLs before final validation and deduplication.
		 *
		 * @param array<string, string> $alternates Alternate URLs keyed by hreflang.
		 */
		$filtered_alternates  = apply_filters( 'shapetranslate_hreflang_urls', $alternates );
		$alternates           = is_array( $filtered_alternates ) ? $filtered_alternates : $alternates;
		$this->alternate_urls = $this->normalize_alternates( $alternates );

		return $this->alternate_urls;
	}

	/**
	 * Returns ShapeTranslate's canonical URL for the current indexable context.
	 *
	 * @return string
	 */
	public function get_canonical_url() {
		if ( ! $this->is_indexable_request() ) {
			return '';
		}

		$current   = $this->url_manager->get_current_language();
		$canonical = null === $current ? '' : $this->get_context_url( $current );

		/**
		 * Filters the ShapeTranslate canonical URL generated for core output.
		 *
		 * @param string                       $canonical Canonical URL.
		 * @param array<string, mixed>|null    $current   Current language record.
		 */
		$filtered = apply_filters( 'shapetranslate_canonical_url', $canonical, $current );

		return is_string( $filtered ) && $this->is_absolute_web_url( $filtered ) ? $filtered : '';
	}

	/**
	 * Makes a provider-generated canonical language-specific when it is local.
	 *
	 * Empty or external canonicals are preserved because a provider or developer
	 * may have intentionally suppressed or replaced the URL.
	 *
	 * @param mixed $canonical Existing canonical URL.
	 * @return mixed
	 */
	public function filter_provider_canonical( $canonical ) {
		if (
			! is_string( $canonical )
			|| '' === $canonical
			|| ! $this->is_indexable_request()
			|| ! $this->is_same_site_url( $canonical )
		) {
			return $canonical;
		}

		$current = $this->url_manager->get_current_language();

		if ( null === $current ) {
			return $canonical;
		}

		$prefixed = $this->url_manager->prefix_url( $canonical, $current );

		/**
		 * Filters a canonical after ShapeTranslate applies the current prefix.
		 *
		 * This runs for WordPress core, Yoast SEO, and Rank Math canonicals.
		 *
		 * @param string               $prefixed  Language-specific canonical URL.
		 * @param string               $canonical Original provider URL.
		 * @param array<string, mixed> $current   Current language record.
		 */
		$filtered = apply_filters( 'shapetranslate_provider_canonical_url', $prefixed, $canonical, $current );

		return is_string( $filtered ) ? $filtered : $canonical;
	}

	/**
	 * Adds noindex protection to non-public multilingual request variants.
	 *
	 * @param array<string, mixed> $robots Existing WordPress robots directives.
	 * @return array<string, mixed>
	 */
	public function filter_wordpress_robots( $robots ) {
		if ( ! is_array( $robots ) || ! $this->should_force_noindex() ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );

		return $robots;
	}

	/**
	 * Reports whether ShapeTranslate SEO metadata applies to this request.
	 *
	 * @return bool
	 */
	public function is_indexable_request() {
		$indexable = $this->is_public_html_request()
			&& ! is_search()
			&& ! is_404()
			&& ! is_preview()
			&& ! is_trackback();

		/**
		 * Filters whether ShapeTranslate should emit indexable metadata for a request.
		 *
		 * This controls ShapeTranslate canonical and hreflang output only. It does not
		 * override an SEO plugin's existing noindex decision.
		 *
		 * @param bool $indexable Whether metadata may be emitted.
		 */
		return (bool) apply_filters( 'shapetranslate_seo_is_indexable_request', $indexable );
	}

	/**
	 * Returns a language-specific URL for the current query context.
	 *
	 * @param array<string, mixed> $language Target language record.
	 * @return string
	 */
	private function get_context_url( $language ) {
		if ( is_front_page() ) {
			$front_page_id = $this->url_manager->get_front_page_id();

			return 0 < $front_page_id
				? $this->get_public_post_url( $front_page_id, $language )
				: $this->url_manager->get_language_home_url( $language );
		}

		if ( is_home() ) {
			$posts_page_id = $this->url_manager->get_posts_page_id();
			$url           = 0 < $posts_page_id
				? $this->url_manager->get_post_url( $posts_page_id, $language )
				: $this->url_manager->get_language_home_url( $language );

			return $this->add_archive_pagination( $url );
		}

		if ( is_singular() ) {
			$url = $this->get_public_post_url( get_queried_object_id(), $language );

			return $this->add_singular_pagination( $url );
		}

		$queried_object = get_queried_object();

		if ( $queried_object instanceof WP_Term ) {
			$url = $this->get_public_term_url( $queried_object, $language );

			return $this->add_archive_pagination( $url );
		}

		$base_url = $this->get_archive_base_url();

		return '' === $base_url
			? ''
			: $this->add_archive_pagination( $this->url_manager->prefix_url( $base_url, $language ) );
	}

	/**
	 * Returns a public translated post URL for one language.
	 *
	 * @param int                  $post_id  Current or source post identifier.
	 * @param array<string, mixed> $language Target language record.
	 * @return string
	 */
	private function get_public_post_url( $post_id, $language ) {
		if ( null === $this->public_post_urls ) {
			$this->public_post_urls = array();
			$source                 = get_post( $post_id );

			if ( ! $source instanceof WP_Post ) {
				return '';
			}

			$translations = $this->post_translations->get_translations( $post_id );
			$post_ids     = array_values( array_unique( array_map( 'absint', $translations ) ) );

			if ( empty( $post_ids ) ) {
				return '';
			}

			$posts = get_posts(
				array(
					'post__in'                         => $post_ids,
					'post_type'                        => $source->post_type,
					'post_status'                      => array_values( get_post_stati( array( 'public' => true ) ) ),
					'posts_per_page'                   => count( $post_ids ),
					'orderby'                          => 'post__in',
					'ignore_sticky_posts'              => true,
					'no_found_rows'                    => true,
					'suppress_filters'                 => false,
					'update_post_meta_cache'           => false,
					'update_post_term_cache'           => false,

					/*
					 * This is the one query on a rendered page that is meant to
					 * cross languages: it asks what this document is called in
					 * each of them. Held to the language being read, the way
					 * every other listing on the page is, it can only ever find
					 * the document already on screen — and a set of alternates
					 * holding one entry is no alternates at all, so the page
					 * ends up telling search engines nothing about its
					 * translations. The identifiers must survive too: they name
					 * the other languages on purpose.
					 */
					'shapetranslate_skip_language_filter' => true,
				)
			);
			$posts_by_id = array();

			foreach ( $posts as $post ) {
				if ( $post instanceof WP_Post && is_post_publicly_viewable( $post ) ) {
					$posts_by_id[ $post->ID ] = $post;
				}
			}

			foreach ( $translations as $language_id => $target_id ) {
				$target_id = absint( $target_id );

				if ( ! isset( $posts_by_id[ $target_id ] ) ) {
					continue;
				}

				$this->public_post_urls[ $language_id ] = $this->url_manager->get_post_url(
					$posts_by_id[ $target_id ],
					$language_id
				);
			}
		}

		$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

		return isset( $this->public_post_urls[ $language_id ] )
			? $this->public_post_urls[ $language_id ]
			: '';
	}

	/**
	 * Returns a translated public term URL for one language.
	 *
	 * @param WP_Term              $term     Current term.
	 * @param array<string, mixed> $language Target language record.
	 * @return string
	 */
	private function get_public_term_url( WP_Term $term, $language ) {
		if ( null === $this->public_term_urls ) {
			$this->public_term_urls = array();
			$taxonomy               = get_taxonomy( $term->taxonomy );

			if ( ! $taxonomy || ! $taxonomy->public ) {
				return '';
			}

			$translations = $this->term_translations->get_translations( $term->term_id, $term->taxonomy );
			$term_ids     = array_values( array_unique( array_map( 'absint', $translations ) ) );

			if ( empty( $term_ids ) ) {
				return '';
			}

			$terms       = get_terms(
				array(
					'taxonomy'                         => $term->taxonomy,
					'include'                          => $term_ids,
					'hide_empty'                       => false,
					'shapetranslate_skip_language_filter' => true,
				)
			);
			$terms_by_id = array();

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $translated_term ) {
					if ( $translated_term instanceof WP_Term ) {
						$terms_by_id[ $translated_term->term_id ] = $translated_term;
					}
				}
			}

			foreach ( $translations as $language_id => $target_id ) {
				$target_id = absint( $target_id );

				if ( ! isset( $terms_by_id[ $target_id ] ) ) {
					continue;
				}

				$this->public_term_urls[ $language_id ] = $this->url_manager->get_term_url(
					$terms_by_id[ $target_id ],
					$term->taxonomy,
					$language_id
				);
			}
		}

		$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

		return isset( $this->public_term_urls[ $language_id ] )
			? $this->public_term_urls[ $language_id ]
			: '';
	}

	/**
	 * Returns a core URL for the current shared archive context.
	 *
	 * @return string
	 */
	private function get_archive_base_url() {
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
			$url       = is_scalar( $post_type ) ? get_post_type_archive_link( sanitize_key( (string) $post_type ) ) : '';

			return is_string( $url ) ? $url : '';
		}

		if ( is_author() ) {
			$author_id = absint( get_query_var( 'author' ) );

			if ( 0 === $author_id ) {
				$author    = get_queried_object();
				$author_id = isset( $author->ID ) ? absint( $author->ID ) : 0;
			}

			return 0 < $author_id ? get_author_posts_url( $author_id ) : '';
		}

		$year = absint( get_query_var( 'year' ) );

		if ( is_day() ) {
			return get_day_link( $year, absint( get_query_var( 'monthnum' ) ), absint( get_query_var( 'day' ) ) );
		}

		if ( is_month() ) {
			return get_month_link( $year, absint( get_query_var( 'monthnum' ) ) );
		}

		if ( is_year() ) {
			return get_year_link( $year );
		}

		return is_archive() ? $this->get_archive_request_base_url() : '';
	}

	/**
	 * Removes pagination and query data from an otherwise shared archive route.
	 *
	 * @return string
	 */
	private function get_archive_request_base_url() {
		$url   = strtok( $this->url_manager->get_current_request_url(), '?' );
		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['path'] ) ) {
			return '';
		}

		global $wp_rewrite;
		$paged = max( 1, absint( get_query_var( 'paged' ) ) );

		if ( 1 < $paged && $wp_rewrite instanceof \WP_Rewrite ) {
			$pagination = '#/' . preg_quote( $wp_rewrite->pagination_base, '#' ) . '/' . $paged . '/?$#';
			$url        = preg_replace( $pagination, '/', $url );
		}

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Adds current archive pagination to a base URL.
	 *
	 * @param string $url Archive base URL.
	 * @return string
	 */
	private function add_archive_pagination( $url ) {
		$paged = max( 1, absint( get_query_var( 'paged' ) ) );

		if ( '' === $url || $paged < 2 ) {
			return $url;
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'paged', $paged, $url );
		}

		global $wp_rewrite;
		$pagination_base = $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite->pagination_base : 'page';

		return trailingslashit( $url ) . user_trailingslashit( $pagination_base . '/' . $paged, 'paged' );
	}

	/**
	 * Adds current multipage state to a singular URL.
	 *
	 * @param string $url Singular URL.
	 * @return string
	 */
	private function add_singular_pagination( $url ) {
		$page = absint( get_query_var( 'page' ) );

		if ( '' === $url || $page < 2 ) {
			return $url;
		}

		return '' === (string) get_option( 'permalink_structure' )
			? add_query_arg( 'page', $page, $url )
			: trailingslashit( $url ) . user_trailingslashit( (string) $page, 'single_paged' );
	}

	/**
	 * Validates and deduplicates filtered alternate entries.
	 *
	 * @param array<mixed, mixed> $alternates Filtered alternate values.
	 * @return array<string, string>
	 */
	private function normalize_alternates( $alternates ) {
		$normalized = array();
		$seen       = array();

		foreach ( $alternates as $tag => $url ) {
			if ( ! is_string( $tag ) || ! is_string( $url ) || ! $this->is_absolute_web_url( $url ) ) {
				continue;
			}

			$key = strtolower( $tag );

			if ( isset( $seen[ $key ] ) || ( 'x-default' !== $key && ! $this->language_tag->is_valid( $tag ) ) ) {
				continue;
			}

			$seen[ $key ]       = true;
			$normalized[ $tag ] = esc_url_raw( $url );
		}

		return $normalized;
	}

	/**
	 * Reports whether this is a frontend HTML request ShapeTranslate owns.
	 *
	 * @return bool
	 */
	private function is_public_html_request() {
		return $this->url_manager->supports_language_prefixes()
			&& ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! is_feed()
			&& ! is_embed()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Reports whether ShapeTranslate should add noindex to this request.
	 *
	 * @return bool
	 */
	private function should_force_noindex() {
		return $this->is_public_html_request() && ( is_404() || is_preview() );
	}

	/**
	 * Reports whether a URL is an absolute HTTP or HTTPS URL.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	private function is_absolute_web_url( $url ) {
		$parts = wp_parse_url( $url );

		return false !== $parts
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& '' !== $parts['host']
			&& ! isset( $parts['user'], $parts['pass'] );
	}

	/**
	 * Reports whether an absolute URL uses the site's public origin.
	 *
	 * @param string $url URL to inspect.
	 * @return bool
	 */
	private function is_same_site_url( $url ) {
		$url_parts  = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );

		if ( false === $url_parts || false === $home_parts || ! isset( $url_parts['host'], $home_parts['host'] ) ) {
			return false;
		}

		$url_port  = isset( $url_parts['port'] ) ? absint( $url_parts['port'] ) : 0;
		$home_port = isset( $home_parts['port'] ) ? absint( $home_parts['port'] ) : 0;

		return strtolower( $url_parts['host'] ) === strtolower( $home_parts['host'] )
			&& $url_port === $home_port;
	}
}
