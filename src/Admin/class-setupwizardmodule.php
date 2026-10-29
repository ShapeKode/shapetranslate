<?php
/**
 * ShapeTranslate setup wizard module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageCatalog;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Language\LanguagePackInstaller;
use ShapeTranslate\Routing\LanguageHostResolver;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Switcher\NavigationMenuIntegration;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the resumable setup wizard.
 */
final class SetupWizardModule implements ModuleInterface {

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language catalog.
	 *
	 * @var LanguageCatalog
	 */
	private $catalog;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Setup page.
	 *
	 * @var SetupWizardPage
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
	 * @param LanguageCatalog $catalog          Language catalog.
	 * @param PluginSettings  $settings         Central plugin settings.
	 */
	public function __construct( LanguageManager $language_manager, LanguageCatalog $catalog, PluginSettings $settings ) {
		$this->language_manager = $language_manager;
		$this->catalog          = $catalog;
		$this->settings         = $settings;
		$this->page             = new SetupWizardPage( $language_manager, $catalog, $settings );
		$this->language_packs   = new LanguagePackInstaller();
	}

	/** {@inheritdoc} */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 19 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shapetranslate_setup_step', array( $this, 'save_step' ) );
		add_action( 'admin_post_shapetranslate_setup_remove_language', array( $this, 'remove_language' ) );
	}

	/** Registers the rerunnable setup submenu. */
	public function register_menu() {
		add_submenu_page(
			'shapetranslate',
			esc_html__( 'Set Up ShapeTranslate', 'shapetranslate' ),
			esc_html__( 'Setup Wizard', 'shapetranslate' ),
			AdminModule::capability(),
			'shapetranslate-setup',
			array( $this->page, 'render' )
		);
	}

	/**
	 * Enqueues wizard assets.
	 *
	 * @param string $hook_suffix Current page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'shapetranslate_page_shapetranslate-setup' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'shapetranslate-admin', SHAPETRANSLATE_URL . 'assets/css/admin.css', array(), Assets::version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'shapetranslate-admin', SHAPETRANSLATE_URL . 'assets/js/admin.js', array(), Assets::version( 'assets/js/admin.js' ), true );
	}

	/** Handles one nonce-protected wizard step. */
	public function save_step() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to set up ShapeTranslate.', 'shapetranslate' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used to select the nonce verified below.
		$step = isset( $_POST['step'] ) && is_scalar( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : 1;
		$step = min( 5, max( 1, $step ) );
		check_admin_referer( 'shapetranslate_setup_step_' . $step );

		$method = 'save_step_' . $step;
		$result = $this->{$method}();

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result->get_error_message() );
			$this->redirect( $step, 'setup_error' );
		}

		/*
		 * A handler returns a step number when it wants the wizard to stay put,
		 * which is how several languages are added without leaving the page.
		 */
		$next = is_int( $result ) ? min( 5, max( 1, $result ) ) : min( 5, $step + 1 );

		$this->redirect( $next, 5 === $step ? 'setup_complete' : '' );
	}

	/** Removes one language from the wizard language list. */
	public function remove_language() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to set up ShapeTranslate.', 'shapetranslate' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Used to select the nonce verified below.
		$language_id = isset( $_GET['language'] ) ? sanitize_text_field( wp_unslash( $_GET['language'] ) ) : '';
		check_admin_referer( 'shapetranslate_setup_remove_language_' . $language_id );

		$result = $this->language_manager->delete( $language_id );

		if ( is_wp_error( $result ) ) {
			$this->store_error( $result->get_error_message() );
			$this->redirect( 2, 'setup_error' );
		}

		$this->redirect( 2 );
	}

	/**
	 * Saves the default-language step.
	 *
	 * @return true|WP_Error
	 */
	private function save_step_1() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_step().
		$language_id = isset( $_POST['language_id'] ) ? sanitize_text_field( wp_unslash( $_POST['language_id'] ) ) : '';

		if ( $this->language_manager->has_languages() ) {
			$language = $this->language_manager->find( $language_id );

			if ( null === $language ) {
				return new WP_Error( 'setup_language_missing', __( 'Choose a registered site language.', 'shapetranslate' ) );
			}

			if ( empty( $language['enabled'] ) ) {
				$result = $this->language_manager->toggle( $language_id );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$result = $this->language_manager->set_default( $language_id );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_step().
			$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
			$result = $this->create_catalog_language( $locale );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->settings->update_sections(
			array(
				'setup' => array(
					'complete' => false,
					'step'     => 2,
				),
			)
		);

		return true;
	}

	/**
	 * Adds a language, marks the default, or moves on.
	 *
	 * @return true|int|WP_Error
	 */
	private function save_step_2() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_step().
		$action  = isset( $_POST['wizard_action'] ) ? sanitize_key( wp_unslash( $_POST['wizard_action'] ) ) : 'add';
		$default = isset( $_POST['default_language'] ) ? sanitize_text_field( wp_unslash( $_POST['default_language'] ) ) : '';
		$locale  = isset( $_POST['add_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['add_locale'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $default && $default !== $this->language_manager->get_default_id() ) {
			$result = $this->language_manager->set_default( $default );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( '' !== $locale ) {
			$result = $this->add_catalog_language( $locale );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( 'continue' !== $action ) {
			return 2;
		}

		$this->settings->update_sections(
			array(
				'setup' => array(
					'complete' => false,
					'step'     => 3,
				),
			)
		);

		return true;
	}

	/**
	 * Adds one catalog language, reviving a disabled one rather than duplicating it.
	 *
	 * @param string $locale Catalog locale.
	 * @return array<string, mixed>|WP_Error
	 */
	private function add_catalog_language( $locale ) {
		$existing = $this->find_by_locale( $locale );

		if ( null === $existing ) {
			return $this->create_catalog_language( $locale );
		}

		if ( empty( $existing['enabled'] ) ) {
			return $this->language_manager->toggle( $existing['id'] );
		}

		return new WP_Error( 'setup_locale_exists', __( 'That language is already added.', 'shapetranslate' ) );
	}

	/**
	 * Saves the URL configuration step.
	 *
	 * @return true|WP_Error
	 */
	private function save_step_3() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_step().
		$mode           = isset( $_POST['url_mode'] ) ? sanitize_key( wp_unslash( $_POST['url_mode'] ) ) : '';
		$prefix_default = isset( $_POST['prefix_default'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['prefix_default'] ) );
		$domains        = array();

		/*
		 * The field is an array keyed by language identifier, and a posted key
		 * is browser input exactly like the value beside it. Reading the
		 * languages the site actually has, rather than walking what arrived,
		 * keeps an unrecognised key out instead of cleaning it up afterwards,
		 * and leaves every value sanitized at the point it is read.
		 */
		foreach ( $this->language_manager->get_languages() as $language ) {
			$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

			if (
				'' === $language_id
				|| ! isset( $_POST['domain'][ $language_id ] )
				|| ! is_scalar( $_POST['domain'][ $language_id ] )
			) {
				continue;
			}

			$domains[ $language_id ] = sanitize_text_field( wp_unslash( $_POST['domain'][ $language_id ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$url = $this->settings->get_section( 'url' );

		// An unrecognised mode keeps the stored one, so a stale form cannot move a
		// subdomain or domain site back to directories.
		if ( ! in_array( $mode, LanguageHostResolver::modes(), true ) ) {
			$mode = isset( $url['mode'] ) ? (string) $url['mode'] : LanguageHostResolver::MODE_DIRECTORY;
		}

		$result = $this->save_language_domains( $domains );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->settings->update_sections(
			array(
				'url'   => array(
					'mode'           => $mode,
					'prefix_default' => $prefix_default,
				),
				'setup' => array(
					'complete' => false,
					'step'     => 4,
				),
			)
		);

		return true;
	}

	/**
	 * Stores the domain submitted for each language.
	 *
	 * Domains are saved in every URL mode so they can be prepared before the site
	 * is switched over to them.
	 *
	 * @param array<string, mixed> $domains Domains keyed by language identifier.
	 * @return true|WP_Error
	 */
	private function save_language_domains( array $domains ) {
		foreach ( $domains as $language_id => $domain ) {
			$language_id = sanitize_text_field( (string) $language_id );
			$existing    = $this->language_manager->find( $language_id );

			if ( null === $existing ) {
				continue;
			}

			$domain  = is_scalar( $domain ) ? sanitize_text_field( (string) $domain ) : '';
			$current = isset( $existing['domain'] ) ? (string) $existing['domain'] : '';

			if ( $current === $domain ) {
				continue;
			}

			// update() validates a whole record rather than merging into the stored
			// one, so the language is resubmitted with only its domain replaced.
			$existing['domain'] = $domain;
			$result             = $this->language_manager->update( $language_id, $existing );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Saves switcher defaults and optional menu insertion.
	 *
	 * @return true|WP_Error
	 */
	private function save_step_4() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_step().
		$display = isset( $_POST['display'] ) ? sanitize_key( wp_unslash( $_POST['display'] ) ) : 'native_name';
		$layout  = isset( $_POST['layout'] ) ? sanitize_key( wp_unslash( $_POST['layout'] ) ) : 'horizontal';
		$menu_id = isset( $_POST['menu_id'] ) && is_scalar( $_POST['menu_id'] ) ? absint( wp_unslash( $_POST['menu_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 0 < $menu_id && ( ! current_user_can( 'edit_theme_options' ) || ! wp_get_nav_menu_object( $menu_id ) ) ) {
			return new WP_Error( 'setup_menu_missing', __( 'The selected navigation menu is not available.', 'shapetranslate' ) );
		}

		$has_switcher = false;

		if ( 0 < $menu_id ) {
			foreach ( wp_get_nav_menu_items( $menu_id ) as $item ) {
				if ( NavigationMenuIntegration::MENU_ITEM_URL === $item->url ) {
					$has_switcher = true;
					break;
				}
			}
		}

		if ( 0 < $menu_id && ! $has_switcher ) {
			$result = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'   => __( 'Language switcher', 'shapetranslate' ),
					'menu-item-url'     => NavigationMenuIntegration::MENU_ITEM_URL,
					'menu-item-status'  => 'publish',
					'menu-item-type'    => 'custom',
					'menu-item-classes' => 'shapetranslate-menu-switcher',
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$this->settings->update_sections(
			array(
				'switcher' => array(
					'display' => $display,
					'layout'  => $layout,
				),
				'setup'    => array(
					'complete' => false,
					'step'     => 5,
				),
			)
		);

		return true;
	}

	/**
	 * Completes the setup wizard.
	 *
	 * @return true
	 */
	private function save_step_5() {
		$this->settings->update_sections(
			array(
				'setup' => array(
					'complete' => true,
					'step'     => 5,
				),
			)
		);

		/** Fires after the ShapeTranslate setup wizard is completed. */
		do_action( 'shapetranslate_setup_completed' );

		return true;
	}

	/**
	 * Creates a validated language from a catalog locale.
	 *
	 * @param string $locale Catalog locale.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_catalog_language( $locale ) {
		$catalog = $this->catalog->get_languages();

		if ( ! isset( $catalog[ $locale ] ) ) {
			return new WP_Error( 'setup_invalid_locale', __( 'Choose a language from the ShapeTranslate catalog.', 'shapetranslate' ) );
		}

		if ( null !== $this->find_by_locale( $locale ) ) {
			return new WP_Error( 'setup_locale_exists', __( 'That language is already registered.', 'shapetranslate' ) );
		}

		$language             = $catalog[ $locale ];
		$language['url_slug'] = $this->unique_slug( $language['url_slug'], $locale );
		$language['enabled']  = true;

		$created = $this->language_manager->create( $language );

		// Best effort: a failed download must not stop the wizard.
		if ( ! is_wp_error( $created ) ) {
			$this->language_packs->install( $locale );
		}

		return $created;
	}

	/**
	 * Finds a registered language by locale.
	 *
	 * @param string $locale Locale.
	 * @return array<string, mixed>|null
	 */
	private function find_by_locale( $locale ) {
		foreach ( $this->language_manager->get_languages() as $language ) {
			if ( $language['locale'] === $locale ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns a language slug not used by another language.
	 *
	 * @param string $base   Base slug.
	 * @param string $locale Locale.
	 * @return string
	 */
	private function unique_slug( $base, $locale ) {
		$used = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			$used[] = $language['url_slug'];
		}

		$base = sanitize_title( $base );

		if ( ! in_array( $base, $used, true ) ) {
			return $base;
		}

		$locale_slug = sanitize_title( str_replace( '_', '-', $locale ) );

		if ( ! in_array( $locale_slug, $used, true ) ) {
			return $locale_slug;
		}

		$index = 2;

		do {
			$candidate = $locale_slug . '-' . $index;
			++$index;
		} while ( in_array( $candidate, $used, true ) );

		return $candidate;
	}

	/**
	 * Stores an error for the redirected wizard page.
	 *
	 * @param string $message Error message.
	 */
	private function store_error( $message ) {
		set_transient( 'shapetranslate_setup_error_' . get_current_user_id(), sanitize_text_field( $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * Redirects to a wizard step.
	 *
	 * @param int    $step   Wizard step.
	 * @param string $notice Notice code.
	 */
	private function redirect( $step, $notice = '' ) {
		$args = array(
			'page' => 'shapetranslate-setup',
			'step' => min( 5, max( 1, absint( $step ) ) ),
		);

		if ( in_array( $notice, array( 'setup_error', 'setup_complete' ), true ) ) {
			$args['shapetranslate_notice'] = $notice;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
