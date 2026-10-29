<?php
/**
 * Term translation list-table integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Term;
use WP_Term_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds language and translation columns to supported taxonomy screens.
 */
final class TermTranslationListTable {

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $translation_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Admin action helper.
	 *
	 * @var TermTranslationActions
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
	 * @param TermTranslationManager $translation_manager Term translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 * @param TermTranslationActions $actions             Admin action helper.
	 */
	public function __construct(
		TermTranslationManager $translation_manager,
		LanguageManager $language_manager,
		TermTranslationActions $actions
	) {
		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->flags               = new FlagRegistry();
	}

	/**
	 * Registers the shared cache-prime hook.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'get_terms', array( $this, 'prime_list_table_cache' ), 10, 4 );
	}

	/**
	 * Registers dynamic list-table hooks for one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function register_taxonomy( $taxonomy ) {
		add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'add_columns' ) );
		add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'render_column' ), 10, 3 );
	}

	/**
	 * Adds language and translations after the term name column.
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

			if ( 'name' === $key ) {
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
	 * Returns custom term translation column HTML.
	 *
	 * @param string $content  Existing column content.
	 * @param string $column   Column key.
	 * @param int    $term_id  Term identifier.
	 * @return string
	 */
	public function render_column( $content, $column, $term_id ) {
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$taxonomy = $screen ? $screen->taxonomy : '';

		if ( ! $this->translation_manager->supports_taxonomy( $taxonomy ) ) {
			return $content;
		}

		if ( 'shapetranslate_language' === $column ) {
			return $content . $this->get_language_column( $term_id, $taxonomy );
		}

		if ( 'shapetranslate_translations' === $column ) {
			return $content . $this->get_translations_column( $term_id, $taxonomy );
		}

		return $content;
	}

	/**
	 * Primes relationships for the current taxonomy list in two queries.
	 *
	 * @param array<mixed>  $terms      Query results.
	 * @param array<string> $taxonomies Queried taxonomy names.
	 * @param array<mixed>  $args       Query arguments.
	 * @param WP_Term_Query $term_query Term query object.
	 * @return array<mixed>
	 */
	public function prime_list_table_cache( $terms, $taxonomies, $args, $term_query ) {
		unset( $args, $term_query );

		/*
		 * get_terms runs on every request, including admin-ajax and anything that
		 * queries terms during init. is_admin() is true there long before
		 * wp-admin/includes/screen.php is loaded, so the screen has to be tested
		 * for existence, not just for a value. Priming is only an optimization;
		 * skipping it costs a few queries and nothing else.
		 */
		if (
			! is_admin()
			|| empty( $terms )
			|| 1 !== count( $taxonomies )
			|| ! function_exists( 'get_current_screen' )
		) {
			return $terms;
		}

		$screen   = get_current_screen();
		$taxonomy = reset( $taxonomies );

		if (
			! $screen
			|| 'edit-tags' !== $screen->base
			|| $screen->taxonomy !== $taxonomy
			|| ! $this->translation_manager->supports_taxonomy( $taxonomy )
		) {
			return $terms;
		}

		$term_taxonomy_ids = array();

		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$term_taxonomy_ids[] = $term->term_taxonomy_id;
			}
		}

		$this->translation_manager->prime_terms( $term_taxonomy_ids );

		return $terms;
	}

	/**
	 * Builds the assigned language column.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function get_language_column( $term_id, $taxonomy ) {
		$language = $this->translation_manager->get_term_language( $term_id, $taxonomy );

		if ( null === $language ) {
			return sprintf(
				'<span class="shapetranslate-unassigned">%s</span>',
				esc_html__( 'Not assigned', 'shapetranslate' )
			);
		}

		/*
		 * A flag and a two-letter code identify the language at a glance, and a
		 * column of full native names only crowds the row. The name stays
		 * reachable on hover and is the only part a screen reader announces,
		 * since "EN" on its own says very little.
		 */
		return sprintf(
			'<span class="shapetranslate-list-language" title="%3$s">%1$s<strong aria-hidden="true">%2$s</strong><span class="screen-reader-text">%4$s</span></span>',
			$this->flags->get_flag_html( $language ),
			esc_html( strtoupper( $language['language_code'] ) ),
			esc_attr( $language['native_name'] ),
			esc_html( $language['native_name'] )
		);
	}

	/**
	 * Builds compact translation statuses and action links.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function get_translations_column( $term_id, $taxonomy ) {
		$current_id   = $this->translation_manager->get_term_language_id( $term_id, $taxonomy );
		$translations = $this->translation_manager->get_translations( $term_id, $taxonomy );
		$term         = get_term( $term_id, $taxonomy );

		if ( '' === $current_id || ! $term instanceof WP_Term ) {
			return sprintf(
				'<span class="shapetranslate-unassigned">%s</span>',
				esc_html__( 'Assign language first', 'shapetranslate' )
			);
		}

		$states = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			$language_id = $language['id'];
			$target_id   = isset( $translations[ $language_id ] ) ? absint( $translations[ $language_id ] ) : 0;

			if ( empty( $language['enabled'] ) && 0 === $target_id ) {
				continue;
			}

			$state = $this->get_translation_status( $term, $language, $current_id, $target_id );

			if ( '' !== $state ) {
				$states[] = $state;
			}
		}

		return '<div class="shapetranslate-list-translations">' . implode( '', $states ) . '</div>';
	}

	/**
	 * Builds one compact language status.
	 *
	 * @param WP_Term              $term       Current term.
	 * @param array<string, mixed> $language   Language record.
	 * @param string               $current_id Current language identifier.
	 * @param int                  $target_id  Translation term identifier.
	 * @return string
	 */
	private function get_translation_status( WP_Term $term, $language, $current_id, $target_id ) {
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
			$edit_url   = current_user_can( 'edit_term', $target_id )
				? $this->actions->get_edit_url( $target_id, $term->taxonomy )
				: '';
			$edit_label = sprintf(
				/* translators: %s: language name. */
				__( 'Edit %s translation', 'shapetranslate' ),
				$language['native_name']
			);

			if ( '' !== $edit_url && $language['id'] !== $current_id ) {
				return sprintf(
					'<a class="shapetranslate-translation-state has-translation" href="%1$s" title="%2$s" aria-label="%2$s">%3$s<span class="shapetranslate-translation-badge dashicons dashicons-edit" aria-hidden="true"></span></a>',
					esc_url( $edit_url ),
					esc_attr( $edit_label ),
					$marker
				);
			}

			return sprintf(
				'<span class="shapetranslate-translation-state has-translation" title="%1$s">%2$s<span class="shapetranslate-translation-badge dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text">%1$s</span></span>',
				esc_attr( $label ),
				$marker
			);
		}

		if ( empty( $language['enabled'] ) || ! $this->actions->can_create_translation( $term ) ) {
			return '';
		}

		$add_label = sprintf(
			/* translators: %s: native language name. */
			__( 'Add %s translation', 'shapetranslate' ),
			$language['native_name']
		);

		return sprintf(
			'<a class="shapetranslate-translation-state missing-translation" href="%1$s" title="%2$s" aria-label="%2$s">%3$s<span class="shapetranslate-translation-badge dashicons dashicons-plus-alt2" aria-hidden="true"></span></a>',
			esc_url( $this->actions->get_create_url( $term->term_id, $term->taxonomy, $language['id'] ) ),
			esc_attr( $add_label ),
			$marker
		);
	}
}
