<?php
/**
 * Visitor language detection module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Routing;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Integrations\Cache\CacheCompatibility;
use ShapeTranslate\Language\BrowserLanguageDetector;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a first-time visitor to the language their browser asks for.
 *
 * Detection runs only on an unprefixed front page request, so a shared or
 * bookmarked language URL always wins over the browser preference. A returning
 * visitor is recognized by cookie before the header is read, and an internal
 * referrer suppresses detection entirely so following a link back to the site
 * root never bounces the visitor out of the language they were reading.
 */
final class LanguageDetectionModule implements ModuleInterface {

	/**
	 * Cookie that remembers the visitor's last language.
	 */
	const COOKIE_NAME = 'shapetranslate_language';

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
	 * Accept-Language negotiation service.
	 *
	 * @var BrowserLanguageDetector
	 */
	private $detector;

	/**
	 * Plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Whether this response has already announced what it varies on.
	 *
	 * @var bool
	 */
	private $varied = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager      $url_manager      Language URL API.
	 * @param LanguageManager         $language_manager Language manager.
	 * @param BrowserLanguageDetector $detector         Accept-Language negotiation.
	 * @param PluginSettings|null     $settings         Plugin settings.
	 */
	public function __construct(
		LanguageUrlManager $url_manager,
		LanguageManager $language_manager,
		BrowserLanguageDetector $detector,
		?PluginSettings $settings = null
	) {
		$this->url_manager      = $url_manager;
		$this->language_manager = $language_manager;
		$this->detector         = $detector;
		$this->settings         = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		/*
		 * First of all, because every path out of this request needs it: the
		 * detection redirect exits, and so does the canonical one at priority 1.
		 * A response that says nothing about what it varies on is a response a
		 * shared cache will hand to the next reader whatever their language.
		 */
		add_action( 'template_redirect', array( $this, 'protect_detected_response' ), 0 );

		// Runs before RoutingModule::redirect_unprefixed_request() at priority 1.
		add_action( 'template_redirect', array( $this, 'redirect_preferred_language' ), 0 );
		add_action( 'template_redirect', array( $this, 'remember_current_language' ), 5 );
	}

	/**
	 * Keeps a shared cache from serving one visitor's language to everyone.
	 *
	 * Exactly one request on the site is reader-dependent: the site root with
	 * no language in it, where detection decides where the visitor lands. Every
	 * other page names its language in the URL, so what it serves is the same
	 * for every reader and nothing about it varies.
	 *
	 * Holding to that distinction is the whole point of doing this here. A
	 * `Vary: Cookie` on a page that does not vary is not a harmless extra: a
	 * CDN that honors it keys that URL by the visitor's entire cookie jar — a
	 * login cookie, a session, a comment author — and turns one cached page
	 * into a variant per reader. Announced on the one URL that earns it, the
	 * cost is a single address; announced site-wide, it is the hit ratio.
	 *
	 * Full-page cache plugins largely ignore Vary on a cookie, so that same URL
	 * is additionally marked uncacheable.
	 *
	 * @return void
	 */
	public function protect_detected_response() {
		if ( ! $this->is_detection_enabled() || ! $this->is_public_frontend_request() ) {
			return;
		}

		// A language in the URL settles the question, whoever is asking.
		if ( ! is_front_page() || $this->url_manager->request_names_language() ) {
			return;
		}

		$this->vary_response();

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			/**
			 * Filters whether the undecided site root may be page-cached.
			 *
			 * Detection makes this one URL reader-dependent, so it is excluded
			 * from full-page caching by default. Return false on a site that
			 * varies its cache by cookie itself.
			 *
			 * @param bool $exclude Whether to mark the response uncacheable.
			 */
			if ( apply_filters( 'shapetranslate_exclude_detected_root_from_cache', true ) ) {
				/*
				 * Not this plugin's constant to name. DONOTCACHEPAGE is the signal the
				 * page cache plugins already watch for — W3 Total Cache, WP Super Cache,
				 * WP Rocket, Batcache — and they look for that spelling and no other.
				 * A prefixed name would define something nothing reads, which is the
				 * whole feature gone rather than a naming preference honoured.
				 */
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party constant; the name is the contract.
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}

	/**
	 * Announces what the undecided site root varies on.
	 *
	 * @return void
	 */
	private function vary_response() {
		if ( $this->varied || headers_sent() ) {
			return;
		}

		$this->varied = true;

		/**
		 * Filters the request headers the undecided site root varies on.
		 *
		 * The Accept-Language header answers a first visit; the cookie answers
		 * every one after it, and a cache that knows only about the first serves
		 * the wrong language to the second. Both are named for that reason.
		 *
		 * Narrow the list on a CDN that keys the site root by language itself,
		 * or return an empty array to announce nothing. No other page on the
		 * site sends a Vary header, so this affects one URL either way.
		 *
		 * @param array<int, string> $headers Request header names.
		 */
		$headers = apply_filters( 'shapetranslate_vary_headers', array( 'Accept-Language', 'Cookie' ) );

		if ( ! is_array( $headers ) ) {
			return;
		}

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );

			/*
			 * A filtered value is written straight into a response header, so
			 * only something shaped like a header name is passed on. Anything
			 * carrying a newline would append a header of its own.
			 */
			if ( '' === $header || ! preg_match( '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header ) ) {
				continue;
			}

			header( 'Vary: ' . $header, false );
		}
	}

	/**
	 * Redirects an undecided front page request to the visitor's language.
	 *
	 * @return void
	 */
	public function redirect_preferred_language() {
		if ( ! $this->is_detection_request() ) {
			return;
		}

		$language = $this->resolve_preferred_language();

		if ( null === $language ) {
			return;
		}

		$url = $this->preserve_query_string( $this->url_manager->get_language_home_url( $language ) );

		if ( '' === $url || $this->is_current_url( $url ) ) {
			return;
		}

		/**
		 * Filters the URL a detected visitor is sent to.
		 *
		 * Returning an empty string cancels the redirect and leaves the request
		 * to the normal ShapeTranslate routing rules.
		 *
		 * @param string               $url      Target URL.
		 * @param array<string, mixed> $language Detected language record.
		 */
		$url = apply_filters( 'shapetranslate_detected_language_url', $url, $language );

		if ( ! is_string( $url ) || '' === $url || '' === wp_validate_redirect( $url, '' ) ) {
			return;
		}

		$this->store_language_cookie( $language, true );
		$this->vary_response();

		/**
		 * Fires immediately before a detected visitor is redirected.
		 *
		 * @param array<string, mixed> $language Detected language record.
		 * @param string               $url      Target URL.
		 */
		do_action( 'shapetranslate_language_detected', $language, $url );

		wp_safe_redirect( $url, 302, 'ShapeTranslate' );
		exit;
	}

	/**
	 * Remembers the language of an explicit language request.
	 *
	 * Only frontend requests that resolve to one language are recorded, so the
	 * cookie always reflects a language the visitor actually navigated to. A
	 * prefix is the usual proof of that, and where the default language carries
	 * none it is every unprefixed page: skipping those would leave the cookie
	 * naming a language the visitor has already navigated away from.
	 *
	 * @return void
	 */
	public function remember_current_language() {
		if ( ! $this->is_detection_enabled() || ! $this->is_public_frontend_request() ) {
			return;
		}

		if (
			! $this->url_manager->request_has_language_prefix()
			&& ! $this->serves_unprefixed_default_language()
		) {
			return;
		}

		$language = $this->url_manager->get_current_language();

		if ( is_array( $language ) ) {
			$this->store_language_cookie( $language );
		}
	}

	/**
	 * Reports whether this root request is a deliberate default-language visit.
	 *
	 * Only meaningful while the default language is unprefixed, which is what
	 * makes the site root an address the visitor can choose rather than merely
	 * arrive at. wp_get_referer() answers false for an off-site referrer and
	 * for the request's own URL, so only same-site navigation counts.
	 *
	 * @return bool
	 */
	private function request_chose_unprefixed_default_language() {
		return ! $this->url_manager->should_prefix_default_language()
			&& '' !== (string) wp_get_referer();
	}

	/**
	 * Reports whether this request is the default language without a prefix.
	 *
	 * @return bool
	 */
	private function serves_unprefixed_default_language() {
		if ( $this->url_manager->should_prefix_default_language() ) {
			return false;
		}

		$current = $this->url_manager->get_current_language();
		$default = $this->url_manager->get_default_language();

		return is_array( $current )
			&& is_array( $default )
			&& isset( $current['id'], $default['id'] )
			&& $current['id'] === $default['id'];
	}

	/**
	 * Reports whether the current request may be redirected by detection.
	 *
	 * @return bool
	 */
	private function is_detection_request() {
		$allowed = $this->is_detection_enabled()
			&& $this->is_public_frontend_request()
			&& is_front_page()
			&& ! $this->url_manager->request_names_language()
			&& $this->is_plain_get_request();

		/**
		 * Filters whether visitor language detection may run for this request.
		 *
		 * Useful to exclude crawlers, or to disable detection while a full page
		 * cache serves the site root.
		 *
		 * @param bool $allowed Whether detection may run.
		 */
		return (bool) apply_filters( 'shapetranslate_should_detect_language', $allowed );
	}

	/**
	 * Returns the language a visitor should land on, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_preferred_language() {
		/*
		 * Where the default language carries no prefix, the site root is both
		 * the undecided address and that language's own home, and the switcher
		 * has no other address to offer for it. A visitor who arrives from a
		 * link on this site has therefore just chosen the default language, and
		 * answering them from a cookie written on the page they came from would
		 * send them straight back to it — leaving the default language home
		 * unreachable from anywhere inside the site.
		 */
		if ( $this->request_chose_unprefixed_default_language() ) {
			return null;
		}

		$remembered = $this->get_remembered_language();

		if ( null !== $remembered ) {
			return $remembered;
		}

		if ( '' !== (string) wp_get_referer() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Parsed and validated by the detector.
		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) )
			: '';

		if ( '' === $header ) {
			return null;
		}

		$languages = $this->language_manager->get_languages( true );

		/**
		 * Filters the languages offered to browser preference matching.
		 *
		 * @param array<int, array<string, mixed>> $languages Enabled languages.
		 * @param string                           $header    Accept-Language header.
		 */
		$filtered = apply_filters( 'shapetranslate_browser_detection_languages', $languages, $header );

		return $this->detector->match( $header, is_array( $filtered ) ? $filtered : $languages );
	}

	/**
	 * Returns the enabled language stored in the visitor's cookie.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_remembered_language() {
		// An array-shaped cookie (`name[]=x`) would reach sanitize_title() as an array.
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_string( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$slug     = sanitize_title( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$language = '' === $slug ? null : $this->url_manager->resolve_language( $slug );

		return is_array( $language ) && ! empty( $language['enabled'] ) ? $language : null;
	}

	/**
	 * Stores the visitor's language for the next visit.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param bool                 $force    Write even on a page-cached site.
	 * @return void
	 */
	private function store_language_cookie( array $language, $force = false ) {
		if ( headers_sent() || ! isset( $language['url_slug'] ) ) {
			return;
		}

		/*
		 * A page cache stores response headers alongside the page it caches, so
		 * a Set-Cookie written here is replayed to everyone the cached copy is
		 * later served to: one visitor's language, handed out as everybody's.
		 * Worse, the visitors who would need it never reach PHP at all. On such
		 * a site the cookie is written from the browser instead, by
		 * CacheCompatibilityModule, from these same arguments.
		 */
		if ( ! $force && CacheCompatibility::is_active() ) {
			return;
		}

		$slug = sanitize_title( (string) $language['url_slug'] );

		if ( '' === $slug ) {
			return;
		}

		if (
			isset( $_COOKIE[ self::COOKIE_NAME ] )
			&& is_string( $_COOKIE[ self::COOKIE_NAME ] )
			&& sanitize_title( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) === $slug
		) {
			return;
		}

		$_COOKIE[ self::COOKIE_NAME ] = $slug;

		setcookie( self::COOKIE_NAME, $slug, $this->get_cookie_arguments( $language ) );
	}

	/**
	 * Reports whether the visitor's language is remembered at all.
	 *
	 * @return bool
	 */
	public function is_language_cookie_enabled() {
		return $this->is_detection_enabled();
	}

	/**
	 * Returns the arguments the visitor's language cookie is written with.
	 *
	 * Public because the browser-side writer on a page-cached site has to
	 * describe the very same cookie. A path or domain the two disagree on does
	 * not overwrite anything — it writes a second cookie under the same name,
	 * and which one the site then reads back is up to the browser.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return array<string, mixed>
	 */
	public function get_cookie_arguments( array $language ) {
		/**
		 * Filters how long the visitor's language is remembered, in seconds.
		 *
		 * Return zero to send a session cookie instead.
		 *
		 * @param int                  $lifetime Cookie lifetime in seconds.
		 * @param array<string, mixed> $language Language record.
		 */
		$lifetime = (int) apply_filters( 'shapetranslate_language_cookie_lifetime', YEAR_IN_SECONDS, $language );

		$arguments = array(
			'expires'  => 0 < $lifetime ? time() + $lifetime : 0,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => $this->get_cookie_domain(),
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		);

		/**
		 * Filters the arguments the visitor's language cookie is written with.
		 *
		 * Fires before the theme is loaded on a request that redirects.
		 *
		 * @param array<string, mixed> $arguments Arguments for setcookie().
		 * @param array<string, mixed> $language  Language record.
		 */
		$filtered = apply_filters( 'shapetranslate_language_cookie_args', $arguments, $language );

		return is_array( $filtered ) ? $filtered : $arguments;
	}

	/**
	 * Returns the domain the language cookie is written for.
	 *
	 * @return string
	 */
	private function get_cookie_domain() {
		if ( ! $this->url_manager->uses_host_routing() ) {
			return COOKIE_DOMAIN;
		}

		/*
		 * Subdomain routing keeps every language under one registrable domain, so
		 * scoping the cookie to that domain is what lets a reader who chose Bangla
		 * on bn.example.com still be recognized at example.com. COOKIE_DOMAIN is
		 * no help here: it names the site host, which under this mode is one
		 * specific language rather than the family of them.
		 */
		if ( $this->url_manager->hosts()->uses_subdomain_routing() ) {
			$base = $this->url_manager->hosts()->get_base_host();

			return '' === $base ? '' : '.' . $base;
		}

		/*
		 * Separate domains share nothing a cookie can span, and a browser
		 * discards one scoped to a domain it is not visiting — which would leave
		 * detection re-running on every page. A host-only cookie is always
		 * accepted, at the cost of each domain remembering separately.
		 */
		return '';
	}

	/**
	 * Reports whether browser detection is enabled and usable.
	 *
	 * This also gates the language cookie, which exists solely to answer
	 * detection without reading the browser header again. Writing a value
	 * nothing reads would add a consent obligation and a cache variant for no
	 * behavior, so detection being off means no cookie is set at all.
	 *
	 * @return bool
	 */
	private function is_detection_enabled() {
		return $this->settings->should_detect_browser_language()
			&& $this->url_manager->supports_language_prefixes();
	}

	/**
	 * Reports whether this is a normal public HTML frontend request.
	 *
	 * WordPress fires `template_redirect` before it decides a request is for
	 * robots.txt or the favicon, so both reach this plugin. Neither names a
	 * language the visitor chose, and the favicon is not caught by `is_404()`
	 * either — `WP::handle_404()` exempts it.
	 *
	 * @return bool
	 */
	private function is_public_frontend_request() {
		return ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! is_robots()
			&& ! is_favicon()
			&& ! is_feed()
			&& ! is_embed()
			&& ! is_preview()
			&& ! $this->url_manager->is_builder_preview_request()
			&& ! is_404();
	}

	/**
	 * Reports whether the request is a plain GET without submitted data.
	 *
	 * Redirecting a form submission would discard it.
	 *
	 * @return bool
	 */
	private function is_plain_get_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Emptiness check only, no data is read.
		return 'GET' === $method && empty( $_POST );
	}

	/**
	 * Appends the current query string to a redirect target.
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	private function preserve_query_string( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$query = wp_parse_url( $this->url_manager->get_current_request_url(), PHP_URL_QUERY );

		if ( ! is_string( $query ) || '' === $query ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
	}

	/**
	 * Reports whether a URL is the request already being served.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_current_url( $url ) {
		$current = $this->url_manager->get_current_request_url();

		return untrailingslashit( strtok( $url, '?' ) ) === untrailingslashit( strtok( $current, '?' ) );
	}
}
