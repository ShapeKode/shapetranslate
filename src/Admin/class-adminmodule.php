<?php
/**
 * ShapeTranslate admin module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageCatalog;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Language\LanguagePackInstaller;
use ShapeTranslate\Lifecycle\Activator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the language administration screen and action handlers.
 */
final class AdminModule implements ModuleInterface {

	/**
	 * Required capability for language management.
	 *
	 * @var string
	 */
	const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Languages page renderer.
	 *
	 * @var LanguagesPage
	 */
	private $page;

	/**
	 * Language pack installer.
	 *
	 * @var LanguagePackInstaller
	 */
	private $language_packs;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager $language_manager Language manager.
	 * @param LanguageCatalog $language_catalog Language setup catalog.
	 */
	public function __construct( LanguageManager $language_manager, LanguageCatalog $language_catalog ) {
		$this->language_manager = $language_manager;
		$this->page             = new LanguagesPage( $language_manager, $language_catalog );
		$this->language_packs   = new LanguagePackInstaller();
	}

	/**
	 * Returns the capability required to manage languages.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage ShapeTranslate languages.
		 *
		 * @param string $capability Required capability.
		 */
		$capability = apply_filters( 'shapetranslate_manage_languages_capability', self::DEFAULT_CAPABILITY );
		$capability = is_string( $capability ) ? sanitize_key( $capability ) : '';

		return '' !== $capability ? $capability : self::DEFAULT_CAPABILITY;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_setup' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );		add_action( 'admin_post_shapetranslate_save_language', array( $this, 'save_language' ) );
		add_action( 'admin_post_shapetranslate_delete_language', array( $this, 'delete_language' ) );
		add_action( 'admin_post_shapetranslate_set_default_language', array( $this, 'set_default_language' ) );
		add_action( 'admin_post_shapetranslate_toggle_language', array( $this, 'toggle_language' ) );
		add_action( 'admin_post_shapetranslate_move_language', array( $this, 'move_language' ) );
	}

	/**
	 * Redirects the first eligible administrator request to initial setup.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_setup() {
		if ( false === get_transient( Activator::SETUP_REDIRECT_TRANSIENT ) ) {
			return;
		}

		if (
			! current_user_can( self::capability() )
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| is_network_admin()
		) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bulk-activation context.
		$is_bulk_activation = isset( $_GET['activate-multi'] );

		delete_transient( Activator::SETUP_REDIRECT_TRANSIENT );

		if ( $is_bulk_activation || $this->language_manager->has_languages() ) {
			return;
		}

		$setup_url = add_query_arg( 'page', 'shapetranslate-setup', admin_url( 'admin.php' ) );

		wp_safe_redirect( $setup_url );
		exit;
	}

	/**
	 * Registers the top-level ShapeTranslate menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			esc_html__( 'ShapeTranslate Languages', 'shapetranslate' ),
			esc_html__( 'ShapeTranslate', 'shapetranslate' ),
			self::capability(),
			'shapetranslate',
			array( $this->page, 'render' ),
			self::menu_icon(),
			81
		);
	}

	/**
	 * Returns the ShapeTranslate icon as a monochrome data URI for the admin menu.
	 *
	 * The SVG's colors are flattened to the default menu icon color so it
	 * matches core icons before svg-painter applies the admin color scheme.
	 * Falls back to a dashicon when the SVG file cannot be read.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$file = SHAPETRANSLATE_PATH . 'assets/icons/shape_menu_icon.svg';
		$svg  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.

		if ( false === $svg || '' === $svg ) {
			return 'dashicons-translation';
		}

		$svg = preg_replace( '/fill="#[0-9A-Fa-f]{3,6}"/', 'fill="#a7aaad"', $svg );

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for admin menu SVG icons.
	}

	/**
	 * Loads assets only on the ShapeTranslate page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_shapetranslate' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'shapetranslate-admin',
			SHAPETRANSLATE_URL . 'assets/css/admin.css',
			array(),
			Assets::version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'shapetranslate-admin',
			SHAPETRANSLATE_URL . 'assets/js/admin.js',
			array(),
			Assets::version( 'assets/js/admin.js' ),
			true
		);
	}

	/**
	 * Creates or updates a language.
	 *
	 * @return void
	 */
	public function save_language() {
		// An empty ID is a new language, so adding signs `shapetranslate_save_language_`.
		$language_id = $this->authorize_language_action( 'shapetranslate_save_language_' );
		$input       = $this->posted_language_input();
		$is_setup    = '' === $language_id && ! $this->language_manager->has_languages();
		$result      = '' === $language_id
			? $this->language_manager->create( $input )
			: $this->language_manager->update( $language_id, $input );

		if ( is_wp_error( $result ) ) {
			$this->store_form_error( $result, $input, $language_id );
			$this->redirect_to_editor( $language_id, $result->get_error_code() );
		}

		// A new language brings its WordPress translation files; a failed download
		// is reported but does not undo the language.
		$warning_code = '';

		if ( '' === $language_id && is_array( $result ) && isset( $result['locale'] ) ) {
			$warning_code = false === $this->language_packs->install( $result['locale'] ) ? 'language_pack_failed' : '';
		}

		if ( $is_setup ) {
			$this->redirect( 'setup_complete', '', $warning_code );
		}

		$this->redirect( '' === $language_id ? 'language_added' : 'language_updated', '', $warning_code );
	}

	/**
	 * Deletes a language.
	 *
	 * @return void
	 */
	public function delete_language() {
		$language_id = $this->authorize_language_action( 'shapetranslate_delete_language_' );

		$this->redirect_from_result( $this->language_manager->delete( $language_id ), 'language_deleted' );
	}

	/**
	 * Sets the default language.
	 *
	 * @return void
	 */
	public function set_default_language() {
		$language_id = $this->authorize_language_action( 'shapetranslate_set_default_language_' );

		$this->redirect_from_result( $this->language_manager->set_default( $language_id ), 'default_updated' );
	}

	/**
	 * Enables or disables a language.
	 *
	 * @return void
	 */
	public function toggle_language() {
		$language_id = $this->authorize_language_action( 'shapetranslate_toggle_language_' );

		$this->redirect_from_result( $this->language_manager->toggle( $language_id ), 'status_updated' );
	}

	/**
	 * Moves a language one position.
	 *
	 * @return void
	 */
	public function move_language() {
		$language_id = $this->authorize_language_action( 'shapetranslate_move_language_' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize_language_action() verifies this request above.
		$direction = isset( $_POST['direction'] )
			? sanitize_key( wp_unslash( $_POST['direction'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->redirect_from_result( $this->language_manager->move( $language_id, $direction ), 'order_updated' );
	}

	/**
	 * Authorizes an action on one language and returns that language's ID.
	 *
	 * Permissions are checked before the request is read. The ID is then read
	 * only to complete the nonce action it is signed with, and is returned only
	 * once that nonce has been verified.
	 *
	 * @param string $nonce_prefix Nonce action, without the language ID.
	 * @return string
	 */
	private function authorize_language_action( $nonce_prefix ) {
		$this->require_capability();

		$language_id = $this->posted_language_id();
		check_admin_referer( $nonce_prefix . $language_id );

		return $language_id;
	}

	/**
	 * Stops the request unless the current user may manage languages.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage ShapeTranslate languages.', 'shapetranslate' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Reads a language identifier from the request.
	 *
	 * Callers check permissions first; the ID selects the nonce action they
	 * verify before anything is changed.
	 *
	 * @return string
	 */
	private function posted_language_id() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Completes the nonce action verified right after; see authorize_language_action().
		$language_id = isset( $_POST['language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $language_id;
	}

	/**
	 * Reads only supported scalar language fields from the request.
	 *
	 * @return array<string, string>
	 */
	private function posted_language_input() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- save_language() verifies before calling.
		$posted = isset( $_POST['language'] ) && is_array( $_POST['language'] )
			? map_deep( wp_unslash( $_POST['language'] ), 'sanitize_text_field' )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$input = array();

		foreach ( array( 'name', 'locale', 'language_code', 'url_slug', 'native_name', 'is_rtl', 'enabled', 'domain' ) as $field ) {
			$input[ $field ] = isset( $posted[ $field ] ) && is_scalar( $posted[ $field ] )
				? (string) $posted[ $field ]
				: '';
		}

		return $input;
	}

	/**
	 * Stores failed form data for one redirect.
	 *
	 * @param WP_Error             $error       Validation error.
	 * @param array<string, mixed> $input       Submitted fields.
	 * @param string               $language_id Language identifier, if editing.
	 * @return void
	 */
	private function store_form_error( WP_Error $error, array $input, $language_id ) {
		set_transient(
			'shapetranslate_form_' . get_current_user_id(),
			array(
				'error'       => $error->get_error_code(),
				'input'       => $input,
				'language_id' => $language_id,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects back to an editor after failed validation.
	 *
	 * @param string $language_id Language identifier, if editing.
	 * @param string $error_code  Error code.
	 * @return void
	 */
	private function redirect_to_editor( $language_id, $error_code ) {
		if ( '' !== $language_id ) {
			$editor_action = 'edit';
		} else {
			$editor_action = $this->language_manager->has_languages() ? 'add' : 'setup';
		}

		$arguments = array(
			'page'        => 'shapetranslate',
			'action'      => $editor_action,
			'lp_error'    => sanitize_key( $error_code ),
			'language_id' => $language_id,
		);

		wp_safe_redirect( add_query_arg( $arguments, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirects from a domain operation.
	 *
	 * @param mixed  $result       Operation result.
	 * @param string $success_code Success notice code.
	 * @return void
	 */
	private function redirect_from_result( $result, $success_code ) {
		if ( is_wp_error( $result ) ) {
			$this->redirect( '', $result->get_error_code() );
		}

		$this->redirect( $success_code );
	}

	/**
	 * Redirects to the languages list with a controlled notice code.
	 *
	 * @param string $notice_code  Success notice code.
	 * @param string $error_code   Error notice code.
	 * @param string $warning_code Warning notice code.
	 * @return void
	 */
	private function redirect( $notice_code = '', $error_code = '', $warning_code = '' ) {
		$arguments = array( 'page' => 'shapetranslate' );

		if ( '' !== $notice_code ) {
			$arguments['lp_notice'] = sanitize_key( $notice_code );
		}

		if ( '' !== $error_code ) {
			$arguments['lp_error'] = sanitize_key( $error_code );
		}

		if ( '' !== $warning_code ) {
			$arguments['lp_warning'] = sanitize_key( $warning_code );
		}

		wp_safe_redirect( add_query_arg( $arguments, admin_url( 'admin.php' ) ) );
		exit;
	}
}
