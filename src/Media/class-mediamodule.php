<?php
/**
 * Media translation module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Media;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\CurrentLanguageResolver;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Connects media translation to translation creation and attachment resolution.
 */
final class MediaModule implements ModuleInterface {

	/**
	 * Meta keys that describe the shared file rather than one language.
	 *
	 * @var array<int, string>
	 */
	const SHARED_FILE_META_KEYS = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
	);

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationManager
	 */
	private $media;

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Absolute paths that must survive the attachment being deleted.
	 *
	 * @var array<int, string>
	 */
	private $protected_files = array();

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private $current_language;

	/**
	 * Whether a structural change is already being propagated.
	 *
	 * @var bool
	 */
	private $sharing = false;

	/**
	 * Constructor.
	 *
	 * @param MediaTranslationManager $media             Media translation service.
	 * @param PostTranslationManager  $post_translations Post translation manager.
	 * @param CurrentLanguageResolver $current_language  Current language resolver.
	 */
	public function __construct(
		MediaTranslationManager $media,
		PostTranslationManager $post_translations,
		CurrentLanguageResolver $current_language
	) {
		$this->media             = $media;
		$this->post_translations = $post_translations;
		$this->current_language  = $current_language;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'shapetranslate_insert_translation', array( $this, 'insert_media_translation' ), 10, 3 );
		add_filter( 'shapetranslate_translation_featured_image_id', array( $this, 'translate_featured_image_id' ), 10, 2 );
		add_filter( 'shapetranslate_translate_post_meta_value', array( $this, 'translate_thumbnail_meta_value' ), 10, 3 );
		add_filter( 'shapetranslate_copy_post_meta_keys', array( $this, 'add_shared_file_meta_keys' ), 10, 4 );
		add_action( 'delete_attachment', array( $this, 'protect_shared_files' ), 5 );
		add_filter( 'wp_delete_file', array( $this, 'skip_protected_file' ), 5 );
		add_action( 'added_post_meta', array( $this, 'share_structural_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'share_structural_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'unshare_structural_meta' ), 10, 3 );

		if ( ! is_admin() ) {
			add_filter( 'widget_media_image_instance', array( $this, 'translate_media_widget' ), 5 );
		}
	}

	/**
	 * Keeps every language's record describing the same file the same way.
	 *
	 * These keys are structural rather than editorial: they record where the file
	 * lives and which sizes exist. Editing an image in one language rewrites them,
	 * so all of that file's records have to follow, independently of which
	 * editorial items a site chose to synchronize.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param bool               $sync      True for synchronization, false for the one-time copy.
	 * @param int                $source_id Source post identifier.
	 * @param int                $target_id Target post identifier.
	 * @return array<int, string>
	 */
	public function add_shared_file_meta_keys( $keys, $sync, $source_id, $target_id ) {
		unset( $sync, $target_id );

		if ( ! is_array( $keys ) || ! $this->media->is_enabled() || 'attachment' !== get_post_type( absint( $source_id ) ) ) {
			return $keys;
		}

		return array_merge( $keys, self::SHARED_FILE_META_KEYS );
	}

	/**
	 * Remembers the files a deleted media translation must not remove.
	 *
	 * Translations share one file, so deleting a language's record must leave the
	 * file itself in place for the other languages. WordPress deletes attachment
	 * files after the post row is gone, so the paths are collected here, while the
	 * relationship and the metadata are still readable.
	 *
	 * @param int $post_id Attachment being deleted.
	 * @return void
	 */
	public function protect_shared_files( $post_id ) {
		$this->protected_files = array();
		$post_id               = absint( $post_id );

		if ( ! $this->media->is_enabled() || ! $this->has_other_translations( $post_id ) ) {
			return;
		}

		$file = get_attached_file( $post_id );

		if ( ! is_string( $file ) || '' === $file ) {
			return;
		}

		$file      = wp_normalize_path( $file );
		$directory = trailingslashit( dirname( $file ) );
		$paths     = array( $file );
		$metadata  = wp_get_attachment_metadata( $post_id );
		$backup    = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );

		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$paths[] = $directory . $metadata['original_image'];
		}

		$size_sets = array(
			is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array(),
			is_array( $backup ) ? $backup : array(),
		);

		foreach ( $size_sets as $sizes ) {
			foreach ( $sizes as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$paths[] = $directory . $size['file'];
				}
			}
		}

		$this->protected_files = array_values( array_unique( $paths ) );
	}

	/**
	 * Skips deleting a file another language still uses.
	 *
	 * Each protected path is consumed once, so protection ends with the deletion
	 * that requested it and never affects unrelated file operations.
	 *
	 * @param string $file Absolute path WordPress intends to delete.
	 * @return string Empty to skip the deletion, or the unmodified path.
	 */
	public function skip_protected_file( $file ) {
		if ( empty( $this->protected_files ) || ! is_string( $file ) ) {
			return $file;
		}

		$index = array_search( wp_normalize_path( $file ), $this->protected_files, true );

		if ( false === $index ) {
			return $file;
		}

		unset( $this->protected_files[ $index ] );

		return '';
	}

	/**
	 * Propagates a structural change to every language of the same file.
	 *
	 * The shared-file keys reach a new translation through the copy, and after
	 * that nothing was keeping them together. WordPress's own image editor is
	 * why that matters: cropping or rotating an image writes the new path, the
	 * new sizes, and the backup of the old ones straight to metadata and never
	 * touches the post row, so a save-driven copy never hears about it and the
	 * other languages go on describing a file that has been replaced. Reading
	 * the write itself is what catches every editor of an image, including the
	 * regenerate and offload tools that do the same thing.
	 *
	 * @param int    $meta_id    Meta row identifier.
	 * @param int    $object_id  Attachment identifier.
	 * @param string $meta_key   Meta key written.
	 * @param mixed  $meta_value Value written, unslashed.
	 * @return void
	 */
	public function share_structural_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );

		$siblings = $this->shared_file_siblings( $object_id, $meta_key );

		if ( empty( $siblings ) ) {
			return;
		}

		$this->sharing = true;

		try {
			foreach ( $siblings as $sibling_id ) {

				update_post_meta( $sibling_id, $meta_key, wp_slash( $meta_value ) );
			}
		} finally {
			$this->sharing = false;
		}
	}

	/**
	 * Removes a structural key from every language of the same file.
	 *
	 * Restoring an original image deletes the backup sizes rather than writing
	 * them, so the languages that were following the edit have to stop.
	 *
	 * @param array<int, int> $meta_ids  Meta row identifiers.
	 * @param int             $object_id Attachment identifier.
	 * @param string          $meta_key  Meta key removed.
	 * @return void
	 */
	public function unshare_structural_meta( $meta_ids, $object_id, $meta_key ) {
		unset( $meta_ids );

		$siblings = $this->shared_file_siblings( $object_id, $meta_key );

		if ( empty( $siblings ) ) {
			return;
		}

		$this->sharing = true;

		try {
			foreach ( $siblings as $sibling_id ) {
				delete_post_meta( $sibling_id, $meta_key );
			}
		} finally {
			$this->sharing = false;
		}
	}

	/**
	 * Returns the other language records describing one attachment's file.
	 *
	 * The sharing flag is what keeps this from answering its own writes: each
	 * one is a metadata change on an attachment in the same group, and without
	 * it the first edit would propagate forever.
	 *
	 * @param int    $object_id Attachment identifier.
	 * @param string $meta_key  Meta key being written.
	 * @return array<int, int>
	 */
	private function shared_file_siblings( $object_id, $meta_key ) {
		$object_id = absint( $object_id );

		if (
			$this->sharing
			|| ! is_string( $meta_key )
			|| ! in_array( $meta_key, self::SHARED_FILE_META_KEYS, true )
			|| ! $this->media->is_enabled()
			|| 1 > $object_id
			|| 'attachment' !== get_post_type( $object_id )
		) {
			return array();
		}

		$siblings = array();

		foreach ( $this->post_translations->get_translations( $object_id ) as $translation_id ) {
			$translation_id = absint( $translation_id );

			if ( 0 < $translation_id && $translation_id !== $object_id ) {
				$siblings[] = $translation_id;
			}
		}

		if ( empty( $siblings ) ) {
			return array();
		}

		/**
		 * Filters whether a structural media change reaches the other languages.
		 *
		 * Returning an empty array leaves each language's record as it is, which
		 * a site wants only when something else is keeping the files in step.
		 *
		 * @param array<int, int> $siblings  Attachment identifiers to update.
		 * @param int             $object_id Attachment that was edited.
		 * @param string          $meta_key  Meta key being written.
		 */
		$filtered = apply_filters( 'shapetranslate_shared_file_siblings', $siblings, $object_id, $meta_key );

		return is_array( $filtered ) ? array_map( 'absint', $filtered ) : $siblings;
	}

	/**
	 * Answers a media widget with the image record of the language being read.
	 *
	 * The widget stores one attachment identifier, in the language the site was
	 * built in. Left alone it shows that language's alternative text, caption,
	 * and title on every translated page — the file is shared, so the picture is
	 * right and everything said about it is in the wrong language.
	 *
	 * @param array<string, mixed> $instance Widget instance settings.
	 * @return array<string, mixed>
	 */
	public function translate_media_widget( $instance ) {
		if ( ! is_array( $instance ) || empty( $instance['attachment_id'] ) ) {
			return $instance;
		}

		$language = $this->current_language->resolve();

		if ( ! is_array( $language ) || ! isset( $language['id'] ) ) {
			return $instance;
		}

		$source_id = absint( $instance['attachment_id'] );
		$target_id = $this->media->translate_attachment_id( $source_id, (string) $language['id'] );

		if ( $target_id === $source_id ) {
			return $instance;
		}

		$attachment = get_post( $target_id );

		if ( ! $attachment instanceof WP_Post ) {
			return $instance;
		}

		$instance['attachment_id'] = $target_id;

		if ( ! empty( $instance['alt'] ) ) {
			$alt = get_post_meta( $target_id, MediaTranslationManager::ALT_META_KEY, true );

			if ( is_string( $alt ) && '' !== $alt ) {
				$instance['alt'] = $alt;
			}
		}

		if ( ! empty( $instance['caption'] ) && '' !== $attachment->post_excerpt ) {
			$instance['caption'] = $attachment->post_excerpt;
		}

		if ( ! empty( $instance['image_title'] ) && '' !== $attachment->post_title ) {
			$instance['image_title'] = $attachment->post_title;
		}

		return $instance;
	}


	/**
	 * Reports whether an attachment has at least one other language.
	 *
	 * @param int $post_id Attachment identifier.
	 * @return bool
	 */
	private function has_other_translations( $post_id ) {
		foreach ( $this->post_translations->get_translations( $post_id ) as $translation_id ) {
			if ( absint( $translation_id ) !== $post_id && 0 < absint( $translation_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates an attachment translation instead of a standard draft.
	 *
	 * @param int|\WP_Error|null   $translation_id Inserted identifier, if another handler produced one.
	 * @param array<string, mixed> $post_data      Prepared post fields.
	 * @param WP_Post              $source         Source post.
	 * @return int|\WP_Error|null
	 */
	public function insert_media_translation( $translation_id, $post_data, $source ) {
		if (
			null !== $translation_id
			|| ! $source instanceof WP_Post
			|| 'attachment' !== $source->post_type
			|| ! is_array( $post_data )
			|| ! $this->media->is_enabled()
		) {
			return $translation_id;
		}

		return $this->media->insert_translation( $post_data, $source );
	}

	/**
	 * Points a translated post at the featured image's own translation.
	 *
	 * @param int                  $thumbnail_id Source attachment identifier.
	 * @param array<string, mixed> $language     Target language record.
	 * @return int
	 */
	public function translate_featured_image_id( $thumbnail_id, $language ) {
		$language_id = is_array( $language ) && isset( $language['id'] ) && is_scalar( $language['id'] )
			? (string) $language['id']
			: '';

		return $this->media->translate_attachment_id( $thumbnail_id, $language_id );
	}

	/**
	 * Points a synchronized featured image at the translation's own attachment.
	 *
	 * @param mixed  $value       Stored meta value.
	 * @param string $meta_key    Meta key.
	 * @param string $language_id Target language identifier.
	 * @return mixed
	 */
	public function translate_thumbnail_meta_value( $value, $meta_key, $language_id ) {
		if ( '_thumbnail_id' !== $meta_key ) {
			return $value;
		}

		return $this->media->translate_attachment_id( $value, (string) $language_id );
	}
}
