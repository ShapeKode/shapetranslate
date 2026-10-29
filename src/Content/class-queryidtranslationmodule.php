<?php
/**
 * Frontend query identifier translation module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Post;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Answers a hard-coded post or term identifier with its translation.
 *
 * Themes, page builders, and widgets store the identifier of the thing an editor
 * picked: a featured page, a category to list, a set of posts to exclude. That
 * identifier names one language's record, because that is the language the site
 * was built in. Read back on a translated page it points at the wrong language,
 * and where a language constraint also applies it points at nothing at all — a
 * `post__in` naming English posts returns an empty German loop.
 *
 * This module rewrites those identifiers to the language being viewed, so code
 * that knows nothing about ShapeTranslate produces the right language anyway.
 *
 * Only identifiers are translated. Slugs and names stay exactly as they were
 * asked for: the router already maps the routes a visitor can request, and a
 * secondary query naming a slug is naming one specific record.
 *
 * Three rules keep it from changing an answer it should not:
 *
 * - The object's post type or taxonomy must be translatable.
 * - The object must have a language, and it must not already be the right one.
 * - A translation must exist. An untranslated identifier is left exactly as it
 *   is, so a query keeps the results it had before this module existed.
 */
final class QueryIdTranslationModule implements ModuleInterface {

	/**
	 * Query variable that opts one query out of identifier translation.
	 *
	 * @var string
	 */
	const SKIP_QUERY_VAR = 'shapetranslate_skip_id_translation';

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

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
	 * Constructor.
	 *
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 */
	public function __construct(
		LanguageUrlManager $url_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations
	) {
		$this->url_manager       = $url_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		// Another multilingual plugin that owns routing does this itself, and two
		// rewrites of the same identifier would fight over the answer.
		if ( ! $this->url_manager->is_frontend_routing_enabled() ) {
			return;
		}

		// Late, so a query that names its own language has already been built.
		add_action( 'parse_query', array( $this, 'translate_query_ids' ), 100 );
		add_filter( 'get_terms_args', array( $this, 'translate_term_query_ids' ), 20 );
	}

	/**
	 * Rewrites the post and term identifiers one query names.
	 *
	 * @param WP_Query $query Query being parsed.
	 * @return void
	 */
	public function translate_query_ids( $query ) {
		if (
			! $query instanceof WP_Query
			// Checked before anything else: most queries name no identifier at
			// all, and this runs for every one of them.
			|| ! $this->names_identifiers( $query->query_vars )
			|| ! $this->is_translatable_query( $query )
		) {
			return;
		}

		$language_id = $this->get_target_language_id( $query );

		if ( '' === $language_id ) {
			return;
		}

		$this->translate_post_query_vars( $query->query_vars, $language_id );
		$this->translate_term_query_vars( $query->query_vars, $language_id );
	}

	/**
	 * Rewrites the identifiers a term query includes or excludes.
	 *
	 * `wp_list_categories()` and the widgets built on it pass the identifiers a
	 * site owner chose, which have the same problem as the ones in a post query.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @return array<string, mixed>
	 */
	public function translate_term_query_ids( $args ) {
		if ( ! is_array( $args ) || ! $this->is_translatable_request() ) {
			return $args;
		}

		/*
		 * The same opt-outs a post query has. A term query that named several
		 * languages on purpose — the one that collects a term's own translations
		 * for the alternate links, most of all — is naming them because it wants
		 * them, and rewriting every identifier to the language being read leaves
		 * it holding the same term three times.
		 */
		if (
			! empty( $args['shapetranslate_skip_language_filter'] )
			|| ! empty( $args[ self::SKIP_QUERY_VAR ] )
		) {
			return $args;
		}

		/**
		 * Filters whether ShapeTranslate may translate the identifiers in one term query.
		 *
		 * The counterpart of `shapetranslate_translate_query_ids`, which answers the
		 * same question for a post query.
		 *
		 * @param bool                 $translate Whether identifiers should be translated.
		 * @param array<string, mixed> $args      Term query arguments.
		 */
		if ( ! apply_filters( 'shapetranslate_translate_term_query_ids', true, $args ) ) {
			return $args;
		}

		$language = $this->url_manager->get_current_language();

		if ( null === $language ) {
			return $args;
		}

		foreach ( array( 'include', 'exclude' ) as $key ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}

			$identifiers = wp_parse_id_list( $args[ $key ] );

			if ( empty( $identifiers ) ) {
				continue;
			}

			// The taxonomy is left for each term to answer: a term query may name
			// several, and an identifier belongs to exactly one of them.
			$args[ $key ] = $this->translate_term_ids( $identifiers, '', (string) $language['id'] );
		}

		return $args;
	}

	/**
	 * Reports whether a query names anything this module could rewrite.
	 *
	 * @param array<string, mixed> $query_vars Parsed query vars.
	 * @return bool
	 */
	private function names_identifiers( array $query_vars ) {
		foreach (
			array(
				'p',
				'page_id',
				'attachment_id',
				'post_parent',
				'post__in',
				'post__not_in',
				'post_parent__in',
				'post_parent__not_in',
				'cat',
				'tag_id',
				'category__and',
				'category__in',
				'category__not_in',
				'tag__and',
				'tag__in',
				'tag__not_in',
				'tax_query',
			) as $key
		) {
			if ( ! empty( $query_vars[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether one query may have its identifiers rewritten.
	 *
	 * @param WP_Query $query Query being parsed.
	 * @return bool
	 */
	private function is_translatable_query( WP_Query $query ) {
		if (
			! $this->is_translatable_request()

			/*
			 * The main query names what the visitor asked for, and the router has
			 * already decided which translation that route resolves to. This
			 * module exists for the identifiers a theme stored years ago, not for
			 * the one the address bar is holding, and answering the address bar
			 * with something else takes a reader somewhere they did not ask to go.
			 *
			 * A page builder preview is the clearest case. It names its document
			 * by identifier and then checks that the identifier it named is the
			 * one that came back; rewritten to a translation, the check fails and
			 * the editor waits for a preview that never arrives.
			 */
			|| $query->is_main_query()
			|| $query->get( 'suppress_filters' )
			|| $query->get( 'shapetranslate_skip_language_filter' )
			|| $query->get( self::SKIP_QUERY_VAR )

			/*
			 * A preview names the one post an editor is looking at, in whatever
			 * state it is in. Answering with its translation would show them
			 * somebody else's draft instead of their own.
			 */
			|| $query->get( 'preview' )
		) {
			return false;
		}

		/**
		 * Filters whether ShapeTranslate may translate the identifiers in one query.
		 *
		 * @param bool     $translate Whether identifiers should be translated.
		 * @param WP_Query $query     Query being parsed.
		 */
		return (bool) apply_filters( 'shapetranslate_translate_query_ids', true, $query );
	}

	/**
	 * Reports whether the current request context allows rewriting identifiers.
	 *
	 * @return bool
	 */
	private function is_translatable_request() {
		return $this->url_manager->background()->scopes_rendered_page();
	}

	/**
	 * Returns the language one query's identifiers must be rewritten to.
	 *
	 * A query that names a language wins, which is the same opt-in the language
	 * constraint honors: a builder asking for one language's header wants that
	 * language's identifiers too.
	 *
	 * @param WP_Query $query Query being parsed.
	 * @return string Empty when no language could be resolved.
	 */
	private function get_target_language_id( WP_Query $query ) {
		$requested = $query->get( LanguageUrlManager::QUERY_VAR );

		if (
			is_scalar( $requested )
			&& true !== $requested
			&& '' !== (string) $requested
			&& 'current' !== $requested
		) {
			$language = $this->url_manager->resolve_language( $requested );

			return null === $language ? '' : (string) $language['id'];
		}

		$current = $this->url_manager->get_current_language();

		return null === $current ? '' : (string) $current['id'];
	}

	/**
	 * Rewrites every post identifier a query names.
	 *
	 * @param array<string, mixed> $query_vars  Query vars, by reference.
	 * @param string               $language_id Target language identifier.
	 * @return void
	 */
	private function translate_post_query_vars( array &$query_vars, $language_id ) {
		foreach ( array( 'p', 'page_id', 'attachment_id', 'post_parent' ) as $key ) {
			if ( empty( $query_vars[ $key ] ) || ! is_scalar( $query_vars[ $key ] ) ) {
				continue;
			}

			$target = $this->translate_post_id( $query_vars[ $key ], $language_id );

			if ( 0 < $target ) {
				$query_vars[ $key ] = $target;
			}
		}

		foreach ( array( 'post__in', 'post__not_in', 'post_parent__in', 'post_parent__not_in' ) as $key ) {
			if ( empty( $query_vars[ $key ] ) || ! is_array( $query_vars[ $key ] ) ) {
				continue;
			}

			$identifiers = $query_vars[ $key ];
			$this->prime_posts( $identifiers );
			$translated = array();

			foreach ( $identifiers as $identifier ) {
				$target       = $this->translate_post_id( $identifier, $language_id );
				$translated[] = 0 < $target ? $target : $identifier;
			}

			$query_vars[ $key ] = $translated;
		}
	}

	/**
	 * Rewrites every term identifier a query names.
	 *
	 * @param array<string, mixed> $query_vars  Query vars, by reference.
	 * @param string               $language_id Target language identifier.
	 * @return void
	 */
	private function translate_term_query_vars( array &$query_vars, $language_id ) {
		// `cat` is a comma-separated list in which a leading minus excludes.
		if ( ! empty( $query_vars['cat'] ) && is_scalar( $query_vars['cat'] ) ) {
			$query_vars['cat'] = $this->translate_signed_term_list(
				(string) $query_vars['cat'],
				'category',
				$language_id
			);
		}

		if ( ! empty( $query_vars['tag_id'] ) && is_scalar( $query_vars['tag_id'] ) ) {
			$target = $this->translate_term_id( $query_vars['tag_id'], 'post_tag', $language_id );

			if ( 0 < $target ) {
				$query_vars['tag_id'] = $target;
			}
		}

		$taxonomies = array(
			'category__and'    => 'category',
			'category__in'     => 'category',
			'category__not_in' => 'category',
			'tag__and'         => 'post_tag',
			'tag__in'          => 'post_tag',
			'tag__not_in'      => 'post_tag',
		);

		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( empty( $query_vars[ $key ] ) || ! is_array( $query_vars[ $key ] ) ) {
				continue;
			}

			$query_vars[ $key ] = $this->translate_term_ids(
				$query_vars[ $key ],
				$taxonomy,
				$language_id
			);
		}

		/*
		 * Nothing here builds a taxonomy query. The one being read was written by
		 * WordPress, the theme, or whatever made the request, and this rewrites the
		 * term identifiers inside it to the current language so it selects the same
		 * terms rather than that language's absence of them. Leaving it alone would
		 * not make the query cheaper; it would make it return nothing.
		 */
		if ( ! empty( $query_vars['tax_query'] ) && is_array( $query_vars['tax_query'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Rewrites a caller's query; adds none.
			$query_vars['tax_query'] = $this->translate_tax_query( $query_vars['tax_query'], $language_id );
		}
	}

	/**
	 * Rewrites the identifiers inside a taxonomy query, including nested ones.
	 *
	 * @param array<mixed, mixed> $clauses     Taxonomy query clauses.
	 * @param string              $language_id Target language identifier.
	 * @return array<mixed, mixed>
	 */
	private function translate_tax_query( array $clauses, $language_id ) {
		foreach ( $clauses as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}

			if ( ! isset( $clause['taxonomy'], $clause['terms'] ) ) {
				$clauses[ $key ] = $this->translate_tax_query( $clause, $language_id );

				continue;
			}

			// A clause matching on a slug or a name is naming one specific record,
			// and the default field is the identifier this module translates.
			$field = isset( $clause['field'] ) && is_scalar( $clause['field'] )
				? (string) $clause['field']
				: 'term_id';

			if ( 'term_id' !== $field ) {
				continue;
			}

			$clauses[ $key ]['terms'] = $this->translate_term_ids(
				(array) $clause['terms'],
				(string) $clause['taxonomy'],
				$language_id
			);
		}

		return $clauses;
	}

	/**
	 * Rewrites a list of term identifiers, keeping untranslated ones as they are.
	 *
	 * @param array<int, mixed> $identifiers Term identifiers.
	 * @param string            $taxonomy    Taxonomy name, or empty to resolve it.
	 * @param string            $language_id Target language identifier.
	 * @return array<int, mixed>
	 */
	private function translate_term_ids( array $identifiers, $taxonomy, $language_id ) {
		$translated = array();

		foreach ( $identifiers as $identifier ) {
			$target       = $this->translate_term_id( $identifier, $taxonomy, $language_id );
			$translated[] = 0 < $target ? $target : $identifier;
		}

		return $translated;
	}

	/**
	 * Rewrites a comma-separated term list that may exclude with a leading minus.
	 *
	 * @param string $value       Stored list.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $language_id Target language identifier.
	 * @return string
	 */
	private function translate_signed_term_list( $value, $taxonomy, $language_id ) {
		$translated = array();

		foreach ( explode( ',', $value ) as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry ) {
				continue;
			}

			$term_id = absint( $entry );
			$target  = $this->translate_term_id( $term_id, $taxonomy, $language_id );
			$target  = 0 < $target ? $target : $term_id;

			$translated[] = 0 === strpos( $entry, '-' ) ? '-' . $target : (string) $target;
		}

		return empty( $translated ) ? $value : implode( ',', $translated );
	}

	/**
	 * Returns the translation of one post identifier.
	 *
	 * @param mixed  $post_id     Stored post identifier.
	 * @param string $language_id Target language identifier.
	 * @return int Zero when the identifier must be left as it is.
	 */
	private function translate_post_id( $post_id, $language_id ) {
		$post_id = absint( $post_id );

		if ( 1 > $post_id ) {
			return 0;
		}

		$post = get_post( $post_id );

		if (
			! $post instanceof WP_Post
			|| ! $this->post_translations->supports_post_type( $post->post_type )
		) {
			return 0;
		}

		$current = (string) $this->post_translations->get_post_language_id( $post_id );

		// Content with no language of its own belongs to every language equally,
		// and content already in the target language has nothing to translate to.
		if ( '' === $current || $current === $language_id ) {
			return 0;
		}

		return absint( $this->post_translations->get_translation( $post_id, $language_id ) );
	}

	/**
	 * Returns the translation of one term identifier.
	 *
	 * @param mixed  $term_id     Stored term identifier.
	 * @param string $taxonomy    Taxonomy name, or empty to resolve it.
	 * @param string $language_id Target language identifier.
	 * @return int Zero when the identifier must be left as it is.
	 */
	private function translate_term_id( $term_id, $taxonomy, $language_id ) {
		$term_id = absint( $term_id );

		if ( 1 > $term_id ) {
			return 0;
		}

		$term = get_term( $term_id, $taxonomy );

		if (
			! $term instanceof WP_Term
			|| ! $this->term_translations->supports_taxonomy( $term->taxonomy )
		) {
			return 0;
		}

		$current = (string) $this->term_translations->get_term_language_id( $term->term_id, $term->taxonomy );

		if ( '' === $current || $current === $language_id ) {
			return 0;
		}

		return absint(
			$this->term_translations->get_translation( $term->term_id, $term->taxonomy, $language_id )
		);
	}

	/**
	 * Loads a list of posts and their relationships in one pass.
	 *
	 * A query naming twenty posts would otherwise ask for each one separately,
	 * twice: once for the post and once for the language it is assigned.
	 *
	 * @param array<int, mixed> $identifiers Post identifiers.
	 * @return void
	 */
	private function prime_posts( array $identifiers ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $identifiers ) ) ) );

		if ( 2 > count( $post_ids ) ) {
			return;
		}

		_prime_post_caches( $post_ids, false, false );
		$this->post_translations->prime_posts( $post_ids );
	}
}
