<?php
/**
 * WordPress core sitemap compatibility module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\SEO;

use ShapeTranslate\Content\LanguageQueryConstraint;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Query;
use WP_Sitemaps_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the core sitemap listing exactly the URLs a visitor can reach.
 *
 * Permalink filters already give every entry the prefix of the language its own
 * post or term is assigned to, so translations are listed under their own
 * routes without any work here. What the sitemap cannot resolve on its own is
 * content assigned to a language that is no longer enabled: such an item has no
 * route of its own, so its permalink falls back to the unprefixed URL, which
 * belongs to the default language. Listing it would submit the same address
 * twice under different content.
 *
 * Both providers are therefore constrained at query level, which is the only
 * place core allows an entry to be dropped.
 */
final class SitemapModule implements ModuleInterface {

	/**
	 * Query argument marking a query built by a sitemap provider.
	 */
	const QUERY_MARKER = 'shapetranslate_sitemap_query';

	/**
	 * Language URL API.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Shared language SQL constraint.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Enabled language identifiers, resolved once per request.
	 *
	 * @var array<int, string>|null
	 */
	private $enabled_ids;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation relationships.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Language every sitemap query is currently held to.
	 *
	 * Null while the provider has named none. An empty string is a language of
	 * its own: the provider asking for a count across every listed language,
	 * which is not the same as not having been asked.
	 *
	 * @var string|null
	 */
	private $scoped_language_id = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager     $url_manager       Language URL API.
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 * @param PluginSettings|null    $settings          Optional central settings service.
	 */
	public function __construct(
		LanguageUrlManager $url_manager,
		LanguageManager $language_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations,
		?PluginSettings $settings = null
	) {
		$this->url_manager       = $url_manager;
		$this->language_manager  = $language_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->settings          = null === $settings ? new PluginSettings() : $settings;
		$this->constraint        = new LanguageQueryConstraint();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->url_manager->supports_language_prefixes() ) {
			return;
		}

		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'mark_provider_query' ), 20 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'mark_provider_query' ), 20 );
		add_filter( 'posts_clauses', array( $this, 'filter_sitemap_posts' ), 20, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_sitemap_terms' ), 20, 3 );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'replace_provider' ), 20, 2 );
	}

	/**
	 * Marks a provider's query arguments so its clauses can be recognized.
	 *
	 * Both providers hand these arguments straight to `WP_Query` and
	 * `get_terms()`, and both keep unrecognized arguments, so the marker is
	 * still present when the clauses are assembled.
	 *
	 * @param array<string, mixed>|mixed $args Query arguments.
	 * @return array<string, mixed>|mixed
	 */
	public function mark_provider_query( $args ) {
		if ( is_array( $args ) ) {
			$args[ self::QUERY_MARKER ] = true;
		}

		return $args;
	}

	/**
	 * Limits a sitemap post query to publicly reachable languages.
	 *
	 * @param array<string, string>|mixed $clauses SQL clauses.
	 * @param WP_Query|mixed              $query   Query being prepared.
	 * @return array<string, string>|mixed
	 */
	public function filter_sitemap_posts( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof WP_Query || ! $query->get( self::QUERY_MARKER ) ) {
			return $clauses;
		}

		$language_id = $this->get_scoped_language_id();

		if ( '' !== $language_id ) {
			$default = $this->url_manager->get_default_language();

			return null === $default
				? $clauses
				: $this->constraint->apply_to_posts( $clauses, $language_id, $default['id'] );
		}

		$language_ids = $this->get_sitemap_language_ids();

		return empty( $language_ids )
			? $clauses
			: $this->constraint->restrict_posts_to_languages( $clauses, $language_ids );
	}

	/**
	 * Limits a sitemap term query to publicly reachable languages.
	 *
	 * @param array<string, string>|mixed $clauses    SQL clauses.
	 * @param array<int, string>|mixed    $taxonomies Queried taxonomies.
	 * @param array<string, mixed>|mixed  $args       Term query arguments.
	 * @return array<string, string>|mixed
	 */
	public function filter_sitemap_terms( $clauses, $taxonomies, $args ) {
		unset( $taxonomies );

		if ( ! is_array( $clauses ) || ! is_array( $args ) || empty( $args[ self::QUERY_MARKER ] ) ) {
			return $clauses;
		}

		$language_id = $this->get_scoped_language_id();

		if ( '' !== $language_id ) {
			$default = $this->url_manager->get_default_language();

			return null === $default
				? $clauses
				: $this->constraint->apply_to_terms( $clauses, $language_id, $default['id'] );
		}

		$language_ids = $this->get_sitemap_language_ids();

		return empty( $language_ids )
			? $clauses
			: $this->constraint->restrict_terms_to_languages( $clauses, $language_ids );
	}

	/**
	 * Replaces a core provider with one that answers per language.
	 *
	 * The name comes from the filter rather than from the provider: WP_Sitemaps_Provider
	 * declares it protected, so reading it from here is not allowed.
	 *
	 * @param mixed  $provider Sitemap provider being registered.
	 * @param string $name     Name the provider is registered under.
	 * @return mixed
	 */
	public function replace_provider( $provider, $name = '' ) {
		if ( ! $provider instanceof WP_Sitemaps_Provider || ! $this->splits_by_language() ) {
			return $provider;
		}

		/*
		 * Only the two providers whose queries this module constrains. A
		 * provider ShapeTranslate cannot scope would be advertised once per
		 * language and answer every one of them with the same URLs.
		 */
		if ( ! in_array( (string) $name, array( 'posts', 'taxonomies' ), true ) ) {
			return $provider;
		}

		return new SitemapLanguageProvider( $provider, $this );
	}

	/**
	 * Runs one callback with every sitemap query held to a single language.
	 *
	 * The index is built outside any language's own request, so the counts it
	 * needs cannot be read from the URL. The provider names the language it is
	 * counting for, and the clause filters above read it back here.
	 *
	 * @param string   $language_id Language identifier, or an empty string to
	 *                              count across every listed language.
	 * @param callable $callback    Work to run in that language.
	 * @return mixed Whatever the callback returns.
	 */
	public function with_language( $language_id, callable $callback ) {
		$previous                 = $this->scoped_language_id;
		$this->scoped_language_id = (string) $language_id;

		try {
			return $callback();
		} finally {
			$this->scoped_language_id = $previous;
		}
	}

	/**
	 * Returns the language one sitemap query must be held to.
	 *
	 * @return string Empty when the query covers every listed language.
	 */
	public function get_scoped_language_id() {
		if ( null !== $this->scoped_language_id ) {
			return $this->scoped_language_id;
		}

		/*
		 * Nothing else on the request says which language a sitemap page is
		 * for: the index named the address, and the provider was handed only a
		 * subtype. So while the sitemap is divided, a page answers for the
		 * language its address resolved to — including the default language at
		 * the address that carries no prefix, which is the one the index
		 * published for it.
		 */
		if ( ! $this->splits_by_language() ) {
			return '';
		}

		if ( ! $this->request_names_a_divided_sitemap() ) {
			return '';
		}

		$current = $this->url_manager->get_current_language();

		return null === $current ? '' : (string) $current['id'];
	}

	/**
	 * Reports whether the request is for a sitemap that was divided by language.
	 *
	 * @return bool
	 */
	private function request_names_a_divided_sitemap() {
		$provider = get_query_var( 'sitemap' );
		$subtype  = get_query_var( 'sitemap-subtype' );

		if ( ! is_scalar( $provider ) || ! is_scalar( $subtype ) ) {
			return false;
		}

		$provider = (string) $provider;
		$subtype  = (string) $subtype;

		return '' !== $provider
			&& '' !== $subtype
			&& $this->is_translatable_subtype( $provider, $subtype );
	}

	/**
	 * Reports whether a sitemap subtype is translated on this site.
	 *
	 * @param string $provider_name Provider name, `posts` or `taxonomies`.
	 * @param string $subtype       Post type or taxonomy name.
	 * @return bool
	 */
	public function is_translatable_subtype( $provider_name, $subtype ) {
		if ( 'posts' === $provider_name ) {
			return $this->post_translations->supports_post_type( $subtype );
		}

		return 'taxonomies' === $provider_name
			&& $this->term_translations->supports_taxonomy( $subtype );
	}

	/**
	 * Points one sitemap address at the language it lists.
	 *
	 * @param string $url         Sitemap URL as core built it.
	 * @param string $language_id Language identifier.
	 * @return string
	 */
	public function localize_sitemap_url( $url, $language_id ) {
		return $this->url_manager->prefix_url( $url, $language_id );
	}

	/**
	 * Reports whether the core sitemap is divided by language.
	 *
	 * Host routing already divides it: each host serves one language and lists
	 * only its own content, which is the form a site owner submits each property
	 * in. Dividing again would offer a language a second address on a host that
	 * does not serve it.
	 *
	 * @return bool
	 */
	public function splits_by_language() {
		$split = $this->settings->is_sitemap_split_enabled()
			&& ! $this->url_manager->uses_host_routing()
			&& 1 < count( $this->get_sitemap_language_ids() );

		/**
		 * Filters whether the core sitemap is split into one file per language.
		 *
		 * @param bool $split Whether per-language sitemaps are produced.
		 */
		return (bool) apply_filters( 'shapetranslate_split_sitemaps_by_language', $split );
	}

	/**
	 * Returns the identifiers of the languages this sitemap may list.
	 *
	 * One sitemap covers every language while they share a host, because they
	 * share an address to be listed under. Host routing gives each language its
	 * own, and a sitemap may only list URLs on the host that served it — a
	 * search engine treats anything else as a cross-domain submission and needs
	 * the other host verified separately before it will read it. So each host
	 * answers for itself, which is also the form a site owner submits: one
	 * sitemap per property, exactly as the property is registered.
	 *
	 * @return array<int, string>
	 */
	public function get_sitemap_language_ids() {
		if ( null !== $this->enabled_ids ) {
			return $this->enabled_ids;
		}

		$ids = array();

		if ( $this->url_manager->uses_host_routing() ) {
			$current = $this->url_manager->get_current_language();
			$ids     = null === $current ? array() : array( (string) $current['id'] );
		} else {
			foreach ( $this->language_manager->get_languages( true ) as $language ) {
				if ( is_array( $language ) && isset( $language['id'] ) && is_scalar( $language['id'] ) ) {
					$ids[] = (string) $language['id'];
				}
			}
		}

		/**
		 * Filters the languages whose content may appear in the core sitemap.
		 *
		 * @param array<int, string> $ids Enabled language identifiers.
		 */
		$filtered = apply_filters( 'shapetranslate_sitemap_language_ids', $ids );

		$this->enabled_ids = is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ) ) ) : $ids;

		return $this->enabled_ids;
	}
}
