<?php
/**
 * Term translation form fields.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Renders language assignment and translation actions on term forms.
 */
final class TermTranslationFields {

	/**
	 * Term translation manager.
	 *
	 * @var TermTranslationManager
	 */
	private $translation_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Admin action helper.
	 *
	 * @var TermTranslationActions
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
	 * @param TermTranslationManager $translation_manager Term translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 * @param TermTranslationActions $actions             Admin action helper.
	 */
	public function __construct(
		TermTranslationManager $translation_manager,
		LanguageManager $language_manager,
		TermTranslationActions $actions
	) {
		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->flags               = new FlagRegistry();
	}

	/**
	 * Renders the language selector on a taxonomy add form.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function render_add_fields( $taxonomy ) {
		$selected_language = (string) $this->new_term_language_id( $taxonomy );

		wp_nonce_field(
			'shapetranslate_add_term_language_' . $taxonomy,
			'shapetranslate_term_language_nonce'
		);
		?>
		<div class="form-field term-shapetranslate-language-wrap">
			<label for="shapetranslate-term-language"><?php esc_html_e( 'Language', 'shapetranslate' ); ?></label>
			<?php $this->render_language_select( $selected_language, '' ); ?>
			<p><?php esc_html_e( 'Language assigned to this term.', 'shapetranslate' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders language and translation controls on a term edit form.
	 *
	 * @param WP_Term $term Current term.
	 * @return void
	 */
	public function render_edit_fields( WP_Term $term ) {
		$current_language_id = $this->translation_manager->get_term_language_id( $term->term_id, $term->taxonomy );
		$selected_language   = '' !== $current_language_id
			? $current_language_id
			: (string) $this->new_term_language_id( $term->taxonomy );

		wp_nonce_field(
			'shapetranslate_edit_term_language_' . $term->taxonomy . '_' . $term->term_id,
			'shapetranslate_term_language_nonce'
		);
		?>
		<tr class="form-field term-shapetranslate-language-wrap">
			<th scope="row">
				<label for="shapetranslate-term-language"><?php esc_html_e( 'Language', 'shapetranslate' ); ?></label>
			</th>
			<td>
				<?php $this->render_language_select( $selected_language, $current_language_id ); ?>
				<p class="description"><?php esc_html_e( 'Language assigned to this term.', 'shapetranslate' ); ?></p>
			</td>
		</tr>
		<tr class="form-field term-shapetranslate-translations-wrap">
			<th scope="row"><?php esc_html_e( 'Translations', 'shapetranslate' ); ?></th>
			<td>
				<?php $this->render_translations( $term, $current_language_id ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Returns the language a term with no assignment starts in.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function new_term_language_id( $taxonomy ) {
		/**
		 * Filters the language a term with no assignment starts in.
		 *
		 * @param string $language_id Configured default language identifier.
		 * @param string $taxonomy    Taxonomy name.
		 */
		return (string) apply_filters(
			'shapetranslate_new_term_language_id',
			$this->language_manager->get_default_id(),
			(string) $taxonomy
		);
	}

	/**
	 * Renders an enabled-language selector while retaining a disabled assignment.
	 *
	 * @param string $selected_language_id Selected language identifier.
	 * @param string $current_language_id  Persisted language identifier.
	 * @return void
	 */
	private function render_language_select( $selected_language_id, $current_language_id ) {
		?>
		<select id="shapetranslate-term-language" name="shapetranslate_term_language_id">
			<?php if ( '' === $selected_language_id ) : ?>
				<option value=""><?php esc_html_e( 'Select a language', 'shapetranslate' ); ?></option>
			<?php endif; ?>
			<?php foreach ( $this->language_manager->get_languages() as $language ) : ?>
				<?php if ( empty( $language['enabled'] ) && $current_language_id !== $language['id'] ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<option value="<?php echo esc_attr( $language['id'] ); ?>" <?php selected( $selected_language_id, $language['id'] ); ?>>
					<?php echo esc_html( $language['native_name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders available and existing translation actions.
	 *
	 * @param WP_Term $term                Current term.
	 * @param string  $current_language_id Current language identifier.
	 * @return void
	 */
	private function render_translations( WP_Term $term, $current_language_id ) {
		if ( '' === $current_language_id ) {
			?>
			<p class="description">
				<?php esc_html_e( 'Update the term to assign its language before adding translations.', 'shapetranslate' ); ?>
			</p>
			<?php
			return;
		}

		$translations = $this->translation_manager->get_translations( $term->term_id, $term->taxonomy );
		?>
		<ul class="shapetranslate-translation-links">
			<?php foreach ( $this->language_manager->get_languages() as $language ) : ?>
				<?php $this->render_language_action( $term, $language, $current_language_id, $translations ); ?>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders one language relationship action.
	 *
	 * @param WP_Term              $term                Current term.
	 * @param array<string, mixed> $language            Language record.
	 * @param string               $current_language_id Current language identifier.
	 * @param array<string, int>   $translations        Translation map.
	 * @return void
	 */
	private function render_language_action( WP_Term $term, $language, $current_language_id, $translations ) {
		$language_id = $language['id'];
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
				<?php echo esc_html( strtoupper( $language['language_code'] ) ); ?>
			</span>
			<span class="shapetranslate-translation-action">
				<?php if ( $language_id === $current_language_id ) : ?>
					<span class="shapetranslate-translation-icon is-current" title="<?php esc_attr_e( 'Current language', 'shapetranslate' ); ?>">
						<span class="dashicons dashicons-yes" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Current language', 'shapetranslate' ); ?></span>
					</span>
				<?php elseif ( 0 < $target_id ) : ?>
					<?php $this->render_existing_translation( $target_id, $term->taxonomy, $language ); ?>
				<?php elseif ( ! empty( $language['enabled'] ) && $this->actions->can_create_translation( $term ) ) : ?>
					<a class="shapetranslate-translation-icon is-add" href="<?php echo esc_url( $this->actions->get_create_url( $term->term_id, $term->taxonomy, $language_id ) ); ?>" title="<?php echo esc_attr( $add_label ); ?>" aria-label="<?php echo esc_attr( $add_label ); ?>">
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
	 * Renders an edit link for an existing translated term.
	 *
	 * @param int                  $term_id  Translation term identifier.
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<string, mixed> $language Target language record.
	 * @return void
	 */
	private function render_existing_translation( $term_id, $taxonomy, $language ) {
		$edit_url = current_user_can( 'edit_term', $term_id )
			? $this->actions->get_edit_url( $term_id, $taxonomy )
			: '';
		$label    = sprintf(
			/* translators: %s: language name. */
			__( 'Edit %s translation', 'shapetranslate' ),
			$language['native_name']
		);

		if ( '' !== $edit_url ) {
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
