<?php
/**
 * Term translation admin module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers taxonomy forms, actions, notices, and list-table integrations.
 */
final class TermTranslationModule implements ModuleInterface {

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
	 * Form field renderer.
	 *
	 * @var TermTranslationFields
	 */
	private $fields;

	/**
	 * List-table integration.
	 *
	 * @var TermTranslationListTable
	 */
	private $list_table;

	/**
	 * Admin action helper.
	 *
	 * @var TermTranslationActions
	 */
	private $actions;

	/**
	 * Constructor.
	 *
	 * @param TermTranslationManager $translation_manager Term translation manager.
	 * @param LanguageManager        $language_manager    Language manager.
	 */
	public function __construct( TermTranslationManager $translation_manager, LanguageManager $language_manager ) {
		$actions = new TermTranslationActions();

		$this->translation_manager = $translation_manager;
		$this->language_manager    = $language_manager;
		$this->actions             = $actions;
		$this->fields              = new TermTranslationFields( $translation_manager, $language_manager, $actions );
		$this->list_table          = new TermTranslationListTable( $translation_manager, $language_manager, $actions );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		$this->list_table->register_hooks();
		add_action( 'admin_init', array( $this, 'register_taxonomy_hooks' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_screen_assets' ) );
		add_action( 'created_term', array( $this, 'save_new_term_language' ), 10, 3 );
		add_filter( 'shapetranslate_new_term_language_id', array( $this, 'filter_request_term_language' ), 20, 2 );
		add_action( 'edited_term', array( $this, 'save_edited_term_language' ), 10, 3 );
		add_action( 'admin_post_shapetranslate_create_term_translation', array( $this, 'create_translation' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Registers dynamic hooks after public taxonomies are available.
	 *
	 * @return void
	 */
	public function register_taxonomy_hooks() {
		foreach ( $this->translation_manager->get_supported_taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this->fields, 'render_add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this->fields, 'render_edit_fields' ) );
			$this->list_table->register_taxonomy( $taxonomy );
		}
	}

	/**
	 * Loads translation UI styles on supported taxonomy screens.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if (
			! $screen
			|| ! in_array( $screen->base, array( 'edit-tags', 'term' ), true )
			|| ! $this->translation_manager->supports_taxonomy( $screen->taxonomy )
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
	 * Loads the inline term language bridge on classic post screens.
	 *
	 * The block editor creates terms over REST, which already carries the
	 * language, so only the classic meta box needs this.
	 *
	 * @return void
	 */
	public function enqueue_post_screen_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if (
			! $screen
			|| 'post' !== $screen->base
			|| ! $this->language_manager->has_languages()
			|| ! $this->has_inline_term_taxonomy( $screen->post_type )
		) {
			return;
		}

		wp_enqueue_script(
			'shapetranslate-classic-term-language',
			SHAPETRANSLATE_URL . 'assets/js/classic-term-language.js',
			array(),
			Assets::version( 'assets/js/classic-term-language.js' ),
			true
		);
	}

	/**
	 * Reports whether a post type offers inline creation of supported terms.
	 *
	 * Only a hierarchical taxonomy gets the add form the script writes into;
	 * a tag box creates its terms with the post itself.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private function has_inline_term_taxonomy( $post_type ) {
		$supported = $this->translation_manager->get_supported_taxonomies();

		foreach ( get_object_taxonomies( (string) $post_type ) as $taxonomy ) {
			if ( in_array( $taxonomy, $supported, true ) && is_taxonomy_hierarchical( $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Assigns the submitted language to a newly created term.
	 *
	 * @param int    $term_id          New term identifier.
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function save_new_term_language( $term_id, $term_taxonomy_id, $taxonomy ) {
		unset( $term_taxonomy_id );

		$taxonomy_object = get_taxonomy( $taxonomy );

		if (
			! $taxonomy_object
			|| ! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| ! current_user_can( $taxonomy_object->cap->manage_terms )
			|| ! $this->verify_term_nonce( 'shapetranslate_add_term_language_' . $taxonomy )
		) {
			return;
		}

		$this->save_language( $term_id, $taxonomy );
	}

	/**
	 * Saves the submitted language for an edited term.
	 *
	 * @param int    $term_id          Edited term identifier.
	 * @param int    $term_taxonomy_id Term-taxonomy identifier.
	 * @param string $taxonomy         Taxonomy name.
	 * @return void
	 */
	public function save_edited_term_language( $term_id, $term_taxonomy_id, $taxonomy ) {
		unset( $term_taxonomy_id );

		if (
			! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| ! current_user_can( 'edit_term', $term_id )
			|| ! $this->verify_term_nonce( 'shapetranslate_edit_term_language_' . $taxonomy . '_' . absint( $term_id ) )
		) {
			return;
		}

		$this->save_language( $term_id, $taxonomy );
	}

	/**
	 * Creates and opens a translated term copy.
	 *
	 * @return void
	 */
	public function create_translation() {
		// Values select the nonce action and are validated before any mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$source_term_id = isset( $_GET['source_term_id'] ) && is_scalar( $_GET['source_term_id'] )
			? absint( wp_unslash( $_GET['source_term_id'] ) )
			: 0;
		$taxonomy       = isset( $_GET['taxonomy'] ) && is_scalar( $_GET['taxonomy'] )
			? sanitize_key( wp_unslash( $_GET['taxonomy'] ) )
			: '';
		$language_id    = isset( $_GET['language_id'] ) && is_scalar( $_GET['language_id'] )
			? sanitize_text_field( wp_unslash( $_GET['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer(
			'shapetranslate_create_term_translation_' . $source_term_id . '_' . $taxonomy . '_' . $language_id
		);

		$term = get_term( $source_term_id, $taxonomy );

		if (
			! $term instanceof WP_Term
			|| ! $this->translation_manager->supports_taxonomy( $taxonomy )
			|| ! $this->actions->can_create_translation( $term )
		) {
			wp_die(
				esc_html__( 'You are not allowed to create this term translation.', 'shapetranslate' ),
				'',
				array( 'response' => 403 )
			);
		}

		$result = $this->translation_manager->create_translation( $source_term_id, $taxonomy, $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
			$this->redirect_to_term( $source_term_id, $taxonomy );
		}

		$edit_url = $this->actions->get_edit_url( $result, $taxonomy );

		if ( '' === $edit_url ) {
			$edit_url = add_query_arg( 'taxonomy', $taxonomy, admin_url( 'edit-tags.php' ) );
		}

		wp_safe_redirect( add_query_arg( 'shapetranslate_notice', 'term_translation_created', $edit_url ) );
		exit;
	}

	/**
	 * Renders controlled term translation notices.
	 *
	 * @return void
	 */
	public function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted notice code.
		$notice_code = isset( $_GET['shapetranslate_notice'] )
			? sanitize_key( wp_unslash( $_GET['shapetranslate_notice'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'term_translation_created' === $notice_code ) {
			printf(
				'<div class="shapetranslate-notice notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Translated term created and linked.', 'shapetranslate' )
			);
		}

		$error_code = get_transient( 'shapetranslate_term_error_' . get_current_user_id() );
		delete_transient( 'shapetranslate_term_error_' . get_current_user_id() );
		$errors = $this->error_messages();

		if ( is_string( $error_code ) && '' !== $error_code ) {
			$error_message = isset( $errors[ $error_code ] )
				? $errors[ $error_code ]
				: __( 'ShapeTranslate could not complete the term translation action.', 'shapetranslate' );

			printf(
				'<div class="shapetranslate-notice notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error_message )
			);
		}
	}

	/**
	 * Answers the language a term created by the current request starts in.
	 *
	 * Two classic-editor paths create a term while a post is being written, and
	 * neither of them reaches ShapeTranslate through the taxonomy screen. Inline
	 * category creation posts to admin-ajax, and the request WordPress builds
	 * for it carries no post identifier at all. Tags typed into the tag box are
	 * created by wp_insert_post() while it stores the submitted taxonomies,
	 * before the post's own language has been saved.
	 *
	 * Both requests still carry the language the editor chose, so that is what
	 * is read here: the term belongs to the post being written, not to the site
	 * default. Each source is accepted only behind the nonce that already
	 * guards it, and the value is a request for a language rather than a grant,
	 * because TermTranslationManager validates it before anything is stored.
	 *
	 * @param string $language_id Resolved language identifier.
	 * @param string $taxonomy    Taxonomy name.
	 * @return string
	 */
	public function filter_request_term_language( $language_id, $taxonomy = '' ) {
		$requested = $this->inline_term_language( sanitize_key( $taxonomy ) );

		if ( '' === $requested ) {
			$requested = $this->saving_post_language();
		}

		return '' === $requested ? $language_id : $requested;
	}

	/**
	 * Returns the language submitted with an inline add-term request.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function inline_term_language( $taxonomy ) {
		if ( '' === $taxonomy ) {
			return '';
		}

		// WordPress guards its own inline add-term request with this nonce, and
		// verifying it is what makes the submitted value readable here.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below.
		$nonce_key = '_ajax_nonce-add-' . $taxonomy;
		$nonce     = isset( $_POST[ $nonce_key ] ) && is_scalar( $_POST[ $nonce_key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'add-' . $taxonomy ) ) {
			return '';
		}

		return isset( $_POST['shapetranslate_term_language_id'] ) && is_scalar( $_POST['shapetranslate_term_language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_term_language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Returns the language submitted by the post currently being saved.
	 *
	 * @return string
	 */
	private function saving_post_language() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below.
		$post_id = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] )
			? absint( wp_unslash( $_POST['post_ID'] ) )
			: 0;
		$nonce   = isset( $_POST['shapetranslate_language_nonce'] ) && is_scalar( $_POST['shapetranslate_language_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_language_nonce'] ) )
			: '';

		if (
			1 > $post_id
			|| '' === $nonce
			|| ! wp_verify_nonce( $nonce, 'shapetranslate_save_post_language_' . $post_id )
		) {
			return '';
		}

		return isset( $_POST['shapetranslate_language_id'] ) && is_scalar( $_POST['shapetranslate_language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
	/**
	 * Saves a sanitized submitted language through the domain service.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	private function save_language( $term_id, $taxonomy ) {
		// The calling handler has already verified the add or edit nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$language_id = isset( $_POST['shapetranslate_term_language_id'] ) && is_scalar( $_POST['shapetranslate_term_language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_term_language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = $this->translation_manager->set_term_language( $term_id, $taxonomy, $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result );
		}
	}

	/**
	 * Verifies the shared submitted term-language nonce field.
	 *
	 * @param string $action Expected nonce action.
	 * @return bool
	 */
	private function verify_term_nonce( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verification is this method's purpose.
		$nonce = isset( $_POST['shapetranslate_term_language_nonce'] ) && is_scalar( $_POST['shapetranslate_term_language_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_term_language_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Stores a relationship error for the next admin request.
	 *
	 * @param WP_Error $error Relationship error.
	 * @return void
	 */
	private function store_error( WP_Error $error ) {
		set_transient(
			'shapetranslate_term_error_' . get_current_user_id(),
			sanitize_key( $error->get_error_code() ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects to a term editor or taxonomy list fallback.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	private function redirect_to_term( $term_id, $taxonomy ) {
		$url = $this->actions->get_edit_url( $term_id, $taxonomy );

		if ( '' === $url ) {
			$url = add_query_arg( 'taxonomy', $taxonomy, admin_url( 'edit-tags.php' ) );
		}

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
			'translation_term_not_found'          => __( 'The requested term could not be found.', 'shapetranslate' ),
			'unsupported_translation_taxonomy'    => __( 'That taxonomy is not supported by ShapeTranslate.', 'shapetranslate' ),
			'term_translation_language_not_found' => __( 'Select a registered language.', 'shapetranslate' ),
			'term_translation_language_disabled'  => __( 'Select an enabled language.', 'shapetranslate' ),
			'duplicate_term_translation_language' => __( 'A term translation for that language already exists.', 'shapetranslate' ),
			'conflicting_term_language'           => __( 'This term is already assigned to a different language.', 'shapetranslate' ),
			'conflicting_term_translation_groups' => __( 'Terms from different translation groups cannot be linked.', 'shapetranslate' ),
			'missing_source_term_language'        => __( 'Assign a language to the source term first.', 'shapetranslate' ),
			'missing_default_language'            => __( 'Configure an enabled default language before translating terms.', 'shapetranslate' ),
			'term_translation_storage_error'      => __( 'ShapeTranslate could not save the term translation relationship.', 'shapetranslate' ),
			'term_exists'                         => __( 'A term with that generated name already exists. Rename it or create the translation manually.', 'shapetranslate' ),
		);
	}
}
