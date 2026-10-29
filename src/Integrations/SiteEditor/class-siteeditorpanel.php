<?php
/**
 * What the Site Editor language panel knows about the template on screen.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Admin\AdminModule;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Navigation\NavigationMenuRoutes;
use ShapeTranslate\Navigation\NavigationMenus;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one template's languages, and answers for any of them over REST.
 *
 * The panel used to be handed a single answer, worked out from the address the
 * Site Editor was opened on. That address is only true for as long as the
 * editor stays where it started, and it rarely does: an editor opens the site,
 * picks Patterns, opens the header, and the page never reloads once. The panel
 * was then describing a template nobody was looking at any more, or saying that
 * nothing was open at all.
 *
 * So the same description is also served over REST, and the panel asks for it
 * again whenever the editor moves. One builder answers both, because a panel
 * that described an open template differently depending on whether the page had
 * just loaded would be two features wearing one name.
 */
final class SiteEditorPanel implements ModuleInterface {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'shapetranslate/v1';

	/**
	 * Route serving the panel's description of one template.
	 */
	const ROUTE = 'site-editor/panel';

	/**
	 * Template part translation service.
	 *
	 * @var TemplateParts
	 */
	private $parts;

	/**
	 * Template translation service.
	 *
	 * @var Templates
	 */
	private $templates;

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
	 * Flag lookup.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Constructor.
	 *
	 * @param TemplateParts   $parts            Template part translation service.
	 * @param Templates       $templates        Template translation service.
	 * @param LanguageManager $language_manager Language manager.
	 * @param NavigationMenus $menus            Menu language service.
	 */
	public function __construct(
		TemplateParts $parts,
		Templates $templates,
		LanguageManager $language_manager,
		NavigationMenus $menus
	) {
		$this->parts            = $parts;
		$this->templates        = $templates;
		$this->menus            = $menus;
		$this->language_manager = $language_manager;
		$this->flags            = new FlagRegistry();
	}

	/**
	 * Returns the path the panel route answers on.
	 *
	 * @return string
	 */
	public static function path() {
		return self::NAMESPACE . '/' . self::ROUTE;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->parts->is_available() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		/*
		 * Later than the template services answer, and only for a menu, which is
		 * the one thing they do not recognise: they read a slug out of the
		 * address and a menu is opened by identifier.
		 */
		add_filter( 'shapetranslate_editor_language_id', array( $this, 'answer_editor_language' ), 20 );
	}

	/**
	 * Says which language the Site Editor is working in while a menu is open.
	 *
	 * Without this the editor kept asking for the default language's content
	 * while a Bengali menu was on screen, so the link search offered English
	 * pages and an editor building a Bengali menu was handed English pages to
	 * build it out of — the exact mixing the language on the request prevents
	 * everywhere else.
	 *
	 * @param mixed $language_id Language identifier answered so far.
	 * @return mixed
	 */
	public function answer_editor_language( $language_id ) {
		$menu_id = $this->open_menu_id();

		if ( '' === $menu_id ) {
			return $language_id;
		}

		$menu_language = $this->menus->get_language_id( $menu_id );

		return '' !== $menu_language ? $menu_language : $language_id;
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'describe' ),
					'permission_callback' => array( $this, 'may_read' ),
					'args'                => array(
						'postType' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'slug'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
			)
		);
	}

	/**
	 * Reports whether the current user may be told about template languages.
	 *
	 * Reading only, so this asks for the capability that opens the panel rather
	 * than the pair the writing routes ask for. An editor who may look at the
	 * Site Editor's language panel may be told what it says.
	 *
	 * @return bool|WP_Error
	 */
	public function may_read() {
		if ( current_user_can( AdminModule::capability() ) ) {
			return true;
		}

		return new WP_Error(
			'shapetranslate_forbidden',
			__( 'You are not allowed to read template languages.', 'shapetranslate' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Describes whatever template the request names.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function describe( WP_REST_Request $request ) {
		$service = $this->service_for( (string) $request->get_param( 'postType' ) );
		$slug    = (string) $request->get_param( 'slug' );

		return new WP_REST_Response( $this->data( $service, $slug ) );
	}

	/**
	 * Returns the description the panel starts with, from the address it loaded on.
	 *
	 * @return array<string, mixed>
	 */
	public function initial_data() {
		$menu_id = $this->open_menu_id();

		if ( '' !== $menu_id ) {
			return $this->data( $this->service_for( NavigationMenus::POST_TYPE ), $menu_id );
		}

		$service = $this->open_service();
		$slug    = null === $service ? '' : $service->get_edited_slug();

		return $this->data( $service, $slug );
	}

	/**
	 * Returns the menu the Site Editor was opened on, if it was opened on one.
	 *
	 * Both address forms are read, the same two the template services read: the
	 * `postType` and `postId` pair, and the single `p` path that replaced it.
	 *
	 * @return string Menu identifier, or an empty string.
	 */
	private function open_menu_id() {
		if ( ! $this->parts->is_site_editor() ) {
			return '';
		}

		$type = '';
		$id   = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, and validated by shape below.
		if ( isset( $_GET['p'] ) && is_string( $_GET['p'] ) ) {
			$matches = array();

			if ( preg_match( '#^/([a-z_]+)/(\d+)$#', sanitize_text_field( wp_unslash( $_GET['p'] ) ), $matches ) ) {
				$type = $matches[1];
				$id   = $matches[2];
			}
		}

		if ( '' === $type && isset( $_GET['postType'], $_GET['postId'] ) && is_string( $_GET['postType'] ) ) {
			$type = sanitize_key( wp_unslash( $_GET['postType'] ) );
			$id   = (string) absint( wp_unslash( $_GET['postId'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return NavigationMenus::POST_TYPE === $type && '0' !== $id ? $id : '';
	}

	/**
	 * Returns the service answering for one post type.
	 *
	 * @param string $post_type Post type.
	 * @return BlockTemplates|NavigationMenus|null
	 */
	public function service_for( $post_type ) {
		foreach ( array( $this->parts, $this->templates, $this->menus ) as $service ) {
			if ( $service->post_type() !== $post_type ) {
				continue;
			}

			// Menus are translated only where the site asked for that.
			if ( $service instanceof NavigationMenus && ! $service->is_available() ) {
				return null;
			}

			return $service;
		}

		return null;
	}

	/**
	 * Returns the service answering for whatever the address named.
	 *
	 * A template part and a template are told apart by the address, and only one
	 * of them can be open, so the first to recognise it is the one that answers.
	 *
	 * @return BlockTemplates|null
	 */
	private function open_service() {
		foreach ( array( $this->parts, $this->templates ) as $service ) {
			if ( '' !== $service->get_edited_slug() ) {
				return $service;
			}
		}

		return null;
	}

	/**
	 * Returns what the panel needs to describe one template.
	 *
	 * @param BlockTemplates|NavigationMenus|null $service Service answering for it.
	 * @param string                              $slug    Template slug, or a menu identifier.
	 * @return array<string, mixed>
	 */
	public function data( $service, $slug ) {
		$slug    = (string) $slug;
		$is_menu = $service instanceof NavigationMenus;

		if ( ( ! $service instanceof BlockTemplates && ! $is_menu ) || '' === $slug ) {
			return $this->nothing_open();
		}

		/*
		 * Asked about something the theme cannot render. Describing it anyway
		 * would put a language and a row of buttons against a template that does
		 * not exist, and every one of those buttons would fail when pressed.
		 */
		if ( ! $service->exists( $slug ) ) {
			return $this->nothing_open();
		}

		$current      = $service->get_language_id( $slug );
		$translations = $service->get_translations( $slug );
		$default_id   = $service->default_language_id();
		$languages    = array();

		foreach ( $this->language_manager->get_languages() as $language ) {
			$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

			if ( '' === $language_id ) {
				continue;
			}

			$target = isset( $translations[ $language_id ] ) ? (string) $translations[ $language_id ] : '';

			/*
			 * A menu is always a post, so every version of one can be deleted.
			 * A template may be a file the theme ships, which has nothing behind
			 * it to delete and is not this plugin's file to remove.
			 */
			$deletable = $is_menu
				? '' !== $target
				: ( '' !== $target && null !== $service->find_post( $target ) );

			$languages[] = array(
				'id'          => $language_id,
				'name'        => isset( $language['name'] ) ? (string) $language['name'] : $language_id,
				'nativeName'  => isset( $language['native_name'] ) ? (string) $language['native_name'] : $language_id,
				'flagUrl'     => $this->flags->get_flag_url( $language ),
				'code'        => isset( $language['language_code'] ) ? (string) $language['language_code'] : $language_id,
				'slug'        => $target,
				'isCurrent'   => $language_id === $current,
				'isDefault'   => $language_id === $default_id,
				/*
				 * A language nobody has enabled is still shown when it holds this
				 * template, because hiding it would hide the only way back to it.
				 */
				'isEnabled'   => ! empty( $language['enabled'] ) || $language_id === $current,
				/*
				 * The button says so by being disabled rather than by failing
				 * when it is pressed.
				 */
				'isDeletable' => $deletable,
				'editUrl'     => '' === $target ? '' : $service->editor_url( $target ),
			);
		}

		return array_merge(
			$this->nothing_open(),
			array(
				'slug'       => $slug,
				'postType'   => $service->post_type(),
				'title'      => $service->get_title( $slug ),
				'language'   => $current,
				'languages'  => $languages,
				'isOpen'     => true,
				'isTemplate' => $service instanceof Templates,
				'isMenu'     => $is_menu,
				'canMove'    => $service->can_change_language( $slug ),
				'moveNotice' => $service->message( 'is_fallback' ),
				'routes'     => $this->routes_for( $service ),
			)
		);
	}

	/**
	 * Returns the four operations one service answers, by name.
	 *
	 * The names are the same whichever service it is, because the panel that
	 * calls them does the same four things to a menu as it does to a header.
	 *
	 * @param BlockTemplates|NavigationMenus $service Service answering for it.
	 * @return array<string, string>
	 */
	private function routes_for( $service ) {
		if ( $service instanceof NavigationMenus ) {
			return array(
				'translation' => NavigationMenuRoutes::path( 'translation' ),
				'language'    => NavigationMenuRoutes::path( 'language' ),
				'link'        => NavigationMenuRoutes::path( 'link' ),
				'candidates'  => NavigationMenuRoutes::path( 'candidates' ),
			);
		}

		return array(
			'translation' => SiteEditorRoutes::path( $service, SiteEditorRoutes::TRANSLATION ),
			'language'    => SiteEditorRoutes::path( $service, SiteEditorRoutes::LANGUAGE ),
			'link'        => SiteEditorRoutes::path( $service, SiteEditorRoutes::LINK ),
			'candidates'  => SiteEditorRoutes::path( $service, SiteEditorRoutes::CANDIDATES ),
		);
	}

	/**
	 * Returns the description of a panel with no template behind it.
	 *
	 * The route to ask again on is part of it, because this is also what the
	 * panel is handed at load time, and the whole point of the route is to be
	 * called from a panel that was told nothing was open.
	 *
	 * @return array<string, mixed>
	 */
	private function nothing_open() {
		return array(
			'isOpen'     => false,
			'languages'  => array(),
			'canManage'  => current_user_can( 'edit_theme_options' ) && current_user_can( AdminModule::capability() ),
			'panelRoute' => self::path(),
			/*
			 * The post types the panel may ask about. The editor names the type
			 * of whatever it has open, and anything not listed here — a page
			 * being edited in the same editor, say — is not this panel's to
			 * describe.
			 */
			'postTypes'  => $this->menus->is_available()
				? array( $this->parts->post_type(), $this->templates->post_type(), $this->menus->post_type() )
				: array( $this->parts->post_type(), $this->templates->post_type() ),
		);
	}
}
