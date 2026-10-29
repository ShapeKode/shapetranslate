<?php
/**
 * Language of a request that has no page address of its own.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the language an AJAX or REST request is answering in.
 *
 * A rendered page says which language it is in through its own address. The two
 * request kinds that produce part of a page without being one — admin-ajax.php
 * and the REST API — live at a fixed address that belongs to no language. Left
 * at that, everything they return is written in the default language while the
 * reader is looking at another one: a filtered listing, a "load more" page of
 * posts, a block previewed in the editor.
 *
 * Each kind is given the signals it can be trusted to carry.
 *
 * A REST collection is a public interface, and what it answers has to depend on
 * what the caller asked for and nothing else. Only an explicit language is read
 * there: the `lang` argument on the request, or the one the dispatcher captured
 * from a body the argument could not be read from before dispatch.
 *
 * admin-ajax.php is the opposite. It is never called on its own; a page calls
 * it, and that page names a language in its own address. So an explicit
 * argument is read first, and then the page that made the call is asked. That
 * is what lets a theme or plugin which has never heard of ShapeTranslate answer in
 * the right language without changing a line.
 *
 * The visitor's language cookie is deliberately not read. It records the last
 * language the visitor chose, which is not the same question: while the default
 * language goes unprefixed, a reader who once visited a prefixed language still
 * carries that cookie on every page of the default one, and a call made from
 * such a page would be answered in a language the reader is not looking at. The
 * calling page cannot be wrong about itself, so it is the only page signal used.
 *
 * Administration calls are excluded. A wp-admin screen answers in the
 * administration's own language and has a language filter of its own, so
 * reading a visitor's language here would fight that filter rather than help
 * it. A call that names no language is left exactly as it was before this class
 * existed, which is what keeps every screen that has always worked working.
 */
final class BackgroundLanguageResolver {

	/**
	 * Request argument marking an AJAX call as belonging to the administration.
	 *
	 * A referrer already answers this for every call made from a screen, so
	 * nothing has to send this. It is here for the integration whose referrer
	 * says frontend while the call is administration work — a builder editing a
	 * document through its own preview, say — which sends it to say so.
	 *
	 * @var string
	 */
	const BACKEND_ARGUMENT = 'shapetranslate_ajax_backend';

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Language the dispatcher captured for the call it is running.
	 *
	 * @var string
	 */
	private $override = '';

	/**
	 * Language detected from the request itself, once looked up.
	 *
	 * @var string|null
	 */
	private $resolved = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager $url_manager Language URL service.
	 */
	public function __construct( LanguageUrlManager $url_manager ) {
		$this->url_manager = $url_manager;
	}

	/**
	 * Reports whether this request serves a page without being one.
	 *
	 * @return bool
	 */
	public function is_background_request() {
		return wp_doing_ajax() || $this->is_rest_request();
	}

	/**
	 * Records the language the dispatcher resolved for the call it is running.
	 *
	 * The previous value is returned so a caller that dispatches a request of
	 * its own can put back what it displaced. Without that, one nested call
	 * would leave the outer request with no language for the rest of its life.
	 *
	 * @param string $language_id Language identifier, or an empty string to clear.
	 * @return string Language identifier this replaced.
	 */
	public function set_override( $language_id ) {
		$previous       = $this->override;
		$this->override = is_string( $language_id ) ? $language_id : '';

		return $previous;
	}

	/**
	 * Returns the language the dispatcher captured, if any.
	 *
	 * @return string
	 */
	public function get_override() {
		return $this->override;
	}

	/**
	 * Returns the language this request named, if it named one.
	 *
	 * @return string Empty when the request names no language of its own.
	 */
	public function resolve_language_id() {
		if ( '' !== $this->override ) {
			return $this->override;
		}

		if ( ! $this->is_background_request() ) {
			return '';
		}

		if ( null === $this->resolved ) {
			/*
			 * Detection runs an extension filter, and a callback on it may well
			 * ask what the current language is. Answering that inner question
			 * with "none named yet" is what keeps it from asking forever.
			 */
			$this->resolved = '';
			$this->resolved = $this->detect();
		}

		return $this->resolved;
	}

	/**
	 * Reports whether the language filters that scope a page may run here.
	 *
	 * This is the single answer every query filter asks for, so a rendered page,
	 * an AJAX call made from one, and a REST call that asked for a language are
	 * all scoped by the same rule.
	 *
	 * A background request that named no language is left exactly as it was
	 * before: a REST collection nobody gave a language to still answers in every
	 * language, which is what an interface with no language in its contract has
	 * to keep doing.
	 *
	 * @return bool
	 */
	public function scopes_request() {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		if ( $this->is_background_request() ) {
			return '' !== $this->resolve_language_id();
		}

		return ! is_admin();
	}

	/**
	 * Reports whether this request is a page, or a piece one loaded for itself.
	 *
	 * Narrower than scopes_request(): the REST API is excluded. REST serves
	 * callers that are not pages, and several of the behaviours a page takes for
	 * granted are wrong there — rewriting the identifiers in a query to the
	 * current language answers a caller that asked for one record with a
	 * different one, and narrowing every term lookup makes `term_exists()` say
	 * no about a term that exists.
	 *
	 * @return bool
	 */
	public function scopes_rendered_page() {
		return ! $this->is_rest_request() && $this->scopes_request();
	}

	/**
	 * Reports whether WordPress is answering a REST request.
	 *
	 * @return bool
	 */
	public function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Works out the language of this request and lets extensions have the last word.
	 *
	 * @return string Language identifier, or empty when none was named.
	 */
	private function detect() {
		$language_id = $this->read_signals();

		/**
		 * Filters the language an AJAX or REST request is answered in.
		 *
		 * An integration that carries the language some other way — its own
		 * request argument, a header, a session — supplies it here. Returning an
		 * empty string leaves the request unscoped, which is what a request that
		 * names no language has always done.
		 *
		 * @param string $language_id Language identifier, or empty when none was named.
		 */
		$filtered = apply_filters( 'shapetranslate_background_request_language_id', $language_id );

		if ( ! is_scalar( $filtered ) || '' === (string) $filtered ) {
			return '';
		}

		return $this->url_manager->resolve_language_id( (string) $filtered );
	}

	/**
	 * Reads the language signals this request kind is allowed to carry.
	 *
	 * @return string Language identifier, or empty when none was named.
	 */
	private function read_signals() {

		if ( $this->is_rest_request() ) {
			return $this->get_argument_language_id(
				array( $this->url_manager->get_public_query_var(), LanguageUrlManager::QUERY_VAR )
			);
		}

		/*
		 * The plugin's own variable is unambiguous — nothing in WordPress or in
		 * the administration uses that name — so a call sending it means it,
		 * whatever else the call looks like. The public `lang` is not: the
		 * administration's own language filter is spelled the same way, and a
		 * screen filtered to one language sends it back with everything it does.
		 */
		$language_id = $this->get_argument_language_id( array( LanguageUrlManager::QUERY_VAR ) );

		if ( '' !== $language_id || $this->is_administration_call() ) {
			return $language_id;
		}

		$language_id = $this->get_argument_language_id( array( $this->url_manager->get_public_query_var() ) );

		return '' === $language_id ? $this->get_calling_page_language_id() : $language_id;
	}

	/**
	 * Returns the language named by one of the request's own arguments.
	 *
	 * `$_GET` and `$_POST` are read rather than `$_REQUEST` so the answer is the
	 * same before and after WordPress has assembled the latter: the locale is
	 * asked for while the default text domain loads, which is earlier than that.
	 *
	 * @param array<int, string> $names Argument names to read, in order.
	 * @return string
	 */
	private function get_argument_language_id( array $names ) {
		foreach ( $names as $name ) {
			foreach ( array( $_GET, $_POST ) as $source ) { // phpcs:ignore WordPress.Security.NonceVerification -- Read-only language lookup, no state is changed.
				if ( ! isset( $source[ $name ] ) || ! is_scalar( $source[ $name ] ) ) {
					continue;
				}

				$language_id = $this->url_manager->resolve_language_id( (string) wp_unslash( $source[ $name ] ) );

				if ( '' !== $language_id ) {
					return $language_id;
				}
			}
		}

		return '';
	}

	/**
	 * Returns the language of the page that made this call.
	 *
	 * A page that carries no language in its address is the default one — that
	 * is the answer the page itself was rendered with, and a fragment loaded
	 * into it has to agree with it. Reading that as "no language named" instead
	 * would leave the default language the one language whose pages cannot ask
	 * for anything, which is exactly backwards while it is the unprefixed one.
	 *
	 * @return string
	 */
	private function get_calling_page_language_id() {
		$referrer = $this->get_referrer();

		if ( '' === $referrer ) {
			return '';
		}

		$language_id = $this->url_manager->get_url_language_id( $referrer );

		if ( '' !== $language_id ) {
			return $language_id;
		}

		$default = $this->url_manager->get_default_language();

		return is_array( $default ) && isset( $default['id'] ) ? (string) $default['id'] : '';
	}

	/**
	 * Reports whether this call was made by an administration screen.
	 *
	 * @return bool
	 */
	private function is_administration_call() {
		// phpcs:ignore WordPress.Security.NonceVerification -- Presence check on a routing hint, no state is changed.
		if ( ! empty( $_GET[ self::BACKEND_ARGUMENT ] ) || ! empty( $_POST[ self::BACKEND_ARGUMENT ] ) ) {
			return true;
		}

		$referrer = $this->get_referrer();

		if ( '' === $referrer ) {
			/*
			 * A call with no referrer cannot be placed on either side, and the
			 * administration is the side where guessing wrong breaks a screen
			 * that has always worked. So it is treated as one. A caller that
			 * knows better says so by naming the language in the plugin's own
			 * argument, which is read before this is asked.
			 */
			return true;
		}

		$path = (string) wp_parse_url( $referrer, PHP_URL_PATH );

		return false !== strpos( $path, '/wp-admin/' ) || '/wp-login.php' === substr( $path, -13 );
	}

	/**
	 * Returns the address of the page that made this call.
	 *
	 * The referrer comes from wp_get_referer() rather than from the header
	 * directly, because that call validates the host: a referrer pointing
	 * somewhere else can never decide what language this site answers in.
	 *
	 * @return string
	 */
	private function get_referrer() {
		if ( ! function_exists( 'wp_get_referer' ) ) {
			return '';
		}

		$referrer = wp_get_referer();

		return is_string( $referrer ) ? $referrer : '';
	}
}
