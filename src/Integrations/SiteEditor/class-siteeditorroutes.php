<?php
/**
 * REST routes behind the Site Editor language panel.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Admin\AdminModule;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the Site Editor manage the languages of one template.
 *
 * The panel that offers these is inside the Site Editor, which is a single page
 * that never reloads, so the work has to happen where the editor already talks:
 * over REST. Everything that writes answers with the address of what it wrote,
 * because the one thing anyone does after moving or starting a translation is
 * edit it.
 *
 * One of these runs per translated type, under a path of its own, so the same
 * four operations read the same whether the thing being translated is a header
 * or the template that names it.
 */
final class SiteEditorRoutes implements ModuleInterface {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'shapetranslate/v1';

	/**
	 * Route serving one language's version of a template.
	 */
	const TRANSLATION = 'translation';

	/**
	 * Route moving a template into another language.
	 */
	const LANGUAGE = 'language';

	/**
	 * Route adopting an existing template as a translation.
	 */
	const LINK = 'link';

	/**
	 * Route listing what could be adopted.
	 */
	const CANDIDATES = 'candidates';

	/**
	 * Template translation service.
	 *
	 * @var BlockTemplates
	 */
	private $templates;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Constructor.
	 *
	 * @param BlockTemplates  $templates        Template translation service.
	 * @param LanguageManager $language_manager Language manager.
	 */
	public function __construct( BlockTemplates $templates, LanguageManager $language_manager ) {
		$this->templates        = $templates;
		$this->language_manager = $language_manager;
	}

	/**
	 * Returns the REST path one route answers on for one type.
	 *
	 * @param BlockTemplates $templates Template translation service.
	 * @param string         $route     Route name.
	 * @return string
	 */
	public static function path( BlockTemplates $templates, $route ) {
		return self::NAMESPACE . '/' . $templates->route_base() . '/' . $route;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$slug = array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_title',
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
			'slug'     => $slug,
			'language' => $language,
		);

		$arguments = array(
			'slug'     => $slug,
			'language' => array_merge( $language, array( 'validate_callback' => array( $this, 'validate_language' ) ) ),
		);

		$this->register_route(
			self::TRANSLATION,
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

		$this->register_route(
			self::LANGUAGE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'move' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => $arguments,
				),
			)
		);

		$this->register_route(
			self::LINK,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'link' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => array_merge( $arguments, array( 'target' => $slug ) ),
				),
			)
		);

		$this->register_route(
			self::CANDIDATES,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'candidates' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => array( 'slug' => $slug ),
				),
			)
		);
	}

	/**
	 * Reports whether the current user may manage template languages.
	 *
	 * Both capabilities, because both things are being done at once: a language
	 * is being assigned, and the active theme is being given something it did not
	 * have.
	 *
	 * @return bool|WP_Error
	 */
	public function may_manage() {
		if ( current_user_can( AdminModule::capability() ) && current_user_can( 'edit_theme_options' ) ) {
			return true;
		}

		return new WP_Error(
			'shapetranslate_forbidden',
			__( 'You are not allowed to manage template languages.', 'shapetranslate' ),
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
	 * Creates one language's version of a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$slug        = (string) $request->get_param( 'slug' );
		$language_id = (string) $request->get_param( 'language' );

		/*
		 * The slug the panel holds is whichever template is open, which is as
		 * likely to be a translation as the one it was made from. Both name the
		 * same header, and only one of them can be translated from.
		 */
		$source  = $this->templates->read_slug( $slug )->base();
		$created = $this->templates->create_translation( $source, $language_id );

		if ( is_wp_error( $created ) ) {
			return $this->as_rest_error( $created );
		}

		$post = get_post( $created );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'shapetranslate_part_missing',
				__( 'The template was created but could not be read back.', 'shapetranslate' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'slug'     => (string) $post->post_name,
				'language' => $language_id,
				'editUrl'  => $this->templates->editor_url( $post->post_name ),
			),
			201
		);
	}

	/**
	 * Deletes one language's version of a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$slug        = (string) $request->get_param( 'slug' );
		$language_id = (string) $request->get_param( 'language' );
		$source      = $this->templates->read_slug( $slug )->base();
		$translated  = $this->templates->get_translations( $source );

		if ( ! isset( $translated[ $language_id ] ) ) {
			return new WP_Error(
				'shapetranslate_part_not_translated',
				$this->templates->message( 'not_translated' ),
				array( 'status' => 404 )
			);
		}

		$post = $this->templates->find_post( $translated[ $language_id ] );

		if ( ! $post instanceof WP_Post ) {
			/*
			 * Something the theme ships as a file and nobody has customized. There
			 * is no post to delete, and deleting the file is not this plugin's to
			 * do.
			 */
			return new WP_Error(
				'shapetranslate_part_from_theme',
				$this->templates->message( 'from_theme' ),
				array( 'status' => 409 )
			);
		}

		wp_delete_post( $post->ID, true );
		$this->templates->forget();

		return new WP_REST_Response(
			array(
				'language' => $language_id,
				'source'   => $source,
				'editUrl'  => $this->templates->editor_url( $source ),
			),
			200
		);
	}

	/**
	 * Moves the open template into another language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function move( WP_REST_Request $request ) {
		$moved = $this->templates->change_language(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $moved ) ) {
			return $this->as_rest_error( $moved );
		}

		return new WP_REST_Response(
			array(
				'slug'     => $moved['slug'],
				'language' => $moved['language'],
				'editUrl'  => $this->templates->editor_url( $moved['slug'] ),
			),
			200
		);
	}

	/**
	 * Adopts a template the site already has as one language's version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link( WP_REST_Request $request ) {
		$linked = $this->templates->link_existing(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'target' ),
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $linked ) ) {
			return $this->as_rest_error( $linked );
		}

		return new WP_REST_Response(
			array(
				'slug'     => $linked['slug'],
				'language' => $linked['language'],
				'editUrl'  => $this->templates->editor_url( $linked['slug'] ),
			),
			200
		);
	}

	/**
	 * Lists the templates that could be adopted as a translation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function candidates( WP_REST_Request $request ) {
		return new WP_REST_Response(
			$this->templates->link_candidates( (string) $request->get_param( 'slug' ) ),
			200
		);
	}

	/**
	 * Registers one route under this type's path.
	 *
	 * @param string                    $route Route name.
	 * @param array<int, array<string, mixed>> $handlers Route handlers.
	 * @return void
	 */
	private function register_route( $route, array $handlers ) {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->templates->route_base() . '/' . $route,
			$handlers
		);
	}

	/**
	 * Gives a service error the status the editor needs to report it.
	 *
	 * @param WP_Error $error Error raised by the service.
	 * @return WP_Error
	 */
	private function as_rest_error( WP_Error $error ) {
		$statuses = array(
			'shapetranslate_part_not_found'      => 404,
			'shapetranslate_language_not_found'  => 400,
			'shapetranslate_part_is_translation' => 400,
			'shapetranslate_link_self'           => 400,
			'shapetranslate_link_default'        => 400,
			'shapetranslate_part_is_fallback'    => 409,
			'shapetranslate_part_exists'         => 409,
			'shapetranslate_part_slug_taken'     => 409,
			'duplicate_translation_language'  => 409,
			'conflicting_post_language'       => 409,
			'conflicting_translation_groups'  => 409,
		);

		$code   = $error->get_error_code();
		$status = isset( $statuses[ $code ] ) ? $statuses[ $code ] : 500;

		return new WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}
}
