<?php
/**
 * Translation copy and synchronization engine.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Sync;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Settings\SyncCatalog;
use ShapeTranslate\Settings\WorkflowSettings;
use ShapeTranslate\Taxonomy\TaxonomySupport;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Copies enabled items into a new translation and keeps chosen items in sync.
 *
 * The engine runs in two phases. Creating a translation copies every item whose
 * copy option is enabled, once. After that, only items whose synchronization
 * option is enabled follow later edits, and only for editors allowed to change
 * every translation in the group.
 */
final class TranslationSynchronizer {

	/**
	 * Meta keys that must never be written to another post.
	 *
	 * These describe one specific post's editing or trash state, so copying them
	 * would corrupt the target. They are enforced after developer filters run.
	 *
	 * @var array<int, string>
	 */
	const BLOCKED_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pingme',
		'_encloseme',
	);

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Taxonomy support policy.
	 *
	 * @var TaxonomySupport
	 */
	private $taxonomy_support;

	/**
	 * Whether the engine is currently writing to a translation.
	 *
	 * @var bool
	 */
	private $writing = false;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 * @param WorkflowSettings       $workflow_settings Workflow settings.
	 * @param TaxonomySupport        $taxonomy_support  Taxonomy support policy.
	 */
	public function __construct(
		PostTranslationManager $post_translations,
		TermTranslationManager $term_translations,
		WorkflowSettings $workflow_settings,
		TaxonomySupport $taxonomy_support
	) {
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
		$this->workflow_settings = $workflow_settings;
		$this->taxonomy_support  = $taxonomy_support;
	}

	/**
	 * Copies enabled items into a freshly created translation.
	 *
	 * Content, excerpt, and the featured image are already applied by the
	 * translation manager while inserting the draft, so this phase covers
	 * taxonomies, custom fields, the page template, sticky state, and post format.
	 *
	 * @param int                 $target_id    Translated post identifier.
	 * @param int                 $source_id    Source post identifier.
	 * @param string              $language_id  Target language identifier.
	 * @param array<string, bool> $copy_options Resolved copy options.
	 * @return void
	 */
	public function copy( $target_id, $source_id, $language_id, array $copy_options = array() ) {
		$source = get_post( absint( $source_id ) );
		$target = get_post( absint( $target_id ) );

		if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post || $source->ID === $target->ID ) {
			return;
		}

		$options = empty( $copy_options ) ? $this->workflow_settings->get() : $copy_options;

		$this->apply_items( $source, $target, (string) $language_id, $options, false );

		/**
		 * Fires after enabled items have been copied into a new translation.
		 *
		 * @param int                 $target_id   Translated post identifier.
		 * @param int                 $source_id   Source post identifier.
		 * @param string              $language_id Target language identifier.
		 * @param array<string, bool> $options     Applied copy options.
		 */
		do_action( 'shapetranslate_translation_items_copied', $target->ID, $source->ID, (string) $language_id, $options );
	}

	/**
	 * Propagates enabled items from one post to every other post in its group.
	 *
	 * @param int $post_id Post that was just saved.
	 * @return void
	 */
	public function synchronize_from( $post_id ) {
		$source = get_post( absint( $post_id ) );

		if ( ! $source instanceof WP_Post || ! $this->post_translations->supports_post_type( $source->post_type ) ) {
			return;
		}

		if ( ! $this->workflow_settings->has_enabled_sync_items() || ! $this->current_user_can_synchronize( $source->ID ) ) {
			return;
		}

		$options = $this->workflow_settings->get();

		foreach ( $this->post_translations->get_translations( $source->ID ) as $language_id => $translation_id ) {
			$target = absint( $translation_id ) === $source->ID ? null : get_post( absint( $translation_id ) );

			if ( ! $target instanceof WP_Post ) {
				continue;
			}

			$this->apply_items( $source, $target, (string) $language_id, $options, true );

			/**
			 * Fires after enabled items have been synchronized into one translation.
			 *
			 * @param int    $target_id   Translated post identifier.
			 * @param int    $source_id   Edited post identifier.
			 * @param string $language_id Target language identifier.
			 */
			do_action( 'shapetranslate_translation_items_synchronized', $target->ID, $source->ID, (string) $language_id );
		}
	}

	/**
	 * Propagates one post's sticky state to its translations.
	 *
	 * WordPress sticks or unsticks a post only after saving it, in Quick Edit,
	 * the classic editor and the REST API alike, so synchronize_from() on
	 * `save_post` still reads the old state. This runs from `post_stuck` and
	 * `post_unstuck`, once the new state is stored.
	 *
	 * @param int $post_id Post whose sticky state changed.
	 * @return void
	 */
	public function synchronize_sticky( $post_id ) {
		$source = get_post( absint( $post_id ) );

		if (
			$this->writing
			|| ! $source instanceof WP_Post
			|| 'post' !== $source->post_type
			|| ! $this->post_translations->supports_post_type( $source->post_type )
			|| ! $this->is_enabled( 'sticky', true )
			|| ! $this->current_user_can_synchronize( $source->ID )
		) {
			return;
		}

		$options       = $this->workflow_settings->get();
		$was_writing   = $this->writing;
		$this->writing = true;

		try {
			foreach ( $this->post_translations->get_translations( $source->ID ) as $translation_id ) {
				$target = absint( $translation_id ) === $source->ID ? null : get_post( absint( $translation_id ) );

				if ( $target instanceof WP_Post ) {
					$this->apply_sticky( $source, $target, $options, true );
				}
			}
		} finally {
			$this->writing = $was_writing;
		}
	}

	/**
	 * Reports whether the current user may change synchronized data.
	 *
	 * Synchronization writes to posts the editor never opened, so it is allowed
	 * only when that editor could have edited each of them directly.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	public function current_user_can_synchronize( $post_id ) {
		$post_id = absint( $post_id );
		$allowed = true;

		foreach ( $this->post_translations->get_translations( $post_id ) as $translation_id ) {
			$translation_id = absint( $translation_id );

			if ( $translation_id === $post_id || 1 > $translation_id ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $translation_id ) ) {
				$allowed = false;
				break;
			}
		}

		/**
		 * Filters whether the current user may change synchronized data.
		 *
		 * @param bool $allowed Whether synchronization is permitted.
		 * @param int  $post_id Post the editor is working on.
		 */
		return (bool) apply_filters( 'shapetranslate_current_user_can_synchronize', $allowed, $post_id );
	}

	/**
	 * Returns the meta keys one phase writes into a translation.
	 *
	 * Public custom fields are collected from both posts so a field cleared on
	 * the source is also cleared on the translation. Protected keys are excluded
	 * because they usually belong to one specific post; the page template and the
	 * featured image are the documented exceptions.
	 *
	 * @param int                      $source_id   Source post identifier.
	 * @param int                      $target_id   Target post identifier.
	 * @param string                   $language_id Target language identifier.
	 * @param bool                     $sync        True for the synchronization phase.
	 * @param array<string, bool>|null $options     Resolved options, or null to read stored settings.
	 * @return array<int, string>
	 */
	public function get_meta_keys( $source_id, $target_id, $language_id, $sync, ?array $options = null ) {
		$keys = array();

		if ( $this->is_enabled( 'post_meta', $sync, $options ) ) {
			$source_keys = (array) get_post_custom_keys( $source_id );
			$target_keys = (array) get_post_custom_keys( $target_id );

			foreach ( array_unique( array_merge( $source_keys, $target_keys ) ) as $meta_key ) {
				if ( is_string( $meta_key ) && ! is_protected_meta( $meta_key, 'post' ) ) {
					$keys[] = $meta_key;
				}
			}
		}

		if ( $this->is_enabled( 'page_template', $sync, $options ) ) {
			$keys[] = '_wp_page_template';
		}

		if ( $sync && $this->is_enabled( 'featured_image', true, $options ) ) {
			$keys[] = '_thumbnail_id';
		}

		/**
		 * Filters the meta keys copied into or synchronized with a translation.
		 *
		 * @param array<int, string> $keys        Meta keys.
		 * @param bool               $sync        True for synchronization, false for the one-time copy.
		 * @param int                $source_id   Source post identifier.
		 * @param int                $target_id   Target post identifier.
		 * @param string             $language_id Target language identifier.
		 */
		$filtered = apply_filters( 'shapetranslate_copy_post_meta_keys', $keys, $sync, $source_id, $target_id, $language_id );
		$keys     = is_array( $filtered ) ? $filtered : $keys;
		$resolved = array();

		foreach ( $keys as $meta_key ) {
			if ( is_string( $meta_key ) && '' !== $meta_key && ! in_array( $meta_key, self::BLOCKED_META_KEYS, true ) ) {
				$resolved[] = $meta_key;
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	/**
	 * Reports whether the engine is currently writing to a translation.
	 *
	 * @return bool
	 */
	public function is_writing() {
		return $this->writing;
	}

	/**
	 * Applies every enabled item of one phase to a single translation.
	 *
	 * @param WP_Post             $source      Source post.
	 * @param WP_Post             $target      Target post.
	 * @param string              $language_id Target language identifier.
	 * @param array<string, bool> $options     Resolved options.
	 * @param bool                $sync        True for the synchronization phase.
	 * @return void
	 */
	private function apply_items( WP_Post $source, WP_Post $target, $language_id, array $options, $sync ) {
		$was_writing   = $this->writing;
		$this->writing = true;

		try {
			$this->apply_meta( $source, $target, $language_id, $options, $sync );
			$this->apply_taxonomies( $source, $target, $language_id, $options, $sync );
			$this->apply_sticky( $source, $target, $options, $sync );

			if ( $sync ) {
				$this->apply_post_fields( $source, $target, $language_id, $options );
			}
		} finally {
			$this->writing = $was_writing;
		}
	}

	/**
	 * Writes the resolved meta keys into the translation.
	 *
	 * @param WP_Post             $source      Source post.
	 * @param WP_Post             $target      Target post.
	 * @param string              $language_id Target language identifier.
	 * @param array<string, bool> $options     Resolved options.
	 * @param bool                $sync        True for the synchronization phase.
	 * @return void
	 */
	private function apply_meta( WP_Post $source, WP_Post $target, $language_id, array $options, $sync ) {
		foreach ( $this->get_meta_keys( $source->ID, $target->ID, $language_id, $sync, $options ) as $meta_key ) {
			// False, not true: a meta key can hold several values and all of them are copied.
			$source_values = get_post_meta( $source->ID, $meta_key, false );
			$target_values = get_post_meta( $target->ID, $meta_key, false );
			$source_values = is_array( $source_values ) ? $source_values : array();
			$target_values = is_array( $target_values ) ? $target_values : array();

			if ( empty( $source_values ) ) {
				if ( ! empty( $target_values ) ) {
					delete_post_meta( $target->ID, $meta_key );
				}

				continue;
			}

			if ( ! empty( $target_values ) ) {
				delete_post_meta( $target->ID, $meta_key );
			}

			foreach ( $source_values as $value ) {
				$value = $this->translate_meta_value( $value, $meta_key, $source->ID, $target->ID, $language_id );

				add_post_meta( $target->ID, $meta_key, is_object( $value ) ? $value : wp_slash( $value ) );
			}
		}
	}

	/**
	 * Lets a translated value replace a copied meta value.
	 *
	 * @param mixed  $value       Stored meta value.
	 * @param string $meta_key    Meta key.
	 * @param int    $source_id   Source post identifier.
	 * @param int    $target_id   Target post identifier.
	 * @param string $language_id Target language identifier.
	 * @return mixed
	 */
	private function translate_meta_value( $value, $meta_key, $source_id, $target_id, $language_id ) {
		/**
		 * Filters one meta value before it is written to a translation.
		 *
		 * Media is shared between languages in Free, so attachment identifiers are
		 * passed through unchanged unless an addon maps them.
		 *
		 * @param mixed  $value       Stored meta value.
		 * @param string $meta_key    Meta key.
		 * @param string $language_id Target language identifier.
		 * @param int    $source_id   Source post identifier.
		 * @param int    $target_id   Target post identifier.
		 */
		return apply_filters( 'shapetranslate_translate_post_meta_value', $value, $meta_key, $language_id, $source_id, $target_id );
	}

	/**
	 * Assigns the translation's terms for every eligible taxonomy.
	 *
	 * @param WP_Post             $source      Source post.
	 * @param WP_Post             $target      Target post.
	 * @param string              $language_id Target language identifier.
	 * @param array<string, bool> $options     Resolved options.
	 * @param bool                $sync        True for the synchronization phase.
	 * @return void
	 */
	private function apply_taxonomies( WP_Post $source, WP_Post $target, $language_id, array $options, $sync ) {
		$copy_taxonomies = $this->is_enabled( 'taxonomies', $sync, $options );
		$copy_format     = $this->is_enabled( 'post_format', $sync, $options );

		if ( ! $copy_taxonomies && ! $copy_format ) {
			return;
		}

		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$is_format = 'post_format' === $taxonomy;

			if ( $is_format ? ! $copy_format : ! $copy_taxonomies ) {
				continue;
			}

			$terms = wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$mapped = $this->map_terms( (array) $terms, $taxonomy, $language_id );

			if ( ! $is_format ) {
				$mapped = array_merge( $mapped, $this->target_only_terms( $source, $target, $taxonomy ) );
			}

			wp_set_object_terms( $target->ID, array_values( array_unique( $mapped ) ), $taxonomy );
		}
	}

	/**
	 * Returns the terms on a translation that its source language cannot express.
	 *
	 * Synchronization replaces the target's terms with the source's, so a term
	 * the other language chose and the source has no translation of would be
	 * deleted by a save the editor never connected to it. Such a term was never
	 * the source's to remove, so it survives the write. A term the source does
	 * have a translation of stays removable, which is what makes unassigning one
	 * still propagate.
	 *
	 * @param WP_Post $source   Post being saved.
	 * @param WP_Post $target   Translation being written to.
	 * @param string  $taxonomy Taxonomy name.
	 * @return array<int, int>
	 */
	private function target_only_terms( WP_Post $source, WP_Post $target, $taxonomy ) {
		if ( ! $this->taxonomy_support->supports( $taxonomy ) ) {
			return array();
		}

		$source_language_id = $this->post_translations->get_post_language_id( $source->ID );

		if ( '' === $source_language_id ) {
			return array();
		}

		$assigned = wp_get_object_terms( $target->ID, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $assigned ) ) {
			return array();
		}

		$kept = array();

		foreach ( $assigned as $term_id ) {
			$term_id = absint( $term_id );

			if ( 1 > $term_id ) {
				continue;
			}

			$in_source = absint(
				$this->term_translations->get_translation( $term_id, $taxonomy, $source_language_id )
			);

			if ( 1 > $in_source ) {
				$kept[] = $term_id;
			}
		}

		return $kept;
	}

	/**
	 * Maps source term IDs to their translations when the taxonomy is translatable.
	 *
	 * A term with no translation in the target language is dropped rather than
	 * carried over, because an assignment naming another language's term shows a
	 * translation holding terms its own language cannot list, and the editor then
	 * removes them on the next save anyway. `shapetranslate_mapped_term_id` can keep
	 * the source term instead.
	 *
	 * @param array<int, mixed> $term_ids    Source term identifiers.
	 * @param string            $taxonomy    Taxonomy name.
	 * @param string            $language_id Target language identifier.
	 * @return array<int, int>
	 */
	private function map_terms( array $term_ids, $taxonomy, $language_id ) {
		$translatable = '' !== $language_id && $this->taxonomy_support->supports( $taxonomy );
		$mapped       = array();

		foreach ( $term_ids as $term_id ) {
			$term_id = absint( $term_id );

			if ( 1 > $term_id ) {
				continue;
			}

			if ( ! $translatable ) {
				$mapped[] = $term_id;

				continue;
			}

			$translation = absint(
				$this->term_translations->get_translation( $term_id, $taxonomy, $language_id )
			);

			/**
			 * Filters the term a source assignment becomes in the target language.
			 *
			 * Returning zero drops the assignment, which is what an untranslated
			 * term does by default.
			 *
			 * @param int    $translation Translated term identifier, or zero when none exists.
			 * @param int    $term_id     Source term identifier.
			 * @param string $taxonomy    Taxonomy name.
			 * @param string $language_id Target language identifier.
			 */
			$translation = absint(
				apply_filters(
					'shapetranslate_mapped_term_id',
					$translation,
					$term_id,
					$taxonomy,
					$language_id
				)
			);

			if ( 0 < $translation ) {
				$mapped[] = $translation;
			}
		}

		return array_values( array_unique( $mapped ) );
	}

	/**
	 * Aligns the translation's sticky state with its source.
	 *
	 * @param WP_Post             $source  Source post.
	 * @param WP_Post             $target  Target post.
	 * @param array<string, bool> $options Resolved options.
	 * @param bool                $sync    True for the synchronization phase.
	 * @return void
	 */
	private function apply_sticky( WP_Post $source, WP_Post $target, array $options, $sync ) {
		if ( ! $this->is_enabled( 'sticky', $sync, $options ) || 'post' !== $target->post_type ) {
			return;
		}

		if ( is_sticky( $source->ID ) ) {
			stick_post( $target->ID );

			return;
		}

		if ( $sync && is_sticky( $target->ID ) ) {
			unstick_post( $target->ID );
		}
	}

	/**
	 * Synchronizes enabled core post fields into the translation.
	 *
	 * Only fields that actually differ are written, so an unchanged translation
	 * never receives a new modification date.
	 *
	 * @param WP_Post             $source      Source post.
	 * @param WP_Post             $target      Target post.
	 * @param string              $language_id Target language identifier.
	 * @param array<string, bool> $options     Resolved options.
	 * @return void
	 */
	private function apply_post_fields( WP_Post $source, WP_Post $target, $language_id, array $options ) {
		$fields = array();

		if ( $this->is_enabled( 'comment_status', true, $options ) && $source->comment_status !== $target->comment_status ) {
			$fields['comment_status'] = $source->comment_status;
		}

		if ( $this->is_enabled( 'ping_status', true, $options ) && $source->ping_status !== $target->ping_status ) {
			$fields['ping_status'] = $source->ping_status;
		}

		if ( $this->is_enabled( 'menu_order', true, $options ) && (int) $source->menu_order !== (int) $target->menu_order ) {
			$fields['menu_order'] = (int) $source->menu_order;
		}

		if ( $this->is_enabled( 'post_date', true, $options ) && $source->post_date !== $target->post_date ) {
			$fields['post_date']     = $source->post_date;
			$fields['post_date_gmt'] = $source->post_date_gmt;
			$fields['edit_date']     = true;
		}

		if ( $this->is_enabled( 'post_parent', true, $options ) ) {
			$parent = $this->resolve_parent( (int) $source->post_parent, $language_id );

			if ( $parent !== (int) $target->post_parent ) {
				$fields['post_parent'] = $parent;
			}
		}

		if ( empty( $fields ) ) {
			return;
		}

		$fields['ID'] = $target->ID;

		wp_update_post( wp_slash( $fields ) );
	}

	/**
	 * Resolves the post parent a translation should use.
	 *
	 * @param int    $source_parent Source parent identifier.
	 * @param string $language_id   Target language identifier.
	 * @return int
	 */
	public function resolve_parent( $source_parent, $language_id ) {
		$source_parent = absint( $source_parent );

		if ( 1 > $source_parent || '' === (string) $language_id ) {
			return $source_parent;
		}

		$translated_parent = absint( $this->post_translations->get_translation( $source_parent, $language_id ) );

		return 0 < $translated_parent ? $translated_parent : $source_parent;
	}

	/**
	 * Reports whether one catalog item is enabled for the running phase.
	 *
	 * @param string                   $item_id Catalog item identifier.
	 * @param bool                     $sync    True for the synchronization phase.
	 * @param array<string, bool>|null $options Resolved options, or null to read stored settings.
	 * @return bool
	 */
	private function is_enabled( $item_id, $sync, ?array $options = null ) {
		if ( $sync ) {
			$settings = null === $options ? $this->workflow_settings->get() : $options;

			return ! empty( $settings[ SyncCatalog::sync_key( $item_id ) ] );
		}

		return $this->workflow_settings->is_copy_enabled( $item_id, $options );
	}
}
