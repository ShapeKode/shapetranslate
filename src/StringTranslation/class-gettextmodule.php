<?php
/**
 * Translates theme and plugin text through gettext.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\CurrentLanguageResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Answers gettext with the translations entered in String Translation.
 *
 * Only the per-domain filters are used, and only for domains that have a
 * translation, so a site that never translated a theme string pays nothing
 * and every other text domain is never seen. A stored translation replaces
 * whatever the theme's own language file says; text with none is left alone.
 */
final class GettextModule implements ModuleInterface {

	/**
	 * Gettext string storage.
	 *
	 * @var GettextStrings
	 */
	private $strings;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private $resolver;

	/**
	 * Translations of the language last resolved, keyed by domain and key.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private $map = null;

	/**
	 * Whether the language is being resolved, which can itself call gettext.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * Constructor.
	 *
	 * @param GettextStrings          $strings  Gettext string storage.
	 * @param CurrentLanguageResolver $resolver Current language resolver.
	 */
	public function __construct( GettextStrings $strings, CurrentLanguageResolver $resolver ) {
		$this->strings  = $strings;
		$this->resolver = $resolver;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'shapetranslate_string_translation_saved', array( $this, 'refresh_after_change' ), 10, 5 );
		add_action( 'shapetranslate_string_translation_deleted', array( $this, 'refresh_after_change' ), 10, 3 );
		add_action( 'shapetranslate_language_deleted', array( $this->strings, 'refresh' ) );

		// Screens in the dashboard speak the user's own language.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// The language is known from the URL early, but a request can still
		// change it later, by its route or by switching locale.
		add_action( 'parse_request', array( $this, 'reset' ), 100 );
		add_action( 'change_locale', array( $this, 'reset' ) );

		foreach ( GettextStrings::get_domains() as $domain ) {
			add_filter( 'gettext_' . $domain, array( $this, 'translate' ), 20, 3 );
			add_filter( 'gettext_with_context_' . $domain, array( $this, 'translate_with_context' ), 20, 4 );
			add_filter( 'ngettext_' . $domain, array( $this, 'translate_plural' ), 20, 5 );
			add_filter( 'ngettext_with_context_' . $domain, array( $this, 'translate_plural_with_context' ), 20, 6 );
		}
	}

	/**
	 * Recounts translated domains after a gettext string translation changes.
	 *
	 * @param string $string_id   String ID.
	 * @param string $language_id Language ID.
	 * @param mixed  $value       Saved or removed value.
	 * @param mixed  $current     Previous value, on save.
	 * @param mixed  $definition  String definition, on save.
	 * @return void
	 */
	public function refresh_after_change( $string_id, $language_id, $value, $current = null, $definition = null ) {
		unset( $string_id, $language_id, $value, $current );

		// A deletion carries no definition, so it always recounts.
		if ( is_array( $definition ) && ! GettextStrings::is_group( isset( $definition['string_group'] ) ? $definition['string_group'] : '' ) ) {
			return;
		}

		$this->strings->refresh();
		$this->reset();
	}

	/**
	 * Forgets the loaded translations so the next lookup resolves the language again.
	 *
	 * @return void
	 */
	public function reset() {
		$this->map = null;
	}

	/**
	 * Translates a text.
	 *
	 * @param string $translation Translation so far.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate( $translation, $text, $domain ) {
		return $this->lookup( $domain, (string) $text, '', $translation );
	}

	/**
	 * Translates a text with a context.
	 *
	 * @param string $translation Translation so far.
	 * @param string $text        Original text.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_with_context( $translation, $text, $context, $domain ) {
		return $this->lookup( $domain, (string) $text, (string) $context, $translation );
	}

	/**
	 * Translates a singular or plural text.
	 *
	 * The singular and the plural are stored as two strings, and the count
	 * picks between them the way English does.
	 *
	 * @param string $translation Translation so far.
	 * @param string $single      Singular text.
	 * @param string $plural      Plural text.
	 * @param int    $number      Count.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_plural( $translation, $single, $plural, $number, $domain ) {
		return $this->lookup( $domain, 1 === (int) $number ? (string) $single : (string) $plural, '', $translation );
	}

	/**
	 * Translates a singular or plural text with a context.
	 *
	 * @param string $translation Translation so far.
	 * @param string $single      Singular text.
	 * @param string $plural      Plural text.
	 * @param int    $number      Count.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_plural_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		return $this->lookup( $domain, 1 === (int) $number ? (string) $single : (string) $plural, (string) $context, $translation );
	}

	/**
	 * Returns the stored translation of a text, or the one gettext already has.
	 *
	 * @param string $domain      Text domain.
	 * @param string $text        Original text.
	 * @param string $context     Context.
	 * @param string $translation Translation so far.
	 * @return string
	 */
	private function lookup( $domain, $text, $context, $translation ) {
		$map = $this->map();

		if ( ! isset( $map[ $domain ] ) ) {
			return $translation;
		}

		$key = GettextStrings::key( $text, $context );

		return isset( $map[ $domain ][ $key ] ) ? $map[ $domain ][ $key ] : $translation;
	}

	/**
	 * Loads the current language's translations once.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function map() {
		if ( null !== $this->map ) {
			return $this->map;
		}

		if ( $this->resolving ) {
			return array();
		}

		$this->resolving = true;

		try {
			$language = $this->resolver->resolve();
		} finally {
			$this->resolving = false;
		}

		$this->map = is_array( $language ) && ! empty( $language['id'] )
			? $this->strings->get_map( (string) $language['id'] )
			: array();

		return $this->map;
	}
}
