<?php
/**
 * Language constraint for frontend comment queries.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Comment_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Limits comment listings to comments on content in the current language.
 *
 * A comment carries no language of its own; it inherits the language of the post
 * it belongs to. A listing already scoped to specific posts is therefore already
 * scoped to their languages, and only the open-ended listings — recent comments,
 * comment feeds, archives — need constraining.
 */
final class CommentLanguageModule implements ModuleInterface {

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
	private $translation_manager;

	/**
	 * Shared language query constraint.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager     $url_manager         Language URL service.
	 * @param PostTranslationManager $translation_manager Post translation manager.
	 */
	public function __construct(
		LanguageUrlManager $url_manager,
		PostTranslationManager $translation_manager
	) {
		$this->url_manager         = $url_manager;
		$this->translation_manager = $translation_manager;
		$this->constraint          = new LanguageQueryConstraint();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'parse_comment_query', array( $this, 'separate_language_cache' ) );
		add_filter( 'comments_clauses', array( $this, 'filter_comments_by_language' ), 10, 2 );
	}

	/**
	 * Gives each language its own comment cache bucket.
	 *
	 * WordPress caches comment queries by their arguments, and the request
	 * language is not one of them. Without a distinct domain the first language
	 * to run a query would answer it for every other language too.
	 *
	 * @param WP_Comment_Query $query Comment query.
	 * @return void
	 */
	public function separate_language_cache( $query ) {
		if ( ! $query instanceof WP_Comment_Query ) {
			return;
		}

		$language = $this->get_query_language( $query );

		if ( null === $language ) {
			return;
		}

		$domain = isset( $query->query_vars['cache_domain'] ) && is_scalar( $query->query_vars['cache_domain'] )
			? (string) $query->query_vars['cache_domain']
			: 'core';

		$query->query_vars['cache_domain'] = $domain . '_shapetranslate_' . $language['id'];
	}

	/**
	 * Restricts an open-ended comment listing to the current language.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Comment_Query      $query   Comment query.
	 * @return array<string, string>
	 */
	public function filter_comments_by_language( $clauses, $query ) {
		global $wpdb;

		if ( ! is_array( $clauses ) || ! isset( $clauses['join'], $clauses['where'] ) ) {
			return $clauses;
		}

		$language = $this->get_query_language( $query );
		$default  = $this->url_manager->get_default_language();

		if ( null === $language || null === $default ) {
			return $clauses;
		}

		/**
		 * Filters whether ShapeTranslate constrains one comment query by language.
		 *
		 * @param bool                 $filter   Whether the query should be constrained.
		 * @param WP_Comment_Query     $query    Comment query.
		 * @param array<string, mixed> $language Current language record.
		 */
		if ( ! apply_filters( 'shapetranslate_filter_comments_by_language', true, $query, $language ) ) {
			return $clauses;
		}

		/*
		 * The assignment join hangs off the posts table, which a comment query
		 * only joins when it needs post columns. Matching the pattern WordPress
		 * itself produces avoids joining the same table twice.
		 */
		if ( ! preg_match( "#JOIN\s+{$wpdb->posts}\s+ON#i", $clauses['join'] ) ) {
			
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clauses['join'] .= " JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->comments}.comment_post_ID";
		}

		if ( '' === trim( $clauses['where'] ) ) {
			$clauses['where'] = '1=1';
		}

		return $this->constraint->apply_to_posts( $clauses, $language['id'], $default['id'] );
	}

	/**
	 * Returns the language one comment query should be limited to.
	 *
	 * @param mixed $query Comment query.
	 * @return array<string, mixed>|null Null when the query must not be filtered.
	 */
	private function get_query_language( $query ) {
		if (
			! $query instanceof WP_Comment_Query
			|| ! $this->url_manager->background()->scopes_rendered_page()
			|| ! $this->url_manager->is_frontend_routing_enabled()
		) {
			return null;
		}

		$vars = is_array( $query->query_vars ) ? $query->query_vars : array();

		/*
		 * A listing already tied to specific posts or comments carries its own
		 * language with it. Constraining it again could only remove rows the
		 * caller asked for by ID — a single post's comment thread, a reply chain,
		 * a moderation lookup.
		 */
		foreach ( array( 'comment__in', 'parent', 'post_id', 'post__in', 'post_parent' ) as $scoped ) {
			if ( ! empty( $vars[ $scoped ] ) ) {
				return null;
			}
		}

		if ( ! empty( $vars['post_type'] ) ) {
			foreach ( (array) $vars['post_type'] as $post_type ) {
				if ( ! $this->translation_manager->supports_post_type( (string) $post_type ) ) {
					return null;
				}
			}
		}

		return $this->url_manager->get_current_language();
	}
}
