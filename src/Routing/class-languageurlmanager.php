<?php
/**
 * Language-aware frontend URL service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Routing;

use ShapeTranslate\Content\LanguageQueryConstraint;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves URL languages and builds prefixed translation URLs.
 */
final class LanguageUrlManager {

	/**
	 * Language query variable.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'shapetranslate_lang';

	/**
	 * Query argument that carries the language in query routing mode.
	 *
	 * Short enough to live in a public URL, and the name multilingual plugins
	 * have used for it long enough that visitors and integrations recognize it.
	 *
	 * @var string
	 */
	const PUBLIC_QUERY_VAR = 'lang';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Post translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation manager.
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
	 * Unfiltered static front page identifier for this request.
	 *
	 * @var int
	 */
	private $front_page_id;

	/**
	 * Unfiltered posts page identifier for this request.
	 *
	 * @var int
	 */
	private $posts_page_id;

	/**
	 * Enabled languages keyed by stable identifier.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $languages_by_id;

	/**
	 * Enabled languages keyed by URL slug.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $languages_by_slug;

	/**
	 * Language of the document a preview request shows, once resolved.
	 *
	 * Null until it has been looked up; an empty string means this request
	 * previews nothing, so the lookup is not repeated.
	 *
	 * @var string|null
	 */
	private $previewed_language_id;

	/**
	 * Prevents recursive permalink filtering while loading a source URL.
	 *
	 * @var bool
	 */
	private $suspend_post_filter = false;

	/**
	 * Prevents recursive term-link filtering while loading a core term URL.
	 *
	 * @var bool
	 */
	private $suspend_term_filter = false;

	/**
	 * Host-based routing resolver.
	 *
	 * @var LanguageHostResolver
	 */
	private $hosts;

	/**
	 * Request host, once it has been confirmed to be one this site answers on.
	 *
	 * Null until the question has been asked; an empty string means the request
	 * arrived on a host that serves no language here, so the answer is not
	 * looked up again.
	 *
	 * @var string|null
	 */
	private $routed_request_host = null;

	/**
	 * Language resolver for requests that carry no page address.
	 *
	 * @var BackgroundLanguageResolver
	 */
	private $background;

	/**
	 * Language constraint, built the first time an archive asks.
	 *
	 * @var LanguageQueryConstraint|null
	 */
	private $constraint = null;

	/**
	 * Whether an archive holds posts, keyed by language and post types.
	 *
	 * @var array<string, bool>
	 */
	private $archive_posts = array();

	/**
	 * Whether the site publishes in a language, keyed by language identifier.
	 *
	 * @var array<string, bool>
	 */
	private $language_content = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 * @param PluginSettings|null    $settings          Optional central settings service.
	 */
	public function __construct(
		LanguageManager $language_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations,
		?PluginSettings $settings = null
	) {
		$this->language_manager  = $language_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->settings          = null === $settings ? new PluginSettings() : $settings;
		$this->hosts             = new LanguageHostResolver( $this->settings );
		$this->background        = new BackgroundLanguageResolver( $this );
		$this->front_page_id     = absint( get_option( 'page_on_front' ) );
		$this->posts_page_id     = absint( get_option( 'page_for_posts' ) );
	}

	/**
	 * Reports whether the default language receives a URL prefix.
	 *
	 * @return bool
	 */
	public function should_prefix_default_language() {
		return $this->settings->should_prefix_default_language();
	}

	/**
	 * Returns the host-based routing resolver.
	 *
	 * @return LanguageHostResolver
	 */
	public function hosts() {
		return $this->hosts;
	}

	/**
	 * Returns the resolver for requests that carry no page address.
	 *
	 * AJAX and REST requests reach WordPress at an address that names no
	 * language, so what they are answering in is decided separately.
	 *
	 * @return BackgroundLanguageResolver
	 */
	public function background() {
		return $this->background;
	}

	/**
	 * Reports whether the language is carried by the hostname.
	 *
	 * @return bool
	 */
	public function uses_host_routing() {
		return $this->hosts->uses_host_routing();
	}

	/**
	 * Reports whether the language is carried by a query argument.
	 *
	 * @return bool
	 */
	public function uses_query_routing() {
		return $this->hosts->uses_query_routing();
	}

	/**
	 * Returns the public query argument that names a language in a URL.
	 *
	 * The plugin's own `shapetranslate_lang` variable stays available everywhere as
	 * the unambiguous form. This is the short one that goes into visitor-facing
	 * URLs, so a site whose theme or another plugin already owns `lang` can move
	 * it without losing the internal one.
	 *
	 * @return string
	 */
	public function get_public_query_var() {
		/**
		 * Filters the query argument that carries the language in query routing.
		 *
		 * @param string $variable Public query argument name.
		 */
		$variable = apply_filters( 'shapetranslate_public_query_var', self::PUBLIC_QUERY_VAR );
		$variable = is_scalar( $variable ) ? sanitize_key( (string) $variable ) : '';

		return '' === $variable ? self::PUBLIC_QUERY_VAR : $variable;
	}

	/**
	 * Returns the host serving one language, or an empty string in directory mode.
	 *
	 * @param mixed $language Language reference.
	 * @return string
	 */
	public function get_language_host( $language ) {
		$record = $this->resolve_language( $language );

		if ( null === $record ) {
			return '';
		}

		$default = $this->get_default_language();

		return $this->hosts->get_host( $record, null !== $default && $record['id'] === $default['id'] );
	}

	/**
	 * Returns the language detected from the request prefix or the default.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_current_language() {
		$this->load_languages();
		$language_id = $this->get_detected_language_id();

		return isset( $this->languages_by_id[ $language_id ] )
			? $this->languages_by_id[ $language_id ]
			: null;
	}

	/**
	 * Returns the configured enabled default language.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_default_language() {
		$this->load_languages();
		$default_id = $this->language_manager->get_default_id();

		return isset( $this->languages_by_id[ $default_id ] )
			? $this->languages_by_id[ $default_id ]
			: null;
	}

	/**
	 * Returns enabled languages in configured order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_languages() {
		$this->load_languages();

		return array_values( $this->languages_by_id );
	}

	/**
	 * Returns enabled language URL slugs in configured order.
	 *
	 * @return array<int, string>
	 */
	public function get_language_slugs() {
		$this->load_languages();

		return array_keys( $this->languages_by_slug );
	}

	/**
	 * Resolves a language ID, URL slug, code, or language record.
	 *
	 * @param mixed $language Language reference.
	 * @return array<string, mixed>|null
	 */
	public function resolve_language( $language ) {
		$this->load_languages();

		if ( is_array( $language ) && isset( $language['id'] ) ) {
			$language = $language['id'];
		}

		if ( ! is_scalar( $language ) ) {
			return null;
		}

		$value = (string) $language;

		if ( isset( $this->languages_by_id[ $value ] ) ) {
			return $this->languages_by_id[ $value ];
		}

		$value = sanitize_title( $value );

		if ( isset( $this->languages_by_slug[ $value ] ) ) {
			return $this->languages_by_slug[ $value ];
		}

		foreach ( $this->languages_by_id as $record ) {
			if ( sanitize_title( $record['language_code'] ) === $value ) {
				return $record;
			}
		}

		/*
		 * A WordPress locale is what a caller outside ShapeTranslate is most likely
		 * to have in hand — it is the identifier core itself uses — so a request
		 * naming `bn_BD` means the same language as one naming `bn`.
		 */
		foreach ( $this->languages_by_id as $record ) {
			if ( isset( $record['locale'] ) && sanitize_title( (string) $record['locale'] ) === $value ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Resolves a language reference to the identifier it is stored under.
	 *
	 * @param mixed $language Language identifier, URL slug, code, locale, or record.
	 * @return string Empty when no enabled language answers to the reference.
	 */
	public function resolve_language_id( $language ) {
		$record = $this->resolve_language( $language );

		return is_array( $record ) && isset( $record['id'] ) ? (string) $record['id'] : '';
	}

	/**
	 * Returns the detected enabled language identifier.
	 *
	 * @return string
	 */
	public function get_detected_language_id() {
		$this->load_languages();

		/*
		 * A preview answers for the document an editor opened, so it is that
		 * document's language that is being viewed. Reading it from the URL
		 * instead only works while the URL happens to carry a prefix, and a
		 * draft's preview URL carries none: the request then resolves to the
		 * default language, the language constraint finds nothing, and the
		 * builder is handed a 404 in place of the document it asked for.
		 */
		$previewed_id = $this->get_previewed_language_id();

		if ( '' !== $previewed_id ) {
			return $previewed_id;
		}

		$slug = $this->get_request_language_slug();

		if ( '' !== $slug && isset( $this->languages_by_slug[ $slug ] ) ) {
			return $this->languages_by_slug[ $slug ]['id'];
		}

		$default = $this->get_default_language();

		return null === $default ? '' : $default['id'];
	}

	/**
	 * Supplies URL-prefix detection to the core current-language resolver.
	 *
	 * @param string $default_id Existing default language identifier.
	 * @return string
	 */
	public function filter_current_language_id( $default_id ) {
		$detected_id = $this->get_detected_language_id();

		return '' === $detected_id ? $default_id : $detected_id;
	}

	/**
	 * Returns a prefixed home URL for one enabled language.
	 *
	 * @param mixed $language Language reference.
	 * @return string
	 */
	public function get_language_home_url( $language ) {
		$record = $this->resolve_language( $language );

		return null === $record ? '' : $this->prefix_url( LanguageHostResolver::site_url(), $record );
	}

	/**
	 * Returns the configured source front page identifier.
	 *
	 * The value is captured before frontend routing changes WordPress's effective
	 * front page to the translation for the current request.
	 *
	 * @return int
	 */
	public function get_front_page_id() {
		return $this->front_page_id;
	}

	/**
	 * Returns the configured source posts page identifier.
	 *
	 * Like the front page, the value is captured before routing rewrites the
	 * effective posts page to the translation for the current request.
	 *
	 * @return int
	 */
	public function get_posts_page_id() {
		return $this->posts_page_id;
	}

	/**
	 * Reports whether ShapeTranslate may own frontend language routes.
	 *
	 * Competing multilingual routers are disabled by default because two global
	 * permalink systems cannot safely own the same request paths.
	 *
	 * @return bool
	 */
	public function is_frontend_routing_enabled() {
		/**
		 * Filters whether ShapeTranslate should register and expose frontend routing.
		 *
		 * @param bool $enabled Whether frontend routing should be available.
		 */
		return (bool) apply_filters(
			'shapetranslate_enable_frontend_routing',
			! defined( 'POLYLANG_VERSION' ) && ! defined( 'ICL_SITEPRESS_VERSION' )
		);
	}

	/**
	 * Reports whether language-prefix URLs can be generated safely.
	 *
	 * @return bool
	 */
	public function supports_language_prefixes() {
		return $this->is_frontend_routing_enabled() && $this->has_usable_permalinks();
	}

	/**
	 * Reports whether the permalink structure allows language URLs.
	 *
	 * Only a path prefix needs pretty permalinks. Host routing rewrites the host
	 * and query routing appends an argument, and both leave the path exactly as
	 * WordPress built it, so either works on any permalink structure.
	 *
	 * @return bool
	 */
	private function has_usable_permalinks() {
		return $this->hosts->uses_host_routing()
			|| $this->hosts->uses_query_routing()
			|| '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Points a same-site public URL at one enabled language.
	 *
	 * Where the language goes depends on the configured mode: a path prefix, the
	 * hostname, or a query argument. Existing ShapeTranslate prefixes and arguments
	 * are replaced whichever mode wrote them, so a site that changes its mode
	 * keeps producing one language per URL. Query strings and fragments are
	 * preserved. Administrative, REST, and WordPress asset paths are ignored.
	 *
	 * @param string $url      URL to transform.
	 * @param mixed  $language Language reference.
	 * @return string
	 */
	public function prefix_url( $url, $language ) {
		$record = $this->resolve_language( $language );

		if (
			null === $record
			|| ! is_string( $url )
			|| '' === $url
			|| ! $this->has_usable_permalinks()
		) {
			return $url;
		}

		$url_parts  = wp_parse_url( $url );
		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );

		if ( false === $url_parts || false === $home_parts ) {
			return $url;
		}

		if ( ! $this->is_same_site_url( $url_parts, $home_parts ) ) {
			return $url;
		}

		if ( $this->hosts->uses_host_routing() ) {
			return $this->host_url( $url_parts, $record, $url );
		}

		if ( $this->hosts->uses_query_routing() ) {
			return $this->query_url( $url_parts, $record, $url );
		}

		$path      = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
		$home_path = isset( $home_parts['path'] ) ? trailingslashit( $home_parts['path'] ) : '/';
		$relative  = $this->get_home_relative_path( $path, $home_path );
		$segments  = '' === $relative ? array() : explode( '/', trim( $relative, '/' ) );

		if ( ! empty( $segments ) && $this->is_reserved_path( $segments[0] ) ) {
			return $url;
		}

		if ( ! empty( $segments ) && isset( $this->languages_by_slug[ sanitize_title( $segments[0] ) ] ) ) {
			array_shift( $segments );
		}

		$default    = $this->get_default_language();
		$add_prefix = $this->should_prefix_default_language()
			|| null === $default
			|| $record['id'] !== $default['id'];

		if ( $add_prefix ) {
			array_unshift( $segments, $record['url_slug'] );
		}

		$trailing_slash = '/' === substr( $path, -1 ) || empty( $segments );
		$new_relative   = implode( '/', $segments );
		$new_path       = trailingslashit( $home_path ) . $new_relative;

		if ( $trailing_slash ) {
			$new_path = trailingslashit( $new_path );
		}

		$url_parts['path'] = '/' . ltrim( $new_path, '/' );

		return $this->build_url( $url_parts, $url );
	}

	/**
	 * Reports whether a parsed URL belongs to this site.
	 *
	 * Under host routing every language host is part of the site, so a URL that
	 * already carries one must still be rewritable to another language.
	 *
	 * @param array<string, mixed> $url_parts  Parsed candidate URL.
	 * @param array<string, mixed> $home_parts Parsed home URL.
	 * @return bool
	 */
	private function is_same_site_url( $url_parts, $home_parts ) {
		if ( ! isset( $url_parts['host'] ) ) {
			return true; // A relative URL is always local.
		}

		if (
			isset( $home_parts['host'] )
			&& strtolower( $url_parts['host'] ) === strtolower( $home_parts['host'] )
		) {
			return true;
		}

		if ( ! $this->hosts->uses_host_routing() ) {
			return false;
		}

		/*
		 * The site's own address is the site whether or not the www spelling
		 * matches, and whether or not it currently serves a language. A request
		 * that arrived on the bare host while every language lives on a subdomain
		 * still has to be rewritable, or it could never be sent anywhere.
		 */
		if ( $this->hosts->normalize( $url_parts['host'] ) === $this->hosts->get_site_host() ) {
			return true;
		}

		$this->load_languages();
		$default = $this->get_default_language();

		return null !== $this->hosts->match(
			$url_parts['host'],
			$this->languages_by_id,
			null === $default ? '' : $default['id']
		);
	}

	/**
	 * Returns the language one finished URL names, if any.
	 *
	 * This reads a URL the way the router will read it on the next request,
	 * which is what lets a redirect be checked before it is issued: a target
	 * that would not be understood as the language it was built for is a target
	 * the next request would try to correct again.
	 *
	 * @param string $url URL to inspect.
	 * @return string Language identifier, or empty when the URL names none.
	 */
	public function get_url_language_id( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$this->load_languages();
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		if ( $this->hosts->uses_host_routing() ) {
			$default  = $this->get_default_language();
			$language = $this->hosts->match(
				isset( $parts['host'] ) ? $parts['host'] : '',
				$this->languages_by_id,
				null === $default ? '' : $default['id']
			);

			return null === $language ? '' : (string) $language['id'];
		}

		if ( $this->hosts->uses_query_routing() ) {
			return $this->get_query_string_language_id( isset( $parts['query'] ) ? $parts['query'] : '' );
		}

		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );
		$home_path  = is_array( $home_parts ) && isset( $home_parts['path'] )
			? trailingslashit( $home_parts['path'] )
			: '/';
		$relative   = trim(
			$this->get_home_relative_path( isset( $parts['path'] ) ? $parts['path'] : '/', $home_path ),
			'/'
		);
		$first      = '' === $relative ? '' : sanitize_title( strtok( $relative, '/' ) );

		return isset( $this->languages_by_slug[ $first ] )
			? (string) $this->languages_by_slug[ $first ]['id']
			: '';
	}

	/**
	 * Returns the language named by one query string.
	 *
	 * @param string $query Query string without its leading question mark.
	 * @return string Language identifier, or empty when none is named.
	 */
	private function get_query_string_language_id( $query ) {
		if ( ! is_string( $query ) || '' === $query ) {
			return '';
		}

		$arguments = array();
		wp_parse_str( $query, $arguments );

		foreach ( array( $this->get_public_query_var(), self::QUERY_VAR ) as $variable ) {
			if ( ! isset( $arguments[ $variable ] ) || ! is_scalar( $arguments[ $variable ] ) ) {
				continue;
			}

			$slug = sanitize_title( (string) $arguments[ $variable ] );

			if ( isset( $this->languages_by_slug[ $slug ] ) ) {
				return (string) $this->languages_by_slug[ $slug ]['id'];
			}
		}

		return '';
	}

	/**
	 * Rewrites a URL onto the host that serves one language.
	 *
	 * @param array<string, mixed> $url_parts Parsed URL.
	 * @param array<string, mixed> $record    Resolved language record.
	 * @param string               $original  Original URL.
	 * @return string
	 */
	private function host_url( $url_parts, $record, $original ) {
		$default = $this->get_default_language();
		$host    = $this->hosts->get_host( $record, null !== $default && $record['id'] === $default['id'] );

		if ( '' === $host ) {
			return $original;
		}

		$this->load_languages();

		$path       = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );
		$home_path  = is_array( $home_parts ) && isset( $home_parts['path'] )
			? trailingslashit( $home_parts['path'] )
			: '/';
		$relative   = $this->get_home_relative_path( $path, $home_path );
		$segments   = '' === $relative ? array() : explode( '/', trim( $relative, '/' ) );

		if ( ! empty( $segments ) && $this->is_reserved_path( $segments[0] ) ) {
			return $original;
		}

		/*
		 * A site switched over from directory routing still has prefixed URLs in
		 * menus, content, and caches. The language now lives in the host, so a
		 * leftover prefix would name a page that does not exist.
		 */
		if ( ! empty( $segments ) && isset( $this->languages_by_slug[ sanitize_title( $segments[0] ) ] ) ) {
			array_shift( $segments );
		}

		$trailing_slash    = '/' === substr( $path, -1 ) || empty( $segments );
		$new_path          = trailingslashit( $home_path ) . implode( '/', $segments );
		$url_parts['path'] = '/' . ltrim( $trailing_slash ? trailingslashit( $new_path ) : $new_path, '/' );
		$url_parts['host'] = $host;

		return $this->build_url( $url_parts, $original );
	}

	/**
	 * Names the language in a URL's query string instead of its path.
	 *
	 * The path WordPress produced is kept exactly as it is, which is what lets
	 * this mode serve a site with plain permalinks: `/?p=12&lang=de` resolves
	 * through core's own query handling, with the language read beside it.
	 *
	 * @param array<string, mixed> $url_parts Parsed URL.
	 * @param array<string, mixed> $record    Resolved language record.
	 * @param string               $original  Original URL.
	 * @return string
	 */
	private function query_url( $url_parts, $record, $original ) {
		$this->load_languages();

		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );
		$home_path  = is_array( $home_parts ) && isset( $home_parts['path'] )
			? trailingslashit( $home_parts['path'] )
			: '/';
		$path       = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
		$relative   = $this->get_home_relative_path( $path, $home_path );
		$segments   = '' === $relative ? array() : explode( '/', trim( $relative, '/' ) );

		if ( ! empty( $segments ) && $this->is_reserved_path( $segments[0] ) ) {
			return $original;
		}

		if ( ! empty( $segments ) && isset( $this->languages_by_slug[ sanitize_title( $segments[0] ) ] ) ) {
			array_shift( $segments );

			$trailing_slash    = '/' === substr( $path, -1 ) || empty( $segments );
			$new_path          = trailingslashit( $home_path ) . implode( '/', $segments );
			$url_parts['path'] = '/' . ltrim( $trailing_slash ? trailingslashit( $new_path ) : $new_path, '/' );
		}

		$rebuilt = $this->build_url( $url_parts, $original );

		$rebuilt = remove_query_arg(
			array( $this->get_public_query_var(), self::QUERY_VAR ),
			$rebuilt
		);

		$default      = $this->get_default_language();
		$add_argument = $this->should_prefix_default_language()
			|| null === $default
			|| $record['id'] !== $default['id'];

		return $add_argument
			? add_query_arg( $this->get_public_query_var(), $record['url_slug'], $rebuilt )
			: $rebuilt;
	}

	/**
	 * Returns a translated post or term URL.
	 *
	 * @param int    $object_id         Source post or term identifier.
	 * @param mixed  $target_language   Target language reference.
	 * @param string $taxonomy          Taxonomy name for term translations.
	 * @return string Empty when no target translation exists.
	 */
	public function get_translation_url( $object_id, $target_language, $taxonomy = '' ) {
		$language = $this->resolve_language( $target_language );

		if ( null === $language ) {
			return '';
		}

		if ( '' !== $taxonomy ) {
			$target_id = $this->term_translations->get_translation(
				$object_id,
				$taxonomy,
				$language['id']
			);

			if ( 0 < $target_id ) {
				return $this->get_term_url( $target_id, $taxonomy, $language );
			}
		} else {
			$target_id = $this->post_translations->get_translation( $object_id, $language['id'] );

			if ( 0 < $target_id && $this->is_readable_translation( $target_id ) ) {
				return $this->get_post_url( $target_id, $language );
			}
		}

		/**
		 * Filters the URL returned when a requested translation is unavailable.
		 *
		 * @param string               $url       Empty fallback URL.
		 * @param int                  $object_id Source object identifier.
		 * @param array<string, mixed> $language  Target language record.
		 * @param string               $taxonomy  Taxonomy name, or empty for posts.
		 */
		$fallback = apply_filters(
			'shapetranslate_missing_translation_url',
			'',
			absint( $object_id ),
			$language,
			sanitize_key( $taxonomy )
		);

		return is_string( $fallback ) ? $fallback : '';
	}

	/**
	 * Reports whether a visitor can open a translated post.
	 *
	 * A translation group also holds work in progress: drafts, pending reviews,
	 * and scheduled posts. Their addresses answer 404 for the visitor who follows
	 * them, so an unreadable translation counts as missing and the switcher falls
	 * back to its configured unavailable behavior instead of offering a dead link.
	 * Private posts stay available to the users allowed to read them, which is the
	 * rule the SEO module already applies to hreflang.
	 *
	 * @param int $post_id Translated post identifier.
	 * @return bool
	 */
	public function is_readable_translation( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		return 'private' === $post->post_status && current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Returns the equivalent URL for the current request in another language.
	 *
	 * Singular and taxonomy requests require an existing translation. Archives,
	 * search, pagination, and 404 requests retain their path and query string.
	 *
	 * @param mixed $target_language Target language reference.
	 * @return string Empty when a required object translation is unavailable.
	 */
	public function switch_language_url( $target_language ) {
		$language = $this->resolve_language( $target_language );

		if ( null === $language ) {
			return '';
		}

		$url = $this->resolve_switch_url( $language );

		/*
		 * Switching to the language already on screen keeps the reader where they
		 * are. A page belonging to no translation group — a cart, a checkout, an
		 * account page — answers the lookup above with nothing even for the
		 * language it is being read in, and calling that unavailable lets a
		 * switcher drop the reader's own language from the reader's own route.
		 */
		if ( '' === $url && $this->is_current_language( $language ) ) {
			$url = $this->prefix_url( $this->get_current_request_url(), $language );
		}

		return $url;
	}

	/**
	 * Reports whether a language is the one the request is being read in.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return bool
	 */
	private function is_current_language( array $language ) {
		$current = $this->get_current_language();

		return null !== $current
			&& ! empty( $language['id'] )
			&& (string) $current['id'] === (string) $language['id'];
	}

	/**
	 * Resolves the route one language addresses for the current request.
	 *
	 * @param array<string, mixed> $language Target language record.
	 * @return string
	 */
	private function resolve_switch_url( array $language ) {
		if ( is_front_page() ) {
			return 0 < $this->front_page_id
				? $this->get_translation_url( $this->front_page_id, $language )
				: $this->get_language_home_url( $language );
		}

		if ( is_home() ) {
			if ( 1 > $this->posts_page_id ) {
				return $this->get_language_home_url( $language );
			}

			$posts_page_url = $this->get_translation_url( $this->posts_page_id, $language );

			/*
			 * An untranslated posts page still answers in every language: the
			 * route keeps the source page and the archive under it is held to
			 * the language asked for, so the reader is not sent home from an
			 * archive that works.
			 */
			return '' === $posts_page_url
				? $this->get_post_url( $this->posts_page_id, $language )
				: $posts_page_url;
		}

		if ( is_singular() ) {
			return $this->get_translation_url( get_queried_object_id(), $language );
		}

		$queried_object = get_queried_object();

		if ( $queried_object instanceof WP_Term ) {
			return $this->get_translation_url(
				$queried_object->term_id,
				$language,
				$queried_object->taxonomy
			);
		}

		if ( is_archive() ) {
			return $this->get_archive_translation_url( $language );
		}

		return $this->prefix_url( $this->get_current_request_url(), $language );
	}

	/**
	 * Returns the archive URL a language can be offered, or an empty string.
	 *
	 * A single post is offered to a language when its translation exists. An
	 * archive has no translation to look up — every language can address the
	 * same route — so the equivalent question is whether that route would hold
	 * anything once the language filter runs. Answering it is what lets
	 * `unavailable_behavior` reach an archive at all: without it every language
	 * always had a URL here, and none was ever reported unavailable.
	 *
	 * @param array<string, mixed> $language Target language record.
	 * @return string
	 */
	private function get_archive_translation_url( array $language ) {
		/*
		 * The archive is being read in this language right now, so whether it
		 * holds anything is not in question. Asking anyway would spend a query to
		 * learn nothing, and answering "no" would drop the reader's own language
		 * from the switcher.
		 */
		if ( $this->is_current_language( $language ) ) {
			return $this->prefix_url( $this->get_current_request_url(), $language );
		}

		$post_types = $this->get_archive_post_types();
		$hide       = ! $this->archive_has_posts( $post_types, $language );

		/**
		 * Filters whether a language is dropped from an archive it holds nothing in.
		 *
		 * Return false to keep offering the archive in a language that would show
		 * an empty result, which is what a site wants when the archive itself is
		 * the destination rather than the posts on it.
		 *
		 * @param bool                 $hide       Whether to drop the language.
		 * @param array<string, mixed> $language   Target language record.
		 * @param array<int, string>   $post_types Post types the archive lists.
		 */
		$hide = (bool) apply_filters( 'shapetranslate_hide_archive_translation_url', $hide, $language, $post_types );

		return $hide ? '' : $this->prefix_url( $this->get_current_request_url(), $language );
	}

	/**
	 * Returns the post types the archive being viewed lists.
	 *
	 * @return array<int, string>
	 */
	private function get_archive_post_types() {
		$post_types = get_query_var( 'post_type' );
		$post_types = is_scalar( $post_types ) ? array( (string) $post_types ) : (array) $post_types;
		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );

		return empty( $post_types ) ? array( 'post' ) : $post_types;
	}

	/**
	 * Reports whether an archive holds anything in one language.
	 *
	 * A post type the site never made translatable carries no assignment at all,
	 * so no language owns any of it. Offering the archive anyway would send a
	 * reader to a route that answers with the same content they are already
	 * looking at, under a language it was never written in.
	 *
	 * @param array<int, string>   $post_types Post types the archive lists.
	 * @param array<string, mixed> $language   Target language record.
	 * @return bool
	 */
	private function archive_has_posts( array $post_types, array $language ) {
		$default = $this->get_default_language();

		if ( null === $default || empty( $language['id'] ) ) {
			return false;
		}

		$language_id = (string) $language['id'];
		$key         = $language_id . '|' . implode( ',', $post_types );

		if ( isset( $this->archive_posts[ $key ] ) ) {
			return $this->archive_posts[ $key ];
		}

		/*
		 * One rule answers both the dropdown and the page: the same clause the
		 * frontend query runs. A post type the site left untranslated carries no
		 * assignment, so it comes back empty here for every language but the
		 * default — which is exactly what its archive will show.
		 */
		if ( null === $this->constraint ) {
			$this->constraint = new LanguageQueryConstraint();
		}

		$has = $this->constraint->has_posts_in_language( $post_types, $language_id, (string) $default['id'] );

		$this->archive_posts[ $key ] = $has;

		return $has;
	}

	/**
	 * Reports whether the site publishes anything in one language.
	 *
	 * Whether this page has a translation and whether the site speaks a language
	 * are separate questions, and a switcher that confuses them tells the reader
	 * something untrue: a cart page has no translation in any language, yet a
	 * site with a hundred translated posts plainly is multilingual. This answers
	 * the second question so an offer can survive a page that cannot honour it.
	 *
	 * @param mixed $language Language reference.
	 * @return bool
	 */
	public function language_has_content( $language ) {
		$language = $this->resolve_language( $language );
		$default  = $this->get_default_language();

		if ( null === $language || null === $default ) {
			return false;
		}

		$language_id = (string) $language['id'];

		if ( isset( $this->language_content[ $language_id ] ) ) {
			return $this->language_content[ $language_id ];
		}

		if ( null === $this->constraint ) {
			$this->constraint = new LanguageQueryConstraint();
		}

		$has = $this->constraint->has_any_post_in_language( $language_id, (string) $default['id'] );

		/**
		 * Filters whether the site is considered to publish in one language.
		 *
		 * @param bool                 $has      Whether any published post carries this language.
		 * @param array<string, mixed> $language Language record.
		 */
		$has = (bool) apply_filters( 'shapetranslate_language_has_content', $has, $language );

		$this->language_content[ $language_id ] = $has;

		return $has;
	}

	/**
	 * Builds a canonical language URL for a post.
	 *
	 * Translation members share the source post's core route. ShapeTranslate does
	 * not translate the stored slug in Phase 4.
	 *
	 * @param int|WP_Post $post     Post identifier or object.
	 * @param mixed       $language Optional language reference.
	 * @param string      $raw_url  Optional unfiltered URL for this post.
	 * @return string
	 */
	public function get_post_url( $post, $language = '', $raw_url = '' ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$language = '' === $language
			? $this->resolve_language( $this->post_translations->get_post_language_id( $post->ID ) )
			: $this->resolve_language( $language );

		if ( null === $language ) {
			return is_string( $raw_url ) ? $raw_url : '';
		}

		/*
		 * A front page is the front page in whichever language it was written
		 * for, and that address is the language root rather than the page's own
		 * slug. WordPress records a single `page_on_front`, so only the
		 * translation group can say that this page is that page in another
		 * language.
		 */
		if ( $this->is_front_page_post( $post->ID ) ) {
			return $this->get_language_home_url( $language );
		}

		/*
		 * Each translation is addressed by its own slug. Nothing is borrowed from
		 * the rest of the group: a reader following a link into another language
		 * lands on a URL written in that language, and a post is reachable under
		 * one address rather than one per language of the site.
		 */
		if ( '' === $raw_url ) {
			$raw_url = $this->get_raw_post_url( $post->ID );
		}

		if ( ! $this->has_routable_slug( $post->ID ) ) {
			return $raw_url;
		}

		return $this->prefix_url( $raw_url, $language );
	}

	/**
	 * Reports whether a post is the front page of the language it is written in.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_front_page_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( 1 > $post_id || 1 > $this->front_page_id ) {
			return false;
		}

		if ( $this->front_page_id === $post_id ) {
			return true;
		}

		$language_id = $this->post_translations->get_post_language_id( $post_id );

		return '' !== $language_id
			&& $post_id === $this->post_translations->get_translation( $this->front_page_id, $language_id );
	}

	/**
	 * Reports whether a post can produce a resolvable permalink of its own.
	 *
	 * A post keeps an empty `post_name` until WordPress first generates one, and
	 * an `auto-draft` never receives one at all, so `get_permalink()` answers with
	 * the query-string form for both.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function has_routable_slug( $post_id ) {
		$post = get_post( absint( $post_id ) );

		return $post instanceof WP_Post
			&& 'auto-draft' !== $post->post_status
			&& '' !== (string) $post->post_name;
	}

	/**
	 * Returns the stable language ID assigned to one post.
	 *
	 * @param int|WP_Post $post Post identifier or object.
	 * @return string Empty when the post has no assigned language.
	 */
	public function get_post_language_id( $post ) {
		$post = get_post( $post );

		return $post instanceof WP_Post
			? (string) $this->post_translations->get_post_language_id( $post->ID )
			: '';
	}

	/**
	 * Builds a canonical language URL for a term.
	 *
	 * @param int|WP_Term $term     Term identifier or object.
	 * @param string      $taxonomy Taxonomy name.
	 * @param mixed       $language Optional language reference.
	 * @param string      $raw_url  Optional unfiltered term URL.
	 * @return string
	 */
	public function get_term_url( $term, $taxonomy, $language = '', $raw_url = '' ) {
		$term = get_term( $term, $taxonomy );

		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		$language = '' === $language
			? $this->resolve_language( $this->term_translations->get_term_language_id( $term->term_id, $term->taxonomy ) )
			: $this->resolve_language( $language );

		if ( null === $language ) {
			return is_string( $raw_url ) ? $raw_url : '';
		}

		if ( '' === $raw_url ) {
			$this->suspend_term_filter = true;

			try {
				$link = get_term_link( $term );
			} finally {
				$this->suspend_term_filter = false;
			}

			$raw_url = is_wp_error( $link ) ? '' : $link;
		}

		return $this->prefix_url( $raw_url, $language );
	}

	/**
	 * Filters post and custom post type permalinks.
	 *
	 * @param string  $url  Core permalink.
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public function filter_post_permalink( $url, $post ) {
		return $this->suspend_post_filter ? $url : $this->get_post_url( $post, '', $url );
	}

	/**
	 * Filters page permalinks.
	 *
	 * @param string $url     Core page permalink.
	 * @param int    $post_id Page identifier.
	 * @return string
	 */
	public function filter_page_permalink( $url, $post_id ) {
		return $this->suspend_post_filter ? $url : $this->get_post_url( $post_id, '', $url );
	}

	/**
	 * Prefixes preview URLs using the previewed post's language.
	 *
	 * @param string  $url  Preview URL.
	 * @param WP_Post $post Previewed post.
	 * @return string
	 */
	public function filter_preview_url( $url, $post ) {
		$language_id = $post instanceof WP_Post
			? $this->post_translations->get_post_language_id( $post->ID )
			: '';

		return '' === $language_id ? $url : $this->prefix_url( $url, $language_id );
	}

	/**
	 * Filters term permalinks.
	 *
	 * @param string  $url      Core term URL.
	 * @param WP_Term $term     Term object.
	 * @param string  $taxonomy Taxonomy name.
	 * @return string
	 */
	public function filter_term_permalink( $url, $term, $taxonomy ) {
		return $this->suspend_term_filter ? $url : $this->get_term_url( $term, $taxonomy, '', $url );
	}

	/**
	 * Prefixes an archive, search, or pagination URL with the current language.
	 *
	 * @param string $url Core frontend URL.
	 * @return string
	 */
	public function filter_current_url( $url ) {
		$language = $this->get_current_language();

		return null === $language ? $url : $this->prefix_url( $url, $language );
	}

	/**
	 * Reports whether the current request named an enabled language.
	 *
	 * For a page that is the language its address carries. For an AJAX or REST
	 * call, which has no address of its own, it is the language the call named
	 * by other means.
	 *
	 * @return bool
	 */
	public function request_has_language_prefix() {
		return '' !== $this->get_request_language_slug();
	}

	/**
	 * Reports whether the visitor's own URL named a language.
	 *
	 * This is narrower than request_has_language_prefix(): it asks whether the
	 * visitor already chose, not merely whether a language could be resolved. The
	 * site root resolves to the default language in every mode, and arriving
	 * there is not a choice — it is the request that language detection exists to
	 * answer.
	 *
	 * @return bool
	 */
	public function request_names_language() {
		if ( ! $this->hosts->uses_host_routing() ) {
			return $this->request_has_language_prefix();
		}

		$request_host = $this->hosts->normalize( $this->hosts->get_request_host() );

		return '' !== $request_host && $request_host !== $this->hosts->get_site_host();
	}

	/**
	 * Reports whether the request arrived on a host that serves no language.
	 *
	 * Under host routing every language has an address of its own, and the site
	 * host is one of them only while the default language is unprefixed. When it
	 * is not, the bare address answers in the default language while every link
	 * on the page names a language host, which is the same page at two addresses.
	 *
	 * @return bool
	 */
	public function request_host_serves_no_language() {
		if ( ! $this->uses_host_routing() ) {
			return false;
		}

		$this->load_languages();
		$default = $this->get_default_language();

		return null === $this->hosts->match(
			$this->hosts->get_request_host(),
			$this->languages_by_id,
			null === $default ? '' : $default['id']
		);
	}

	/**
	 * Returns the request host, but only once it is one this site answers on.
	 *
	 * `LanguageHostResolver::get_request_host()` reads the Host header and
	 * checks that it is shaped like a hostname. That is the right answer for
	 * the callers that ask "which language did this address name", because a
	 * host naming none is exactly what they are looking for.
	 *
	 * It is the wrong answer for a caller building an absolute URL to put on
	 * the page. The Host header is written by whoever sent the request, so a
	 * server with a catch-all virtual host will answer for any name at all, and
	 * an address built from that name is one the site never chose: a canonical
	 * link, an `og:url`, or every switcher link on the page, pointing somewhere
	 * else entirely — and pointing there for every later reader once a page
	 * cache has stored the response.
	 *
	 * So this asks the further question the other callers do not need: is this
	 * a host the site is configured to serve? It is the same test
	 * `HostOriginModule` already applies before rewriting content URLs onto the
	 * request host, asked here for the URLs the page is built from.
	 *
	 * @return string Empty when the request host serves no language here.
	 */
	private function get_routed_request_host() {
		if ( null !== $this->routed_request_host ) {
			return $this->routed_request_host;
		}

		$host = $this->hosts->get_request_host();

		$this->routed_request_host = '' === $host || $this->request_host_serves_no_language()
			? ''
			: $host;

		return $this->routed_request_host;
	}

	/**
	 * Returns the current request URL using the configured site origin.
	 *
	 * @return string
	 */
	public function get_current_request_url() {
		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );

		/*
		 * Sanitized by esc_url_raw(), the sanitizer an address takes: it drops
		 * what does not belong in a URL, including the encoded newlines a header
		 * injection would be carried on, and leaves percent-encoding intact.
		 *
		 * Not sanitize_text_field(). It deletes every percent-encoded sequence it
		 * finds, and a slug written in any script but Latin is nothing else:
		 * `/home-page-বাংলা/` arrives as `/home-page-%e0%a6%ac%e0%a6%be%e2%80%a6/`
		 * and would come back as `/home-page-/`. Every caller comparing this
		 * against a permalink would then find them different, and the redirect
		 * meant to correct the address would send the reader to the address they
		 * already asked for, forever.
		 */
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		if ( false === $home_parts || ! isset( $home_parts['host'] ) ) {
			return LanguageHostResolver::site_url();
		}

		$scheme = isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = $home_parts['host'];

		/*
		 * Under host routing the configured site host names one specific language,
		 * so building the current URL from it would rewrite every request onto that
		 * language. The host the request actually arrived on is the truthful one —
		 * as long as it is one this site answers on. A request carrying any other
		 * Host header is answered at the configured address instead, because this
		 * URL is what canonical links, switcher links and redirect comparisons are
		 * built from, and none of them may name a host the site never chose.
		 */
		if ( $this->hosts->uses_host_routing() ) {
			$request_host = $this->get_routed_request_host();
			$host         = '' === $request_host ? $host : $request_host;
		}

		$origin = $scheme . '://' . $host;

		if ( isset( $home_parts['port'] ) ) {
			$origin .= ':' . absint( $home_parts['port'] );
		}

		return esc_url_raw( $origin . '/' . ltrim( $uri, '/' ) );
	}

	/**
	 * Returns the current request URL as the given language addresses it.
	 *
	 * The `current` fallback means "leave the reader where they are", and where
	 * they are is a route, not a language. An archive or a search route is
	 * addressable in every language, so the same route under the target language
	 * is what keeping them there amounts to.
	 *
	 * A single post route is not: it belongs to one translation group, and a
	 * language with no member of that group cannot address it. Those return an
	 * empty string, so the caller offers a page that exists instead of a link
	 * back to the one already on screen.
	 *
	 * @param mixed $language Language reference.
	 * @return string Empty when the route has no address in that language.
	 */
	public function get_current_request_url_in_language( $language ) {
		/*
		 * An archive or a search route is a query, not a document: every language
		 * addresses the same one, so the reader can be kept exactly where they
		 * are. A single post route belongs to one translation group, and a
		 * language with no member of that group has no address on it at all —
		 * saying so lets the caller offer something that exists instead of a link
		 * back to the page already on screen.
		 */
		if ( ! is_archive() && ! is_search() ) {
			return '';
		}

		return $this->prefix_url( $this->get_current_request_url(), $language );
	}

	/**
	 * Reports whether a URL points to a WordPress infrastructure path.
	 *
	 * @param string $url URL to inspect.
	 * @return bool
	 */
	public function is_excluded_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( false === $parts ) {
			return true;
		}

		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );
		$home_path  = is_array( $home_parts ) && isset( $home_parts['path'] )
			? trailingslashit( $home_parts['path'] )
			: '/';
		$path       = isset( $parts['path'] ) ? $parts['path'] : '/';
		$relative   = trim( $this->get_home_relative_path( $path, $home_path ), '/' );
		$first      = '' === $relative ? '' : strtok( $relative, '/' );

		return '' !== $first && $this->is_reserved_path( $first );
	}

	/**
	 * Reports whether a page builder is rendering its editor preview frame.
	 *
	 * Every redirect that moves a visitor to their language already stands down
	 * for is_preview(), because an editor looking at their own work has to stay
	 * on the URL the editor opened. A page builder preview is that same request
	 * in every way that matters, but it announces itself with its own query
	 * argument rather than through WordPress, so is_preview() never sees it. The
	 * frame is then bounced to a language home or a canonical route, the builder
	 * no longer finds the document it asked for, and the editor waits for a
	 * preview that never arrives.
	 *
	 * Language resolution itself is left alone: a translation still has to be
	 * previewed in its own language. Only the redirects stand down.
	 *
	 * @return bool
	 */
	public function is_builder_preview_request() {
		/**
		 * Filters the query arguments that mark a page builder preview frame.
		 *
		 * A builder not listed here announces its preview under its own name and
		 * can add it, rather than having to disable ShapeTranslate routing.
		 *
		 * @param array<int, string> $arguments Query argument names.
		 */
		$arguments = apply_filters(
			'shapetranslate_builder_preview_query_args',
			array( 'elementor-preview' )
		);

		if ( ! is_array( $arguments ) ) {
			return false;
		}

		foreach ( $arguments as $argument ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Request shape only; no input is read.
			if ( is_string( $argument ) && isset( $_GET[ $argument ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the language of the document a preview request is showing.
	 *
	 * Resolved once per request: the detected language is asked for repeatedly,
	 * and the answer cannot change while one request is being served.
	 *
	 * @return string Empty when this is not a preview, or the document has no
	 *                language of its own.
	 */
	private function get_previewed_language_id() {
		if ( null !== $this->previewed_language_id ) {
			return $this->previewed_language_id;
		}

		/*
		 * Pluggable functions load after the plugins do, and the current language
		 * is asked for before that. Answering "no preview" then would be wrong,
		 * and remembering it would keep the preview from ever being recognised
		 * later in the same request, so this leaves the question open instead.
		 */
		if ( ! function_exists( 'is_user_logged_in' ) ) {
			return '';
		}

		$this->previewed_language_id = '';

		if ( ! is_user_logged_in() ) {
			return $this->previewed_language_id;
		}

		$post_id = $this->get_previewed_post_id();

		if ( 0 === $post_id ) {
			return $this->previewed_language_id;
		}

		$language_id = $this->get_post_language_id( $post_id );

		if ( isset( $this->languages_by_id[ $language_id ] ) ) {
			$this->previewed_language_id = $language_id;
		}

		return $this->previewed_language_id;
	}

	/**
	 * Returns the post identifier a preview request names.
	 *
	 * A builder carries the identifier in the argument that marks the preview.
	 * A WordPress preview marks itself and names the post separately, the way
	 * `wp-admin` builds the link.
	 *
	 * @return int Zero when the request names no previewed post.
	 */
	private function get_previewed_post_id() {
		$candidates = array();

		if ( $this->is_builder_preview_request() ) {
			/** This filter is documented in src/Routing/class-languageurlmanager.php */
			$arguments = apply_filters(
				'shapetranslate_builder_preview_query_args',
				array( 'elementor-preview' )
			);

			if ( is_array( $arguments ) ) {
				$candidates = $arguments;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request-shape check.
		if ( isset( $_GET['preview'] ) ) {
			$candidates = array_merge( $candidates, array( 'preview_id', 'p', 'page_id' ) );
		}

		foreach ( $candidates as $argument ) {
			if ( ! is_string( $argument ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request-shape check.
			$value = isset( $_GET[ $argument ] ) && is_scalar( $_GET[ $argument ] ) ? absint( wp_unslash( $_GET[ $argument ] ) ) : 0;

			if ( 0 < $value ) {
				return $value;
			}
		}

		return 0;
	}

	/**
	 * Loads enabled language lookup maps once per request.
	 *
	 * @return void
	 */
	private function load_languages() {
		if ( null !== $this->languages_by_id && null !== $this->languages_by_slug ) {
			return;
		}

		$this->languages_by_id   = array();
		$this->languages_by_slug = array();

		foreach ( $this->language_manager->get_languages( true ) as $language ) {
			$slug = sanitize_title( $language['url_slug'] );

			if ( '' === $slug ) {
				continue;
			}

			$this->languages_by_id[ $language['id'] ] = $language;
			$this->languages_by_slug[ $slug ]         = $language;
		}
	}

	/**
	 * Returns a valid language slug from parsed query vars or request path.
	 *
	 * @return string
	 */
	private function get_request_language_slug() {
		$this->load_languages();
		global $wp;

		if ( $this->hosts->uses_host_routing() ) {
			$default  = $this->get_default_language();
			$language = $this->hosts->match(
				$this->hosts->get_request_host(),
				$this->languages_by_id,
				null === $default ? '' : $default['id']
			);

			return null === $language ? '' : sanitize_title( $language['url_slug'] );
		}

		/*
		 * A request that has no page address of its own — admin-ajax.php, the
		 * REST API — cannot be read from a path or from query vars, because
		 * WordPress never routed a page to produce them. What such a request
		 * named is answered from the signals it does carry.
		 */
		if ( $this->background->is_background_request() ) {
			$language_id = $this->background->resolve_language_id();

			return isset( $this->languages_by_id[ $language_id ] )
				? sanitize_title( $this->languages_by_id[ $language_id ]['url_slug'] )
				: '';
		}

		$slug = '';

		if ( isset( $wp->query_vars[ self::QUERY_VAR ] ) && is_scalar( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			$slug = sanitize_title( $wp->query_vars[ self::QUERY_VAR ] );
		} else {
			$query_slug = get_query_var( self::QUERY_VAR, '' );
			$slug       = is_scalar( $query_slug ) ? sanitize_title( $query_slug ) : '';
		}

		if ( '' !== $slug && isset( $this->languages_by_slug[ $slug ] ) ) {
			return $slug;
		}

		/*
		 * Query routing reads the argument and nothing else. A path segment that
		 * happens to match a language slug is an ordinary page here, and treating
		 * it as a language would answer the wrong content for it.
		 */
		if ( $this->hosts->uses_query_routing() ) {
			return $this->get_request_query_language_slug();
		}

		$parts      = wp_parse_url( $this->get_current_request_url() );
		$home_parts = wp_parse_url( LanguageHostResolver::site_url() );

		if ( false === $parts || false === $home_parts ) {
			return '';
		}

		$path      = isset( $parts['path'] ) ? $parts['path'] : '/';
		$home_path = isset( $home_parts['path'] ) ? trailingslashit( $home_parts['path'] ) : '/';
		$relative  = trim( $this->get_home_relative_path( $path, $home_path ), '/' );
		$first     = '' === $relative ? '' : sanitize_title( strtok( $relative, '/' ) );

		return isset( $this->languages_by_slug[ $first ] ) ? $first : '';
	}

	/**
	 * Returns the language named by the request's own query string.
	 *
	 * The request URL is parsed rather than $_GET so this answers the same way
	 * before and after WordPress has parsed the request, which is what lets the
	 * current language be resolved from the earliest filters.
	 *
	 * @return string Empty when the request named no enabled language.
	 */
	private function get_request_query_language_slug() {
		$parts = wp_parse_url( $this->get_current_request_url() );

		$language_id = $this->get_query_string_language_id(
			is_array( $parts ) && isset( $parts['query'] ) ? $parts['query'] : ''
		);

		return isset( $this->languages_by_id[ $language_id ] )
			? sanitize_title( $this->languages_by_id[ $language_id ]['url_slug'] )
			: '';
	}

	/**
	 * Loads a core permalink while this service's post filters are suspended.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	private function get_raw_post_url( $post_id ) {
		$this->suspend_post_filter = true;

		try {
			$url = get_permalink( $post_id );
		} finally {
			$this->suspend_post_filter = false;
		}

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Returns a path relative to the WordPress home path.
	 *
	 * @param string $path      Absolute URL path.
	 * @param string $home_path WordPress home path.
	 * @return string
	 */
	private function get_home_relative_path( $path, $home_path ) {
		$path      = '/' . ltrim( $path, '/' );
		$home_path = '/' . trim( $home_path, '/' );

		if ( '/' !== $home_path && 0 === strpos( $path, trailingslashit( $home_path ) ) ) {
			return ltrim( substr( $path, strlen( trailingslashit( $home_path ) ) ), '/' );
		}

		return ltrim( $path, '/' );
	}

	/**
	 * Reports whether a first path segment belongs to WordPress infrastructure.
	 *
	 * @param string $segment First path segment.
	 * @return bool
	 */
	private function is_reserved_path( $segment ) {
		return in_array(
			sanitize_title( $segment ),
			array( 'wp-admin', 'wp-login-php', 'wp-json', 'wp-content', 'wp-includes', 'xmlrpc-php' ),
			true
		);
	}

	/**
	 * Rebuilds a parsed URL without losing query or fragment components.
	 *
	 * @param array<string, mixed> $parts    Parsed URL parts.
	 * @param string               $original Original URL.
	 * @return string
	 */
	private function build_url( $parts, $original ) {
		if ( ! isset( $parts['host'] ) ) {
			return $original;
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$url    = $scheme;

		if ( isset( $parts['user'] ) ) {
			$url .= $parts['user'];

			if ( isset( $parts['pass'] ) ) {
				$url .= ':' . $parts['pass'];
			}

			$url .= '@';
		}

		$url .= $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . absint( $parts['port'] );
		}

		$url .= isset( $parts['path'] ) ? $parts['path'] : '/';

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}
}
