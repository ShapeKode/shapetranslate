<?php
/**
 * Navigation menu language assignments.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Language\LanguageManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Manages menu languages and per-language theme-location selections.
 */
final class MenuLanguageManager {

	/**
	 * Menu language term meta key.
	 *
	 * @var string
	 */
	const LANGUAGE_META_KEY = '_shapetranslate_language_id';

	/**
	 * Theme-specific location assignment key.
	 *
	 * @var string
	 */
	const LOCATION_THEME_MOD = 'shapetranslate_nav_menu_locations';

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager $language_manager Language manager.
	 */
	public function __construct( LanguageManager $language_manager ) {
		$this->language_manager = $language_manager;
	}

	/**
	 * Returns the language assigned to one menu.
	 *
	 * @param int $menu_id Navigation menu term identifier.
	 * @return string
	 */
	public function get_menu_language_id( $menu_id ) {
		$language_id = $this->get_stored_menu_language_id( $menu_id );

		/**
		 * Filters a navigation menu's assigned language identifier.
		 *
		 * @param string $language_id Language identifier, or an empty string.
		 * @param int    $menu_id     Navigation menu term identifier.
		 */
		$filtered = apply_filters( 'shapetranslate_menu_language_id', $language_id, absint( $menu_id ) );

		return is_string( $filtered ) ? $filtered : $language_id;
	}

	/**
	 * Assigns or clears one menu language.
	 *
	 * @param int    $menu_id     Navigation menu term identifier.
	 * @param string $language_id Language identifier, or empty to clear.
	 * @return true|WP_Error
	 */
	public function set_menu_language( $menu_id, $language_id ) {
		$menu_id     = absint( $menu_id );
		$language_id = is_scalar( $language_id ) ? sanitize_text_field( (string) $language_id ) : '';

		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return new WP_Error( 'menu_not_found', __( 'The selected navigation menu could not be found.', 'shapetranslate' ) );
		}

		if ( '' === $language_id ) {
			delete_term_meta( $menu_id, self::LANGUAGE_META_KEY );

			return true;
		}

		if ( null === $this->language_manager->find( $language_id ) ) {
			return new WP_Error( 'menu_language_not_found', __( 'The selected menu language is not registered.', 'shapetranslate' ) );
		}

		update_term_meta( $menu_id, self::LANGUAGE_META_KEY, $language_id );

		return true;
	}

	/**
	 * Returns normalized assignments for registered theme locations.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function get_location_assignments() {
		$normalized = $this->load_location_assignments();

		/**
		 * Filters theme-location menu assignments.
		 *
		 * @param array<string, array<string, int>> $normalized Assignments by location and language.
		 */
		$filtered = apply_filters( 'shapetranslate_menu_location_assignments', $normalized );

		return is_array( $filtered ) ? $filtered : $normalized;
	}

	/**
	 * Loads stored assignments without developer display filters.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function load_location_assignments() {
		$stored       = get_theme_mod( self::LOCATION_THEME_MOD, array() );
		$stored       = is_array( $stored ) && isset( $stored['locations'] ) && is_array( $stored['locations'] )
			? $stored['locations']
			: array();
		$registered   = get_registered_nav_menus();
		$language_ids = wp_list_pluck( $this->language_manager->get_languages(), 'id' );
		$normalized   = array();

		foreach ( $registered as $location => $label ) {
			unset( $label );
			$location = sanitize_key( $location );

			foreach ( $language_ids as $language_id ) {
				$menu_id = isset( $stored[ $location ][ $language_id ] )
					? absint( $stored[ $location ][ $language_id ] )
					: 0;

				if ( 0 < $menu_id ) {
					$normalized[ $location ][ $language_id ] = $menu_id;
				}
			}
		}

		return $normalized;
	}

	/**
	 * Returns the selected menu for a location and language.
	 *
	 * @param string $location    Registered theme location.
	 * @param string $language_id Language identifier.
	 * @return int
	 */
	public function get_menu_for_location( $location, $language_id ) {
		$location    = sanitize_key( $location );
		$assignments = $this->get_location_assignments();
		$menu_id     = isset( $assignments[ $location ][ $language_id ] )
			? absint( $assignments[ $location ][ $language_id ] )
			: 0;

		return 0 < $menu_id && wp_get_nav_menu_object( $menu_id ) ? $menu_id : 0;
	}

	/**
	 * Validates and stores menu-language and location configuration atomically.
	 *
	 * @param array<string|int, mixed> $menu_languages Menu IDs mapped to language IDs.
	 * @param array<string, mixed>     $locations      Location/language menu matrix.
	 * @param bool                     $exclusive      Whether a menu may hold only one language. The
	 *                                                 settings screen offers one language per menu and
	 *                                                 refuses anything else. The WordPress menus screen
	 *                                                 offers a checkbox per language instead, where one
	 *                                                 menu standing in several of them is a choice an
	 *                                                 editor is entitled to make.
	 * @return true|WP_Error
	 */
	public function update_configuration( array $menu_languages, array $locations, $exclusive = true ) {
		$menus           = wp_get_nav_menus();
		$menus_by_id     = array();
		$languages       = $this->language_manager->get_languages();
		$languages_by_id = array();

		foreach ( $menus as $menu ) {
			$menus_by_id[ $menu->term_id ] = $menu;
		}

		foreach ( $languages as $language ) {
			$languages_by_id[ $language['id'] ] = $language;
		}

		$normalized_languages = array();

		foreach ( $menu_languages as $menu_id => $language_id ) {
			$menu_id = absint( $menu_id );

			if ( ! isset( $menus_by_id[ $menu_id ] ) ) {
				continue;
			}

			$language_id = is_scalar( $language_id ) ? sanitize_text_field( (string) $language_id ) : '';

			if ( '' !== $language_id && ! isset( $languages_by_id[ $language_id ] ) ) {
				return new WP_Error( 'menu_language_not_found', __( 'A selected menu language is not registered.', 'shapetranslate' ) );
			}

			$normalized_languages[ $menu_id ] = $language_id;
		}

		$registered_locations = get_registered_nav_menus();
		$normalized_locations = array();
		$used_menu_languages  = array();
		$shared_menus         = array();

		foreach ( $registered_locations as $location => $label ) {
			unset( $label );
			$location = sanitize_key( $location );
			$selected = isset( $locations[ $location ] ) && is_array( $locations[ $location ] )
				? $locations[ $location ]
				: array();

			foreach ( $languages_by_id as $language_id => $language ) {
				unset( $language );
				$menu_id = isset( $selected[ $language_id ] ) ? absint( $selected[ $language_id ] ) : 0;

				if ( 0 === $menu_id ) {
					continue;
				}

				if ( ! isset( $menus_by_id[ $menu_id ] ) ) {
					return new WP_Error( 'menu_not_found', __( 'A selected navigation menu could not be found.', 'shapetranslate' ) );
				}

				$assigned_language = array_key_exists( $menu_id, $normalized_languages )
					? $normalized_languages[ $menu_id ]
					: $this->get_stored_menu_language_id( $menu_id );

				if ( $exclusive && '' !== $assigned_language && $language_id !== $assigned_language ) {
					return new WP_Error(
						'menu_language_conflict',
						__( 'A menu cannot be assigned to a location for a different language.', 'shapetranslate' )
					);
				}

				if ( isset( $used_menu_languages[ $menu_id ] ) && $language_id !== $used_menu_languages[ $menu_id ] ) {
					if ( $exclusive ) {
						return new WP_Error(
							'menu_language_conflict',
							__( 'The same menu cannot be assigned to more than one language.', 'shapetranslate' )
						);
					}

					/*
					 * One menu standing in several languages is a menu that has no
					 * single language of its own: its items are translated as they
					 * are rendered. Recording one of them would be picking a winner
					 * arbitrarily, so it is left with none.
					 */
					$shared_menus[ $menu_id ] = true;
				}

				$normalized_languages[ $menu_id ]                  = $language_id;
				$used_menu_languages[ $menu_id ]                   = $language_id;
				$normalized_locations[ $location ][ $language_id ] = $menu_id;
			}
		}

		foreach ( $normalized_languages as $menu_id => $language_id ) {
			$result = $this->set_menu_language(
				$menu_id,
				isset( $shared_menus[ $menu_id ] ) ? '' : $language_id
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		set_theme_mod(
			self::LOCATION_THEME_MOD,
			array(
				'version'   => 1,
				'locations' => $normalized_locations,
			)
		);

		/**
		 * Fires after menu-language and theme-location assignments are saved.
		 *
		 * @param array<int, string>                $normalized_languages Menu language assignments.
		 * @param array<string, array<string, int>> $normalized_locations Location assignments.
		 */
		do_action( 'shapetranslate_menu_configuration_updated', $normalized_languages, $normalized_locations );

		return true;
	}

	/**
	 * Removes a deleted menu from all ShapeTranslate location mappings.
	 *
	 * @param int $menu_id Deleted navigation menu term identifier.
	 * @return void
	 */
	public function remove_menu( $menu_id ) {
		$menu_id     = absint( $menu_id );
		$assignments = $this->load_location_assignments();
		$changed     = false;

		foreach ( $assignments as $location => $language_menus ) {
			foreach ( $language_menus as $language_id => $assigned_menu_id ) {
				if ( absint( $assigned_menu_id ) === $menu_id ) {
					unset( $assignments[ $location ][ $language_id ] );
					$changed = true;
				}
			}

			if ( empty( $assignments[ $location ] ) ) {
				unset( $assignments[ $location ] );
			}
		}

		if ( $changed ) {
			set_theme_mod(
				self::LOCATION_THEME_MOD,
				array(
					'version'   => 1,
					'locations' => $assignments,
				)
			);
		}
	}

	/**
	 * Reports whether a language remains assigned to any navigation menu.
	 *
	 * @param string $language_id Language identifier.
	 * @return bool
	 */
	public function is_language_in_use( $language_id ) {
		foreach ( wp_get_nav_menus() as $menu ) {
			if ( $language_id === $this->get_stored_menu_language_id( $menu->term_id ) ) {
				return true;
			}
		}

		foreach ( $this->load_location_assignments() as $language_menus ) {
			if ( isset( $language_menus[ $language_id ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns one stored menu language without developer display filters.
	 *
	 * @param int $menu_id Navigation menu term identifier.
	 * @return string
	 */
	private function get_stored_menu_language_id( $menu_id ) {
		return (string) get_term_meta( absint( $menu_id ), self::LANGUAGE_META_KEY, true );
	}
}
