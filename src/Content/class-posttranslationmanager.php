<?php
/**
 * Post translation relationship manager.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Contracts\TranslationRepositoryInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Settings\WorkflowSettings;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates post language assignments and translation groups.
 */
final class PostTranslationManager {

	/**
	 * Translation repository.
	 *
	 * @var TranslationRepositoryInterface
	 */
	private $repository;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Supported post type policy.
	 *
	 * @var PostTypeSupport
	 */
	private $post_type_support;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Whether ShapeTranslate is inserting a translated copy.
	 *
	 * @var bool
	 */
	private $creating_translation = false;

	/**
	 * Language a translated copy is being inserted for.
	 *
	 * @var string
	 */
	private $creating_language_id = '';

	/**
	 * Constructor.
	 *
	 * @param TranslationRepositoryInterface $repository        Translation repository.
	 * @param LanguageManager                $language_manager  Language manager.
	 * @param PostTypeSupport                $post_type_support  Supported post type policy.
	 * @param WorkflowSettings|null          $workflow_settings Optional workflow settings.
	 */
	public function __construct(
		TranslationRepositoryInterface $repository,
		LanguageManager $language_manager,
		PostTypeSupport $post_type_support,
		?WorkflowSettings $workflow_settings = null
	) {
		$this->repository        = $repository;
		$this->language_manager  = $language_manager;
		$this->post_type_support = $post_type_support;
		$this->workflow_settings = null === $workflow_settings ? new WorkflowSettings() : $workflow_settings;
	}

	/**
	 * Returns the language identifier assigned to a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function get_post_language_id( $post_id ) {
		$post_id    = absint( $post_id );
		$assignment = $this->repository->find_by_post( $post_id );

		if ( null !== $assignment ) {
			return $assignment['language_id'];
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post && $this->post_type_support->supports( $post->post_type )
			? $this->get_default_language_id()
			: '';
	}

	/**
	 * Returns the language record assigned to a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, mixed>|null
	 */
	public function get_post_language( $post_id ) {
		$language_id = $this->get_post_language_id( $post_id );
		$language    = '' === $language_id ? null : $this->language_manager->find( $language_id );

		/**
		 * Filters the language assigned to a post.
		 *
		 * @param array<string, mixed>|null $language Language record.
		 * @param int                       $post_id  Post identifier.
		 */
		$filtered = apply_filters( 'shapetranslate_post_language', $language, absint( $post_id ) );

		return is_array( $filtered ) || null === $filtered ? $filtered : $language;
	}

	/**
	 * Returns the translation group identifier for a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function get_group_id( $post_id ) {
		$assignment = $this->repository->find_by_post( absint( $post_id ) );

		return null === $assignment ? '' : $assignment['group_id'];
	}

	/**
	 * Returns the source post identifier for a post's translation group.
	 *
	 * @param int $post_id Post identifier.
	 * @return int
	 */
	public function get_source_post_id( $post_id ) {
		$group_id = $this->get_group_id( $post_id );
		$group    = '' === $group_id ? null : $this->repository->find_group( $group_id );

		if ( null !== $group ) {
			return absint( $group['source_post_id'] );
		}

		return '' === $this->get_post_language_id( $post_id ) ? 0 : absint( $post_id );
	}

	/**
	 * Returns a translated post for one language.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id Language identifier.
	 * @return int Translated post ID, or zero when unavailable.
	 */
	public function get_translation( $post_id, $language_id ) {
		$translations = $this->get_translations( $post_id );

		return isset( $translations[ $language_id ] ) ? $translations[ $language_id ] : 0;
	}

	/**
	 * Returns translation post IDs keyed by stable language identifier.
	 *
	 * The current post is included in the returned group map.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, int>
	 */
	public function get_translations( $post_id ) {
		$post_id     = absint( $post_id );
		$assignment  = $this->repository->find_by_post( $post_id );
		$language_id = $this->get_post_language_id( $post_id );

		if ( null === $assignment ) {
			$translations = '' === $language_id ? array() : array( $language_id => $post_id );
			$group_id     = '';
		} else {
			$translations = $this->filter_usable_translations( $this->get_stored_translations( $post_id ) );
			$group_id     = $assignment['group_id'];
		}

		/**
		 * Filters post translations after relationship validation and loading.
		 *
		 * @param array<string, int> $translations Translation post IDs keyed by language ID.
		 * @param int                $post_id      Requested post identifier.
		 * @param string             $group_id     Translation group identifier.
		 */
		$filtered = apply_filters(
			'shapetranslate_post_translations',
			$translations,
			$post_id,
			$group_id
		);

		return is_array( $filtered ) ? $this->normalize_translation_map( $filtered ) : $translations;
	}

	/**
	 * Assigns a registered, enabled language to a supported post.
	 *
	 * @param int    $post_id     Post identifier.
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function set_post_language( $post_id, $language_id ) {
		$post = $this->validate_post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';
		$assignment  = $this->repository->find_by_post( $post->ID );

		if ( null !== $assignment && $language_id === $assignment['language_id'] ) {
			return $assignment;
		}

		$language = $this->validate_language( $language_id );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( null === $assignment ) {
			$result = $this->link_translations( array( $language_id => $post->ID ), $post->ID );

			return is_wp_error( $result ) ? $result : $this->repository->find_by_post( $post->ID );
		}

		foreach ( $this->repository->get_group_members( $assignment['group_id'] ) as $member ) {
			if ( $language_id === $member['language_id'] && $post->ID !== $member['post_id'] ) {
				return new WP_Error(
					'duplicate_translation_language',
					__( 'This translation group already contains a post for that language.', 'shapetranslate' )
				);
			}
		}

		$old_language_id = $assignment['language_id'];

		if ( ! $this->repository->update_assignment_language( $post->ID, $language_id ) ) {
			return $this->storage_error();
		}

		$updated = $this->repository->find_by_post( $post->ID );

		/**
		 * Fires after a post language assignment changes.
		 *
		 * @param int    $post_id        Post identifier.
		 * @param string $language_id    New language identifier.
		 * @param string $old_language_id Previous language identifier.
		 * @param string $group_id       Translation group identifier.
		 */
		do_action(
			'shapetranslate_post_language_changed',
			$post->ID,
			$language_id,
			$old_language_id,
			$assignment['group_id']
		);

		return $updated;
	}

	/**
	 * Links posts into one translation group.
	 *
	 * Input is keyed by language ID. Existing posts from different groups are
	 * rejected instead of merging groups implicitly.
	 *
	 * @param array<string, int> $translations  Post IDs keyed by language ID.
	 * @param int                $source_post_id Source post identifier for a new group.
	 * @return array<string, mixed>|WP_Error
	 */
	public function link_translations( array $translations, $source_post_id = 0 ) {
		$translations = $this->normalize_translation_map( $translations );

		if ( empty( $translations ) ) {
			return new WP_Error( 'empty_translation_group', __( 'Provide at least one post to link.', 'shapetranslate' ) );
		}

		if ( count( $translations ) !== count( array_unique( array_values( $translations ) ) ) ) {
			return new WP_Error(
				'conflicting_post_languages',
				__( 'A post cannot represent more than one language in a translation group.', 'shapetranslate' )
			);
		}

		$assignments = array();
		$group_ids   = array();
		$post_type   = '';

		foreach ( $translations as $language_id => $post_id ) {
			$post = $this->validate_post( $post_id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			if ( '' !== $post_type && $post_type !== $post->post_type ) {
				return new WP_Error(
					'mixed_translation_post_types',
					__( 'Translations in one group must use the same post type.', 'shapetranslate' )
				);
			}

			$post_type               = $post->post_type;
			$assignments[ $post_id ] = $this->repository->find_by_post( $post_id );
			$existing_assignment     = $assignments[ $post_id ];

			if ( null !== $existing_assignment ) {
				if ( $language_id !== $existing_assignment['language_id'] ) {
					return new WP_Error(
						'conflicting_post_language',
						__( 'A post is already assigned to a different language.', 'shapetranslate' )
					);
				}

				$group_ids[] = $existing_assignment['group_id'];
				continue;
			}

			$language = $this->validate_language( $language_id );

			if ( is_wp_error( $language ) ) {
				return $language;
			}
		}

		$group_ids = array_values( array_unique( $group_ids ) );

		if ( count( $group_ids ) > 1 ) {
			return new WP_Error(
				'conflicting_translation_groups',
				__( 'Posts from different translation groups cannot be linked implicitly.', 'shapetranslate' )
			);
		}

		$is_new_group   = empty( $group_ids );
		$group_id       = $is_new_group ? wp_generate_uuid4() : $group_ids[0];
		$group          = $is_new_group ? null : $this->repository->find_group( $group_id );
		$source_post_id = absint( $source_post_id );

		if ( ! $is_new_group && null === $group ) {
			return $this->storage_error();
		}

		if ( $is_new_group ) {
			if ( ! in_array( $source_post_id, $translations, true ) ) {
				return new WP_Error(
					'invalid_translation_source',
					__( 'The source post must belong to the new translation group.', 'shapetranslate' )
				);
			}
		} else {
			$source_post_id = absint( $group['source_post_id'] );
		}

		$existing_members = $is_new_group ? array() : $this->repository->get_group_members( $group_id );

		foreach ( $translations as $language_id => $post_id ) {
			foreach ( $existing_members as $member ) {
				if ( $language_id === $member['language_id'] && $post_id !== $member['post_id'] ) {
					return new WP_Error(
						'duplicate_translation_language',
						__( 'This translation group already contains a post for that language.', 'shapetranslate' )
					);
				}
			}
		}

		if ( $is_new_group && ! $this->repository->create_group( $group_id, $source_post_id ) ) {
			return $this->storage_error();
		}

		$added_post_ids = array();

		foreach ( $translations as $language_id => $post_id ) {
			if ( null !== $assignments[ $post_id ] ) {
				continue;
			}

			if ( ! $this->repository->add_assignment( $post_id, $group_id, $language_id ) ) {
				$this->rollback_link( $group_id, $added_post_ids, $is_new_group );

				return $this->storage_error();
			}

			$added_post_ids[] = $post_id;
		}

		$result = array(
			'group_id'       => $group_id,
			'source_post_id' => $source_post_id,
			'translations'   => $this->get_stored_translations( reset( $translations ) ),
		);

		/**
		 * Fires after posts are linked into a translation group.
		 *
		 * @param string             $group_id      Translation group identifier.
		 * @param array<string, int> $translations  Complete translation map.
		 * @param int                $source_post_id Source post identifier.
		 */
		do_action(
			'shapetranslate_translations_linked',
			$group_id,
			$result['translations'],
			$source_post_id
		);

		return $result;
	}

	/**
	 * Creates a translated draft and links it to its source.
	 *
	 * Core block content is copied as stored so WordPress block comments and
	 * attributes are not parsed or re-serialized. Core post fields are copied
	 * unconditionally, except the published date, which follows its
	 * synchronization setting. Taxonomies, custom fields, the page template, and
	 * sticky state are applied afterwards by the synchronization engine, which
	 * listens for the creation event this method fires.
	 *
	 * @param int                  $source_post_id Source post identifier.
	 * @param string               $language_id    Target language identifier.
	 * @param array<string, mixed> $options Optional copy behavior overrides.
	 * @return int|WP_Error Created post ID on success.
	 */
	public function create_translation( $source_post_id, $language_id, array $options = array() ) {
		$source = $this->validate_post( $source_post_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( 'trash' === $source->post_status ) {
			return new WP_Error( 'source_post_trashed', __( 'A trashed post cannot be used as a translation source.', 'shapetranslate' ) );
		}

		$source_assignment = $this->repository->find_by_post( $source->ID );

		if ( null === $source_assignment ) {
			$source_assignment = $this->assign_default_language( $source->ID );

			if ( is_wp_error( $source_assignment ) ) {
				return $source_assignment;
			}
		}

		$language = $this->validate_language( $language_id );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$stored_translations = $this->get_stored_translations( $source->ID );

		if ( isset( $stored_translations[ $language_id ] ) ) {
			$usable_translations = $this->filter_usable_translations( $stored_translations );

			if ( isset( $usable_translations[ $language_id ] ) ) {
				return new WP_Error(
					'duplicate_translation_language',
					__( 'This translation group already contains a post for that language.', 'shapetranslate' )
				);
			}

			/*
			 * The language is held by something that cannot stand in as a
			 * translation — an abandoned auto-draft, or a post the editor has
			 * thrown away — so the group releases the slot instead of refusing the
			 * request. Releasing also repoints a group whose source was that
			 * member, which gives the remaining translations a resolvable route
			 * again. The released post keeps the language it was written in and moves
			 * into a group of its own, so restoring it from the trash afterwards
			 * returns it unlinked rather than in the wrong language.
			 */
			$this->release_language_slot( $stored_translations[ $language_id ] );
		}

		$copy_options = $this->workflow_settings->resolve_copy_options( $source, $language, $options );

		$post_data = array(
			'post_type'    => $source->post_type,
			'post_status'  => 'draft',
			'post_title'   => $source->post_title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_author'  => get_current_user_id() ? get_current_user_id() : $source->post_author,
		);

		if ( ! $copy_options['copy_content'] ) {
			$post_data['post_content'] = '';
			$post_data['post_excerpt'] = '';
		}

		$post_data['comment_status'] = $source->comment_status;
		$post_data['ping_status']    = $source->ping_status;
		$post_data['menu_order']     = (int) $source->menu_order;
		$post_data['post_parent']    = $this->resolve_translated_parent( (int) $source->post_parent, $language_id );

		if ( $this->workflow_settings->is_copy_enabled( 'post_date', $copy_options ) ) {
			$post_data['post_date']     = $source->post_date;
			$post_data['post_date_gmt'] = $source->post_date_gmt;
		}

		/**
		 * Filters core post fields copied into a new translated draft.
		 *
		 * Taxonomy and metadata copying must be implemented by later modules.
		 *
		 * @param array<string, mixed> $post_data  New draft fields.
		 * @param WP_Post              $source     Source post.
		 * @param array<string, mixed> $language   Target language.
		 */
		$filtered_data = apply_filters( 'shapetranslate_translation_copy_post_data', $post_data, $source, $language );
		$post_data     = is_array( $filtered_data ) ? $filtered_data : $post_data;

		// The core engine never permits copy filters to change these boundaries.
		unset( $post_data['ID'] );
		$post_data['post_type']     = $source->post_type;
		$post_data['post_status']   = 'draft';
		$this->creating_language_id = (string) $language['id'];
		$this->creating_translation = true;

		try {
			/**
			 * Filters the insertion of a translated draft.
			 *
			 * Post types that WordPress does not create through `wp_insert_post()`
			 * alone can return an identifier or a WP_Error here. Media uses this to
			 * insert an attachment that points at the source file instead of a copy.
			 * Returning null keeps the standard insertion.
			 *
			 * @param int|\WP_Error|null   $new_post_id Inserted post identifier.
			 * @param array<string, mixed> $post_data   Prepared post fields.
			 * @param WP_Post              $source      Source post.
			 * @param array<string, mixed> $language    Target language record.
			 */
			$new_post_id = apply_filters( 'shapetranslate_insert_translation', null, $post_data, $source, $language );

			if ( null === $new_post_id ) {
				$new_post_id = wp_insert_post( wp_slash( $post_data ), true );
			}
		} finally {
			$this->creating_translation = false;
			$this->creating_language_id = '';
		}

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$new_post_id = absint( $new_post_id );

		if ( 1 > $new_post_id ) {
			return new WP_Error( 'translation_insert_failed', __( 'The translated item could not be created.', 'shapetranslate' ) );
		}

		$link_result = $this->link_translations(
			array(
				$source_assignment['language_id'] => $source->ID,
				$language_id                      => $new_post_id,
			),
			$source->ID
		);

		if ( is_wp_error( $link_result ) ) {
			wp_delete_post( $new_post_id, true );

			return $link_result;
		}

		if ( $copy_options['copy_featured_image'] ) {
			$thumbnail_id = absint( get_post_thumbnail_id( $source->ID ) );

			/**
			 * Filters the attachment a translated draft uses as its featured image.
			 *
			 * With media translation enabled this resolves to the attachment's own
			 * translation, so each language can carry its own alternative text.
			 *
			 * @param int                  $thumbnail_id Source attachment identifier.
			 * @param array<string, mixed> $language     Target language record.
			 * @param int                  $new_post_id  Translated post identifier.
			 * @param int                  $source_id    Source post identifier.
			 */
			$thumbnail_id = absint( apply_filters( 'shapetranslate_translation_featured_image_id', $thumbnail_id, $language, $new_post_id, $source->ID ) );

			if ( 0 < $thumbnail_id && set_post_thumbnail( $new_post_id, $thumbnail_id ) ) {
				/**
				 * Fires after a translated draft reuses its source featured image.
				 *
				 * @param int $new_post_id  Translated post identifier.
				 * @param int $source_id    Source post identifier.
				 * @param int $thumbnail_id Shared attachment identifier.
				 */
				do_action( 'shapetranslate_translation_featured_image_copied', $new_post_id, $source->ID, $thumbnail_id );
			}
		}

		/**
		 * Fires after a translated draft has been created and linked.
		 *
		 * @param int                  $new_post_id New translated post identifier.
		 * @param int                  $source_id   Source post identifier.
		 * @param array<string, mixed> $language    Target language record.
		 * @param string               $group_id    Translation group identifier.
		 * @param array<string, bool>  $copy_options Applied copy behavior.
		 */
		do_action(
			'shapetranslate_translation_created',
			$new_post_id,
			$source->ID,
			$language,
			$link_result['group_id'],
			$copy_options
		);

		return $new_post_id;
	}

	/**
	 * Resolves the parent a translated draft should be attached to.
	 *
	 * A parent that is itself translated keeps the translated tree intact. An
	 * untranslated parent is reused so the draft never becomes a stray top-level
	 * page.
	 *
	 * @param int    $source_parent Source parent identifier.
	 * @param string $language_id   Target language identifier.
	 * @return int
	 */
	private function resolve_translated_parent( $source_parent, $language_id ) {
		$source_parent = absint( $source_parent );

		if ( 1 > $source_parent ) {
			return 0;
		}

		$translated_parent = absint( $this->get_translation( $source_parent, $language_id ) );

		return 0 < $translated_parent ? $translated_parent : $source_parent;
	}

	/**
	 * Persists the configured default language for an unassigned post.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function assign_default_language( $post_id ) {
		$assignment = $this->repository->find_by_post( absint( $post_id ) );

		if ( null !== $assignment ) {
			return $assignment;
		}

		$language_id = $this->get_default_language_id();

		if ( '' === $language_id ) {
			return new WP_Error(
				'missing_default_language',
				__( 'Configure an enabled default language before translating content.', 'shapetranslate' )
			);
		}

		return $this->set_post_language( $post_id, $language_id );
	}

	/**
	 * Reports whether ShapeTranslate is inserting a translated copy.
	 *
	 * @return bool
	 */
	public function is_creating_translation() {
		return $this->creating_translation;
	}

	/**
	 * Returns the language a translated copy is being inserted for.
	 *
	 * Empty unless an insertion is in flight, which lets services that run
	 * inside wp_insert_post() know the language of a post that has not been
	 * linked to its group yet.
	 *
	 * @return string
	 */
	public function get_creating_language_id() {
		return $this->creating_language_id;
	}

	/**
	 * Removes a post from its translation group and repairs the group.
	 *
	 * The assignment row is dropped entirely, so the post is left with no
	 * language at all. That is only correct for content WordPress is deleting for
	 * good. A post that survives the call must be handed to detach_from_group()
	 * instead, which keeps its language.
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	public function remove_post( $post_id ) {
		$post_id    = absint( $post_id );
		$assignment = $this->repository->find_by_post( $post_id );

		if ( null === $assignment || ! $this->repository->remove_assignment( $post_id ) ) {
			return;
		}

		$this->repair_group( $assignment['group_id'], $post_id );

		/**
		 * Fires after a post is removed from its translation group.
		 *
		 * @param int                  $post_id    Removed post identifier.
		 * @param array<string, mixed> $assignment Previous assignment.
		 */
		do_action( 'shapetranslate_post_translation_unlinked', $post_id, $assignment );
	}

	/**
	 * Moves a post out of its translation group while keeping its language.
	 *
	 * The post becomes the source of a group of its own, which is the state every
	 * untranslated post is already in. Deleting the assignment instead would take
	 * the language with it, and the post would silently pick the default one back
	 * up the next time WordPress saved it. Restoring from the trash runs
	 * wp_update_post(), so a post written in German would return in English.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool Whether the post was detached.
	 */
	public function detach_from_group( $post_id ) {
		$post_id    = absint( $post_id );
		$assignment = $this->repository->find_by_post( $post_id );

		if ( null === $assignment ) {
			return false;
		}

		$previous_group_id = $assignment['group_id'];
		$language_id       = $assignment['language_id'];
		$group_id          = wp_generate_uuid4();

		if ( ! $this->repository->create_group( $group_id, $post_id ) ) {
			return false;
		}

		if ( ! $this->repository->remove_assignment( $post_id ) ) {
			$this->repository->delete_group( $group_id );

			return false;
		}

		if ( ! $this->repository->add_assignment( $post_id, $group_id, $language_id ) ) {
			// A storage failure must not be the reason a post loses its language.
			$this->repository->add_assignment( $post_id, $previous_group_id, $language_id );
			$this->repository->delete_group( $group_id );

			return false;
		}

		$this->repair_group( $previous_group_id, $post_id );

		/** This action is documented in src/Content/class-posttranslationmanager.php */
		do_action( 'shapetranslate_post_translation_unlinked', $post_id, $assignment );

		return true;
	}

	/**
	 * Repairs a translation group one of its members has just left.
	 *
	 * An emptied group is deleted. A group that lost the member its source
	 * pointer named is repointed at a surviving member, which keeps the remaining
	 * translations reachable.
	 *
	 * @param string $group_id        Translation group identifier.
	 * @param int    $removed_post_id Post that left the group.
	 * @return void
	 */
	private function repair_group( $group_id, $removed_post_id ) {
		$members = $this->repository->get_group_members( $group_id );

		if ( empty( $members ) ) {
			$this->repository->delete_group( $group_id );
			do_action( 'shapetranslate_translation_group_deleted', $group_id );

			return;
		}

		$group = $this->repository->find_group( $group_id );

		if ( null !== $group && absint( $removed_post_id ) === $group['source_post_id'] ) {
			$this->repository->update_group_source( $group_id, $members[0]['post_id'] );
		}
	}

	/**
	 * Frees the language slot a post holds in its translation group.
	 *
	 * A row naming a post that no longer exists has no language worth keeping, so
	 * it is dropped outright. Anything else is detached and keeps its own.
	 *
	 * @param int $post_id Post holding the slot.
	 * @return void
	 */
	private function release_language_slot( $post_id ) {
		if ( get_post( absint( $post_id ) ) instanceof WP_Post ) {
			$this->detach_from_group( $post_id );

			return;
		}

		$this->remove_post( $post_id );
	}

	/**
	 * Reports whether a language has assigned posts.
	 *
	 * @param string $language_id Language identifier.
	 * @return bool
	 */
	public function is_language_in_use( $language_id ) {
		return 0 < $this->repository->count_by_language( $language_id );
	}

	/**
	 * Primes translation relationship caches for a set of posts.
	 *
	 * @param array<int, int> $post_ids Post identifiers.
	 * @return void
	 */
	public function prime_posts( array $post_ids ) {
		$this->repository->prime_for_posts( $post_ids );
	}

	/**
	 * Returns post types supported by the translation engine.
	 *
	 * @return array<int, string>
	 */
	public function get_supported_post_types() {
		return $this->post_type_support->get_post_types();
	}

	/**
	 * Reports whether a post type is supported.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function supports_post_type( $post_type ) {
		return $this->post_type_support->supports( $post_type );
	}

	/**
	 * Validates a supported post.
	 *
	 * @param int $post_id Post identifier.
	 * @return WP_Post|WP_Error
	 */
	private function validate_post( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'translation_post_not_found', __( 'The requested post could not be found.', 'shapetranslate' ) );
		}

		if ( ! $this->post_type_support->supports( $post->post_type ) ) {
			return new WP_Error(
				'unsupported_translation_post_type',
				__( 'That post type is not supported by ShapeTranslate.', 'shapetranslate' )
			);
		}

		/*
		 * WordPress creates an `auto-draft` placeholder as soon as an editor opens
		 * Add New, before any title or slug exists, and discards it days later if
		 * nothing is written. Assigning it a language or making it a translation
		 * group's source would give the whole group a route that resolves to
		 * nothing. Automatic assignment already skips this status, so rejecting it
		 * here keeps every entry point consistent.
		 */
		if ( 'auto-draft' === $post->post_status ) {
			return new WP_Error(
				'auto_draft_translation_post',
				__( 'Save the post before giving it a language.', 'shapetranslate' )
			);
		}

		return $post;
	}

	/**
	 * Validates an enabled language.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate_language( $language_id ) {
		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';
		$language    = $this->language_manager->find( $language_id );

		if ( null === $language || 64 < strlen( $language_id ) ) {
			return new WP_Error( 'translation_language_not_found', __( 'Select a registered language.', 'shapetranslate' ) );
		}

		if ( empty( $language['enabled'] ) ) {
			return new WP_Error( 'translation_language_disabled', __( 'Select an enabled language.', 'shapetranslate' ) );
		}

		return $language;
	}

	/**
	 * Returns an enabled configured default language identifier.
	 *
	 * @return string
	 */
	private function get_default_language_id() {
		$language_id = $this->language_manager->get_default_id();
		$language    = '' === $language_id ? null : $this->language_manager->find( $language_id );

		return null !== $language && ! empty( $language['enabled'] ) ? $language_id : '';
	}

	/**
	 * Normalizes a language-to-post map.
	 *
	 * @param array<mixed> $translations Translation map.
	 * @return array<string, int>
	 */
	private function normalize_translation_map( $translations ) {
		$normalized = array();

		foreach ( $translations as $language_id => $post_id ) {
			if ( ! is_scalar( $language_id ) || ! is_scalar( $post_id ) ) {
				continue;
			}

			$language_id = (string) $language_id;
			$post_id     = absint( $post_id );

			if ( '' !== $language_id && 64 >= strlen( $language_id ) && 0 < $post_id ) {
				$normalized[ $language_id ] = $post_id;
			}
		}

		return $normalized;
	}

	/**
	 * Drops group members that cannot stand in as a translation.
	 *
	 * WordPress creates an `auto-draft` the moment Add New opens and keeps it for
	 * days, a trashed post is content its author has withdrawn, and a
	 * relationship row can outlive the post it names. None of the three can be
	 * read, so counting one as an existing translation hides the Add action in
	 * the admin and points the language switcher at an address that resolves to
	 * nothing.
	 *
	 * Dropping a trashed member does not unlink it: the assignment row stays, so
	 * restoring the post restores the translation. It is only released if the
	 * editor replaces it in the meantime, which is the same bargain the trash
	 * makes everywhere else in WordPress. A released post keeps its language and
	 * comes back unlinked, never in the wrong one.
	 *
	 * @param array<string, int> $translations Stored translation map.
	 * @return array<string, int>
	 */
	private function filter_usable_translations( array $translations ) {
		$unusable = array( 'auto-draft', 'trash' );

		foreach ( $translations as $language_id => $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || in_array( $post->post_status, $unusable, true ) ) {
				unset( $translations[ $language_id ] );
			}
		}

		return $translations;
	}

	/**
	 * Returns the unfiltered stored translation map for integrity checks.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, int>
	 */
	private function get_stored_translations( $post_id ) {
		$assignment = $this->repository->find_by_post( absint( $post_id ) );

		if ( null === $assignment ) {
			return array();
		}

		$translations = array();

		foreach ( $this->repository->get_group_members( $assignment['group_id'] ) as $member ) {
			$translations[ $member['language_id'] ] = absint( $member['post_id'] );
		}

		return $translations;
	}

	/**
	 * Rolls back assignments added during a failed link operation.
	 *
	 * @param string          $group_id       Translation group identifier.
	 * @param array<int, int> $added_post_ids Newly added post identifiers.
	 * @param bool            $is_new_group   Whether this operation created the group.
	 * @return void
	 */
	private function rollback_link( $group_id, $added_post_ids, $is_new_group ) {
		foreach ( $added_post_ids as $post_id ) {
			$this->repository->remove_assignment( $post_id );
		}

		if ( $is_new_group ) {
			$this->repository->delete_group( $group_id );
		}
	}

	/**
	 * Returns a consistent relationship storage error.
	 *
	 * @return WP_Error
	 */
	private function storage_error() {
		return new WP_Error(
			'translation_storage_error',
			__( 'ShapeTranslate could not save the translation relationship.', 'shapetranslate' )
		);
	}
}
