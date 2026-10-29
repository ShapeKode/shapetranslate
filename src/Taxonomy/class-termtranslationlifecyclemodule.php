<?php
/**
 * Term translation lifecycle module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Taxonomy;

use ShapeTranslate\Contracts\ModuleInterface;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps term relationships consistent in admin, REST, CLI, and custom requests.
 */
final class TermTranslationLifecycleModule implements ModuleInterface {

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $translation_manager;

	/**
	 * Constructor.
	 *
	 * @param TermTranslationManager $translation_manager Term translation manager.
	 */
	public function __construct( TermTranslationManager $translation_manager ) {
		$this->translation_manager = $translation_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'created_term', array( $this, 'assign_default_language' ), 20, 3 );
		add_action( 'delete_term', array( $this, 'remove_deleted_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'synchronize_edited_term' ), 20, 3 );
		add_filter( 'shapetranslate_pre_delete_language', array( $this, 'prevent_language_deletion' ), 10, 3 );
	}

	/**
	 * Assigns a language to a term created without one.
	 *
	 * The language of the request the term was created in is used, so a category
	 * added from the editor of a translated post belongs to that post's language
	 * and appears in the list the editor is showing. Only a term created outside
	 * any language context falls back to the configured default.
	 *
	 * Explicit taxonomy-form assignments run earlier and are preserved.
	 *
	 * @param int    $term_id          New term identifier.
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function assign_default_language( $term_id, $term_taxonomy_id, $taxonomy ) {
		unset( $term_taxonomy_id );

		if (
			$this->translation_manager->is_creating_translation()
			|| ! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| '' !== $this->translation_manager->get_group_id( $term_id, $taxonomy )
		) {
			return;
		}

		/**
		 * Filters automatic default-language assignment for a newly created term.
		 *
		 * @param bool   $assign   Whether ShapeTranslate should persist the default.
		 * @param int    $term_id  Term identifier.
		 * @param string $taxonomy Taxonomy name.
		 */
		$assign = apply_filters(
			'shapetranslate_auto_assign_default_term_language',
			true,
			absint( $term_id ),
			sanitize_key( $taxonomy )
		);

		if ( ! $assign ) {
			return;
		}

		$language_id = $this->new_term_language_id( $term_id, $taxonomy );

		if ( '' === $language_id ) {
			$this->translation_manager->assign_default_language( $term_id, $taxonomy );

			return;
		}

		$result = $this->translation_manager->set_term_language( $term_id, $taxonomy, $language_id );

		if ( is_wp_error( $result ) ) {
			// A language that no longer answers must not leave the term unassigned.
			$this->translation_manager->assign_default_language( $term_id, $taxonomy );
		}
	}

	/**
	 * Resolves the language a newly created term starts in.
	 *
	 * A child term follows its parent, because a translated hierarchy is only
	 * coherent while a branch stays in one language. The request language wins
	 * over that, so an editor creating a child in the language it is showing is
	 * never handed the parent's instead.
	 *
	 * @param int    $term_id  New term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function new_term_language_id( $term_id, $taxonomy ) {
		$term        = get_term( absint( $term_id ), sanitize_key( $taxonomy ) );
		$language_id = '';

		if ( $term instanceof WP_Term && 0 < $term->parent ) {
			$language_id = $this->translation_manager->get_term_language_id( $term->parent, $taxonomy );
		}

		/**
		 * Filters the language a term with no assignment starts in.
		 *
		 * @param string $language_id Resolved language identifier.
		 * @param string $taxonomy    Taxonomy name.
		 */
		$filtered = apply_filters( 'shapetranslate_new_term_language_id', $language_id, (string) $taxonomy );

		return is_scalar( $filtered ) ? (string) $filtered : '';
	}

	/**
	 * Removes a deleted term from its translation group.
	 *
	 * @param int    $term_id          Deleted term identifier.
	 * @param int    $term_taxonomy_id Deleted term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function remove_deleted_term( $term_id, $term_taxonomy_id, $taxonomy ) {
		$this->translation_manager->remove_term( $term_id, $term_taxonomy_id, $taxonomy );
	}

	/**
	 * Reconciles translated parents after any core term edit.
	 *
	 * @param int    $term_id          Edited term identifier.
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function synchronize_edited_term( $term_id, $term_taxonomy_id, $taxonomy ) {
		unset( $term_taxonomy_id );

		if ( $this->translation_manager->supports_taxonomy( $taxonomy ) ) {
			$this->translation_manager->synchronize_hierarchy( $term_id, $taxonomy );
		}
	}

	/**
	 * Prevents deletion of a language that remains assigned to terms.
	 *
	 * @param true|WP_Error        $can_delete  Existing deletion decision.
	 * @param string               $language_id Language identifier.
	 * @param array<string, mixed> $language    Language record.
	 * @return true|WP_Error
	 */
	public function prevent_language_deletion( $can_delete, $language_id, $language ) {
		unset( $language );

		if ( true !== $can_delete || ! $this->translation_manager->is_language_in_use( $language_id ) ) {
			return $can_delete;
		}

		return new WP_Error(
			'language_in_use',
			__( 'This language is assigned to terms and cannot be deleted.', 'shapetranslate' )
		);
	}
}
