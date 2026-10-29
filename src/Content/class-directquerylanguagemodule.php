<?php
/**
 * Language scope for the pages WordPress answers without WP_Query.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Post;
use WP_Widget_Calendar;

defined( 'ABSPATH' ) || exit;

/**
 * Holds WordPress's hand-written post queries to the language being read.
 *
 * Almost everything on a page runs through WP_Query, and `posts_clauses` holds
 * all of it to one language. Three things do not: the previous and next post
 * links, the archive list, and the calendar each build their own SQL against
 * the posts table. Left alone they answer from every language at once — a
 * Bengali post followed by an English one, a month that claims twelve posts and
 * shows four, a calendar with dates linking to nothing.
 */
final class DirectQueryLanguageModule implements ModuleInterface {

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Shared SQL builder.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Whether a calendar is being built right now.
	 *
	 * @var bool
	 */
	private $building_calendar = false;

	/**
	 * Whether the `query` filter is attached at this moment.
	 *
	 * @var bool
	 */
	private $reading_queries = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct( LanguageUrlManager $url_manager, PostTranslationManager $post_translations ) {
		$this->url_manager       = $url_manager;
		$this->post_translations = $post_translations;
		$this->constraint        = new LanguageQueryConstraint();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'get_previous_post_join', array( $this, 'filter_adjacent_join' ), 10, 5 );
		add_filter( 'get_next_post_join', array( $this, 'filter_adjacent_join' ), 10, 5 );
		add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_where' ), 10, 5 );
		add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_where' ), 10, 5 );

		add_filter( 'getarchives_join', array( $this, 'filter_archives_join' ), 10, 2 );
		add_filter( 'getarchives_where', array( $this, 'filter_archives_where' ), 10, 2 );

		/*
		 * The calendar takes none of this. It runs four queries of its own with
		 * no filter on any of them, so the only way in is to know when it is
		 * running and read the SQL on its way to the database.
		 *
		 * Only the three hooks that mark the window are registered here. The
		 * `query` filter is not: it is the one hook in this plugin that every
		 * statement the site runs would pass through, and a filter that reads
		 * all of them to act on four belongs attached for those four alone. It
		 * is added when a calendar opens and removed when it closes.
		 */
		add_filter( 'get_calendar_args', array( $this, 'open_calendar' ) );
		add_filter( 'pre_render_block', array( $this, 'open_calendar_block' ), 10, 2 );
		add_filter( 'widget_display_callback', array( $this, 'open_calendar_widget' ), 10, 2 );
		add_filter( 'get_calendar', array( $this, 'close_calendar' ) );
	}

	/**
	 * Joins the language assignment onto an adjacent post lookup.
	 *
	 * @param string       $join           Existing join clause.
	 * @param bool         $in_same_term   Whether the adjacent post shares a term.
	 * @param int[]|string $excluded_terms Terms to exclude.
	 * @param string       $taxonomy       Taxonomy the term belongs to.
	 * @param WP_Post      $post           Post the lookup starts from.
	 * @return string
	 */
	public function filter_adjacent_join( $join, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		unset( $in_same_term, $excluded_terms, $taxonomy );

		if ( ! is_string( $join ) || '' === $this->adjacent_language( $post ) ) {
			return $join;
		}

		if ( false !== strpos( $join, LanguageQueryConstraint::POST_ALIAS ) ) {
			return $join;
		}

		// get_adjacent_post() selects `FROM $wpdb->posts AS p`, so the join has
		// to name the alias rather than the table.
		return $join . $this->constraint->posts_join_clause( 'p' );
	}

	/**
	 * Holds an adjacent post lookup to the language being read.
	 *
	 * @param string       $where          Existing where clause.
	 * @param bool         $in_same_term   Whether the adjacent post shares a term.
	 * @param int[]|string $excluded_terms Terms to exclude.
	 * @param string       $taxonomy       Taxonomy the term belongs to.
	 * @param WP_Post      $post           Post the lookup starts from.
	 * @return string
	 */
	public function filter_adjacent_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		unset( $in_same_term, $excluded_terms, $taxonomy );

		$language_id = $this->adjacent_language( $post );

		if ( ! is_string( $where ) || '' === $language_id ) {
			return $where;
		}

		return $where . $this->constraint->posts_where_clause( $language_id, $this->default_language_id() );
	}

	/**
	 * Returns the language an adjacent post lookup should be held to.
	 *
	 * The language comes from the post the reader is on rather than from the
	 * request, because that post is what "next" and "previous" are relative to.
	 * A post carrying no language leaves the links as WordPress built them.
	 *
	 * @param mixed $post Post the lookup starts from.
	 * @return string Empty when the links should be left alone.
	 */
	private function adjacent_language( $post ) {
		if ( ! $this->filters_frontend() || ! $post instanceof WP_Post ) {
			return '';
		}

		if ( ! $this->post_translations->supports_post_type( $post->post_type ) ) {
			return '';
		}

		$language_id = (string) $this->post_translations->get_post_language_id( $post->ID );

		if ( '' === $language_id ) {
			$language_id = $this->current_language_id();
		}

		/**
		 * Filters the language the previous and next post links are held to.
		 *
		 * Returning an empty string leaves them reaching every language, which
		 * is what a site wants where one sequence of posts runs through all of
		 * them.
		 *
		 * @param string  $language_id Language the links are limited to.
		 * @param WP_Post $post        Post the links are relative to.
		 */
		$filtered = apply_filters( 'shapetranslate_adjacent_post_language', $language_id, $post );

		return is_string( $filtered ) ? $filtered : $language_id;
	}

	/**
	 * Joins the language assignment onto the archive list query.
	 *
	 * @param string               $join Existing join clause.
	 * @param array<string, mixed> $args Parsed wp_get_archives() arguments.
	 * @return string
	 */
	public function filter_archives_join( $join, $args ) {
		if ( ! is_string( $join ) || '' === $this->archives_language( $args ) ) {
			return $join;
		}

		if ( false !== strpos( $join, LanguageQueryConstraint::POST_ALIAS ) ) {
			return $join;
		}

		return $join . $this->constraint->posts_join_clause();
	}

	/**
	 * Holds the archive list to the language being read.
	 *
	 * The counts beside each month are the reason this matters: an unfiltered
	 * list offers "January (12)" and the archive behind it shows four, because
	 * the archive itself is held to one language and the count was not.
	 *
	 * @param string               $where Existing where clause.
	 * @param array<string, mixed> $args  Parsed wp_get_archives() arguments.
	 * @return string
	 */
	public function filter_archives_where( $where, $args ) {
		$language_id = $this->archives_language( $args );

		if ( ! is_string( $where ) || '' === $language_id ) {
			return $where;
		}

		return $where . $this->constraint->posts_where_clause( $language_id, $this->default_language_id() );
	}

	/**
	 * Returns the language an archive list should be held to.
	 *
	 * @param mixed $args Parsed wp_get_archives() arguments.
	 * @return string Empty when the list should be left alone.
	 */
	private function archives_language( $args ) {
		if ( ! $this->filters_frontend() || ! is_array( $args ) ) {
			return '';
		}

		$post_type = isset( $args['post_type'] ) && is_scalar( $args['post_type'] )
			? (string) $args['post_type']
			: 'post';

		return $this->post_translations->supports_post_type( $post_type )
			? $this->current_language_id()
			: '';
	}

	/**
	 * Marks the start of a calendar and keeps its cache per language.
	 *
	 * WordPress caches the rendered calendar under a key built from these
	 * arguments. The language is not among them, so on a site with a persistent
	 * object cache the first language to be served would answer for all of them;
	 * naming it here is what tells the two calendars apart.
	 *
	 * @param mixed $args Calendar arguments.
	 * @return mixed
	 */
	public function open_calendar( $args ) {
		if ( ! $this->filters_frontend() ) {
			return $args;
		}

		$this->building_calendar = true;
		$this->read_queries();

		if ( ! is_array( $args ) ) {
			return $args;
		}

		$language_id = $this->current_language_id();

		if ( '' !== $language_id ) {
			$args['shapetranslate_language'] = $language_id;
		}

		return $args;
	}

	/**
	 * Marks the start of a calendar rendered as a block.
	 *
	 * `get_calendar_args` arrived in WordPress 6.8 and is the tidy way in. This
	 * is how the two calendars a site actually places are caught on the versions
	 * before it, where the cache cannot be keyed and is dropped instead.
	 *
	 * @param string|null          $pre   Short-circuited block content.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null Unmodified.
	 */
	public function open_calendar_block( $pre, $block ) {
		if (
			! $this->building_calendar
			&& is_array( $block )
			&& isset( $block['blockName'] )
			&& 'core/calendar' === $block['blockName']
		) {
			$this->open_calendar_without_args();

			/*
			 * A calendar that renders closes itself through `get_calendar`, which
			 * core applies on the cached path as well as the uncached one. This
			 * covers the calendar that never renders at all: another callback on
			 * `pre_render_block` returning content short-circuits the block, and
			 * without something to close the window behind it the reader would be
			 * left open for the rest of the request.
			 *
			 * Only when a window was actually opened: a request this module does
			 * not filter opens none, and has nothing to close.
			 */
			if ( $this->building_calendar ) {
				add_filter( 'render_block', array( $this, 'close_calendar_block' ), 10, 2 );
			}
		}

		return $pre;
	}

	/**
	 * Closes a calendar block that has finished rendering, or never rendered.
	 *
	 * @param string               $content Rendered block content.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string Unmodified.
	 */
	public function close_calendar_block( $content, $block ) {
		if ( ! is_array( $block ) || ! isset( $block['blockName'] ) || 'core/calendar' !== $block['blockName'] ) {
			return $content;
		}

		$this->building_calendar = false;
		$this->stop_reading_queries();

		remove_filter( 'render_block', array( $this, 'close_calendar_block' ), 10 );

		return $content;
	}

	/**
	 * Marks the start of a calendar rendered as a classic widget.
	 *
	 * @param array<string, mixed>|false $instance Widget settings.
	 * @param mixed                      $widget   Widget being displayed.
	 * @return array<string, mixed>|false Unmodified.
	 */
	public function open_calendar_widget( $instance, $widget ) {
		if ( ! $this->building_calendar && $widget instanceof WP_Widget_Calendar ) {
			$this->open_calendar_without_args();
		}

		return $instance;
	}

	/**
	 * Opens a calendar on a WordPress with no argument filter to key it by.
	 *
	 * @return void
	 */
	private function open_calendar_without_args() {
		if ( ! $this->filters_frontend() ) {
			return;
		}

		$this->building_calendar = true;
		$this->read_queries();

		// Nothing can distinguish one language's calendar from another's in the
		// stored key here, so the stored calendar is not reused.
		wp_cache_delete( 'get_calendar', 'calendar' );
	}

	/**
	 * Marks the end of a calendar.
	 *
	 * @param string $output Rendered calendar.
	 * @return string Unmodified.
	 */
	public function close_calendar( $output ) {
		$this->building_calendar = false;
		$this->stop_reading_queries();

		return $output;
	}

	/**
	 * Starts reading the SQL being sent to the database.
	 *
	 * @return void
	 */
	private function read_queries() {
		if ( $this->reading_queries ) {
			return;
		}

		$this->reading_queries = true;

		add_filter( 'query', array( $this, 'filter_calendar_query' ) );
	}

	/**
	 * Stops reading the SQL being sent to the database.
	 *
	 * @return void
	 */
	private function stop_reading_queries() {
		if ( ! $this->reading_queries ) {
			return;
		}

		$this->reading_queries = false;

		remove_filter( 'query', array( $this, 'filter_calendar_query' ) );
	}

	/**
	 * Holds the calendar's own queries to the language being read.
	 *
	 * Only queries sent while a calendar is being built are touched, and only
	 * those reading the posts table. The language test goes immediately after
	 * the query's own `WHERE` rather than at the end, because these queries
	 * carry `ORDER BY` and `LIMIT` that nothing may follow.
	 *
	 * The `building_calendar` test is kept although this is now attached only
	 * inside that window: the two answer to different things going wrong. The
	 * attachment bounds which statements this can ever see; the flag is what a
	 * statement is measured against once it is seen, and a window left open by
	 * something unforeseen still rewrites nothing while the flag says no
	 * calendar is being built.
	 *
	 * @param string $sql Query about to run.
	 * @return string
	 */
	public function filter_calendar_query( $sql ) {
		global $wpdb;

		if ( ! $this->building_calendar || ! is_string( $sql ) || '' === $sql ) {
			return $sql;
		}

		if ( false !== strpos( $sql, LanguageQueryConstraint::POST_ALIAS ) ) {
			return $sql;
		}

		$condition = $this->constraint->posts_language_condition(
			$this->current_language_id(),
			$this->default_language_id()
		);

		if ( '' === $condition ) {
			return $sql;
		}

		$pattern = '/\bFROM\s+' . preg_quote( $wpdb->posts, '/' ) . '\b/i';
		$joined  = preg_replace(
			$pattern,
			'$0' . str_replace( '\\', '\\\\', $this->constraint->posts_join_clause() ),
			$sql,
			1,
			$count
		);

		// Not a posts query: the calendar also asks the database to format a
		// date, which reads no table at all.
		if ( ! is_string( $joined ) || 1 !== $count ) {
			return $sql;
		}

		$where = stripos( $joined, ' WHERE ' );

		if ( false === $where ) {
			return $sql;
		}

		return substr_replace( $joined, ' WHERE ' . $condition . ' AND ', $where, strlen( ' WHERE ' ) );
	}

	/**
	 * Reports whether this request is one whose queries should be constrained.
	 *
	 * @return bool
	 */
	private function filters_frontend() {
		return $this->url_manager->background()->scopes_rendered_page();
	}

	/**
	 * Returns the language being read.
	 *
	 * @return string
	 */
	private function current_language_id() {
		$language = $this->url_manager->get_current_language();

		return null === $language ? '' : (string) $language['id'];
	}

	/**
	 * Returns the site's default language.
	 *
	 * @return string
	 */
	private function default_language_id() {
		$language = $this->url_manager->get_default_language();

		return null === $language ? '' : (string) $language['id'];
	}
}
