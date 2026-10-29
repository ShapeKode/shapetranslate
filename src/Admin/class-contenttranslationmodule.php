<?php
/**
 * Content translation admin module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Registers post editor, action, lifecycle, and list-table integrations.
 */
final class ContentTranslationModule implements ModuleInterface {

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
	 * Meta box renderer.
	 *
	 * @var TranslationMetaBox
	 */
	private $meta_box;

	/**
	 * List table integration.
	 *
	 * @var TranslationListTable
	 */
	private $list_table;

	/**
	 * Translation action helper.
	 *
	 * @var TranslationActions
	 */
	private $actions;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $translation_manager Translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 */
	public function __construct( PostTranslationManager $translation_manager, LanguageManager $language_manager ) {
		$actions = new TranslationActions();

		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->meta_box            = new TranslationMetaBox( $translation_manager, $language_manager, $actions );
		$this->list_table          = new TranslationListTable( $translation_manager, $language_manager, $actions );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		$this->list_table->register_hooks();
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'save_post', array( $this, 'save_post_language' ), 10, 2 );
		add_action( 'admin_post_shapetranslate_create_translation', array( $this, 'create_translation' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Loads translation UI styles on supported post screens.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if (
			! $screen
			|| ! in_array( $screen->base, array( 'post', 'edit' ), true )
			|| ! $this->translation_manager->supports_post_type( $screen->post_type )
		) {
			return;
		}

		wp_enqueue_style(
			'shapetranslate-admin',
			SHAPETRANSLATE_URL . 'assets/css/admin.css',
			array(),
			Assets::version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Registers the language meta box for supported posts.
	 *
	 * @param string  $post_type Current post type.
	 * @param WP_Post $post      Current post.
	 * @return void
	 */
	public function register_meta_box( $post_type, $post ) {
		if (
			! $post instanceof WP_Post
			|| ! $this->translation_manager->supports_post_type( $post_type )
			|| ! $this->language_manager->has_languages()
			|| ! current_user_can( 'edit_post', $post->ID )
		) {
			return;
		}

		add_meta_box(
			'shapetranslate-language',
			esc_html__( 'Language', 'shapetranslate' ),
			array( $this->meta_box, 'render' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Saves a post language from the editor.
	 *
	 * @param int     $post_id Post identifier.
	 * @param WP_Post $post    Saved post.
	 * @return void
	 */
	public function save_post_language( $post_id, $post ) {
		if (
			! $post instanceof WP_Post
			|| ! $this->translation_manager->supports_post_type( $post->post_type )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		$nonce = isset( $_POST['shapetranslate_language_nonce'] ) && is_scalar( $_POST['shapetranslate_language_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_language_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! wp_verify_nonce( $nonce, 'shapetranslate_save_post_language_' . absint( $post_id ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$language_id = isset( $_POST['shapetranslate_language_id'] ) && is_scalar( $_POST['shapetranslate_language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_language_id'] ) )
			: '';

		$result = $this->translation_manager->set_post_language( $post_id, $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
		}
	}

	/**
	 * Creates and opens a translated draft.
	 *
	 * @return void
	 */
	public function create_translation() {
		// Values select the nonce action and are validated before any mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$source_post_id = isset( $_GET['source_post_id'] ) && is_scalar( $_GET['source_post_id'] )
			? absint( wp_unslash( $_GET['source_post_id'] ) )
			: 0;
		$language_id    = isset( $_GET['language_id'] ) && is_scalar( $_GET['language_id'] )
			? sanitize_text_field( wp_unslash( $_GET['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'shapetranslate_create_translation_' . $source_post_id . '_' . $language_id );

		$post = get_post( $source_post_id );

		if (
			! $post instanceof WP_Post
			|| ! $this->translation_manager->supports_post_type( $post->post_type )
			|| ! $this->actions->can_create_translation( $post )
		) {
			wp_die(
				esc_html__( 'You are not allowed to create this translation.', 'shapetranslate' ),
				'',
				array( 'response' => 403 )
			);
		}

		$result = $this->translation_manager->create_translation( $source_post_id, $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
			$this->redirect_to_post( $source_post_id );
		}

		$edit_url = get_edit_post_link( $result, '' );
		$edit_url = is_string( $edit_url ) && '' !== $edit_url
			? $edit_url
			: add_query_arg(
				array(
					'post'   => absint( $result ),
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);

		wp_safe_redirect( add_query_arg( 'shapetranslate_notice', 'translation_created', $edit_url ) );
		exit;
	}

	/**
	 * Renders controlled content translation notices.
	 *
	 * @return void
	 */
	public function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted notice code.
		$notice_code = isset( $_GET['shapetranslate_notice'] )
			? sanitize_key( wp_unslash( $_GET['shapetranslate_notice'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$notices = array(
			'translation_created' => __( 'Translated draft created and linked.', 'shapetranslate' ),
		);

		if ( isset( $notices[ $notice_code ] ) ) {
			printf(
				'<div class="shapetranslate-notice notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $notices[ $notice_code ] )
			);
		}

		$error_code = get_transient( 'shapetranslate_content_error_' . get_current_user_id() );
		delete_transient( 'shapetranslate_content_error_' . get_current_user_id() );
		$errors = $this->error_messages();

		if ( is_string( $error_code ) && '' !== $error_code ) {
			$error_message = isset( $errors[ $error_code ] )
				? $errors[ $error_code ]
				: __( 'ShapeTranslate could not complete the translation action.', 'shapetranslate' );

			printf(
				'<div class="shapetranslate-notice notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error_message )
			);
		}
	}

	/**
	 * Stores a relationship error for the next admin request.
	 *
	 * @param WP_Error $error Relationship error.
	 * @return void
	 */
	private function store_error( WP_Error $error ) {
		set_transient(
			'shapetranslate_content_error_' . get_current_user_id(),
			sanitize_key( $error->get_error_code() ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects to a post editor.
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	private function redirect_to_post( $post_id ) {
		$url = get_edit_post_link( $post_id, '' );
		$url = is_string( $url ) && '' !== $url
			? $url
			: add_query_arg(
				array(
					'post'   => absint( $post_id ),
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Returns allowlisted relationship error messages.
	 *
	 * @return array<string, string>
	 */
	private function error_messages() {
		return array(
			'translation_post_not_found'        => __( 'The requested post could not be found.', 'shapetranslate' ),
			'unsupported_translation_post_type' => __( 'That post type is not supported by ShapeTranslate.', 'shapetranslate' ),
			'translation_language_not_found'    => __( 'Select a registered language.', 'shapetranslate' ),
			'translation_language_disabled'     => __( 'Select an enabled language.', 'shapetranslate' ),
			'duplicate_translation_language'    => __( 'A translation for that language already exists.', 'shapetranslate' ),
			'conflicting_post_language'         => __( 'This post is already assigned to a different language.', 'shapetranslate' ),
			'conflicting_translation_groups'    => __( 'Posts from different translation groups cannot be linked.', 'shapetranslate' ),
			'mixed_translation_post_types'      => __( 'Translations in one group must use the same post type.', 'shapetranslate' ),
			'missing_source_language'           => __( 'Assign a language to the source post first.', 'shapetranslate' ),
			'missing_default_language'          => __( 'Configure an enabled default language before translating content.', 'shapetranslate' ),
			'source_post_trashed'               => __( 'A trashed post cannot be used as a translation source.', 'shapetranslate' ),
			'translation_storage_error'         => __( 'ShapeTranslate could not save the translation relationship.', 'shapetranslate' ),
		);
	}
}
