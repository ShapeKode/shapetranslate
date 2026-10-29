<?php
/**
 * Translation copy and synchronization module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Sync;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Settings\WorkflowSettings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the synchronization engine to translation and post lifecycle events.
 */
final class SyncModule implements ModuleInterface {

	/**
	 * Copy and synchronization engine.
	 *
	 * @var TranslationSynchronizer
	 */
	private $synchronizer;

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Request-local cache of whether any synchronization item is enabled.
	 *
	 * @var bool|null
	 */
	private $sync_enabled;

	/**
	 * Whether a metadata guard decision is already being resolved.
	 *
	 * @var bool
	 */
	private $guarding = false;

	/**
	 * Constructor.
	 *
	 * @param TranslationSynchronizer $synchronizer      Copy and synchronization engine.
	 * @param PostTranslationManager  $post_translations Post translation manager.
	 * @param WorkflowSettings        $workflow_settings Workflow settings.
	 */
	public function __construct(
		TranslationSynchronizer $synchronizer,
		PostTranslationManager $post_translations,
		WorkflowSettings $workflow_settings
	) {
		$this->synchronizer      = $synchronizer;
		$this->post_translations = $post_translations;
		$this->workflow_settings = $workflow_settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_new_translation' ), 20, 5 );
		add_action( 'save_post', array( $this, 'synchronize_saved_post' ), 20, 2 );

		// Core changes the sticky state after save_post, so it is synchronized again once stored.
		add_action( 'post_stuck', array( $this->synchronizer, 'synchronize_sticky' ) );
		add_action( 'post_unstuck', array( $this->synchronizer, 'synchronize_sticky' ) );

		foreach ( array( 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ) as $filter ) {
			add_filter( $filter, array( $this, 'guard_synchronized_metadata' ), 1, 3 );
		}
	}

	/**
	 * Copies enabled items into a newly created translation.
	 *
	 * Runs after the Elementor integration so a builder document is already in
	 * place when custom fields are written.
	 *
	 * @param int                  $new_post_id  Translated post identifier.
	 * @param int                  $source_id    Source post identifier.
	 * @param array<string, mixed> $language     Target language record.
	 * @param string               $group_id     Translation group identifier.
	 * @param array<string, bool>  $copy_options Applied copy behavior.
	 * @return void
	 */
	public function copy_new_translation( $new_post_id, $source_id, $language = array(), $group_id = '', $copy_options = array() ) {
		$language_id = is_array( $language ) && isset( $language['id'] ) && is_scalar( $language['id'] )
			? (string) $language['id']
			: '';

		$this->synchronizer->copy(
			absint( $new_post_id ),
			absint( $source_id ),
			$language_id,
			is_array( $copy_options ) ? $copy_options : array()
		);
	}

	/**
	 * Propagates enabled items after a post in a translation group is saved.
	 *
	 * @param int     $post_id Saved post identifier.
	 * @param WP_Post $post    Saved post.
	 * @return void
	 */
	public function synchronize_saved_post( $post_id, $post ) {
		if (
			! $post instanceof WP_Post
			|| $this->synchronizer->is_writing()
			|| $this->post_translations->is_creating_translation()
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| 'auto-draft' === $post->post_status
			|| ! $this->has_enabled_sync_items()
		) {
			return;
		}

		$this->synchronizer->synchronize_from( absint( $post_id ) );
	}

	/**
	 * Blocks metadata writes an editor is not allowed to propagate.
	 *
	 * A synchronized custom field belongs to every translation in the group. An
	 * editor who cannot edit those translations must not change it indirectly, so
	 * the write is refused instead of silently applying to one language only.
	 *
	 * @param null|bool $check     Short-circuit value passed by WordPress.
	 * @param int       $object_id Post identifier.
	 * @param string    $meta_key  Meta key being written.
	 * @return null|bool
	 */
	public function guard_synchronized_metadata( $check, $object_id, $meta_key ) {
		if (
			null !== $check
			|| ! is_string( $meta_key )
			|| $this->guarding
			|| $this->synchronizer->is_writing()
			|| ! $this->has_enabled_sync_items()
		) {
			return $check;
		}

		$post_id = absint( $object_id );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->post_translations->supports_post_type( $post->post_type ) ) {
			return $check;
		}

		// Capability and meta-key resolution can read metadata themselves.
		$this->guarding = true;

		try {
			$translations = $this->post_translations->get_translations( $post_id );

			if ( 2 > count( $translations ) || $this->synchronizer->current_user_can_synchronize( $post_id ) ) {
				return $check;
			}

			foreach ( $translations as $language_id => $translation_id ) {
				if ( absint( $translation_id ) === $post_id ) {
					continue;
				}

				$keys = $this->synchronizer->get_meta_keys( $post_id, absint( $translation_id ), (string) $language_id, true );

				if ( in_array( $meta_key, $keys, true ) ) {
					return false;
				}
			}
		} finally {
			$this->guarding = false;
		}

		return $check;
	}

	/**
	 * Reports whether any synchronization item is enabled, once per request.
	 *
	 * @return bool
	 */
	private function has_enabled_sync_items() {
		if ( null === $this->sync_enabled ) {
			$this->sync_enabled = $this->workflow_settings->has_enabled_sync_items();
		}

		return $this->sync_enabled;
	}
}
