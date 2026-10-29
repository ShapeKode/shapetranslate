<?php
/**
 * WordPress locale switching module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;

defined( 'ABSPATH' ) || exit;

/**
 * Makes WordPress itself run in the language the request is answering in.
 *
 * Translating content is not enough for a page to read as one language. Theme
 * and plugin strings, date and number formatting, text direction, and the
 * document language WordPress prints all derive from `get_locale()`, so a
 * request in a language has to answer with that language's locale.
 *
 * The language is the one detection resolved for the request, and not a second
 * opinion about it: the address under a language prefix, the language an AJAX
 * or REST call named because a fragment belongs to the page it joins, the
 * language of the document a preview is showing, and, where none of those
 * settle it, the default the site answers its bare address in. That is the
 * same answer the content query, the document language attributes, and the
 * switcher are all already built on, so a locale taken from anywhere else is
 * how a page ends up serving one language's posts in another language's theme
 * strings, dates, and text direction.
 *
 * Administration screens, login, cron, and CLI resolve no language of their
 * own, so they keep the site locale without needing a guard here.
 */
final class LocaleModule implements ModuleInterface {

	/**
	 * Guards against a filter callback re-entering locale resolution.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * Locale resolved for this request, or an empty string when none applies.
	 *
	 * @var string|null
	 */
	private $resolved;

	/**
	 * Locale this module last handed WordPress, or null before the first call.
	 *
	 * @var string|null
	 */
	private $applied = null;

	/**
	 * Locale answered while refresh_locale() switches away from it.
	 *
	 * @var string|null
	 */
	private $holding = null;

	/**
	 * Language URL API.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager $url_manager Language URL API.
	 */
	public function __construct( LanguageUrlManager $url_manager ) {
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		/*
		 * WordPress loads the default text domain after `plugins_loaded`, and
		 * themes and plugins load theirs later still, so registering here is
		 * early enough for every normal text domain.
		 */
		add_filter( 'locale', array( $this, 'filter_locale' ), 20 );

		/*
		 * Any code can write an email or other output in another language with
		 * switch_to_locale(), as WordPress itself does for a new user or a
		 * password reset. ShapeTranslate strings and translated options follow the
		 * same switch, so such output reads as one language end to end.
		 */
		add_filter( 'shapetranslate_current_language_id', array( $this, 'follow_switched_locale' ), 900, 2 );
		add_filter( 'shapetranslate_translate_option', array( $this, 'translate_options_when_switched' ) );

		/*
		 * WordPress writes to a user in the user's own locale, such as the
		 * welcome and password reset emails, and reads it from the profile.
		 */
		add_action( 'user_register', array( $this, 'save_user_locale' ) );

		/*
		 * A REST call can name its language in a body, which cannot be read
		 * until the request is dispatched — long after the text domains were
		 * loaded. When that happens the locale has to be resolved again.
		 */
		add_action( 'shapetranslate_request_language_changed', array( $this, 'refresh_locale' ) );

		/*
		 * Before `$wp_locale` is built, which is what reads the global and
		 * what `is_rtl()` answers from. `setup_theme` is the last hook that
		 * still runs ahead of it.
		 */
		add_action( 'setup_theme', array( $this, 'apply_text_direction' ), 20 );
		add_action( 'shapetranslate_request_language_changed', array( $this, 'apply_text_direction' ), 20 );
	}

	/**
	 * Replaces the site locale with the request language's locale.
	 *
	 * @param string|mixed $locale Locale WordPress resolved.
	 * @return string|mixed
	 */
	public function filter_locale( $locale ) {
		/*
		 * An explicit switch_to_locale() is a caller saying which language the
		 * next piece of output is in, such as an email sent in its customer's
		 * language from a request made in another. The switcher answers this
		 * filter before it reaches here, and overriding it with the request's
		 * language would undo the switch while leaving it on the stack.
		 *
		 * The switcher is read directly rather than through is_locale_switched():
		 * the locale is asked for before WordPress creates the switcher, and that
		 * function assumes it exists.
		 */
		if (
			isset( $GLOBALS['wp_locale_switcher'] )
			&& $GLOBALS['wp_locale_switcher'] instanceof \WP_Locale_Switcher
			&& $GLOBALS['wp_locale_switcher']->is_switched()
		) {
			$this->applied = is_string( $locale ) ? $locale : '';

			return $locale;
		}

		if ( null !== $this->holding ) {
			return $this->holding;
		}

		$resolved = $this->resolve_request_locale();

		if ( '' === $resolved ) {
			$this->applied = is_string( $locale ) ? $locale : '';

			return $locale;
		}

		/**
		 * Filters the locale ShapeTranslate applies to a prefixed frontend request.
		 *
		 * Returning an empty string restores the locale WordPress resolved.
		 *
		 * @param string       $resolved Locale for the request language.
		 * @param string|mixed $locale   Locale WordPress resolved.
		 */
		$filtered = apply_filters( 'shapetranslate_request_locale', $resolved, $locale );
		$applied  = is_string( $filtered ) && '' !== $filtered ? $filtered : $locale;

		$this->applied = is_string( $applied ) ? $applied : '';

		return $applied;
	}

	/**
	 * Resolves the locale again after the request changed language mid-flight.
	 *
	 * Answering `get_locale()` differently from here on is not enough on its
	 * own: the text domains WordPress, the theme, and every plugin loaded are
	 * already in memory in the locale that was current when they were read.
	 * Switching is what reloads them, so the strings a REST call returns are
	 * written in the language it asked for rather than the one the site was
	 * booted in.
	 *
	 * @return void
	 */
	public function refresh_locale() {
		$previous       = $this->applied;
		$this->resolved = null;
		$this->applied  = null;

		/*
		 * Nothing has asked for a locale yet, so nothing has been loaded in the
		 * wrong one and the next caller resolves this for itself.
		 */
		if ( null === $previous || ! did_action( 'init' ) ) {
			return;
		}

		$resolved = $this->resolve_request_locale();

		$target = '' === $resolved ? get_locale() : $resolved;

		if ( ! is_string( $target ) || '' === $target || $target === $previous ) {
			return;
		}

		/*
		 * switch_to_locale() does nothing when the locale it is asked for is
		 * already the current one, and this filter answers with the new
		 * language as soon as the request changes, so the switch would be
		 * refused and the text domains left in the old language. The locale
		 * the text domains were loaded in is answered until the switch has
		 * happened.
		 */
		$this->holding = $previous;

		try {
			switch_to_locale( $target );
		} finally {
			$this->holding = null;
		}
	}

	/**
	 * Makes `is_rtl()` answer for the configured language, not for a .mo string.
	 *
	 * WordPress derives text direction from a string inside the locale's own
	 * translation file: `WP_Locale::init()` reads `_x( 'ltr', 'text direction' )`
	 * unless `$GLOBALS['text_direction']` already says otherwise. That makes the
	 * direction depend on whether the translation pack happens to be installed,
	 * which is not something a site's language list should be at the mercy of —
	 * an Arabic language with no `ar` pack would print `dir="rtl"` from the
	 * language record while `is_rtl()` answered false, and the theme's
	 * `-rtl.css` would never load.
	 *
	 * Announcing the direction the language was configured with settles that
	 * ahead of `WP_Locale`, and every consumer follows from there: `is_rtl()`,
	 * the RTL stylesheet each handle registers, and whatever the theme asks.
	 *
	 * Which language that is comes from resolve_request_language() — the same
	 * call filter_locale() switches on — so `is_rtl()`, the locale, and the
	 * `dir` the document prints always describe one language.
	 *
	 * @return void
	 */
	public function apply_text_direction() {
		$language = $this->resolve_request_language();

		if ( null === $language ) {
			return;
		}

		$direction = LanguageTag::direction( $language );

		// Writing this global is what this module exists to do; WordPress reads it back for RTL.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['text_direction'] = $direction;

		/*
		 * A locale object built before this ran — which is every request that
		 * changed language after `init`, and any `switch_to_locale()` since —
		 * read the global at construction and will not read it again.
		 */
		if ( isset( $GLOBALS['wp_locale'] ) && $GLOBALS['wp_locale'] instanceof \WP_Locale ) {
			$GLOBALS['wp_locale']->text_direction = $direction;
		}

		/*
		 * Only if the styles registry already exists. Asking for it before
		 * `wp_default_styles` has run would build it early, and it is that hook
		 * which copies the direction across for a registry built later.
		 */
		if ( did_action( 'wp_default_styles' ) ) {
			wp_styles()->text_direction = $direction;
		}
	}

	/**
	 * Returns the language this request is being answered in, if any.
	 *
	 * The one place the question is asked. Both the locale and the text
	 * direction are read from what this returns, so neither can describe a
	 * language the other does not — which is the whole reason it exists as a
	 * method rather than as two conditions that happen to look alike.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_request_language() {
		if ( $this->resolving || ! $this->is_switchable_request() ) {
			return null;
		}

		/*
		 * Language lookup reads options and runs extension filters, either of
		 * which may call get_locale() again. The guard above answers that inner
		 * call with the unfiltered locale instead of recursing.
		 */
		$this->resolving = true;

		try {
			$language = $this->url_manager->get_current_language();
		} finally {
			$this->resolving = false;
		}

		return is_array( $language ) ? $language : null;
	}

	/**
	 * Returns the locale this request should run in, resolved once.
	 *
	 * A language whose locale is missing or malformed leaves WordPress on the
	 * site locale: a translated page in the site's own language is a smaller
	 * failure than one in a locale that names no translation file at all.
	 *
	 * @return string Empty when the site locale must be left alone.
	 */
	private function resolve_request_locale() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		if ( $this->resolving ) {
			return '';
		}

		$language = $this->resolve_request_language();

		$locale = null !== $language && isset( $language['locale'] ) && is_scalar( $language['locale'] )
			? trim( (string) $language['locale'] )
			: '';

		$this->resolved = preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,12}){0,3}$/', $locale ) ? $locale : '';

		return $this->resolved;
	}

	/**
	 * Answers the language of a locale that was switched to with switch_to_locale().
	 *
	 * Runs late, so it only decides when nothing else has declared a language.
	 * A locale no enabled language uses leaves the answer as it was.
	 *
	 * @param string                         $language_id Language identifier resolved so far.
	 * @param array<int, array<string,mixed>> $languages   Enabled languages.
	 * @return string
	 */
	public function follow_switched_locale( $language_id, $languages = array() ) {
		if ( ! $this->is_locale_switched() ) {
			return $language_id;
		}

		$locale = get_locale();

		foreach ( (array) $languages as $language ) {
			if ( is_array( $language ) && isset( $language['id'], $language['locale'] ) && is_scalar( $language['locale'] ) && 0 === strcasecmp( $locale, (string) $language['locale'] ) ) {
				return (string) $language['id'];
			}
		}

		return $language_id;
	}

	/**
	 * Remembers the language a visitor registered in as the user's locale.
	 *
	 * WordPress sends a user's emails in the 'locale' user meta, so a visitor who
	 * registered on a translated page reads the welcome and password reset emails
	 * in that language. An account created in wp-admin, or one that works in the
	 * dashboard, says nothing about the language of its owner and is left alone.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function save_user_locale( $user_id ) {
		$user_id = absint( $user_id );

		if ( 0 === $user_id || ( is_admin() && ! wp_doing_ajax() ) || user_can( $user_id, 'edit_posts' ) ) {
			return;
		}

		if ( '' !== (string) get_user_meta( $user_id, 'locale', true ) ) {
			return;
		}

		$locale = $this->resolve_request_locale();

		if ( '' !== $locale ) {
			update_user_meta( $user_id, 'locale', $locale );
		}
	}

	/**
	 * Lets declared options be translated for output written in a switched locale.
	 *
	 * Without this, an email sent from wp-admin would read the stored original.
	 *
	 * @param bool $translate Whether the option is translated.
	 * @return bool
	 */
	public function translate_options_when_switched( $translate ) {
		return $this->is_locale_switched() ? true : (bool) $translate;
	}

	/**
	 * Reports whether code has switched the locale with switch_to_locale().
	 *
	 * The switcher is read directly, because it does not exist until WordPress
	 * creates it.
	 *
	 * @return bool
	 */
	private function is_locale_switched() {
		return isset( $GLOBALS['wp_locale_switcher'] )
			&& $GLOBALS['wp_locale_switcher'] instanceof \WP_Locale_Switcher
			&& $GLOBALS['wp_locale_switcher']->is_switched();
	}

	/**
	 * Reports whether this request may run in a translated locale.
	 *
	 * @return bool
	 */
	private function is_switchable_request() {
		$allowed = $this->url_manager->supports_language_prefixes()
			&& $this->url_manager->background()->scopes_request();

		/**
		 * Filters whether ShapeTranslate may switch the WordPress locale.
		 *
		 * @param bool $allowed Whether the locale may be switched.
		 */
		return (bool) apply_filters( 'shapetranslate_should_switch_locale', $allowed );
	}
}
