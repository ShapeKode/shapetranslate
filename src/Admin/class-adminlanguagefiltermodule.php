<?php
/**
 * Admin bar language filter module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\LanguageQueryConstraint;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Admin_Bar;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Filters the administration screens to one language.
 *
 * An admin bar menu offers every enabled language plus a show-all entry, and
 * the choice is remembered per user. While one language is selected the post,
 * term, and media listings show only that language, and content created from
 * those screens starts in it. The same indexed join the frontend and REST API
 * use provides the constraint, so a filtered listing costs one extra join
 * rather than a second query.
 */
final class AdminLanguageFilterModule implements ModuleInterface {

	/**
	 * Admin bar node identifier.
	 *
	 * @var string
	 */
	const NODE_ID = 'shapetranslate-languages';

	/**
	 * Filter state.
	 *
	 * @var AdminLanguageFilter
	 */
	private $filter;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

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
	 * Shared language constraint.
	 *
	 * @var LanguageQueryConstraint
	 */
	private $constraint;

	/**
	 * Language flag registry.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Cached availability, resolved once because it gates every admin query.
	 *
	 * @var bool|null
	 */
	private $available;

	/**
	 * Constructor.
	 *
	 * @param AdminLanguageFilter    $filter            Filter state.
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 */
	public function __construct(
		AdminLanguageFilter $filter,
		LanguageManager $language_manager,
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations
	) {
		$this->filter            = $filter;
		$this->language_manager  = $language_manager;
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->constraint        = new LanguageQueryConstraint();
		$this->flags             = new FlagRegistry();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 80 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_styles' ) );
		add_filter( 'posts_clauses', array( $this, 'filter_post_clauses' ), 10, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_term_clauses' ), 10, 3 );
		add_filter( 'shapetranslate_new_post_language_id', array( $this, 'filtered_language_id' ) );
		add_filter( 'shapetranslate_new_term_language_id', array( $this, 'filtered_language_id' ) );

		// Runs before TranslationLifecycleModule::assign_default_language() at priority 20.
		add_action( 'wp_after_insert_post', array( $this, 'assign_filtered_language' ), 15, 2 );
	}

	/**
	 * Aligns the flags rendered inside the admin bar menu.
	 *
	 * The rules ride on the core admin bar stylesheet because the menu appears
	 * on every screen, while the plugin's own stylesheet loads only on
	 * ShapeTranslate and translation screens.
	 *
	 * @return void
	 */
	public function enqueue_admin_bar_styles() {
		if (
			! $this->is_filter_available()
			|| ! is_admin_bar_showing()
			|| ! wp_style_is( 'admin-bar', 'registered' )
		) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-' . self::NODE_ID . ' .shapetranslate-flag{'
				. 'width:16px;height:11px;margin-right:6px;vertical-align:baseline;'
			. '}'
		);
	}

	/**
	 * Adds the language filter menu to the admin bar.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public function register_admin_bar( $admin_bar ) {
		if ( ! $admin_bar instanceof WP_Admin_Bar || ! $this->is_filter_available() ) {
			return;
		}

		$languages = $this->filter->get_available_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$current  = $this->filter->get_language();
		$show_all = __( 'Show all languages', 'shapetranslate' );

		$admin_bar->add_node(
			array(
				'id'     => self::NODE_ID,
				'title'  => $this->node_title( null === $current ? $show_all : $current['native_name'], $current ),
				'href'   => $this->filter->get_filter_url( AdminLanguageFilter::SHOW_ALL ),
				'meta'   => array( 'title' => __( 'Filters content by language', 'shapetranslate' ) ),
				'parent' => false,
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => self::NODE_ID . '-all',
				'parent' => self::NODE_ID,
				'title'  => esc_html( $show_all ),
				'href'   => $this->filter->get_filter_url( AdminLanguageFilter::SHOW_ALL ),
			)
		);

		foreach ( $languages as $language ) {
			$admin_bar->add_node(
				array(
					'id'     => self::NODE_ID . '-' . sanitize_key( $language['id'] ),
					'parent' => self::NODE_ID,
					'title'  => $this->flags->get_flag_html( $language ) . esc_html( $language['native_name'] ),
					'href'   => $this->filter->get_filter_url( $language['url_slug'] ),
					'meta'   => array( 'lang' => esc_attr( $language['locale'] ) ),
				)
			);
		}
	}

	/**
	 * Constrains an admin post listing to the filtered language.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Query              $query   Query being filtered.
	 * @return array<string, string>
	 */
	public function filter_post_clauses( $clauses, $query ) {
		if ( ! is_array( $clauses ) || ! $query instanceof WP_Query || ! $this->should_filter_posts( $query ) ) {
			return $clauses;
		}

		return $this->constraint->apply_to_posts(
			$clauses,
			$this->filter->get_language_id(),
			$this->language_manager->get_default_id()
		);
	}

	/**
	 * Constrains an admin term listing to the filtered language.
	 *
	 * @param array<string, string> $clauses    SQL clauses.
	 * @param array<int, string>    $taxonomies Queried taxonomies.
	 * @param array<string, mixed>  $args       Query arguments.
	 * @return array<string, string>
	 */
	public function filter_term_clauses( $clauses, $taxonomies, $args ) {
		if ( ! is_array( $clauses ) || ! is_array( $taxonomies ) || 1 !== count( $taxonomies ) ) {
			return $clauses;
		}

		// Reading the terms one object holds is never a listing, so it keeps every
		// term that object was given whatever language the listing is filtered to.
		if ( is_array( $args ) && ! empty( $args['object_ids'] ) ) {
			return $clauses;
		}

		$taxonomy = (string) reset( $taxonomies );

		if (
			! $this->is_filter_active()
			|| ! $this->is_term_list_screen( $taxonomy )
			|| ! $this->term_translations->supports_taxonomy( $taxonomy )
		) {
			return $clauses;
		}

		return $this->constraint->apply_to_terms(
			$clauses,
			$this->filter->get_language_id(),
			$this->language_manager->get_default_id()
		);
	}

	/**
	 * Replaces the language a new post or term starts in.
	 *
	 * @param string $language_id Configured default language identifier.
	 * @return string
	 */
	public function filtered_language_id( $language_id ) {
		return $this->is_filter_active() ? $this->filter->get_language_id() : $language_id;
	}

	/**
	 * Assigns the filtered language to a post saved without one.
	 *
	 * Guards match TranslationLifecycleModule so the two never disagree about
	 * which saves receive an automatic assignment; only the language differs.
	 *
	 * @param int     $post_id Post identifier.
	 * @param WP_Post $post    Saved post.
	 * @return void
	 */
	public function assign_filtered_language( $post_id, $post ) {
		if (
			! $post instanceof WP_Post
			|| ! $this->is_filter_active()
			|| 'auto-draft' === $post->post_status
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| $this->post_translations->is_creating_translation()
			|| ! $this->post_translations->supports_post_type( $post->post_type )
			|| '' !== $this->post_translations->get_group_id( $post_id )
		) {
			return;
		}

		$this->post_translations->set_post_language( $post_id, $this->filter->get_language_id() );
	}

	/**
	 * Reports whether a post query belongs to a filtered admin listing.
	 *
	 * @param WP_Query $query Query being filtered.
	 * @return bool
	 */
	private function should_filter_posts( WP_Query $query ) {
		if ( ! $this->is_filter_active() ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );

		if ( ! is_string( $post_type ) || '' === $post_type || ! $this->post_translations->supports_post_type( $post_type ) ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			// The media modal loads its library over admin-ajax rather than a screen.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Action name only, verified by core.
			$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] )
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Action name only, verified by core.
				? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
				: '';

			return 'query-attachments' === $action;
		}

		if ( ! $query->is_main_query() ) {
			return false;
		}

		$screen = $this->get_screen();

		return null !== $screen && in_array( $screen->base, array( 'edit', 'upload' ), true );
	}

	/**
	 * Reports whether the request is the list table for one taxonomy.
	 *
	 * Only the term listing is filtered. A taxonomy control inside the post
	 * editor follows the language of the post being edited, which the editor
	 * integration already supplies, so narrowing it here would fight that.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private function is_term_list_screen( $taxonomy ) {
		$screen = $this->get_screen();

		return null !== $screen
			&& 'edit-tags' === $screen->base
			&& $screen->taxonomy === $taxonomy;
	}

	/**
	 * Reports whether the filter menu may be shown to this user.
	 *
	 * @return bool
	 */
	private function is_filter_available() {
		if ( null !== $this->available ) {
			return $this->available;
		}

		$available = is_admin()
			&& ! is_network_admin()
			&& is_user_logged_in()
			&& current_user_can( 'edit_posts' )
			&& $this->language_manager->has_languages();

		/**
		 * Filters whether the admin language filter is offered and applied.
		 *
		 * @param bool $available Whether the filter is available.
		 */
		$this->available = (bool) apply_filters( 'shapetranslate_enable_admin_language_filter', $available );

		return $this->available;
	}

	/**
	 * Reports whether a language is selected and the filter may run.
	 *
	 * @return bool
	 */
	private function is_filter_active() {
		return $this->is_filter_available() && $this->filter->is_filtering();
	}

	/**
	 * Builds the admin bar node title.
	 *
	 * @param string                    $label    Visible label.
	 * @param array<string, mixed>|null $language Selected language, if any.
	 * @return string
	 */
	private function node_title( $label, $language ) {
		$flag = null === $language ? '' : $this->flags->get_flag_html( $language );

		return '<span class="ab-label">' . $flag . esc_html( $label ) . '</span>';
	}

	/**
	 * Returns the current screen when one is available.
	 *
	 * @return \WP_Screen|null
	 */
	private function get_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen ? $screen : null;
	}
}
