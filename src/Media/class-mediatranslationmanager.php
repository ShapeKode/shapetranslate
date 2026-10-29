<?php
/**
 * Media translation service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Media;

use ShapeTranslate\Content\PostTranslationManager;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each language its own attachment record for one shared file.
 *
 * A media translation is a second attachment post pointing at the same file on
 * disk. Nothing is uploaded or duplicated: the stored path, the generated sizes,
 * and the GUID are reused, so only the editorial text — title, alternative text,
 * caption, and description — becomes language specific.
 */
final class MediaTranslationManager {

	/**
	 * Alternative text meta key.
	 *
	 * @var string
	 */
	const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $post_translations Post translation manager.
	 */
	public function __construct( PostTranslationManager $post_translations ) {
		$this->post_translations = $post_translations;
	}

	/**
	 * Reports whether media items are translatable on this site.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->post_translations->supports_post_type( 'attachment' );
	}

	/**
	 * Inserts an attachment translation that shares the source file.
	 *
	 * @param array<string, mixed> $post_data Prepared post fields.
	 * @param WP_Post              $source    Source attachment.
	 * @return int|WP_Error Attachment identifier on success.
	 */
	public function insert_translation( array $post_data, WP_Post $source ) {
		if ( 'attachment' !== $source->post_type ) {
			return new WP_Error( 'invalid_media_source', __( 'Only attachments can be translated as media.', 'shapetranslate' ) );
		}

		$post_data['post_status']    = 'inherit';
		$post_data['post_mime_type'] = $source->post_mime_type;
		$post_data['guid']           = $source->guid;

		$translation_id = wp_insert_attachment( wp_slash( $post_data ), false, 0, true );

		if ( is_wp_error( $translation_id ) ) {
			return $translation_id;
		}

		$translation_id = absint( $translation_id );

		if ( 1 > $translation_id ) {
			return new WP_Error( 'media_translation_failed', __( 'The media translation could not be created.', 'shapetranslate' ) );
		}

		$this->share_source_file( $translation_id, $source->ID );

		return $translation_id;
	}

	/**
	 * Points a media translation at the source file and its generated sizes.
	 *
	 * @param int $translation_id Media translation identifier.
	 * @param int $source_id      Source attachment identifier.
	 * @return void
	 */
	public function share_source_file( $translation_id, $source_id ) {
		$translation_id = absint( $translation_id );
		$source_id      = absint( $source_id );
		$file           = get_attached_file( $source_id, true );

		if ( is_string( $file ) && '' !== $file ) {
			// Directly updates post meta, so the value has to arrive slashed.
			update_attached_file( $translation_id, wp_slash( $file ) );
		}

		$metadata = wp_get_attachment_metadata( $source_id, true );

		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $translation_id, wp_slash( $metadata ) );
		}

		$alt = get_post_meta( $source_id, self::ALT_META_KEY, true );

		if ( is_string( $alt ) && '' !== $alt ) {
			update_post_meta( $translation_id, self::ALT_META_KEY, wp_slash( $alt ) );
		}

		/**
		 * Fires after a media translation has been pointed at the shared file.
		 *
		 * @param int $translation_id Media translation identifier.
		 * @param int $source_id      Source attachment identifier.
		 */
		do_action( 'shapetranslate_media_file_shared', $translation_id, $source_id );
	}

	/**
	 * Resolves an attachment to its translation in one language.
	 *
	 * An attachment without a translation in the requested language keeps its own
	 * identifier, so a partially translated library never breaks an image.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_id   Target language identifier.
	 * @return int
	 */
	public function translate_attachment_id( $attachment_id, $language_id ) {
		$attachment_id = absint( $attachment_id );
		$language_id   = (string) $language_id;

		if ( 1 > $attachment_id || '' === $language_id || ! $this->is_enabled() ) {
			return $attachment_id;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return $attachment_id;
		}

		$translation = absint( $this->post_translations->get_translation( $attachment_id, $language_id ) );

		return 0 < $translation ? $translation : $attachment_id;
	}
}
