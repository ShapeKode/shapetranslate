<?php
/**
 * Post translation meta box.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders language assignment and translation actions in post editors.
 */
final class TranslationMetaBox {

	/**
	 * Translation manager.
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
	 * Translation action helper.
	 *
	 * @var TranslationActions
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
	 * @param PostTranslationManager $translation_manager Translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 * @param TranslationActions     $actions             Translation action helper.
	 */
	public function __construct(
		PostTranslationManager $translation_manager,
		LanguageManager $language_manager,
		TranslationActions $actions
	) {
		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->flags               = new FlagRegistry();
	}

	/**
	 * Renders the post editor controls.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render( WP_Post $post ) {
		$languages           = $this->language_manager->get_languages();
		$current_language_id = $this->translation_manager->get_post_language_id( $post->ID );

		/**
		 * Filters the language a post with no assignment starts in.
		 *
		 * @param string  $language_id Configured default language identifier.
		 * @param WP_Post $post        Post being edited.
		 */
		$new_language_id = apply_filters(
			'shapetranslate_new_post_language_id',
			$this->language_manager->get_default_id(),
			$post
		);

		$selected_language = '' !== $current_language_id
			? $current_language_id
			: (string) $new_language_id;
		$translations      = $this->translation_manager->get_translations( $post->ID );

		wp_nonce_field( 'shapetranslate_save_post_language_' . $post->ID, 'shapetranslate_language_nonce' );
		?>
		<p>
			<label class="screen-reader-text" for="shapetranslate-post-language">
				<?php esc_html_e( 'Post language', 'shapetranslate' ); ?>
			</label>
			<select id="shapetranslate-post-language" name="shapetranslate_language_id" class="widefat">
				<?php if ( '' === $selected_language ) : ?>
					<option value=""><?php esc_html_e( 'Select a language', 'shapetranslate' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $languages as $language ) : ?>
					<?php if ( empty( $language['enabled'] ) && $current_language_id !== $language['id'] ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<option value="<?php echo esc_attr( $language['id'] ); ?>" <?php selected( $selected_language, $language['id'] ); ?>>
						<?php echo esc_html( $language['native_name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<div class="shapetranslate-meta-box-translations">
			<h4><?php esc_html_e( 'Translations', 'shapetranslate' ); ?></h4>
			<?php if ( '' === $current_language_id ) : ?>
				<p class="description">
					<?php esc_html_e( 'Save the post to assign its language before adding translations.', 'shapetranslate' ); ?>
				</p>
			<?php else : ?>
				<ul class="shapetranslate-translation-links">
					<?php foreach ( $languages as $language ) : ?>
						<?php $this->render_language_action( $post, $language, $current_language_id, $translations ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders one language relationship action.
	 *
	 * @param WP_Post              $post                Current post.
	 * @param array<string, mixed> $language            Language record.
	 * @param string               $current_language_id Current language identifier.
	 * @param array<string, int>   $translations        Translation map.
	 * @return void
	 */
	private function render_language_action( $post, $language, $current_language_id, $translations ) {
		$language_id = $language['id'];
		$code        = strtoupper( $language['language_code'] );
		$target_id   = isset( $translations[ $language_id ] ) ? absint( $translations[ $language_id ] ) : 0;
		$add_label   = sprintf(
			/* translators: %s: native language name. */
			__( 'Add %s translation', 'shapetranslate' ),
			$language['native_name']
		);
		?>
		<li>
			<span class="shapetranslate-language-code">
				<?php echo $this->flags->get_flag_html( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by FlagRegistry::get_flag_html(). ?>
				<?php echo esc_html( $code ); ?>
			</span>
			<span class="shapetranslate-translation-action">
				<?php if ( $language_id === $current_language_id ) : ?>
					<span class="shapetranslate-translation-icon is-current" title="<?php esc_attr_e( 'Current language', 'shapetranslate' ); ?>">
						<span class="dashicons dashicons-yes" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Current language', 'shapetranslate' ); ?></span>
					</span>
				<?php elseif ( 0 < $target_id ) : ?>
					<?php $this->render_existing_translation( $target_id, $language ); ?>
				<?php elseif ( ! empty( $language['enabled'] ) && $this->actions->can_create_translation( $post ) ) : ?>
					<a class="shapetranslate-translation-icon is-add" href="<?php echo esc_url( $this->actions->get_create_url( $post->ID, $language_id ) ); ?>" title="<?php echo esc_attr( $add_label ); ?>" aria-label="<?php echo esc_attr( $add_label ); ?>">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( $add_label ); ?></span>
					</a>
				<?php else : ?>
					<span class="shapetranslate-translation-icon is-unavailable" title="<?php esc_attr_e( 'Unavailable', 'shapetranslate' ); ?>">
						<span class="dashicons dashicons-minus" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Unavailable', 'shapetranslate' ); ?></span>
					</span>
				<?php endif; ?>
			</span>
		</li>
		<?php
	}

	/**
	 * Renders the state of an existing translation.
	 *
	 * @param int                  $post_id  Translation post identifier.
	 * @param array<string, mixed> $language Target language record.
	 * @return void
	 */
	private function render_existing_translation( $post_id, $language ) {
		if ( 'trash' === get_post_status( $post_id ) ) {
			esc_html_e( 'In Trash', 'shapetranslate' );
			return;
		}

		$edit_url = current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id, '' ) : '';
		$label    = sprintf(
			/* translators: %s: language name. */
			__( 'Edit %s translation', 'shapetranslate' ),
			$language['native_name']
		);

		if ( is_string( $edit_url ) && '' !== $edit_url ) {
			?>
			<a class="shapetranslate-translation-icon is-edit" href="<?php echo esc_url( $edit_url ); ?>" title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<span class="dashicons dashicons-edit" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
			</a>
			<?php
			return;
		}

		esc_html_e( 'Translation exists', 'shapetranslate' );
	}
}
