<?php
/**
 * REST routes managing the languages of a block theme's menus.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Admin\AdminModule;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The four things the Site Editor's language panel does, for a menu.
 *
 * Deliberately the same four operations, under the same names, as the routes
 * behind a template: the panel that calls these is the panel that calls those,
 * and it should not have to know which of the three it is looking at. What a
 * template names by slug a menu names by identifier, so `slug` here carries a
 * post identifier — the name stays because it is the panel's word for "the
 * thing on screen", and the panel never reads what is inside it.
 */
final class NavigationMenuRoutes implements ModuleInterface {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'shapetranslate/v1';

	/**
	 * Menu language service.
	 *
	 * @var NavigationMenus
	 */
	private $menus;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Constructor.
	 *
	 * @param NavigationMenus $menus            Menu language service.
	 * @param LanguageManager $language_manager Language manager.
	 */
	public function __construct( NavigationMenus $menus, LanguageManager $language_manager ) {
		$this->menus            = $menus;
		$this->language_manager = $language_manager;
	}

	/**
	 * Returns the path one of these routes answers on.
	 *
	 * @param string $route Route name.
	 * @return string
	 */
	public static function path( $route ) {
		return self::NAMESPACE . '/' . NavigationMenus::ROUTE_BASE . '/' . $route;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! $this->menus->is_available() ) {
			return;
		}

		$menu = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		);

		$language = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
		);

		/*
		 * Removal keeps the unvalidated argument: a translation left behind by a
		 * language since deleted from the registry still has to be removable.
		 */
		$removal = array(
			'slug'     => $menu,
			'language' => $language,
		);

		$arguments = array(
			'slug'     => $menu,
			'language' => array_merge( $language, array( 'validate_callback' => array( $this, 'validate_language' ) ) ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . NavigationMenus::ROUTE_BASE . '/translation',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => $arguments,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => $removal,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . NavigationMenus::ROUTE_BASE . '/language',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'move' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => $arguments,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . NavigationMenus::ROUTE_BASE . '/link',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'link' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => array_merge( $arguments, array( 'target' => $menu ) ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . NavigationMenus::ROUTE_BASE . '/candidates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'candidates' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => array( 'slug' => $menu ),
				),
			)
		);
	}

	/**
	 * Reports whether the current user may manage menu languages.
	 *
	 * @return bool|WP_Error
	 */
	public function may_manage() {
		if ( current_user_can( AdminModule::capability() ) && current_user_can( 'edit_theme_options' ) ) {
			return true;
		}

		return new WP_Error(
			'shapetranslate_forbidden',
			__( 'You are not allowed to manage menu languages.', 'shapetranslate' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Refuses a language the registry does not know before any callback runs.
	 *
	 * @param mixed $value Requested language identifier.
	 * @return true|WP_Error
	 */
	public function validate_language( $value ) {
		if ( is_string( $value ) && null !== $this->language_manager->find( sanitize_text_field( $value ) ) ) {
			return true;
		}

		return new WP_Error(
			'shapetranslate_language_not_found',
			__( 'The requested language does not exist.', 'shapetranslate' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Starts one language's version of a menu, and says where to edit it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$created = $this->menus->create_translation(
			$request->get_param( 'slug' ),
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $created ) ) {
			return $this->as_rest_error( $created );
		}

		return new WP_REST_Response(
			array(
				'slug'     => (string) $created,
				'language' => (string) $request->get_param( 'language' ),
				'editUrl'  => $this->menus->editor_url( $created ),
			),
			201
		);
	}

	/**
	 * Deletes one language's version of a menu.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$removed = $this->menus->delete_translation(
			$request->get_param( 'slug' ),
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $removed ) ) {
			return $this->as_rest_error( $removed );
		}

		return new WP_REST_Response(
			array(
				'slug'     => (string) $removed['menu'],
				'language' => $removed['language'],
				'editUrl'  => $this->menus->editor_url( $removed['menu'] ),
			),
			200
		);
	}

	/**
	 * Moves a menu into another language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function move( WP_REST_Request $request ) {
		$moved = $this->menus->change_language(
			$request->get_param( 'slug' ),
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $moved ) ) {
			return $this->as_rest_error( $moved );
		}

		return new WP_REST_Response(
			array(
				'slug'     => (string) $moved['menu'],
				'language' => $moved['language'],
				'editUrl'  => $this->menus->editor_url( $moved['menu'] ),
			),
			200
		);
	}

	/**
	 * Adopts a menu the site already has as one language's version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link( WP_REST_Request $request ) {
		$linked = $this->menus->link_existing(
			$request->get_param( 'slug' ),
			$request->get_param( 'target' ),
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $linked ) ) {
			return $this->as_rest_error( $linked );
		}

		return new WP_REST_Response(
			array(
				'slug'     => (string) $linked['menu'],
				'language' => $linked['language'],
				'editUrl'  => $this->menus->editor_url( $linked['menu'] ),
			),
			200
		);
	}

	/**
	 * Lists the menus that could be adopted as a translation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function candidates( WP_REST_Request $request ) {
		return new WP_REST_Response( $this->menus->link_candidates( $request->get_param( 'slug' ) ), 200 );
	}

	/**
	 * Gives a service error the status the editor needs to report it.
	 *
	 * @param WP_Error $error Error raised by the service.
	 * @return WP_Error
	 */
	private function as_rest_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 0;

		$statuses = array(
			'shapetranslate_menu_not_found'      => 404,
			'shapetranslate_menu_not_translated' => 404,
			'shapetranslate_menu_exists'         => 409,
			'shapetranslate_link_self'           => 400,
			'invalid_post'                    => 404,
			'source_post_trashed'             => 409,
			'duplicate_translation_language'  => 409,
			'conflicting_post_language'       => 409,
			'conflicting_post_languages'      => 409,
			'conflicting_translation_groups'  => 409,
			'mixed_translation_post_types'    => 409,
		);

		$code = $error->get_error_code();

		if ( 0 === $status ) {
			$status = isset( $statuses[ $code ] ) ? $statuses[ $code ] : 500;
		}

		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}
}
