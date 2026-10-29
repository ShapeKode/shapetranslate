<?php
/**
 * Language-aware search form.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Routing;

use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a search submitted from a translated page inside that language.
 *
 * Search results are already constrained by the request language, but the form
 * WordPress renders always submits to the bare site root. Submitting from a
 * translated page would therefore drop the language and search the default one
 * instead. Pointing the form at the current language's own root restores it.
 */
final class SearchFormModule implements ModuleInterface {

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

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
		// Late, so a theme's own get_search_form output is the string being fixed.
		add_filter( 'get_search_form', array( $this, 'localize_search_form' ), 99 );
		add_filter( 'render_block_core/search', array( $this, 'localize_search_form' ), 99 );
	}

	/**
	 * Points one rendered search form at the current language.
	 *
	 * @param string $form Rendered search form markup.
	 * @return string
	 */
	public function localize_search_form( $form ) {
		if ( ! is_string( $form ) || '' === trim( $form ) || is_admin() ) {
			return $form;
		}

		if ( ! $this->url_manager->is_frontend_routing_enabled() ) {
			return $form;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return $form;
		}

		$default = $this->url_manager->get_default_language();

		/*
		 * The default language on an unprefixed site already submits to the right
		 * place, and rewriting the action there would only add a redirect.
		 */
		if (
			! $this->url_manager->uses_host_routing()
			&& ! $this->url_manager->should_prefix_default_language()
			&& null !== $default
			&& $language['id'] === $default['id']
		) {
			return $form;
		}

		/**
		 * Filters the action a localized search form submits to.
		 *
		 * @param string               $url      Language home URL.
		 * @param array<string, mixed> $language Current language record.
		 */
		$url = apply_filters(
			'shapetranslate_search_form_action',
			$this->url_manager->get_language_home_url( $language ),
			$language
		);

		if ( ! is_string( $url ) || '' === $url ) {
			return $form;
		}

		/*
		 * A GET form submits its own fields to the action's path and discards any
		 * query string already on it, so query routing cannot put the language
		 * there: rewriting the action would silently drop it on submit.
		 */
		$rewritten = $this->url_manager->supports_language_prefixes()
			&& ! $this->url_manager->uses_query_routing()
			? $this->replace_action( $form, $url )
			: '';

		/*
		 * Without a rewritable action — plain permalinks, or markup this could not
		 * parse — the language travels as the public query variable instead. The
		 * URL is less tidy, but the search still lands in the right language.
		 */
		return '' === $rewritten ? $this->append_language_field( $form, $language ) : $rewritten;
	}

	/**
	 * Replaces the action attribute of the first form tag.
	 *
	 * @param string $form Rendered form markup.
	 * @param string $url  Replacement action URL.
	 * @return string Empty when no form action could be replaced.
	 */
	private function replace_action( $form, $url ) {
		if ( ! preg_match( '#<form[^>]*>#i', $form, $matches ) ) {
			return '';
		}

		$open     = $matches[0];
		$replaced = preg_replace(
			'#\saction=("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
			' action="' . esc_url( $url ) . '"',
			$open,
			1,
			$count
		);

		if ( ! is_string( $replaced ) || 1 !== $count ) {
			return '';
		}

		$position = strpos( $form, $open );

		return false === $position
			? ''
			: substr_replace( $form, $replaced, $position, strlen( $open ) );
	}

	/**
	 * Adds the language as a hidden field on the first form.
	 *
	 * @param string               $form     Rendered form markup.
	 * @param array<string, mixed> $language Current language record.
	 * @return string
	 */
	private function append_language_field( $form, $language ) {
		/*
		 * Query routing names the language with the same argument its URLs carry,
		 * so a submitted search reads as the address a visitor could have typed.
		 * Every other mode uses the unambiguous internal variable.
		 */
		$variable = $this->url_manager->uses_query_routing()
			? $this->url_manager->get_public_query_var()
			: LanguageUrlManager::QUERY_VAR;

		if ( false !== strpos( $form, 'name="' . $variable . '"' ) ) {
			return $form;
		}

		$position = strripos( $form, '</form>' );

		if ( false === $position ) {
			return $form;
		}

		$field = sprintf(
			'<input type="hidden" name="%1$s" value="%2$s" />',
			esc_attr( $variable ),
			esc_attr( $language['url_slug'] )
		);

		return substr_replace( $form, $field, $position, 0 );
	}
}
