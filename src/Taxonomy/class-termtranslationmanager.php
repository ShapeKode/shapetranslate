<?php
/**
 * Term translation relationship manager.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Taxonomy;

use ShapeTranslate\Contracts\TermTranslationRepositoryInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates term language assignments, groups, and translated hierarchy.
 */
final class TermTranslationManager {

	/**
	 * Term relationship repository.
	 *
	 * @var TermTranslationRepositoryInterface
	 */
	private $repository;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Supported taxonomy policy.
	 *
	 * @var TaxonomySupport
	 */
	private $taxonomy_support;

	/**
	 * Whether hierarchy synchronization is already running.
	 *
	 * @var bool
	 */
	private $synchronizing_hierarchy = false;

	/**
	 * Whether ShapeTranslate is inserting a translated term copy.
	 *
	 * @var bool
	 */
	private $creating_translation = false;

	/**
	 * Constructor.
	 *
	 * @param TermTranslationRepositoryInterface $repository       Term relationship repository.
	 * @param LanguageManager                    $language_manager Language manager.
	 * @param TaxonomySupport                    $taxonomy_support Supported taxonomy policy.
	 */
	public function __construct(
		TermTranslationRepositoryInterface $repository,
		LanguageManager $language_manager,
		TaxonomySupport $taxonomy_support
	) {
		$this->repository       = $repository;
		$this->language_manager = $language_manager;
		$this->taxonomy_support = $taxonomy_support;
	}

	/**
	 * Returns the language identifier assigned to a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function get_term_language_id( $term_id, $taxonomy ) {
		$term_id    = absint( $term_id );
		$taxonomy   = sanitize_key( $taxonomy );
		$assignment = $this->repository->find_by_term( $term_id, $taxonomy );

		if ( null !== $assignment ) {
			return $assignment['language_id'];
		}

		$term = get_term( $term_id, $taxonomy );

		return $term instanceof WP_Term && $this->taxonomy_support->supports( $taxonomy )
			? $this->get_default_language_id()
			: '';
	}

	/**
	 * Returns the language record assigned to a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, mixed>|null
	 */
	public function get_term_language( $term_id, $taxonomy ) {
		$language_id = $this->get_term_language_id( $term_id, $taxonomy );
		$language    = '' === $language_id ? null : $this->language_manager->find( $language_id );

		/**
		 * Filters the language assigned to a term.
		 *
		 * @param array<string, mixed>|null $language Language record.
		 * @param int                       $term_id  Term identifier.
		 * @param string                    $taxonomy Taxonomy name.
		 */
		$filtered = apply_filters(
			'shapetranslate_term_language',
			$language,
			absint( $term_id ),
			sanitize_key( $taxonomy )
		);

		return is_array( $filtered ) || null === $filtered ? $filtered : $language;
	}

	/**
	 * Returns the translation group identifier for a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function get_group_id( $term_id, $taxonomy ) {
		$assignment = $this->repository->find_by_term( absint( $term_id ), sanitize_key( $taxonomy ) );

		return null === $assignment ? '' : $assignment['group_id'];
	}

	/**
	 * Returns the source term identifier for a term's translation group.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return int
	 */
	public function get_source_term_id( $term_id, $taxonomy ) {
		$group_id = $this->get_group_id( $term_id, $taxonomy );
		$group    = '' === $group_id ? null : $this->repository->find_group( $group_id );

		if ( null === $group ) {
			return '' === $this->get_term_language_id( $term_id, $taxonomy ) ? 0 : absint( $term_id );
		}

		$source = get_term_by(
			'term_taxonomy_id',
			$group['source_term_taxonomy_id'],
			$group['taxonomy']
		);

		return $source instanceof WP_Term ? $source->term_id : 0;
	}

	/**
	 * Returns a translated term for one language.
	 *
	 * @param int    $term_id     Term identifier.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $language_id Language identifier.
	 * @return int Translated term ID, or zero when unavailable.
	 */
	public function get_translation( $term_id, $taxonomy, $language_id ) {
		$translations = $this->get_translations( $term_id, $taxonomy );

		return isset( $translations[ $language_id ] ) ? $translations[ $language_id ] : 0;
	}

	/**
	 * Returns translated term IDs keyed by stable language identifier.
	 *
	 * The current term is included in the returned group map.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, int>
	 */
	public function get_translations( $term_id, $taxonomy ) {
		$term_id     = absint( $term_id );
		$taxonomy    = sanitize_key( $taxonomy );
		$assignment  = $this->repository->find_by_term( $term_id, $taxonomy );
		$language_id = $this->get_term_language_id( $term_id, $taxonomy );

		if ( null === $assignment ) {
			$translations = '' === $language_id ? array() : array( $language_id => $term_id );
			$group_id     = '';
		} else {
			$translations = $this->get_stored_translations( $term_id, $taxonomy );
			$group_id     = $assignment['group_id'];
		}

		/**
		 * Filters term translations after relationship validation and loading.
		 *
		 * @param array<string, int> $translations Term IDs keyed by language ID.
		 * @param int                $term_id      Requested term identifier.
		 * @param string             $taxonomy     Taxonomy name.
		 * @param string             $group_id     Translation group identifier.
		 */
		$filtered = apply_filters(
			'shapetranslate_term_translations',
			$translations,
			$term_id,
			$taxonomy,
			$group_id
		);

		return is_array( $filtered ) ? $this->normalize_translation_map( $filtered ) : $translations;
	}

	/**
	 * Assigns a registered, enabled language to a supported term.
	 *
	 * @param int    $term_id     Term identifier.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function set_term_language( $term_id, $taxonomy, $language_id ) {
		$term = $this->validate_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';
		$assignment  = $this->repository->find_by_term_taxonomy( $term->term_taxonomy_id );

		if ( null !== $assignment && $language_id === $assignment['language_id'] ) {
			return $assignment;
		}

		$language = $this->validate_language( $language_id );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( null === $assignment ) {
			$result = $this->link_translations(
				array( $language_id => $term->term_id ),
				$term->taxonomy,
				$term->term_id
			);

			return is_wp_error( $result )
				? $result
				: $this->repository->find_by_term_taxonomy( $term->term_taxonomy_id );
		}

		foreach ( $this->repository->get_group_members( $assignment['group_id'] ) as $member ) {
			if ( $language_id === $member['language_id'] && $term->term_taxonomy_id !== $member['term_taxonomy_id'] ) {
				return new WP_Error(
					'duplicate_term_translation_language',
					__( 'This translation group already contains a term for that language.', 'shapetranslate' )
				);
			}
		}

		$old_language_id = $assignment['language_id'];

		if ( ! $this->repository->update_assignment_language( $term->term_taxonomy_id, $language_id ) ) {
			return $this->storage_error();
		}

		$this->synchronize_hierarchy( $term->term_id, $term->taxonomy );

		/**
		 * Fires after a term language assignment changes.
		 *
		 * @param int    $term_id         Term identifier.
		 * @param string $taxonomy        Taxonomy name.
		 * @param string $language_id     New language identifier.
		 * @param string $old_language_id Previous language identifier.
		 * @param string $group_id        Translation group identifier.
		 */
		do_action(
			'shapetranslate_term_language_changed',
			$term->term_id,
			$term->taxonomy,
			$language_id,
			$old_language_id,
			$assignment['group_id']
		);

		return $this->repository->find_by_term_taxonomy( $term->term_taxonomy_id );
	}

	/**
	 * Links terms from one taxonomy into a translation group.
	 *
	 * Existing terms from different groups are rejected instead of being merged.
	 *
	 * @param array<string, int> $translations  Term IDs keyed by language ID.
	 * @param string             $taxonomy      Taxonomy name.
	 * @param int                $source_term_id Source term identifier for a new group.
	 * @return array<string, mixed>|WP_Error
	 */
	public function link_translations( array $translations, $taxonomy, $source_term_id = 0 ) {
		$taxonomy     = sanitize_key( $taxonomy );
		$translations = $this->normalize_translation_map( $translations );

		if ( empty( $translations ) ) {
			return new WP_Error( 'empty_term_translation_group', __( 'Provide at least one term to link.', 'shapetranslate' ) );
		}

		if ( count( $translations ) !== count( array_unique( array_values( $translations ) ) ) ) {
			return new WP_Error(
				'conflicting_term_languages',
				__( 'A term cannot represent more than one language in a translation group.', 'shapetranslate' )
			);
		}

		$assignments = array();
		$group_ids   = array();
		$terms       = array();

		foreach ( $translations as $language_id => $term_id ) {
			$term = $this->validate_term( $term_id, $taxonomy );

			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$terms[ $term_id ]       = $term;
			$assignments[ $term_id ] = $this->repository->find_by_term_taxonomy( $term->term_taxonomy_id );
			$existing_assignment     = $assignments[ $term_id ];

			if ( null !== $existing_assignment ) {
				if ( $language_id !== $existing_assignment['language_id'] ) {
					return new WP_Error(
						'conflicting_term_language',
						__( 'A term is already assigned to a different language.', 'shapetranslate' )
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
				'conflicting_term_translation_groups',
				__( 'Terms from different translation groups cannot be linked implicitly.', 'shapetranslate' )
			);
		}

		$is_new_group = empty( $group_ids );
		$group_id     = $is_new_group ? wp_generate_uuid4() : $group_ids[0];
		$group        = $is_new_group ? null : $this->repository->find_group( $group_id );
		$source_term  = isset( $terms[ absint( $source_term_id ) ] ) ? $terms[ absint( $source_term_id ) ] : null;

		if ( ! $is_new_group && ( null === $group || $taxonomy !== $group['taxonomy'] ) ) {
			return $this->storage_error();
		}

		if ( $is_new_group && ! $source_term instanceof WP_Term ) {
			return new WP_Error(
				'invalid_term_translation_source',
				__( 'The source term must belong to the new translation group.', 'shapetranslate' )
			);
		}

		$existing_members = $is_new_group ? array() : $this->repository->get_group_members( $group_id );

		foreach ( $translations as $language_id => $term_id ) {
			foreach ( $existing_members as $member ) {
				if ( $language_id === $member['language_id'] && $term_id !== $member['term_id'] ) {
					return new WP_Error(
						'duplicate_term_translation_language',
						__( 'This translation group already contains a term for that language.', 'shapetranslate' )
					);
				}
			}
		}

		if (
			$is_new_group
			&& ! $this->repository->create_group( $group_id, $source_term->term_taxonomy_id, $taxonomy )
		) {
			return $this->storage_error();
		}

		$added_term_taxonomy_ids = array();

		foreach ( $translations as $language_id => $term_id ) {
			if ( null !== $assignments[ $term_id ] ) {
				continue;
			}

			$term = $terms[ $term_id ];

			if (
				! $this->repository->add_assignment(
					$term->term_taxonomy_id,
					$term->term_id,
					$taxonomy,
					$group_id,
					$language_id
				)
			) {
				$this->rollback_link( $group_id, $added_term_taxonomy_ids, $is_new_group );

				return $this->storage_error();
			}

			$added_term_taxonomy_ids[] = $term->term_taxonomy_id;
		}

		$this->synchronize_hierarchy( reset( $translations ), $taxonomy );

		$source_term_id = $is_new_group
			? $source_term->term_id
			: $this->get_source_term_id( reset( $translations ), $taxonomy );
		$result         = array(
			'group_id'       => $group_id,
			'source_term_id' => $source_term_id,
			'taxonomy'       => $taxonomy,
			'translations'   => $this->get_stored_translations( reset( $translations ), $taxonomy ),
		);

		/**
		 * Fires after terms are linked into a translation group.
		 *
		 * @param string             $group_id      Translation group identifier.
		 * @param array<string, int> $translations  Complete term translation map.
		 * @param int                $source_term_id Source term identifier.
		 * @param string             $taxonomy      Taxonomy name.
		 */
		do_action(
			'shapetranslate_term_translations_linked',
			$group_id,
			$result['translations'],
			$source_term_id,
			$taxonomy
		);

		return $result;
	}

	/**
	 * Joins terms that already carry translation groups of their own.
	 *
	 * The link_translations() method refuses this on purpose: two terms that each hold a
	 * group are two translation sets, and a save must never decide on its own
	 * that they are one. An import is where that decision is made, and where it
	 * is the whole point — content arrives already translated, every term
	 * already carrying a language, and nothing in the site yet says which term
	 * is which term's counterpart. This is the explicit form of that decision.
	 *
	 * Every group named here is merged whole, including members nobody named: a
	 * group is one translation set, and half of a set cannot be moved out of it
	 * without leaving two groups that each claim the same content. A merge in
	 * which two sides hold the same language is refused rather than resolved,
	 * because only the caller knows which of the two terms was meant.
	 *
	 * @param array<string, int> $translations   Term IDs keyed by language ID.
	 * @param string             $taxonomy       Taxonomy name.
	 * @param int                $source_term_id Term whose group and source survive.
	 * @return array<string, mixed>|WP_Error
	 */
	public function merge_translations( array $translations, $taxonomy, $source_term_id = 0 ) {
		$taxonomy       = sanitize_key( $taxonomy );
		$translations   = $this->normalize_translation_map( $translations );
		$source_term_id = absint( $source_term_id );

		if ( empty( $translations ) ) {
			return new WP_Error( 'empty_term_translation_group', __( 'Provide at least one term to link.', 'shapetranslate' ) );
		}

		if ( count( $translations ) !== count( array_unique( array_values( $translations ) ) ) ) {
			return new WP_Error(
				'conflicting_term_languages',
				__( 'A term cannot represent more than one language in a translation group.', 'shapetranslate' )
			);
		}

		$terms       = array();
		$assignments = array();
		$group_ids   = array();

		foreach ( $translations as $language_id => $term_id ) {
			$term = $this->validate_term( $term_id, $taxonomy );

			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$terms[ $term_id ]       = $term;
			$assignment              = $this->repository->find_by_term_taxonomy( $term->term_taxonomy_id );
			$assignments[ $term_id ] = $assignment;

			if ( null === $assignment ) {
				$language = $this->validate_language( $language_id );

				if ( is_wp_error( $language ) ) {
					return $language;
				}

				continue;
			}

			if ( $language_id !== $assignment['language_id'] ) {
				return new WP_Error(
					'conflicting_term_language',
					__( 'A term is already assigned to a different language.', 'shapetranslate' )
				);
			}

			$group_ids[] = $assignment['group_id'];
		}

		$group_ids = array_values( array_unique( $group_ids ) );

		if ( empty( $group_ids ) ) {
			return $this->link_translations( $translations, $taxonomy, $source_term_id );
		}

		$keeper_group_id = isset( $assignments[ $source_term_id ] ) && null !== $assignments[ $source_term_id ]
			? $assignments[ $source_term_id ]['group_id']
			: $group_ids[0];
		$languages       = array();
		$moves           = array();

		foreach ( $group_ids as $group_id ) {
			$group = $this->repository->find_group( $group_id );

			if ( null === $group || $taxonomy !== $group['taxonomy'] ) {
				return $this->storage_error();
			}

			foreach ( $this->repository->get_group_members( $group_id ) as $member ) {
				if ( isset( $languages[ $member['language_id'] ] ) ) {
					return new WP_Error(
						'duplicate_term_translation_language',
						__( 'This translation group already contains a term for that language.', 'shapetranslate' )
					);
				}

				$languages[ $member['language_id'] ] = $member['term_taxonomy_id'];

				if ( $group_id !== $keeper_group_id ) {
					$moves[ $member['term_taxonomy_id'] ] = $group_id;
				}
			}
		}

		$additions = array();

		foreach ( $translations as $language_id => $term_id ) {
			if ( null !== $assignments[ $term_id ] ) {
				continue;
			}

			if ( isset( $languages[ $language_id ] ) ) {
				return new WP_Error(
					'duplicate_term_translation_language',
					__( 'This translation group already contains a term for that language.', 'shapetranslate' )
				);
			}

			$languages[ $language_id ] = $terms[ $term_id ]->term_taxonomy_id;
			$additions[ $term_id ]     = $language_id;
		}

		$moved = array();
		$added = array();

		foreach ( $moves as $term_taxonomy_id => $previous_group_id ) {
			if ( ! $this->repository->update_assignment_group( $term_taxonomy_id, $keeper_group_id ) ) {
				$this->rollback_merge( $moved, $added );

				return $this->storage_error();
			}

			$moved[ $term_taxonomy_id ] = $previous_group_id;
		}

		foreach ( $additions as $term_id => $language_id ) {
			$term = $terms[ $term_id ];

			if (
				! $this->repository->add_assignment(
					$term->term_taxonomy_id,
					$term->term_id,
					$taxonomy,
					$keeper_group_id,
					$language_id
				)
			) {
				$this->rollback_merge( $moved, $added );

				return $this->storage_error();
			}

			$added[] = $term->term_taxonomy_id;
		}

		foreach ( $group_ids as $group_id ) {
			if ( $group_id === $keeper_group_id || ! empty( $this->repository->get_group_members( $group_id ) ) ) {
				continue;
			}

			$this->repository->delete_group( $group_id );

			/** This action is documented in src/Taxonomy/class-termtranslationmanager.php */
			do_action( 'shapetranslate_term_translation_group_deleted', $group_id, $taxonomy );
		}

		$source       = isset( $terms[ $source_term_id ] ) ? $terms[ $source_term_id ] : null;
		$keeper_group = $this->repository->find_group( $keeper_group_id );

		if (
			$source instanceof WP_Term
			&& null !== $keeper_group
			&& $source->term_taxonomy_id !== $keeper_group['source_term_taxonomy_id']
		) {
			$this->repository->update_group_source( $keeper_group_id, $source->term_taxonomy_id );
		}

		$anchor_term_id = (int) reset( $translations );
		$result         = array(
			'group_id'       => $keeper_group_id,
			'source_term_id' => $this->get_source_term_id( $anchor_term_id, $taxonomy ),
			'taxonomy'       => $taxonomy,
			'translations'   => $this->get_stored_translations( $anchor_term_id, $taxonomy ),
		);

		// Called twice with the same set, the second call has nothing to say.
		if ( empty( $moved ) && empty( $added ) ) {
			return $result;
		}

		$this->synchronize_hierarchy( $anchor_term_id, $taxonomy );
		$result['translations'] = $this->get_stored_translations( $anchor_term_id, $taxonomy );

		/** This action is documented in src/Taxonomy/class-termtranslationmanager.php */
		do_action(
			'shapetranslate_term_translations_linked',
			$keeper_group_id,
			$result['translations'],
			$result['source_term_id'],
			$taxonomy
		);

		return $result;
	}


	/**
	 * Creates and links a translated term copy.
	 *
	 * Only core name, slug, description, and translated parent fields are copied.
	 *
	 * @param int    $source_term_id Source term identifier.
	 * @param string $taxonomy      Taxonomy name.
	 * @param string $language_id   Target language identifier.
	 * @return int|WP_Error Created term ID on success.
	 */
	public function create_translation( $source_term_id, $taxonomy, $language_id ) {
		$source = $this->validate_term( $source_term_id, $taxonomy );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$source_assignment = $this->repository->find_by_term_taxonomy( $source->term_taxonomy_id );

		if ( null === $source_assignment ) {
			$source_assignment = $this->assign_default_language( $source->term_id, $source->taxonomy );

			if ( is_wp_error( $source_assignment ) ) {
				return $source_assignment;
			}
		}

		$language = $this->validate_language( $language_id );

		if ( is_wp_error( $language ) ) {
			return $language;
		}

		if ( 0 < $this->get_translation( $source->term_id, $source->taxonomy, $language_id ) ) {
			return new WP_Error(
				'duplicate_term_translation_language',
				__( 'This translation group already contains a term for that language.', 'shapetranslate' )
			);
		}

		$generated_name = sprintf(
			/* translators: 1: source term name, 2: target language native name. */
			_x( '%1$s (%2$s)', 'generated translated term name', 'shapetranslate' ),
			$source->name,
			$language['native_name']
		);
		$term_data = array(
			'name'        => $generated_name,
			'description' => $source->description,
			'slug'        => sanitize_title( $source->slug . '-' . $language['url_slug'] ),
			'parent'      => $this->get_translated_parent_id( $source, $language_id ),
		);

		/**
		 * Filters core term fields copied into a new translated term.
		 *
		 * Arbitrary term metadata is intentionally outside the core engine.
		 *
		 * A translated branch mirrors the source branch, so a filtered parent
		 * stands only while the source parent has no counterpart in this
		 * language. Once it has one, hierarchy synchronization owns the parent.
		 *
		 * @param array<string, mixed> $term_data New term fields.
		 * @param WP_Term              $source   Source term.
		 * @param array<string, mixed> $language Target language record.
		 */
		$filtered_data              = apply_filters(
			'shapetranslate_term_translation_copy_data',
			$term_data,
			$source,
			$language
		);
		$term_data                  = is_array( $filtered_data ) ? $filtered_data : $term_data;
		$name                       = isset( $term_data['name'] ) && is_scalar( $term_data['name'] )
			? sanitize_text_field( (string) $term_data['name'] )
			: $generated_name;
		$name                       = '' === $name ? $generated_name : $name;
		$args                       = array(
			'description' => isset( $term_data['description'] ) && is_scalar( $term_data['description'] )
				? (string) $term_data['description']
				: '',
			'slug'        => isset( $term_data['slug'] ) && is_scalar( $term_data['slug'] )
				? sanitize_title( (string) $term_data['slug'] )
				: '',
			'parent'      => is_taxonomy_hierarchical( $source->taxonomy )
				&& isset( $term_data['parent'] ) && is_scalar( $term_data['parent'] )
				? absint( $term_data['parent'] )
				: 0,
		);
		$this->creating_translation = true;

		try {
			$result = wp_insert_term( $name, $source->taxonomy, $args );
		} finally {
			$this->creating_translation = false;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_term_id = absint( $result['term_id'] );
		$link_result = $this->link_translations(
			array(
				$source_assignment['language_id'] => $source->term_id,
				$language_id                      => $new_term_id,
			),
			$source->taxonomy,
			$source->term_id
		);

		if ( is_wp_error( $link_result ) ) {
			wp_delete_term( $new_term_id, $source->taxonomy );

			return $link_result;
		}

		/**
		 * Fires after a translated term has been created and linked.
		 *
		 * @param int                  $new_term_id New translated term identifier.
		 * @param int                  $source_id   Source term identifier.
		 * @param string               $taxonomy    Taxonomy name.
		 * @param array<string, mixed> $language    Target language record.
		 * @param string               $group_id    Translation group identifier.
		 */
		do_action(
			'shapetranslate_term_translation_created',
			$new_term_id,
			$source->term_id,
			$source->taxonomy,
			$language,
			$link_result['group_id']
		);

		return $new_term_id;
	}

	/**
	 * Persists the configured default language for an unassigned term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, mixed>|WP_Error
	 */
	public function assign_default_language( $term_id, $taxonomy ) {
		$assignment = $this->repository->find_by_term( absint( $term_id ), sanitize_key( $taxonomy ) );

		if ( null !== $assignment ) {
			return $assignment;
		}

		$language_id = $this->get_default_language_id();

		if ( '' === $language_id ) {
			return new WP_Error(
				'missing_default_language',
				__( 'Configure an enabled default language before translating terms.', 'shapetranslate' )
			);
		}

		return $this->set_term_language( $term_id, $taxonomy, $language_id );
	}

	/**
	 * Reports whether ShapeTranslate is inserting a translated term copy.
	 *
	 * @return bool
	 */
	public function is_creating_translation() {
		return $this->creating_translation;
	}

	/**
	 * Removes a deleted term and repairs or removes its translation group.
	 *
	 * @param int    $term_id          Deleted term identifier.
	 * @param int    $term_taxonomy_id Deleted term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function remove_term( $term_id, $term_taxonomy_id, $taxonomy ) {
		$term_id          = absint( $term_id );
		$term_taxonomy_id = absint( $term_taxonomy_id );
		$taxonomy         = sanitize_key( $taxonomy );
		$assignment       = $this->repository->find_by_term_taxonomy( $term_taxonomy_id );

		if (
			null === $assignment
			|| $taxonomy !== $assignment['taxonomy']
			|| ! $this->repository->remove_assignment( $term_taxonomy_id )
		) {
			return;
		}

		$group_id = $assignment['group_id'];
		$members  = $this->repository->get_group_members( $group_id );

		if ( empty( $members ) ) {
			$this->repository->delete_group( $group_id );
			do_action( 'shapetranslate_term_translation_group_deleted', $group_id, $taxonomy );
		} else {
			$group = $this->repository->find_group( $group_id );

			if ( null !== $group && $term_taxonomy_id === $group['source_term_taxonomy_id'] ) {
				$this->repository->update_group_source( $group_id, $members[0]['term_taxonomy_id'] );
			}

			$this->synchronize_hierarchy( $members[0]['term_id'], $taxonomy );
		}

		/**
		 * Fires after a deleted term is removed from its translation group.
		 *
		 * @param int                  $term_id    Deleted term identifier.
		 * @param string               $taxonomy   Taxonomy name.
		 * @param array<string, mixed> $assignment Removed assignment.
		 */
		do_action( 'shapetranslate_term_translation_unlinked', $term_id, $taxonomy, $assignment );
	}

	/**
	 * Synchronizes one term group and descendant group parents after an edit.
	 *
	 * @param int    $term_id  Edited term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function synchronize_hierarchy( $term_id, $taxonomy ) {
		$group_id = $this->get_group_id( $term_id, $taxonomy );

		if (
			$this->synchronizing_hierarchy
			|| '' === $group_id
			|| ! is_taxonomy_hierarchical( $taxonomy )
		) {
			return;
		}

		$this->synchronizing_hierarchy = true;

		try {
			$this->synchronize_group_parent( $group_id );
			$this->synchronize_descendant_group_parents( $term_id, $taxonomy );
		} finally {
			$this->synchronizing_hierarchy = false;
		}
	}

	/**
	 * Reports whether a language has assigned terms.
	 *
	 * @param string $language_id Language identifier.
	 * @return bool
	 */
	public function is_language_in_use( $language_id ) {
		return 0 < $this->repository->count_by_language( $language_id );
	}

	/**
	 * Primes translation relationship caches for term-taxonomy identifiers.
	 *
	 * @param array<int, int> $term_taxonomy_ids Term-taxonomy identifiers.
	 * @return void
	 */
	public function prime_terms( array $term_taxonomy_ids ) {
		$this->repository->prime_for_term_taxonomies( $term_taxonomy_ids );
	}

	/**
	 * Returns taxonomies supported by the translation engine.
	 *
	 * @return array<int, string>
	 */
	public function get_supported_taxonomies() {
		return $this->taxonomy_support->get_taxonomies();
	}

	/**
	 * Reports whether a taxonomy is supported.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function supports_taxonomy( $taxonomy ) {
		return $this->taxonomy_support->supports( $taxonomy );
	}

	/**
	 * Validates a supported term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return WP_Term|WP_Error
	 */
	private function validate_term( $term_id, $taxonomy ) {
		$taxonomy = sanitize_key( $taxonomy );

		if ( ! $this->taxonomy_support->supports( $taxonomy ) ) {
			return new WP_Error(
				'unsupported_translation_taxonomy',
				__( 'That taxonomy is not supported by ShapeTranslate.', 'shapetranslate' )
			);
		}

		$term = get_term( absint( $term_id ), $taxonomy );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'translation_term_not_found', __( 'The requested term could not be found.', 'shapetranslate' ) );
		}

		return $term;
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
			return new WP_Error( 'term_translation_language_not_found', __( 'Select a registered language.', 'shapetranslate' ) );
		}

		if ( empty( $language['enabled'] ) ) {
			return new WP_Error( 'term_translation_language_disabled', __( 'Select an enabled language.', 'shapetranslate' ) );
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
	 * Normalizes a language-to-term map.
	 *
	 * @param array<mixed> $translations Translation map.
	 * @return array<string, int>
	 */
	private function normalize_translation_map( $translations ) {
		$normalized = array();

		foreach ( $translations as $language_id => $term_id ) {
			if ( ! is_scalar( $language_id ) || ! is_scalar( $term_id ) ) {
				continue;
			}

			$language_id = (string) $language_id;
			$term_id     = absint( $term_id );

			if ( '' !== $language_id && 64 >= strlen( $language_id ) && 0 < $term_id ) {
				$normalized[ $language_id ] = $term_id;
			}
		}

		return $normalized;
	}

	/**
	 * Returns the unfiltered stored translation map.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, int>
	 */
	private function get_stored_translations( $term_id, $taxonomy ) {
		$assignment = $this->repository->find_by_term( absint( $term_id ), sanitize_key( $taxonomy ) );

		if ( null === $assignment ) {
			return array();
		}

		$translations = array();

		foreach ( $this->repository->get_group_members( $assignment['group_id'] ) as $member ) {
			$translations[ $member['language_id'] ] = absint( $member['term_id'] );
		}

		return $translations;
	}

	/**
	 * Returns the translated parent matching a target language when available.
	 *
	 * @param WP_Term $term        Source term.
	 * @param string  $language_id Target language identifier.
	 * @return int
	 */
	private function get_translated_parent_id( WP_Term $term, $language_id ) {
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) || 1 > $term->parent ) {
			return 0;
		}

		return $this->get_translation( $term->parent, $term->taxonomy, $language_id );
	}

	/**
	 * Synchronizes translated parents in one group from the group's source tree.
	 *
	 * @param string $group_id Translation group identifier.
	 * @return void
	 */
	private function synchronize_group_parent( $group_id ) {
		$group = $this->repository->find_group( $group_id );

		if ( null === $group || ! is_taxonomy_hierarchical( $group['taxonomy'] ) ) {
			return;
		}

		$source = get_term_by(
			'term_taxonomy_id',
			$group['source_term_taxonomy_id'],
			$group['taxonomy']
		);

		if ( ! $source instanceof WP_Term ) {
			return;
		}

		foreach ( $this->repository->get_group_members( $group_id ) as $member ) {
			$term = get_term( $member['term_id'], $group['taxonomy'] );

			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			if ( $term->term_taxonomy_id === $source->term_taxonomy_id ) {
				$parent_id = $source->parent;
			} else {
				$parent_id = $this->get_translated_parent_id( $source, $member['language_id'] );

				/*
				 * The source sits under a parent this language holds no copy of, so
				 * there is no counterpart to mirror. Moving the term to the root
				 * would discard the parent chosen here, which is the only hierarchy
				 * this language has; that choice stands until a counterpart exists.
				 */
				if ( 0 === $parent_id && 0 < $source->parent ) {
					continue;
				}
			}

			if ( $term->term_id !== $parent_id && $term->parent !== $parent_id ) {
				wp_update_term( $term->term_id, $group['taxonomy'], array( 'parent' => $parent_id ) );
			}
		}
	}

	/**
	 * Synchronizes groups below any translated counterpart of one parent term.
	 *
	 * @param int    $term_id  Parent term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	private function synchronize_descendant_group_parents( $term_id, $taxonomy ) {
		if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return;
		}

		$parent_translations = $this->get_stored_translations( $term_id, $taxonomy );
		$parent_term_ids     = empty( $parent_translations )
			? array( absint( $term_id ) )
			: array_values( $parent_translations );
		$descendant_groups   = array();

		foreach ( $parent_term_ids as $parent_term_id ) {
			$children = get_term_children( $parent_term_id, $taxonomy );

			if ( is_wp_error( $children ) ) {
				continue;
			}

			foreach ( $children as $child_id ) {
				$group_id = $this->get_group_id( $child_id, $taxonomy );

				if ( '' !== $group_id ) {
					$descendant_groups[] = $group_id;
				}
			}
		}

		foreach ( array_unique( $descendant_groups ) as $group_id ) {
			$this->synchronize_group_parent( $group_id );
		}
	}

	/**
	 * Restores group membership after a failed merge.
	 *
	 * @param array<int, string> $moved Previous group IDs keyed by term-taxonomy ID.
	 * @param array<int, int>    $added Term-taxonomy IDs assigned during the merge.
	 * @return void
	 */
	private function rollback_merge( array $moved, array $added ) {
		foreach ( $added as $term_taxonomy_id ) {
			$this->repository->remove_assignment( $term_taxonomy_id );
		}

		foreach ( $moved as $term_taxonomy_id => $previous_group_id ) {
			$this->repository->update_assignment_group( $term_taxonomy_id, $previous_group_id );
		}
	}


	/**
	 * Rolls back assignments added during a failed link operation.
	 *
	 * @param string          $group_id               Translation group identifier.
	 * @param array<int, int> $term_taxonomy_ids      Newly added term-taxonomy identifiers.
	 * @param bool            $is_new_group           Whether this operation created the group.
	 * @return void
	 */
	private function rollback_link( $group_id, $term_taxonomy_ids, $is_new_group ) {
		foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
			$this->repository->remove_assignment( $term_taxonomy_id );
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
			'term_translation_storage_error',
			__( 'ShapeTranslate could not save the term translation relationship.', 'shapetranslate' )
		);
	}
}
