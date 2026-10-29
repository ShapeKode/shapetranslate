<?php
/**
 * Host-based language routing resolver.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Routing;

use ShapeTranslate\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps enabled languages onto hostnames for the subdomain and domain URL modes.
 *
 * Directory routing carries the language in the request path, and query routing
 * in a query argument beside it. The remaining two carry it in the host instead,
 * so every question about "which language is this" and "where does this language
 * live" moves from the path to the hostname. This class owns that mapping in one
 * place; nothing else needs to know how a host is derived. It also answers which
 * of the four modes is configured, because that decision belongs beside it.
 */
final class LanguageHostResolver {

	/**
	 * Language lives in the first path segment.
	 *
	 * @var string
	 */
	const MODE_DIRECTORY = 'directory';

	/**
	 * Language lives in a subdomain derived from its URL slug.
	 *
	 * @var string
	 */
	const MODE_SUBDOMAIN = 'subdomain';

	/**
	 * Language lives on its own registered domain.
	 *
	 * @var string
	 */
	const MODE_DOMAIN = 'domain';

	/**
	 * Language lives in a query argument on an otherwise untouched URL.
	 *
	 * @var string
	 */
	const MODE_QUERY = 'query';

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Configured URL mode, once read.
	 *
	 * @var string|null
	 */
	private $mode = null;

	/**
	 * Number of settings saves the held mode was read after.
	 *
	 * @var int
	 */
	private $mode_generation = -1;

	/**
	 * Constructor.
	 *
	 * @param PluginSettings $settings Central plugin settings.
	 */
	public function __construct( PluginSettings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns every supported URL mode.
	 *
	 * @return array<int, string>
	 */
	public static function modes() {
		return array( self::MODE_DIRECTORY, self::MODE_SUBDOMAIN, self::MODE_DOMAIN, self::MODE_QUERY );
	}

	/**
	 * Returns the configured URL mode.
	 *
	 * @return string
	 */
	public function get_mode() {
		/*
		 * Held for the request because nearly every URL the plugin touches asks
		 * this first, and answering it means normalizing and filtering the whole
		 * settings array. A page carrying a menu and a switcher would do that
		 * hundreds of times to learn something that cannot change while the page
		 * is being built.
		 *
		 * The one moment it does change is a settings save, and counting those is
		 * both cheap and exact — cheaper than re-reading, and unlike a cleared
		 * cache it needs nothing to have been wired up in advance.
		 */
		$generation = (int) did_action( 'shapetranslate_settings_updated' );

		if ( null !== $this->mode && $generation === $this->mode_generation ) {
			return $this->mode;
		}

		$url  = $this->settings->get_section( 'url' );
		$mode = isset( $url['mode'] ) && is_scalar( $url['mode'] ) ? (string) $url['mode'] : self::MODE_DIRECTORY;

		$this->mode            = in_array( $mode, self::modes(), true ) ? $mode : self::MODE_DIRECTORY;
		$this->mode_generation = $generation;

		return $this->mode;
	}

	/**
	 * Reports whether the language is carried by the hostname.
	 *
	 * @return bool
	 */
	public function uses_host_routing() {
		return in_array( $this->get_mode(), array( self::MODE_SUBDOMAIN, self::MODE_DOMAIN ), true );
	}

	/**
	 * Reports whether every language lives under one registrable domain.
	 *
	 * The two host modes differ in what they can share. Subdomains sit inside a
	 * single domain, so a cookie or a certificate can cover all of them at once;
	 * separate domains share nothing at all. Anything that spans languages has
	 * to know which of the two it is looking at.
	 *
	 * @return bool
	 */
	public function uses_subdomain_routing() {
		return self::MODE_SUBDOMAIN === $this->get_mode();
	}

	/**
	 * Reports whether the language is carried by a query argument.
	 *
	 * This is the one mode that leaves both the host and the path exactly as
	 * WordPress built them, so it is also the only mode a site without pretty
	 * permalinks can use.
	 *
	 * @return bool
	 */
	public function uses_query_routing() {
		return self::MODE_QUERY === $this->get_mode();
	}

	/**
	 * Returns the host that serves one language.
	 *
	 * The answer is an address, not a comparison key, so it keeps the www prefix
	 * the site is configured with. Callers that need to decide whether two hosts
	 * name the same language run it through normalize() themselves.
	 *
	 * @param array<string, mixed> $language   Language record.
	 * @param bool                 $is_default Whether this is the default language.
	 * @return string Empty in directory mode, or when no host can be derived.
	 */
	public function get_host( array $language, $is_default = false ) {
		switch ( $this->get_mode() ) {
			case self::MODE_DOMAIN:
				$domain = isset( $language['domain'] ) ? $this->host_value( $language['domain'] ) : '';

				/*
				 * A language with no domain of its own stays on the site host. That
				 * keeps a half-configured site reachable instead of routing it to a
				 * hostname nobody has pointed anywhere.
				 */
				return '' === $domain ? $this->get_canonical_site_host() : $domain;

			case self::MODE_SUBDOMAIN:
				$base = $this->get_base_host();

				if ( '' === $base ) {
					return '';
				}

				/*
				 * Reuses the directory mode's default-prefix choice: when the default
				 * language shows no prefix, it also takes the site's own address here.
				 * That address is the configured one rather than the registrable base,
				 * so a site served from www stays on www instead of being moved to a
				 * bare domain that may hold no certificate.
				 */
				if ( $is_default && ! $this->settings->should_prefix_default_language() ) {
					return $this->get_canonical_site_host();
				}

				$slug = sanitize_title( isset( $language['url_slug'] ) ? (string) $language['url_slug'] : '' );

				return '' === $slug ? '' : $slug . '.' . $base;
		}

		return '';
	}

	/**
	 * Finds the language served by one host.
	 *
	 * @param string                              $host        Hostname to match.
	 * @param array<string, array<string, mixed>> $languages   Enabled languages.
	 * @param string                              $default_id  Default language identifier.
	 * @return array<string, mixed>|null
	 */
	public function match( $host, array $languages, $default_id = '' ) {
		$host = $this->normalize( $host );

		if ( '' === $host || ! $this->uses_host_routing() ) {
			return null;
		}

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || ! isset( $language['id'] ) ) {
				continue;
			}

			$candidate = $this->normalize(
				$this->get_host( $language, $language['id'] === $default_id )
			);

			if ( '' !== $candidate && $candidate === $host ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns the site host in comparable form.
	 *
	 * @return string
	 */
	public function get_site_host() {
		return $this->normalize( $this->get_canonical_site_host() );
	}

	/**
	 * Returns the site host exactly as WordPress is configured to serve it.
	 *
	 * The normalize() method answers "do these two hosts name the same
	 * language", and for that question a leading www is noise. Addressing a host is the opposite
	 * question: a site configured on www.example.com is reachable there and may
	 * hold no certificate without it, so a URL built for it has to keep the
	 * prefix. Both forms exist because comparing and addressing are not the same
	 * operation, and answering one with the other is what silently moved every
	 * internal link off www.
	 *
	 * @return string
	 */
	public function get_canonical_site_host() {
		$parts = wp_parse_url( self::site_url() );

		return $this->host_value( is_array( $parts ) && isset( $parts['host'] ) ? $parts['host'] : '' );
	}

	/**
	 * Returns the configured site address without the home_url filters.
	 *
	 * Host routing points home_url() at the current language. Everything that
	 * decides which language a URL belongs to has to compare against the site's
	 * one fixed address instead, or the answer would depend on the answer.
	 *
	 * @return string
	 */
	public static function site_url() {
		static $resolving = false;

		$home = get_option( 'home' );

		if ( is_string( $home ) && '' !== $home ) {
			return trailingslashit( $home );
		}

		/*
		 * Only an install with no home option reaches home_url(), and that call is
		 * filtered by routing, which asks for this address again. One of the two
		 * has to stop; the relative root is the answer that cannot recurse.
		 */
		if ( $resolving ) {
			return '/';
		}

		$resolving = true;

		try {
			return trailingslashit( home_url( '/' ) );
		} finally {
			$resolving = false;
		}
	}

	/**
	 * Returns the host the current request actually arrived on.
	 *
	 * Host routing has to read the real request host: on a language domain the
	 * configured site host names a different language entirely.
	 *
	 * Like get_host(), this is the addressable form: it keeps www, because the
	 * current request URL is rebuilt from it and a reader who arrived on www
	 * must not be quietly moved off it. Callers matching it against a language
	 * run it through normalize() first.
	 *
	 * @return string
	 */
	public function get_request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_scalar( $_SERVER['HTTP_HOST'] )
			? $this->host_value( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
			: '';

		return '' === $host ? $this->get_canonical_site_host() : $host;
	}

	/**
	 * Returns the registrable host that language subdomains are built on.
	 *
	 * A site served from www needs its language subdomains beside www, not
	 * beneath it: bn.example.com, never bn.www.example.com. That is exactly the
	 * www-free form, so the comparison host is the right base to build on.
	 *
	 * @return string
	 */
	public function get_base_host() {
		$host = $this->get_site_host();

		/**
		 * Filters the host that language subdomains are derived from.
		 *
		 * @param string $host Registrable base host.
		 */
		$filtered = apply_filters( 'shapetranslate_subdomain_base_host', $host );

		return is_string( $filtered ) ? $this->normalize( $filtered ) : $host;
	}

	/**
	 * Reduces a hostname to a comparable form.
	 *
	 * Accepts a bare host, a host with a port, or a full URL, so a site owner can
	 * paste "https://example.fr/" into the domain field and still be understood.
	 *
	 * @param mixed $host Candidate hostname.
	 * @return string
	 */
	public function normalize( $host ) {
		$host = $this->host_value( $host );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Reduces a hostname to a bare, addressable form, www and all.
	 *
	 * This is normalize() without the one step that loses information. Accepts a
	 * bare host, a host with a port, or a full URL, so a site owner can paste
	 * "https://example.fr/" into the domain field and still be understood.
	 *
	 * @param mixed $host Candidate hostname.
	 * @return string Empty when the value is not a usable hostname.
	 */
	private function host_value( $host ) {
		if ( ! is_scalar( $host ) ) {
			return '';
		}

		$host = trim( (string) $host );

		if ( '' === $host ) {
			return '';
		}

		if ( false !== strpos( $host, '//' ) ) {
			$parts = wp_parse_url( $host );
			$host  = is_array( $parts ) && isset( $parts['host'] ) ? $parts['host'] : '';
		}

		$host = strtolower( $host );
		$host = preg_replace( '#[/?].*$#', '', $host );
		$host = is_string( $host ) ? $host : '';
		$host = preg_replace( '/:\d+$/', '', $host );
		$host = is_string( $host ) ? trim( $host, '.' ) : '';

		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) ? $host : '';
	}
}
