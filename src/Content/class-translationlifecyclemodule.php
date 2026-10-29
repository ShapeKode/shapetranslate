<?php
/**
 * Translation relationship lifecycle module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Contracts\ModuleInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps translation relationships consistent in every request context.
 */
final class TranslationLifecycleModule implements ModuleInterface {

	/**
	 * Translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translation_manager;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $translation_manager Translation manager.
	 */
	public function __construct( PostTranslationManager $translation_manager ) {
		$this->translation_manager = $translation_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'before_delete_post', array( $this, 'remove_deleted_post' ), 10, 2 );

		// wp_delete_post() hands attachments to wp_delete_attachment() before
		// before_delete_post fires, so media needs its own deletion hook.
		add_action( 'delete_attachment', array( $this, 'remove_deleted_post' ), 20, 2 );
		add_action( 'trashed_post', array( $this, 'post_trashed' ), 10, 2 );
		add_action( 'untrashed_post', array( $this, 'post_untrashed' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'assign_default_language' ), 20, 4 );
		add_filter( 'shapetranslate_pre_delete_language', array( $this, 'prevent_language_deletion' ), 10, 3 );
	}

	/**
	 * Assigns the configured default language after a normal content save.
	 *
	 * Explicit editor assignments run earlier and are preserved. Translated-copy
	 * insertion is excluded because its target language is linked separately.
	 *
	 * @param int           $post_id     Post identifier.
	 * @param \WP_Post      $post        Inserted or updated post.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the update, or null for inserts.
	 * @return void
	 */
	public function assign_default_language( $post_id, $post, $update, $post_before ) {
		unset( $update, $post_before );

		if (
			! $post instanceof \WP_Post
			|| 'auto-draft' === $post->post_status
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| $this->translation_manager->is_creating_translation()
			|| ! $this->translation_manager->supports_post_type( $post->post_type )
			|| '' !== $this->translation_manager->get_group_id( $post_id )
		) {
			return;
		}

		/**
		 * Filters automatic default-language assignment for a saved post.
		 *
		 * @param bool     $assign  Whether ShapeTranslate should persist the default.
		 * @param int      $post_id Post identifier.
		 * @param \WP_Post $post    Saved post.
		 */
		$assign = apply_filters( 'shapetranslate_auto_assign_default_post_language', true, absint( $post_id ), $post );

		if ( $assign ) {
			$this->translation_manager->assign_default_language( $post_id );
		}
	}

	/**
	 * Removes a permanently deleted post from its translation group.
	 *
	 * @param int           $post_id Post identifier.
	 * @param \WP_Post|null $post    Post being deleted.
	 * @return void
	 */
	public function remove_deleted_post( $post_id, $post = null ) {
		unset( $post );
		$this->translation_manager->remove_post( $post_id );
	}

	/**
	 * Emits a lifecycle hook while preserving trashed relationships.
	 *
	 * @param int    $post_id         Trashed post identifier.
	 * @param string $previous_status Previous post status.
	 * @return void
	 */
	public function post_trashed( $post_id, $previous_status = '' ) {
		if ( '' === $this->translation_manager->get_group_id( $post_id ) ) {
			return;
		}

		do_action( 'shapetranslate_translation_post_trashed', absint( $post_id ), $previous_status );
	}

	/**
	 * Emits a lifecycle hook after a translated post is restored.
	 *
	 * @param int    $post_id         Restored post identifier.
	 * @param string $previous_status Previous post status.
	 * @return void
	 */
	public function post_untrashed( $post_id, $previous_status = '' ) {
		if ( '' === $this->translation_manager->get_group_id( $post_id ) ) {
			return;
		}

		do_action(
			'shapetranslate_translation_post_untrashed',
			absint( $post_id ),
			$previous_status
		);
	}

	/**
	 * Prevents deletion of a language that remains assigned to content.
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
			__( 'This language is assigned to content and cannot be deleted.', 'shapetranslate' )
		);
	}
}
