<?php
/**
 * Per-language theme locations on the WordPress menus screen.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Offers each theme location once per language where menus are assigned.
 *
 * A theme registers one location and expects one menu in it. On a translated
 * site that is one menu too few: the same location has to answer with a
 * different menu in every language. ShapeTranslate already stores that mapping and
 * applies it when a menu is rendered, but the assignment could only be made on
 * the ShapeTranslate settings screen, away from the menu being edited.
 *
 * This offers the same assignment where an editor expects to find it. While the
 * menus screen or the customizer is open, each registered location is presented
 * once per language, so "Primary Menu" becomes "Primary Menu English" and
 * "Primary Menu Bengali", and the checkbox beside a menu assigns it for that one
 * language. What is saved is read back out of those locations and stored the way
 * the settings screen stores it, so both screens describe the same site.
 *
 * The default language keeps the location key the theme registered, so a theme
 * asking for `primary` is answered whether or not ShapeTranslate is active.
 */
final class MenuLocationsModule implements ModuleInterface {

	/**
	 * Separator between a theme location and a language identifier.
	 *
	 * Language identifiers are UUIDs, so this can never occur inside one and a
	 * location that contains it is still split at the right place.
	 *
	 * @var string
	 */
	const SEPARATOR = '___';

	/**
	 * Transient prefix holding a refusal until the screen can report it.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'shapetranslate_menu_locations_notice_';

	/**
	 * Menu language and location manager.
	 *
	 * @var MenuLanguageManager
	 */
	private $menu_manager;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Whether the registered locations have been expanded for this request.
	 *
	 * @var bool
	 */
	private $expanded = false;

	/**
	 * Enabled languages, once read.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $languages;

	/**
	 * Default language identifier, once read.
	 *
	 * @var string|null
	 */
	private $default_language_id;

	/**
	 * Whether an assignment is being stored right now.
	 *
	 * @var bool
	 */
	private $storing = false;

	/**
	 * Constructor.
	 *
	 * @param MenuLanguageManager $menu_manager     Menu language and location manager.
	 * @param LanguageManager     $language_manager Language manager.
	 */
	public function __construct( MenuLanguageManager $menu_manager, LanguageManager $language_manager ) {
		$this->menu_manager     = $menu_manager;
		$this->language_manager = $language_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		/*
		 * Only the two screens that assign menus to locations see the expanded
		 * set. Everywhere else - the ShapeTranslate settings screen included - keeps
		 * reading the locations the theme actually registered, so nothing else
		 * has to know this happened.
		 */
		add_action( 'load-nav-menus.php', array( $this, 'expand_locations' ) );
		add_action( 'customize_register', array( $this, 'expand_locations' ), 5 );

		add_action( 'admin_notices', array( $this, 'report_rejection' ) );

		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'add_language_assignments' ), 20 );
		add_filter(
			'pre_update_option_theme_mods_' . get_option( 'stylesheet' ),
			array( $this, 'capture_language_assignments' )
		);
	}

	/**
	 * Presents every registered theme location once per language.
	 *
	 * @return void
	 */
	public function expand_locations() {
		global $_wp_registered_nav_menus;

		if ( $this->expanded || ! is_array( $_wp_registered_nav_menus ) ) {
			return;
		}

		$languages = $this->get_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$expanded = array();

		foreach ( $_wp_registered_nav_menus as $location => $label ) {
			foreach ( $languages as $language ) {
				$name = isset( $language['name'] ) && is_scalar( $language['name'] )
					? (string) $language['name']
					: (string) $language['id'];

				$expanded[ $this->combine_location( $location, $language ) ] = $label . ' ' . $name;
			}
		}

		// The per-language locations replace the registry this module just read and expanded.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_wp_registered_nav_menus = $expanded;
		$this->expanded           = true;
	}

	/**
	 * Fills the per-language locations with the menus already assigned to them.
	 *
	 * Without this the checkboxes would all be cleared every time the screen is
	 * opened, because WordPress only knows about the assignment it stores itself.
	 *
	 * @param array<string, int>|false $locations Menu identifiers keyed by location.
	 * @return array<string, int>|false
	 */
	public function add_language_assignments( $locations ) {
		if ( ! $this->expanded || ! is_array( $locations ) ) {
			return $locations;
		}

		$assignments = $this->menu_manager->get_location_assignments();

		foreach ( $assignments as $location => $menus ) {
			foreach ( $menus as $language_id => $menu_id ) {
				$language = $this->get_language( (string) $language_id );
				$menu_id  = absint( $menu_id );

				if ( null === $language || 0 === $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
					continue;
				}

				$locations[ $this->combine_location( (string) $location, $language ) ] = $menu_id;
			}
		}

		return $locations;
	}

	/**
	 * Stores what the menus screen assigned, and hands WordPress back its own.
	 *
	 * The per-language locations exist only while the screen is open, so they are
	 * removed from the theme modification before it is written. The default
	 * language's location is a real one and stays.
	 *
	 * Theme modifications all live in one option, and the manager stores its
	 * assignments by writing that same option. That write happens inside this
	 * one, so it has to be kept from re-entering here, and what it stored has to
	 * be folded back into the modifications being written — the write in progress
	 * carries a copy of the option taken before it, and would otherwise put the
	 * assignments straight back the way they were.
	 *
	 * @param mixed $mods Theme modifications about to be saved.
	 * @return mixed
	 */
	public function capture_language_assignments( $mods ) {
		if (
			! $this->expanded
			|| $this->storing
			|| ! is_array( $mods )
			|| ! isset( $mods['nav_menu_locations'] )
			|| ! is_array( $mods['nav_menu_locations'] )
			|| ! current_user_can( 'edit_theme_options' )
		) {
			return $mods;
		}

		$assignments = array();
		$locations   = array();
		$languages   = array();

		foreach ( $mods['nav_menu_locations'] as $location => $menu_id ) {
			$parts   = $this->explode_location( (string) $location );
			$menu_id = absint( $menu_id );

			if ( 0 < $menu_id ) {
				$assignments[ $parts['location'] ][ $parts['language'] ] = $menu_id;

				/*
				 * Ticking a language's location is how an editor says which
				 * language a menu is in. Saying so explicitly is what makes it a
				 * decision rather than a disagreement: the manager compares a menu
				 * against the language it already carries and refuses the whole
				 * save when they differ, and a menu that was ever placed in the
				 * default language's location carries that language forever.
				 */
				$languages[ $menu_id ] = $parts['language'];
			}

			if ( $location === $parts['location'] ) {
				$locations[ $parts['location'] ] = $menu_id;
			}
		}

		$mods['nav_menu_locations'] = $locations;

		$this->storing = true;

		try {
			$result = $this->menu_manager->update_configuration( $languages, $assignments, false );
		} finally {
			$this->storing = false;
		}

		if ( ! is_wp_error( $result ) ) {
			$stored = get_theme_mod( MenuLanguageManager::LOCATION_THEME_MOD );

			if ( false !== $stored ) {
				$mods[ MenuLanguageManager::LOCATION_THEME_MOD ] = $stored;
			}
		} else {
			/*
			 * The screen is WordPress's own and reports nothing of its own accord,
			 * so a refusal would show only as a checkbox quietly clearing itself.
			 * It is held over the redirect and announced instead.
			 */
			set_transient(
				self::NOTICE_TRANSIENT . get_current_user_id(),
				$result->get_error_message(),
				MINUTE_IN_SECONDS
			);

			/**
			 * Fires when a menu assignment made on the menus screen was refused.
			 *
			 * @param \WP_Error                         $result      Reason the assignment was refused.
			 * @param array<string, array<string, int>> $assignments Assignments that were refused.
			 */
			do_action( 'shapetranslate_menu_locations_rejected', $result, $assignments );
		}

		return $mods;
	}

	/**
	 * Shows why an assignment made on the menus screen was refused.
	 *
	 * @return void
	 */
	public function report_rejection() {
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$message = get_transient( $key );

		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );

		wp_admin_notice(
			esc_html( $message ),
			array(
				'type'        => 'error',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Returns the location key presented for one language.
	 *
	 * @param string               $location Registered theme location.
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	private function combine_location( $location, array $language ) {
		$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

		if ( '' === $language_id || $language_id === $this->get_default_language_id() ) {
			return $location;
		}

		return $location . self::SEPARATOR . $language_id;
	}

	/**
	 * Splits a presented location back into a theme location and a language.
	 *
	 * A location carrying no language is the default language's, which is the
	 * same assumption the theme itself makes.
	 *
	 * @param string $location Presented location key.
	 * @return array{location: string, language: string}
	 */
	private function explode_location( $location ) {
		$position = strrpos( $location, self::SEPARATOR );

		if ( false !== $position ) {
			$candidate = substr( $location, $position + strlen( self::SEPARATOR ) );

			if ( null !== $this->get_language( $candidate ) ) {
				return array(
					'location' => substr( $location, 0, $position ),
					'language' => $candidate,
				);
			}
		}

		$default = $this->get_default_language_id();

		return array(
			'location' => $location,
			'language' => $default,
		);
	}

	/**
	 * Returns the enabled languages, in the order the site lists them.
	 *
	 * Read once: expanding the locations and reading the assignments back both
	 * ask for the list several times over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_languages() {
		if ( null === $this->languages ) {
			$languages       = $this->language_manager->get_languages( true );
			$this->languages = is_array( $languages ) ? array_values( $languages ) : array();
		}

		return $this->languages;
	}

	/**
	 * Returns one enabled language record.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|null
	 */
	private function get_language( $language_id ) {
		if ( '' === $language_id ) {
			return null;
		}

		foreach ( $this->get_languages() as $language ) {
			if ( isset( $language['id'] ) && (string) $language['id'] === $language_id ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Returns the default language identifier.
	 *
	 * @return string
	 */
	private function get_default_language_id() {
		if ( null === $this->default_language_id ) {
			$this->default_language_id = (string) $this->language_manager->get_default_id();
		}

		return $this->default_language_id;
	}
}
