<?php
/**
 * Translation list table integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds language and translation columns to supported post list tables.
 */
final class TranslationListTable {

	/**
	 * Translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translation_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Translation action helper.
	 *
	 * @var TranslationActions
	 */
	private $actions;

	/**
	 * Language flag registry.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $translation_manager Translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 * @param TranslationActions     $actions             Translation action helper.
	 */
	public function __construct(
		PostTranslationManager $translation_manager,
		LanguageManager $language_manager,
		TranslationActions $actions
	) {
		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->flags               = new FlagRegistry();
	}

	/**
	 * Registers generic list-table hooks for built-in and custom post types.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'manage_posts_columns', array( $this, 'add_post_columns' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'add_page_columns' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'prime_list_table_cache' ), 10, 2 );
	}

	/**
	 * Adds columns to the Media list table when media is translatable.
	 *
	 * The media library has its own list table, so it needs its own hooks even
	 * though the rendered columns are identical.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_media_columns( $columns ) {
		return $this->translation_manager->supports_post_type( 'attachment' )
			? $this->add_columns( $columns )
			: $columns;
	}

	/**
	 * Adds columns to supported non-page list tables.
	 *
	 * @param array<string, string> $columns   Existing columns.
	 * @param string                $post_type Current post type.
	 * @return array<string, string>
	 */
	public function add_post_columns( $columns, $post_type ) {
		return $this->translation_manager->supports_post_type( $post_type )
			? $this->add_columns( $columns )
			: $columns;
	}

	/**
	 * Adds columns to the Pages list table when pages are supported.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_page_columns( $columns ) {
		return $this->translation_manager->supports_post_type( 'page' )
			? $this->add_columns( $columns )
			: $columns;
	}

	/**
	 * Adds language and translation columns after the title column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_columns( $columns ) {
		$updated            = array();
		$language_label     = __( 'Language', 'shapetranslate' );
		$translations_label = $this->get_translations_column_label();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'title' === $key ) {
				$updated['shapetranslate_language']     = $language_label;
				$updated['shapetranslate_translations'] = $translations_label;
			}
		}

		if ( ! isset( $updated['shapetranslate_language'] ) ) {
			$updated['shapetranslate_language']     = $language_label;
			$updated['shapetranslate_translations'] = $translations_label;
		}

		return $updated;
	}

	/**
	 * Builds the Translations column heading.
	 *
	 * The heading mirrors the flag chips rendered in each row, so the column
	 * reads as a flag legend. The visible flags are decorative; list-table code
	 * strips tags from the label for Screen Options and the responsive view,
	 * which leaves the accessible column name behind.
	 *
	 * @return string
	 */
	private function get_translations_column_label() {
		$label = __( 'Translations', 'shapetranslate' );
		$flags = '';

		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( empty( $language['enabled'] ) ) {
				continue;
			}

			$flag = $this->flags->get_flag_html( $language );

			$flags .= sprintf(
				'<span class="shapetranslate-translations-heading-flag" title="%1$s">%2$s</span>',
				esc_attr( $language['native_name'] ),
				'' !== $flag
					? $flag
					: '<span class="shapetranslate-translation-code">' . esc_html( strtoupper( $language['language_code'] ) ) . '</span>'
			);
		}

		if ( '' === $flags ) {
			return $label;
		}

		return sprintf(
			'<span class="screen-reader-text">%1$s</span><span class="shapetranslate-translations-heading" aria-hidden="true">%2$s</span>',
			esc_html( $label ),
			$flags
		);
	}

	/**
	 * Renders a custom translation column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post identifier.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( ! $this->translation_manager->supports_post_type( get_post_type( $post_id ) ) ) {
			return;
		}

		if ( 'shapetranslate_language' === $column ) {
			$this->render_language_column( $post_id );
		} elseif ( 'shapetranslate_translations' === $column ) {
			$this->render_translations_column( $post_id );
		}
	}

	/**
	 * Returns the language marker as markup instead of printing it.
	 *
	 * A list table a plugin builds for a screen of its own runs neither of the
	 * column hooks above, so an integration has to place the cells itself. Both
	 * columns are captured rather than rewritten to return strings, so a screen
	 * that borrows them shows exactly what the post list shows, and keeps showing
	 * it when one of them changes.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function get_language_markup( $post_id ) {
		ob_start();
		$this->render_language_column( absint( $post_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Returns the translation status and action links as markup.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function get_translations_markup( $post_id ) {
		ob_start();
		$this->render_translations_column( absint( $post_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Primes all relationships used by the current list screen in two queries.
	 *
	 * @param array<int, WP_Post> $posts Queried posts.
	 * @param WP_Query            $query Current query.
	 * @return array<int, WP_Post>
	 */
	public function prime_list_table_cache( $posts, $query ) {
		/*
		 * the_posts runs for every query, including ones made during admin-ajax or
		 * init, where is_admin() is already true but wp-admin/includes/screen.php
		 * has not been loaded yet. Priming is only an optimization, so an absent
		 * screen function simply means no priming.
		 */
		if (
			! is_admin()
			|| ! $query instanceof WP_Query
			|| ! $query->is_main_query()
			|| empty( $posts )
			|| ! function_exists( 'get_current_screen' )
		) {
			return $posts;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return $posts;
		}

		// The media library reports the `upload` base and carries no post type.
		$post_type = 'upload' === $screen->base ? 'attachment' : $screen->post_type;

		if (
			! in_array( $screen->base, array( 'edit', 'upload' ), true )
			|| ! $this->translation_manager->supports_post_type( $post_type )
		) {
			return $posts;
		}

		$post_ids = array_map(
			static function ( $post ) {
				return $post instanceof WP_Post ? $post->ID : 0;
			},
			$posts
		);

		$this->translation_manager->prime_posts( $post_ids );
		$translation_ids = array();

		foreach ( $post_ids as $post_id ) {
			$translation_ids = array_merge(
				$translation_ids,
				array_values( $this->translation_manager->get_translations( $post_id ) )
			);
		}

		$translation_ids = array_values( array_unique( array_filter( array_map( 'absint', $translation_ids ) ) ) );

		if ( ! empty( $translation_ids ) ) {
			_prime_post_caches( $translation_ids, false, false );
		}

		return $posts;
	}

	/**
	 * Renders the assigned language.
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	private function render_language_column( $post_id ) {
		$language = $this->translation_manager->get_post_language( $post_id );

		if ( null === $language ) {
			echo '<span class="shapetranslate-unassigned">';
			esc_html_e( 'Not assigned', 'shapetranslate' );
			echo '</span>';
			return;
		}

		/*
		 * A flag and a two-letter code identify the language at a glance, and a
		 * column of full native names only crowds the row. The name stays
		 * reachable on hover and is the only part a screen reader announces,
		 * since "EN" on its own says very little.
		 */
		printf(
			'<span class="shapetranslate-list-language" title="%3$s">%1$s<strong aria-hidden="true">%2$s</strong><span class="screen-reader-text">%4$s</span></span>',
			$this->flags->get_flag_html( $language ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by FlagRegistry::get_flag_html().
			esc_html( strtoupper( $language['language_code'] ) ),
			esc_attr( $language['native_name'] ),
			esc_html( $language['native_name'] )
		);
	}

	/**
	 * Renders compact translation status and action links.
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	private function render_translations_column( $post_id ) {
		$current_id   = $this->translation_manager->get_post_language_id( $post_id );
		$translations = $this->translation_manager->get_translations( $post_id );
		$post         = get_post( $post_id );

		if ( '' === $current_id || ! $post instanceof WP_Post ) {
			echo '<span class="shapetranslate-unassigned">';
			esc_html_e( 'Assign language first', 'shapetranslate' );
			echo '</span>';
			return;
		}

		echo '<div class="shapetranslate-list-translations">';

		foreach ( $this->language_manager->get_languages() as $language ) {
			$language_id = $language['id'];
			$target_id   = isset( $translations[ $language_id ] ) ? absint( $translations[ $language_id ] ) : 0;

			if ( empty( $language['enabled'] ) && 0 === $target_id ) {
				continue;
			}

			$this->render_translation_status( $post, $language, $current_id, $target_id );
		}

		echo '</div>';
	}

	/**
	 * Renders one compact language status.
	 *
	 * @param WP_Post              $post        Current post.
	 * @param array<string, mixed> $language    Language record.
	 * @param string               $current_id  Current language identifier.
	 * @param int                  $target_id   Translation post identifier.
	 * @return void
	 */
	private function render_translation_status( $post, $language, $current_id, $target_id ) {
		$code   = strtoupper( $language['language_code'] );
		$flag   = $this->flags->get_flag_html( $language );
		$marker = '' !== $flag
			? $flag
			: '<span class="shapetranslate-translation-code">' . esc_html( $code ) . '</span>';
		$label  = sprintf(
			/* translators: %s: native language name. */
			__( '%s translation', 'shapetranslate' ),
			$language['native_name']
		);

		if ( 0 < $target_id ) {
			$edit_url   = 'trash' !== get_post_status( $target_id ) && current_user_can( 'edit_post', $target_id )
				? get_edit_post_link( $target_id, '' )
				: '';
			$edit_label = sprintf(
				/* translators: %s: language name. */
				__( 'Edit %s translation', 'shapetranslate' ),
				$language['native_name']
			);

			if ( is_string( $edit_url ) && '' !== $edit_url && $language['id'] !== $current_id ) {
				printf(
					'<a class="shapetranslate-translation-state has-translation" href="%1$s" title="%2$s" aria-label="%2$s">%3$s<span class="shapetranslate-translation-badge dashicons dashicons-edit" aria-hidden="true"></span></a>',
					esc_url( $edit_url ),
					esc_attr( $edit_label ),
					$marker // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Flag markup sanitized by FlagRegistry::get_flag_html(); the code fallback is escaped above.
				);
			} else {
				printf(
					'<span class="shapetranslate-translation-state has-translation" title="%1$s">%2$s<span class="shapetranslate-translation-badge dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text">%1$s</span></span>',
					esc_attr( $label ),
					$marker // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Flag markup sanitized by FlagRegistry::get_flag_html(); the code fallback is escaped above.
				);
			}

			return;
		}

		if ( empty( $language['enabled'] ) || ! $this->actions->can_create_translation( $post ) ) {
			return;
		}

		$add_label = sprintf(
			/* translators: %s: native language name. */
			__( 'Add %s translation', 'shapetranslate' ),
			$language['native_name']
		);

		printf(
			'<a class="shapetranslate-translation-state missing-translation" href="%1$s" title="%2$s" aria-label="%2$s">%3$s<span class="shapetranslate-translation-badge dashicons dashicons-plus-alt2" aria-hidden="true"></span></a>',
			esc_url( $this->actions->get_create_url( $post->ID, $language['id'] ) ),
			esc_attr( $add_label ),
			$marker // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Flag markup sanitized by FlagRegistry::get_flag_html(); the code fallback is escaped above.
		);
	}
}
