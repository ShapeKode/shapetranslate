<?php
/**
 * Media modal language field.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Puts a language control on the attachment details panel.
 *
 * The Language meta box only exists on the full Edit Media screen, and almost
 * nobody goes there: media is chosen, described, and corrected inside the modal
 * the editor opens over the page they are writing. Without a control there an
 * attachment's language can be seen and changed only on a screen the workflow
 * never visits, which in practice means it cannot be changed at all.
 */
final class MediaTranslationFields implements ModuleInterface {

	/**
	 * Field name inside the attachment details form.
	 *
	 * @var string
	 */
	const FIELD = 'shapetranslate_language';

	/**
	 * Post translation relationship manager.
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
	 * Constructor.
	 *
	 * @param PostTranslationManager $translation_manager Post translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 */
	public function __construct( PostTranslationManager $translation_manager, LanguageManager $language_manager ) {
		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_language_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_language' ), 10, 2 );
	}

	/**
	 * Adds a language selector to the attachment details panel.
	 *
	 * @param array<string, mixed> $fields Attachment form fields.
	 * @param WP_Post              $post   Attachment being edited.
	 * @return array<string, mixed>
	 */
	public function add_language_field( $fields, $post ) {
		global $pagenow;

		if (
			! is_array( $fields )
			|| ! $post instanceof WP_Post
			// The Edit Media screen already carries the meta box, and two controls
			// for one value on one screen is a way to set it twice.
			|| 'post.php' === $pagenow
			|| ! $this->translation_manager->supports_post_type( 'attachment' )
			|| ! $this->language_manager->has_languages()
			|| ! current_user_can( 'edit_post', $post->ID )
		) {
			return $fields;
		}

		$current = (string) $this->translation_manager->get_post_language_id( $post->ID );

		$fields[ self::FIELD ] = array(
			'label' => __( 'Language', 'shapetranslate' ),
			'input' => 'html',
			'html'  => $this->render_select( $post->ID, $current ),
			'helps' => __( 'Language this media item is described in. The file itself is shared.', 'shapetranslate' ),
		);

		return $fields;
	}

	/**
	 * Saves a language submitted from the attachment details panel.
	 *
	 * Core verifies the request before this filter runs; what is checked here is
	 * that this user may edit this attachment, and the language itself is then
	 * validated by the translation manager rather than trusted.
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 * @return array<string, mixed> Unmodified post data.
	 */
	public function save_language( $post, $attachment ) {
		if (
			! is_array( $post )
			|| ! isset( $post['ID'] )
			|| ! is_array( $attachment )
			|| ! isset( $attachment[ self::FIELD ] )
			|| ! is_scalar( $attachment[ self::FIELD ] )
			|| ! $this->translation_manager->supports_post_type( 'attachment' )
		) {
			return $post;
		}

		$post_id     = absint( $post['ID'] );
		$language_id = sanitize_text_field( wp_unslash( (string) $attachment[ self::FIELD ] ) );

		if ( '' === $language_id || 1 > $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return $post;
		}

		if ( $language_id === (string) $this->translation_manager->get_post_language_id( $post_id ) ) {
			return $post;
		}

		$this->translation_manager->set_post_language( $post_id, $language_id );

		return $post;
	}

	/**
	 * Renders the language selector markup.
	 *
	 * A disabled language already assigned to this item stays in the list, so
	 * the panel never reports a language the item is not in.
	 *
	 * @param int    $post_id     Attachment identifier.
	 * @param string $current     Assigned language identifier.
	 * @return string
	 */
	private function render_select( $post_id, $current ) {
		$name    = sprintf( 'attachments[%d][%s]', absint( $post_id ), self::FIELD );
		$markup  = sprintf(
			'<select name="%1$s" id="%1$s" class="shapetranslate-media-language">',
			esc_attr( $name )
		);
		$markup .= '' === $current
			? '<option value="">' . esc_html__( 'Select a language', 'shapetranslate' ) . '</option>'
			: '';

		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( empty( $language['enabled'] ) && $current !== $language['id'] ) {
				continue;
			}

			$markup .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $language['id'] ),
				selected( $current, $language['id'], false ),
				esc_html( $language['native_name'] )
			);
		}

		return $markup . '</select>';
	}
}
