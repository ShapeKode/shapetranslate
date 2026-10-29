<?php
/**
 * Same-origin module for host-based language routing.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Routing;

use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps everything a host-routed page loads on the host that served the page.
 *
 * Under subdomain and domain routing the document arrives from one host while
 * WordPress keeps building asset, REST, and AJAX URLs from the configured site
 * address, which is a different origin. The browser then refuses the ones that
 * matter: a `fetch()` to the REST API, an `admin-ajax` call, a webfont loaded
 * from a stylesheet. Nothing reports an error a site owner can act on — the
 * search box simply stops returning results and the font silently falls back.
 *
 * The fix is not to open the other origin up with CORS headers, which would
 * still leave credentials behind and still be one misconfiguration away from
 * failing. It is to stop producing a second origin at all: a page served from
 * `de.example.com` asks `de.example.com` for everything it needs.
 *
 * Login and the admin are deliberately left on the site's own address. They are
 * where the authentication cookie lives, and moving them to a language host
 * would hand visitors a login form that cannot set a usable session.
 */
final class HostOriginModule implements ModuleInterface {

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Host this request's URLs belong on, once settled.
	 *
	 * @var string|null
	 */
	private $target_host = null;

	/**
	 * Every host the site answers on, in comparable form.
	 *
	 * @var array<int, string>|null
	 */
	private $site_hosts = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager $url_manager Language URL service.
	 */
	public function __construct( LanguageUrlManager $url_manager ) {
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if (
			! $this->url_manager->is_frontend_routing_enabled()
			|| ! $this->url_manager->uses_host_routing()
		) {
			return;
		}

		foreach ( array( 'content_url', 'plugins_url', 'theme_root_uri', 'includes_url', 'rest_url' ) as $filter ) {
			add_filter( $filter, array( $this, 'filter_url' ), 20 );
		}

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ), 20 );
		add_filter( 'admin_url', array( $this, 'filter_admin_url' ), 20, 2 );
		add_filter( 'wp_sitemaps_stylesheet_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'wp_sitemaps_stylesheet_index_url', array( $this, 'filter_url' ), 20 );
		add_filter( 'wp_sitemaps_index_entry', array( $this, 'filter_sitemap_entry' ), 20 );
	}

	/**
	 * Moves one site URL onto the host currently being served.
	 *
	 * @param mixed $url Candidate URL.
	 * @return mixed
	 */
	public function filter_url( $url ) {
		return is_string( $url ) ? $this->to_request_host( $url ) : $url;
	}

	/**
	 * Keeps uploaded media on the host currently being served.
	 *
	 * A page and its images sharing an origin is what lets a canvas read one, and
	 * what keeps a browser from opening a second connection for every thumbnail.
	 *
	 * @param mixed $uploads Upload directory description.
	 * @return mixed
	 */
	public function filter_upload_dir( $uploads ) {
		if ( ! is_array( $uploads ) ) {
			return $uploads;
		}

		foreach ( array( 'url', 'baseurl' ) as $key ) {
			if ( isset( $uploads[ $key ] ) && is_string( $uploads[ $key ] ) ) {
				$uploads[ $key ] = $this->to_request_host( $uploads[ $key ] );
			}
		}

		return $uploads;
	}

	/**
	 * Moves only the AJAX endpoint onto the host currently being served.
	 *
	 * Every other admin URL stays where the session does. This one cannot: it is
	 * the endpoint a frontend script calls, and a cross-origin call to it is
	 * refused before WordPress ever sees it.
	 *
	 * @param mixed $url  Complete admin URL.
	 * @param mixed $path Path relative to the admin URL.
	 * @return mixed
	 */
	public function filter_admin_url( $url, $path ) {
		return 'admin-ajax.php' === $path && is_string( $url )
			? $this->to_request_host( $url )
			: $url;
	}

	/**
	 * Points one sitemap index entry at the host it was requested from.
	 *
	 * @param mixed $entry Sitemap index entry.
	 * @return mixed
	 */
	public function filter_sitemap_entry( $entry ) {
		if ( is_array( $entry ) && isset( $entry['loc'] ) && is_string( $entry['loc'] ) ) {
			$entry['loc'] = $this->to_request_host( $entry['loc'] );
		}

		return $entry;
	}

	/**
	 * Rewrites a same-site URL onto the host serving this request.
	 *
	 * The request host is used rather than the current language's host because
	 * they can differ by a `www` the visitor typed, and an asset URL that does
	 * not match the document's origin is exactly what this module exists to
	 * prevent. A request on a host that serves no language is left alone.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	private function to_request_host( $url ) {
		if ( '' === $url || ! $this->is_rewritable_request() ) {
			return $url;
		}

		$host = $this->get_target_host();

		if ( '' === $host ) {
			return $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || $parts['host'] === $host ) {
			return $url;
		}

		if ( ! $this->is_site_host( $parts['host'] ) ) {
			return $url;
		}

		$parts['host'] = $host;

		/**
		 * Filters a URL after it has been moved onto the request's own host.
		 *
		 * Return the original URL to keep one kind of address on the site host,
		 * which is what a site fronting its uploads with a CDN wants.
		 *
		 * @param string $rewritten Rewritten URL.
		 * @param string $url       Original URL.
		 * @param string $host      Host serving this request.
		 */
		$rewritten = apply_filters(
			'shapetranslate_same_origin_url',
			$this->build_url( $parts ),
			$url,
			$host
		);

		return is_string( $rewritten ) && '' !== $rewritten ? $rewritten : $url;
	}

	/**
	 * Reports whether this request renders a page whose origin matters.
	 *
	 * @return bool
	 */
	private function is_rewritable_request() {
		return ! is_admin()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
	}

	/**
	 * Returns the host this request's URLs belong on, if any.
	 *
	 * A page rebuilds dozens of URLs through these filters, and the answer is the
	 * same for all of them, so it is settled once.
	 *
	 * @return string Empty when nothing should be rewritten.
	 */
	private function get_target_host() {
		if ( null !== $this->target_host ) {
			return $this->target_host;
		}

		$host = $this->url_manager->hosts()->get_request_host();

		$this->target_host = '' === $host || $this->url_manager->request_host_serves_no_language()
			? ''
			: $host;

		return $this->target_host;
	}

	/**
	 * Reports whether one host is an address this site answers on.
	 *
	 * @param string $host Host to test.
	 * @return bool
	 */
	private function is_site_host( $host ) {
		$host = $this->url_manager->hosts()->normalize( $host );

		return '' !== $host && in_array( $host, $this->get_site_hosts(), true );
	}

	/**
	 * Returns every host this site answers on, in comparable form.
	 *
	 * @return array<int, string>
	 */
	private function get_site_hosts() {
		if ( null !== $this->site_hosts ) {
			return $this->site_hosts;
		}

		$hosts = $this->url_manager->hosts();
		$known = array( $hosts->get_site_host() );

		foreach ( $this->url_manager->get_language_slugs() as $slug ) {
			$known[] = $hosts->normalize( $this->url_manager->get_language_host( $slug ) );
		}

		$this->site_hosts = array_values( array_unique( array_filter( $known ) ) );

		return $this->site_hosts;
	}

	/**
	 * Reassembles a parsed URL whose host was replaced.
	 *
	 * @param array<string, mixed> $parts Parsed URL parts.
	 * @return string
	 */
	private function build_url( array $parts ) {
		$url = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' ) . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . absint( $parts['port'] );
		}

		$url .= isset( $parts['path'] ) ? $parts['path'] : '';

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}
}
