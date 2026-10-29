<?php
/**
 * Keeps block template slugs and translation groups consistent.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the two rules the rest of the integration depends on.
 *
 * Writing the language into the slug buys a fallback in one query, and the price
 * is that the slug has to stay true. Three things can make it false, and each of
 * them is answered here.
 *
 * A template can be given a language, or be created already carrying one: the
 * Site Editor lets someone duplicate a header and name it anything. One in the
 * default language must then lose any suffix, and one in another language must
 * gain one, or the query that looks for `header___bn` finds nothing.
 *
 * The default language can change. Everything then has to be renamed at once,
 * because the unsuffixed name belongs to whichever language is default, and the
 * language that was default has to take a suffix it never had.
 *
 * And a template can be deleted. Deleting the one every other language falls
 * back to leaves translations pointing at something that is gone, so the group
 * goes with it.
 *
 * One of these runs per translated type — parts and templates each get their
 * own, because each holds its own slugs and its own groups.
 */
final class BlockTemplateLifecycle implements ModuleInterface {

	/**
	 * Template translation service.
	 *
	 * @var BlockTemplates
	 */
	private $templates;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Whether a rename is already running.
	 *
	 * @var bool
	 */
	private $renaming = false;

	/**
	 * Constructor.
	 *
	 * @param BlockTemplates         $templates         Template translation service.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct(
		BlockTemplates $templates,
		PostTranslationManager $post_translations
	) {
		$this->templates         = $templates;
		$this->post_translations = $post_translations;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() ) {
			return;
		}

		add_action( 'shapetranslate_post_language_changed', array( $this, 'rename_on_language_change' ), 10, 2 );
		add_action( 'shapetranslate_default_language_changed', array( $this, 'rename_everything' ), 10, 2 );

		// Before the translation engine forgets the group at priority 10.
		add_action( 'before_delete_post', array( $this, 'delete_translations' ), 8, 2 );

		add_action( 'wp_after_insert_post', array( $this, 'name_new_template' ), 25, 2 );
	}

	/**
	 * Renames a template when the language it belongs to changes.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id New language identifier.
	 * @return void
	 */
	public function rename_on_language_change( $post_id, $language_id ) {
		if ( $this->renaming || ! $this->is_mine( $post_id ) ) {
			return;
		}

		$this->renaming = true;
		$this->templates->rename_for_language( $post_id, $language_id );
		$this->renaming = false;
	}

	/**
	 * Gives a template created in the editor the name its language calls for.
	 *
	 * The Site Editor writes these through the REST API and names them after
	 * their title, so one duplicated into another language arrives with a name
	 * that says nothing about language. The assignment is the truth; the slug is
	 * made to agree with it.
	 *
	 * @param int   $post_id Post identifier.
	 * @param mixed $post    Post object.
	 * @return void
	 */
	public function name_new_template( $post_id, $post ) {
		if ( $this->renaming || ! $post instanceof WP_Post || $this->templates->post_type() !== $post->post_type ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $post_id );

		if ( '' === $language_id ) {
			return;
		}

		$this->renaming = true;
		$this->templates->rename_for_language( $post_id, $language_id );
		$this->renaming = false;
	}

	/**
	 * Renames every template of this type after the default language changes.
	 *
	 * @param string $new_default New default language identifier.
	 * @param string $old_default Previous default language identifier.
	 * @return void
	 */
	public function rename_everything( $new_default, $old_default ) {
		unset( $new_default, $old_default );

		if ( $this->renaming ) {
			return;
		}

		$this->renaming = true;
		$this->templates->forget();

		/*
		 * The language that is becoming default is renamed first, so its suffix is
		 * released before the template that has to take the unsuffixed name asks
		 * for it. Renaming the other way round would find the name taken and give
		 * up.
		 */
		$posts = $this->all_posts();

		foreach ( $posts as $post ) {
			$language_id = $this->post_translations->get_post_language_id( $post->ID );

			if ( '' !== $language_id && $language_id === $this->templates->default_language_id() ) {
				continue;
			}

			$this->templates->rename_for_language( $post->ID, $language_id );
		}

		foreach ( $posts as $post ) {
			$language_id = $this->post_translations->get_post_language_id( $post->ID );

			if ( '' !== $language_id && $language_id === $this->templates->default_language_id() ) {
				$this->templates->rename_for_language( $post->ID, $language_id );
			}
		}

		$this->renaming = false;
		$this->templates->forget();
	}

	/**
	 * Deletes the translations of a template in the default language.
	 *
	 * Only from the default language down. A translation is what a site made on
	 * purpose and may delete on purpose, and deleting it leaves the rest of the
	 * group renderable. The one every language falls back to is the one that
	 * cannot go on its own.
	 *
	 * @param int   $post_id Post identifier.
	 * @param mixed $post    Post object.
	 * @return void
	 */
	public function delete_translations( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || $this->templates->post_type() !== $post->post_type ) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $post_id );

		if ( '' === $language_id || $language_id !== $this->templates->default_language_id() ) {
			return;
		}

		/**
		 * Filters whether deleting a block template deletes its translations.
		 *
		 * @param bool   $cascade   Whether translations are deleted too.
		 * @param int    $post_id   Template being deleted.
		 * @param string $post_type Post type it belongs to.
		 */
		if ( ! apply_filters( 'shapetranslate_delete_template_part_translations', true, absint( $post_id ), $post->post_type ) ) {
			return;
		}

		foreach ( $this->post_translations->get_translations( $post_id ) as $translated_id ) {
			$translated_id = absint( $translated_id );

			if ( 0 !== $translated_id && $translated_id !== absint( $post_id ) ) {
				wp_delete_post( $translated_id, true );
			}
		}

		$this->templates->forget();
	}

	/**
	 * Returns every post of this type belonging to the active theme.
	 *
	 * @return array<int, WP_Post>
	 */
	private function all_posts() {
		$posts = get_posts(
			array(
				'post_type'                        => $this->templates->post_type(),
				'post_status'                      => array( 'publish', 'draft' ),
				'posts_per_page'                   => 200,
				'no_found_rows'                    => true,
				'shapetranslate_skip_language_filter' => true,
				'tax_query'                        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => BlockTemplates::THEME_TAXONOMY,
						'field'    => 'name',
						'terms'    => $this->templates->theme(),
					),
				),
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Reports whether a post is of the type this instance looks after.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_mine( $post_id ) {
		return $this->templates->post_type() === get_post_type( absint( $post_id ) );
	}
}
