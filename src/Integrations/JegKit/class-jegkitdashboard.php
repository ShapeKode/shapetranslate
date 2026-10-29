<?php
/**
 * Jeg Kit theme builder dashboard integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\JegKit;

use ShapeTranslate\Assets;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Names the language of every template on the builder's own dashboard.
 *
 * The builder's theme builder screen is a React application that identifies each
 * header and footer by its name and nothing else, and its table takes no extra
 * columns: the rows are its own components, the location it offers extensions is
 * reserved for screens this plugin's free version does not have, and the markup
 * a row leaves behind carries no identifier at all. So the language is written
 * into the row from the browser, after the table renders.
 *
 * What makes that safe is where the answer comes from. The list the table draws
 * arrives over one of the builder's own REST routes, and that route's response
 * is answerable here — each template is handed back carrying the language it was
 * written in, alongside the name and identifier the builder asked for. The
 * script therefore never guesses: it reads the same list the table just drew,
 * in the same order, and writes each answer beside the name it belongs to.
 *
 * Every step fails the same way, by leaving the screen exactly as the builder
 * drew it, because all of this reaches into an application ShapeTranslate does not
 * own.
 */
final class JegKitDashboard implements ModuleInterface {

	/**
	 * Screen the builder's dashboard is rendered on.
	 */
	const SCREEN_ID = 'toplevel_page_jkit';

	/**
	 * Route answering with the templates of one location.
	 */
	const TEMPLATE_LIST_ROUTE = '/jkit/v1/getTemplateLists';

	/**
	 * Key the language is handed back under, beside the builder's own.
	 */
	const PAYLOAD_KEY = 'shapetranslate';

	/**
	 * Header and footer language resolution service.
	 *
	 * @var JegKitTemplates
	 */
	private $templates;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language records already looked up, keyed by identifier.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private $languages = array();

	/**
	 * Constructor.
	 *
	 * @param JegKitTemplates        $templates         Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageManager        $language_manager  Language manager.
	 */
	public function __construct(
		JegKitTemplates $templates,
		PostTranslationManager $post_translations,
		LanguageManager $language_manager
	) {
		$this->templates         = $templates;
		$this->post_translations = $post_translations;
		$this->language_manager  = $language_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() ) {
			return;
		}

		add_filter( 'rest_post_dispatch', array( $this, 'answer_template_languages' ), 10, 3 );

		// Ahead of the builder's own dashboard scripts, which register on this
		// hook at the default priority: the middleware below has to be in place
		// before the application it listens to makes its first request.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ), 5 );
	}

	/**
	 * Hands each template back carrying the language it was written in.
	 *
	 * The builder's own answer is left exactly as it was and the language is
	 * added beside it, because the application reads the keys it knows by name
	 * and writes back only the ones its forms hold. A key it never reads cannot
	 * reach the database through it.
	 *
	 * @param mixed $result  Response about to be served.
	 * @param mixed $server  REST server.
	 * @param mixed $request Request being answered.
	 * @return mixed
	 */
	public function answer_template_languages( $result, $server, $request ) {
		if (
			! $result instanceof WP_REST_Response
			|| $result->is_error()
			|| ! $request instanceof WP_REST_Request
			|| self::TEMPLATE_LIST_ROUTE !== $request->get_route()
			|| ! $this->answering_languages()
		) {
			return $result;
		}

		$data = $result->get_data();

		if ( ! is_array( $data ) ) {
			return $result;
		}

		$result->set_data( $this->describe_templates( $data ) );

		return $result;
	}

	/**
	 * Gives the builder's dashboard the script that names each row's language.
	 *
	 * @param mixed $hook_suffix Screen the administration is rendering.
	 * @return void
	 */
	public function enqueue_dashboard_assets( $hook_suffix ) {
		if ( self::SCREEN_ID !== $hook_suffix || ! $this->answering_languages() ) {
			return;
		}

		wp_enqueue_style(
			'shapetranslate-jegkit-dashboard',
			SHAPETRANSLATE_URL . 'assets/css/jeg-kit-dashboard.css',
			array(),
			Assets::version( 'assets/css/jeg-kit-dashboard.css' )
		);

		wp_enqueue_script(
			'shapetranslate-jegkit-dashboard',
			SHAPETRANSLATE_URL . 'assets/js/jeg-kit-dashboard.js',
			array( 'wp-api-fetch' ),
			Assets::version( 'assets/js/jeg-kit-dashboard.js' ),
			true
		);

		wp_localize_script(
			'shapetranslate-jegkit-dashboard',
			'shapeTranslateJegKit',
			array(
				'postTypes'   => JegKitTemplates::post_types(),
				'listRoute'   => self::TEMPLATE_LIST_ROUTE,
				'payloadKey'  => self::PAYLOAD_KEY,
				'templates'   => $this->describe_locations(),
				/* translators: %s: language name. */
				'labelFormat' => __( 'Language: %s', 'shapetranslate' ),
			)
		);
	}

	/**
	 * Describes the templates of every location the builder fills.
	 *
	 * The script is given the lists once with the page, so the first table it
	 * sees is already answered and nothing has to be fetched to draw it.
	 *
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private function describe_locations() {
		$located = array();

		foreach ( JegKitTemplates::post_types() as $post_type ) {
			$located[ $post_type ] = $this->describe_templates(
				array(
					'publish' => $this->list_templates( $post_type, 'publish' ),
					'draft'   => $this->list_templates( $post_type, 'draft' ),
				)
			);
		}

		return $located;
	}

	/**
	 * Returns one status of one location, shaped as the builder shapes it.
	 *
	 * @param string $post_type Template post type.
	 * @param string $status    Post status the builder groups by.
	 * @return array<int, array<string, mixed>>
	 */
	private function list_templates( $post_type, $status ) {
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => $status,
				'orderby'                => 'menu_order',
				'order'                  => 'ASC',
				'posts_per_page'         => -1,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$templates = array();

		foreach ( (array) $posts as $post ) {
			$templates[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
			);
		}

		return $templates;
	}

	/**
	 * Adds the language to every template inside one of the builder's answers.
	 *
	 * The shape is walked rather than assumed, because the builder groups its
	 * templates by status in one answer and by nothing at all in another, and
	 * what both have in common is a template: an entry naming an identifier and
	 * a title. Everything else is passed through untouched.
	 *
	 * @param array<mixed> $data Answer about to be served.
	 * @return array<mixed>
	 */
	private function describe_templates( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( isset( $value['id'] ) && array_key_exists( 'title', $value ) ) {
				$language = $this->describe_language( $value['id'] );

				if ( array() !== $language ) {
					$value[ self::PAYLOAD_KEY ] = $language;
					$data[ $key ]               = $value;
				}

				continue;
			}

			$data[ $key ] = $this->describe_templates( $value );
		}

		return $data;
	}

	/**
	 * Returns what the language of one template is called.
	 *
	 * @param mixed $template_id Template post identifier.
	 * @return array<string, string> Empty when the template names no language.
	 */
	private function describe_language( $template_id ) {
		$template_id = absint( $template_id );

		if ( 0 === $template_id || ! $this->templates->is_template_post_type( get_post_type( $template_id ) ) ) {
			return array();
		}

		$language_id = $this->post_translations->get_post_language_id( $template_id );

		if ( '' === $language_id ) {
			return array();
		}

		$language = $this->find_language( $language_id );

		if ( null === $language ) {
			return array();
		}

		$label = '';

		foreach ( array( 'native_name', 'name' ) as $field ) {
			if ( isset( $language[ $field ] ) && is_string( $language[ $field ] ) && '' !== $language[ $field ] ) {
				$label = $language[ $field ];
				break;
			}
		}

		return array(
			'language' => $language_id,
			'label'    => '' === $label ? $language_id : $label,
		);
	}

	/**
	 * Returns one language record, looked up once per request.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>|null
	 */
	private function find_language( $language_id ) {
		if ( ! array_key_exists( $language_id, $this->languages ) ) {
			$this->languages[ $language_id ] = $this->language_manager->find( $language_id );
		}

		return $this->languages[ $language_id ];
	}

	/**
	 * Reports whether the language is worth naming at all.
	 *
	 * @return bool
	 */
	private function answering_languages() {
		if ( ! $this->language_manager->has_languages() ) {
			return false;
		}

		/**
		 * Filters whether the builder's dashboard names each template's language.
		 *
		 * Returning false leaves the builder's own screen exactly as it draws it.
		 *
		 * @param bool $answer Whether the language is added to the dashboard.
		 */
		return (bool) apply_filters( 'shapetranslate_jegkit_dashboard_languages', true );
	}
}
