<?php
/**
 * ShapeTranslate settings administration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Content\PostTypeSupport;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Navigation\MenuLanguageManager;
use ShapeTranslate\Routing\LanguageHostResolver;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Settings\SettingsTransfer;
use ShapeTranslate\Settings\SyncCatalog;
use ShapeTranslate\Settings\WorkflowSettings;
use ShapeTranslate\Taxonomy\TaxonomySupport;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and secures the ShapeTranslate settings screen and actions.
 */
final class SettingsModule implements ModuleInterface {

	/**
	 * Settings page.
	 *
	 * @var SettingsPage
	 */
	private $page;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Menu language manager.
	 *
	 * @var MenuLanguageManager
	 */
	private $menu_manager;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Post type support policy.
	 *
	 * @var PostTypeSupport
	 */
	private $post_type_support;

	/**
	 * Taxonomy support policy.
	 *
	 * @var TaxonomySupport
	 */
	private $taxonomy_support;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Settings transfer service.
	 *
	 * @var SettingsTransfer
	 */
	private $transfer;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param MenuLanguageManager    $menu_manager      Menu language manager.
	 * @param WorkflowSettings       $workflow_settings Translation workflow settings.
	 * @param PluginSettings         $settings          Central plugin settings.
	 * @param PostTypeSupport        $post_type_support Post type support policy.
	 * @param TaxonomySupport        $taxonomy_support  Taxonomy support policy.
	 * @param TermTranslationManager $term_translations Term relationship manager.
	 */
	public function __construct(
		LanguageManager $language_manager,
		MenuLanguageManager $menu_manager,
		WorkflowSettings $workflow_settings,
		PluginSettings $settings,
		PostTypeSupport $post_type_support,
		TaxonomySupport $taxonomy_support,
		TermTranslationManager $term_translations
	) {
		$this->language_manager  = $language_manager;
		$this->menu_manager      = $menu_manager;
		$this->workflow_settings = $workflow_settings;
		$this->settings          = $settings;
		$this->post_type_support = $post_type_support;
		$this->taxonomy_support  = $taxonomy_support;
		$this->term_translations = $term_translations;
		$this->transfer          = new SettingsTransfer(
			$language_manager,
			$settings,
			$workflow_settings,
			$post_type_support,
			$taxonomy_support
		);
		$this->page              = new SettingsPage(
			$language_manager,
			$menu_manager,
			$workflow_settings,
			$settings,
			$post_type_support,
			$taxonomy_support,
			$term_translations
		);
	}

	/** {@inheritdoc} */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shapetranslate_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_shapetranslate_export_settings', array( $this, 'export_settings' ) );
		add_action( 'admin_post_shapetranslate_import_settings', array( $this, 'import_settings' ) );
	}

	/** Registers the settings submenu. */
	public function register_menu() {
		add_submenu_page(
			'shapetranslate',
			esc_html__( 'ShapeTranslate Settings', 'shapetranslate' ),
			esc_html__( 'Settings', 'shapetranslate' ),
			AdminModule::capability(),
			'shapetranslate-settings',
			array( $this->page, 'render' )
		);
	}

	/**
	 * Loads assets only on the settings page.
	 *
	 * @param string $hook_suffix Current page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'shapetranslate_page_shapetranslate-settings' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'shapetranslate-admin', SHAPETRANSLATE_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'shapetranslate-admin', SHAPETRANSLATE_URL . 'assets/js/admin.js', array(), Assets::version( 'assets/js/admin.js' ), true );
	}

	/** Saves only the active settings tab. */
	public function save_settings() {
		$this->authorize( 'shapetranslate_save_settings' );
		$tab = $this->posted_tab();

		switch ( $tab ) {
			case 'general':
				$result = $this->save_general();
				break;
			case 'url':
				$result = $this->save_url();
				break;
			case 'content':
				$result = $this->save_content();
				break;
			case 'sync':
				$result = $this->save_sync();
				break;
			case 'switcher':
				$result = $this->save_switcher();
				break;
			case 'seo':
				$result = $this->save_seo();
				break;
			case 'advanced':
				$result = $this->save_advanced();
				break;
			default:
				$result = new WP_Error( 'invalid_settings_tab', __( 'The requested settings section is invalid.', 'shapetranslate' ) );
		}

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result->get_error_message() );
			$this->redirect( $tab, 'settings_error' );
		}

		$notice = 'url' === $tab && ! empty( $result['url_changed'] ) ? 'url_settings_changed' : 'settings_saved';
		$this->redirect( $tab, $notice );
	}

	/** Streams a nonce-protected JSON export. */
	public function export_settings() {
		$this->authorize( 'shapetranslate_export_settings' );
		$json = wp_json_encode( $this->transfer->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			$this->store_error( __( 'ShapeTranslate could not encode the settings export.', 'shapetranslate' ) );
			$this->redirect( 'advanced', 'settings_error' );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="shapetranslate-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download with an explicit content type.
		exit;
	}

	/** Imports a validated JSON document. */
	public function import_settings() {
		$this->authorize( 'shapetranslate_import_settings' );
		$json = $this->get_import_json();

		if ( is_wp_error( $json ) ) {
			$this->store_error( $json->get_error_message() );
			$this->redirect( 'advanced', 'import_error' );
		}

		$result = $this->transfer->import_json( $json );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result->get_error_message() );
			$this->redirect( 'advanced', 'import_error' );
		}

		$notice = ! empty( $result['url_changed'] ) ? 'settings_imported_url_changed' : 'settings_imported';
		$this->redirect( 'advanced', $notice );
	}

	/**
	 * Saves default and enabled languages in an invariant-preserving order.
	 *
	 * @return array<string, bool>|WP_Error
	 */
	private function save_general() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$default_id  = isset( $_POST['default_language'] ) && is_scalar( $_POST['default_language'] )
			? sanitize_text_field( wp_unslash( $_POST['default_language'] ) )
			: '';
		$enabled_ids = isset( $_POST['enabled_languages'] ) && is_array( $_POST['enabled_languages'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_languages'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$enabled_ids = array_values( array_unique( $enabled_ids ) );
		$registered  = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			$registered[ $language['id'] ] = $language;
		}

		if (
			empty( $enabled_ids )
			|| ! isset( $registered[ $default_id ] )
			|| ! in_array( $default_id, $enabled_ids, true )
			|| ! empty( array_diff( $enabled_ids, array_keys( $registered ) ) )
		) {
			return new WP_Error( 'invalid_language_configuration', __( 'Choose at least one enabled language and make an enabled language the default.', 'shapetranslate' ) );
		}

		foreach ( $enabled_ids as $language_id ) {
			if ( empty( $registered[ $language_id ]['enabled'] ) ) {
				$result = $this->language_manager->toggle( $language_id );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		$result = $this->language_manager->set_default( $default_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( ! empty( $language['enabled'] ) && ! in_array( $language['id'], $enabled_ids, true ) ) {
				$result = $this->language_manager->toggle( $language['id'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return array( 'url_changed' => false );
	}

	/**
	 * Saves language URL behavior.
	 *
	 * @return array<string, bool>
	 */
	private function save_url() {
		$old = $this->settings->get_section( 'url' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$prefix_default = isset( $_POST['prefix_default'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['prefix_default'] ) );
		$detect_browser = isset( $_POST['detect_browser'] );
		$mode           = isset( $_POST['url_mode'] ) && is_scalar( $_POST['url_mode'] )
			? sanitize_key( wp_unslash( $_POST['url_mode'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $mode, LanguageHostResolver::modes(), true ) ) {
			$mode = isset( $old['mode'] ) ? (string) $old['mode'] : LanguageHostResolver::MODE_DIRECTORY;
		}

		$this->settings->update_sections(
			array(
				'url' => array(
					'mode'           => $mode,
					'prefix_default' => $prefix_default,
					'detect_browser' => $detect_browser,
				),
			)
		);

		return array( 'url_changed' => $old !== $this->settings->get_section( 'url' ) );
	}

	/**
	 * Saves translatable content policies.
	 *
	 * @return array<string, bool>|WP_Error
	 */
	private function save_content() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$post_types_mode = isset( $_POST['post_types_mode'] ) ? sanitize_key( wp_unslash( $_POST['post_types_mode'] ) ) : 'selected';
		$taxonomies_mode = isset( $_POST['taxonomies_mode'] ) ? sanitize_key( wp_unslash( $_POST['taxonomies_mode'] ) ) : 'selected';
		$post_types      = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) : array();
		$taxonomies      = isset( $_POST['taxonomies'] ) && is_array( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['taxonomies'] ) ) : array();
		$media_support   = isset( $_POST['media_support'] );
		$front_fallback  = isset( $_POST['front_fallback'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// An unreadable mode falls back to the narrower policy, so a malformed
		// submission can never widen what becomes translatable.
		$post_types_mode = in_array( $post_types_mode, array( 'all', 'selected' ), true ) ? $post_types_mode : 'selected';
		$taxonomies_mode = in_array( $taxonomies_mode, array( 'all', 'selected' ), true ) ? $taxonomies_mode : 'selected';

		if (
			! empty( array_diff( $post_types, $this->post_type_support->get_available_post_types() ) )
			|| ! empty( array_diff( $taxonomies, $this->taxonomy_support->get_available_taxonomies() ) )
		) {
			return new WP_Error( 'invalid_content_type', __( 'A selected post type or taxonomy is not available.', 'shapetranslate' ) );
		}

		$default_terms = $this->posted_default_terms( $taxonomies_mode, $taxonomies );

		if ( is_wp_error( $default_terms ) ) {
			return $default_terms;
		}

		$this->settings->update_sections(
			array(
				'content' => array(
					'post_types_mode' => $post_types_mode,
					'post_types'      => $post_types,
					'taxonomies_mode' => $taxonomies_mode,
					'taxonomies'      => $taxonomies,
					'media_support'   => $media_support,
					'front_fallback'  => $front_fallback,
					'default_terms'   => $default_terms,
				),
			)
		);

		return array( 'url_changed' => false );
	}

	/**
	 * Returns the validated per-language default term map.
	 *
	 * A term is accepted only when it exists in a taxonomy this save keeps
	 * translatable and already carries the language it is chosen for, so the map
	 * can never point one language at another language's term.
	 *
	 * @param string            $mode       Submitted taxonomy policy mode.
	 * @param array<int,string> $taxonomies Submitted translatable taxonomies.
	 * @return array<string, array<string, int>>|WP_Error
	 */
	private function posted_default_terms( $mode, array $taxonomies ) {
		$map = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by save_settings(); every value is validated below.
		$posted = isset( $_POST['default_terms'] ) && is_array( $_POST['default_terms'] )
			? wp_unslash( $_POST['default_terms'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( $posted as $taxonomy => $languages ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( ! is_array( $languages ) || ! $this->accepts_default_term( $taxonomy, $mode, $taxonomies ) ) {
				continue;
			}

			foreach ( $languages as $language_id => $term_id ) {
				$language_id = sanitize_key( (string) $language_id );
				$term_id     = is_scalar( $term_id ) ? absint( $term_id ) : 0;

				// An empty choice means this language follows the stored default.
				if ( 1 > $term_id ) {
					continue;
				}

				$term = get_term( $term_id, $taxonomy );

				if ( ! is_array( $this->language_manager->find( $language_id ) ) || ! $term instanceof WP_Term ) {
					return new WP_Error( 'invalid_default_term', __( 'A chosen default term is not available.', 'shapetranslate' ) );
				}

				if ( $this->term_translations->get_term_language_id( $term_id, $taxonomy ) !== $language_id ) {
					return new WP_Error( 'invalid_default_term_language', __( 'A default term must belong to the language it is chosen for.', 'shapetranslate' ) );
				}

				$map[ $taxonomy ][ $language_id ] = $term_id;
			}
		}

		return $map;
	}

	/**
	 * Reports whether a taxonomy may carry per-language defaults after this save.
	 *
	 * @param string            $taxonomy   Taxonomy name.
	 * @param string            $mode       Submitted taxonomy policy mode.
	 * @param array<int,string> $taxonomies Submitted translatable taxonomies.
	 * @return bool
	 */
	private function accepts_default_term( $taxonomy, $mode, array $taxonomies ) {
		if ( '' === $taxonomy || ! in_array( $taxonomy, $this->taxonomy_support->get_available_taxonomies(), true ) ) {
			return false;
		}

		if ( 'selected' === $mode && ! in_array( $taxonomy, $taxonomies, true ) ) {
			return false;
		}

		$object = get_taxonomy( $taxonomy );

		return 'category' === $taxonomy || ( $object && ! empty( $object->default_term ) );
	}

	/**
	 * Saves the ongoing synchronization choices.
	 *
	 * Every catalog item is written explicitly so an unchecked box is stored as
	 * disabled rather than falling back to its default. The copy overrides carry
	 * their current values through, because this screen does not expose them.
	 *
	 * @return array<string, bool>|WP_Error
	 */
	private function save_sync() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$workflow = isset( $_POST['workflow'] ) && is_array( $_POST['workflow'] ) ? map_deep( wp_unslash( $_POST['workflow'] ), 'sanitize_text_field' ) : array();
		$settings = $this->workflow_settings->get();

		foreach ( array_keys( SyncCatalog::items() ) as $item_id ) {
			$key              = SyncCatalog::sync_key( $item_id );
			$settings[ $key ] = isset( $workflow[ $key ] );
		}

		$this->workflow_settings->update( $settings );

		return array( 'url_changed' => false );
	}

	/**
	 * Saves switcher and navigation settings.
	 *
	 * @return array<string, bool>|WP_Error
	 */
	private function save_switcher() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$switcher       = isset( $_POST['switcher'] ) && is_array( $_POST['switcher'] ) ? map_deep( wp_unslash( $_POST['switcher'] ), 'sanitize_text_field' ) : array();
		$menu_languages = isset( $_POST['menu_languages'] ) && is_array( $_POST['menu_languages'] ) ? map_deep( wp_unslash( $_POST['menu_languages'] ), 'sanitize_text_field' ) : array();
		$menu_locations = isset( $_POST['menu_locations'] ) && is_array( $_POST['menu_locations'] ) ? map_deep( wp_unslash( $_POST['menu_locations'] ), 'sanitize_text_field' ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$floater = isset( $switcher['floater'] ) && is_array( $switcher['floater'] ) ? $switcher['floater'] : array();

		/*
		 * Stored before the menus are touched, and deliberately so. These two
		 * halves of the tab are independent — one is how a switcher looks, the
		 * other is which menu belongs to which language — but they share a
		 * screen and therefore a single Save. Validating the menus first meant
		 * that any conflict between two menu assignments returned early and
		 * discarded every switcher change made alongside it, without the notice
		 * ever mentioning them: the controls simply came back as they were.
		 * The location matrix re-posts whatever it is currently showing on
		 * every save, so a conflict already sitting in the theme's assignments
		 * was enough to make this tab look like it had stopped saving at all.
		 */
		$this->settings->update_sections(
			array(
				'switcher' => array(
					'display'              => isset( $switcher['display'] ) ? $switcher['display'] : '',
					'layout'               => isset( $switcher['layout'] ) ? $switcher['layout'] : '',
					'hide_current'         => isset( $switcher['hide_current'] ),
					'hide_missing'         => isset( $switcher['hide_missing'] ),
					'unavailable_behavior' => isset( $switcher['unavailable_behavior'] ) ? $switcher['unavailable_behavior'] : '',
					'show_flags'           => isset( $switcher['show_flags'] ),
					'show_disabled'        => isset( $switcher['show_disabled'] ),

					/*
					 * Written whole rather than merged into what is stored: an
					 * unchecked checkbox posts nothing at all, so a floater
					 * merged key by key could never be turned back off.
					 */
					'floater'              => array(
						'enabled'    => isset( $floater['enabled'] ),
						'position'   => isset( $floater['position'] ) ? $floater['position'] : '',
						'layout'     => isset( $floater['layout'] ) ? $floater['layout'] : '',
						'show_flags' => isset( $floater['show_flags'] ),
					),
				),
			)
		);

		/*
		 * Menu assignments change the theme's menu locations, which WordPress
		 * keeps behind `edit_theme_options` however the plugin's own capability
		 * is filtered. A user without it keeps the switcher half of the save.
		 */
		if ( current_user_can( 'edit_theme_options' ) ) {
			// Reported after the switcher settings are safely stored, so the notice
			// names the menu problem without the rest of the tab being lost to it.
			$result = $this->menu_manager->update_configuration( $menu_languages, $menu_locations );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array( 'url_changed' => false );
	}

	/**
	 * Saves SEO settings.
	 *
	 * @return array<string, bool>
	 */
	private function save_seo() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$seo = isset( $_POST['seo'] ) && is_array( $_POST['seo'] ) ? map_deep( wp_unslash( $_POST['seo'] ), 'sanitize_text_field' ) : array();
		$this->settings->update_sections(
			array(
				'seo' => array(
					'hreflang_enabled'  => isset( $seo['hreflang_enabled'] ),
					'x_default_enabled' => isset( $seo['x_default_enabled'] ),
					'split_sitemaps'    => isset( $seo['split_sitemaps'] ),
				),
			)
		);

		return array( 'url_changed' => false );
	}

	/**
	 * Saves uninstall behavior.
	 *
	 * @return array<string, bool>
	 */
	private function save_advanced() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$delete_data = isset( $_POST['delete_data_on_uninstall'] );
		$this->settings->update_sections(
			array( 'advanced' => array( 'delete_data_on_uninstall' => $delete_data ) )
		);

		return array( 'url_changed' => false );
	}

	/**
	 * Returns import JSON from a verified upload or textarea.
	 *
	 * @return string|WP_Error
	 */
	private function get_import_json() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by import_settings().
		if ( isset( $_FILES['settings_file'] ) && is_array( $_FILES['settings_file'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every upload field is validated below.
			$file = $_FILES['settings_file'];

			if ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					return new WP_Error( 'settings_upload_failed', __( 'The settings file upload failed.', 'shapetranslate' ) );
				}

				$name = isset( $file['name'] ) && is_scalar( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
				$size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
				$tmp  = isset( $file['tmp_name'] ) && is_scalar( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

				if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || 1 > $size || SettingsTransfer::MAX_JSON_BYTES < $size ) {
					return new WP_Error( 'invalid_settings_file', __( 'Choose a JSON settings file no larger than 1 MB.', 'shapetranslate' ) );
				}

				if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
					return new WP_Error( 'invalid_settings_upload', __( 'WordPress could not verify the uploaded settings file.', 'shapetranslate' ) );
				}

				// The path is PHP's verified upload temporary file and is never user-controlled.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$json = file_get_contents( $tmp );

				return is_string( $json ) ? $json : new WP_Error( 'settings_file_read_failed', __( 'WordPress could not read the uploaded settings file.', 'shapetranslate' ) );
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON is size-limited and structurally validated before use.
		$json = isset( $_POST['settings_json'] ) && is_scalar( $_POST['settings_json'] ) ? wp_unslash( $_POST['settings_json'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Authorizes a settings action.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function authorize( $nonce_action ) {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage ShapeTranslate settings.', 'shapetranslate' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Returns the allowlisted posted tab.
	 *
	 * @return string
	 */
	private function posted_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_settings().
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';

		return in_array( $tab, SettingsPage::tab_keys(), true ) ? $tab : 'general';
	}

	/**
	 * Stores an error for the redirected settings page.
	 *
	 * @param string $message Error message.
	 */
	private function store_error( $message ) {
		set_transient( 'shapetranslate_settings_error_' . get_current_user_id(), sanitize_text_field( $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * Redirects to an allowlisted settings response.
	 *
	 * @param string $tab    Settings tab.
	 * @param string $notice Notice code.
	 */
	private function redirect( $tab, $notice ) {
		$tab     = in_array( $tab, SettingsPage::tab_keys(), true ) ? $tab : 'general';
		$allowed = array( 'settings_saved', 'settings_error', 'import_error', 'settings_imported', 'settings_imported_url_changed', 'url_settings_changed' );
		$notice  = in_array( $notice, $allowed, true ) ? $notice : 'settings_error';
		$url     = add_query_arg(
			array(
				'page'               => 'shapetranslate-settings',
				'tab'                => $tab,
				'shapetranslate_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
