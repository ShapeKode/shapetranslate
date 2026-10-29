<?php
/**
 * Renders a block theme's templates in the language being read.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the language to the hierarchy WordPress picks a template from.
 *
 * Translating the header covers the words a reader sees on every page, and stops
 * exactly where a language needs the page built differently: an archive that
 * reads right to left and drops a column, a single that puts the author last, a
 * 404 that says something else entirely. None of that is a part. It is the
 * template, and a template is not chosen by a slug anyone wrote down — it is
 * chosen by walking a hierarchy, most specific first, until something exists.
 *
 * So the language goes into the hierarchy. A Bengali page asks for
 * `page-about___bn`, then `page-about`, then `page___bn`, then `page`, and on
 * down: each rung the theme already had, with this language's version of it
 * offered first. WordPress then does what it always does and takes the first one
 * that exists, which makes the fallback free and total. A site that translates
 * one template gets that one template in Bengali and everything else exactly as
 * it was, and a site that translates none is walking the hierarchy it always
 * walked with a few more names in it.
 *
 * Nothing is written into a template to make this work, which is the point.
 * Templates are shared between languages until a site says otherwise, and a site
 * says otherwise by creating one — not by editing the ones it has.
 */
final class TemplateResolver implements ModuleInterface {

	/**
	 * The template types WordPress resolves through a hierarchy.
	 *
	 * The list get_query_template() is called with, which is fixed: every
	 * `is_*()` branch of the template loader names one of these. A type missing
	 * from here is not translated, it is simply resolved the way it always was.
	 *
	 * @var array<int, string>
	 */
	const TYPES = array(
		'index',
		'404',
		'archive',
		'author',
		'category',
		'tag',
		'taxonomy',
		'date',
		'embed',
		'home',
		'frontpage',
		'privacypolicy',
		'page',
		'search',
		'single',
		'singular',
		'attachment',
	);

	/**
	 * Template translation service.
	 *
	 * @var Templates
	 */
	private $templates;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Constructor.
	 *
	 * @param Templates          $templates   Template translation service.
	 * @param LanguageUrlManager $url_manager Language URL service.
	 */
	public function __construct( Templates $templates, LanguageUrlManager $url_manager ) {
		$this->templates   = $templates;
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() || ! $this->translating() ) {
			return;
		}

		add_filter( 'shapetranslate_non_public_post_types', array( $this, 'declare_post_type' ) );
		add_filter( 'shapetranslate_supported_post_types', array( $this, 'support_post_type' ) );
		add_filter( 'shapetranslate_settings', array( $this, 'enable_translation' ) );
		add_filter( 'shapetranslate_language_rest_routes', array( $this, 'keep_templates_unfiltered' ) );
		add_filter( 'shapetranslate_editor_language_id', array( $this, 'answer_editor_language' ), 10, 2 );

		add_action( 'pre_get_posts', array( $this, 'never_narrow_templates' ), 10000 );

		foreach ( self::TYPES as $type ) {
			add_filter( $type . '_template_hierarchy', array( $this, 'add_language_candidates' ) );
		}
	}

	/**
	 * Declares the template post type eligible for translation.
	 *
	 * @param mixed $post_types Post types ShapeTranslate may translate.
	 * @return mixed
	 */
	public function declare_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		$post_types[] = Templates::POST_TYPE;

		return $post_types;
	}

	/**
	 * Adds the template post type to the translatable list.
	 *
	 * @param mixed $post_types Supported post type names.
	 * @return mixed
	 */
	public function support_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		$post_types[] = Templates::POST_TYPE;

		return $post_types;
	}

	/**
	 * Selects templates in the translatable types a site has chosen.
	 *
	 * Offered on the settings screen like any other type, and a site that would
	 * rather keep one set of templates for every language unticks it there. It
	 * starts selected because until it is, nothing else here has anything to work
	 * with: templates carry no language, so there is nothing to resolve.
	 *
	 * @param mixed $settings Normalized ShapeTranslate configuration.
	 * @return mixed
	 */
	public function enable_translation( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$content    = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$post_types = isset( $content['post_types'] ) && is_array( $content['post_types'] ) ? $content['post_types'] : array();

		if ( in_array( Templates::POST_TYPE, $post_types, true ) ) {
			return $settings;
		}

		$post_types[]          = Templates::POST_TYPE;
		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Offers this language's version of every rung of the hierarchy.
	 *
	 * Inserted before the rung it translates rather than in front of the whole
	 * hierarchy, so specificity still wins: a site that has translated `page` but
	 * written a `page-about` for everyone gets `page-about`, because a template
	 * written for one page says more about that page than a language does.
	 *
	 * @param mixed $templates Template files in hierarchy order.
	 * @return mixed
	 */
	public function add_language_candidates( $templates ) {
		if ( ! is_array( $templates ) || empty( $templates ) ) {
			return $templates;
		}

		$language_id = $this->rendering_language_id();

		if ( '' === $language_id || $language_id === $this->templates->default_language_id() ) {
			return $templates;
		}

		$widened = array();

		foreach ( $templates as $template ) {
			if ( ! is_string( $template ) || '' === $template ) {
				continue;
			}

			$translated = $this->in_language( $template, $language_id );

			if ( '' !== $translated ) {
				$widened[] = $translated;
			}

			$widened[] = $template;
		}

		/**
		 * Filters the template files one request may be answered with.
		 *
		 * @param array<int, string> $widened     Files in preference order.
		 * @param array<int, string> $templates   Files WordPress asked for.
		 * @param string             $language_id Language being rendered.
		 */
		$widened = apply_filters( 'shapetranslate_template_hierarchy', $widened, $templates, $language_id );

		return is_array( $widened ) && ! empty( $widened ) ? array_values( array_unique( $widened ) ) : $templates;
	}

	/**
	 * Keeps every template query whole.
	 *
	 * Templates are chosen by name, and the name already carries the language, so
	 * a constraint on top of it could only remove the row the hierarchy was about
	 * to fall back to. That is a page with no template rather than a page in the
	 * wrong language, which is worse.
	 *
	 * @param mixed $query Query about to run.
	 * @return void
	 */
	public function never_narrow_templates( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) && 1 === count( $post_type ) ) {
			$post_type = reset( $post_type );
		}

		if ( Templates::POST_TYPE === $post_type ) {
			$query->set( 'shapetranslate_skip_language_filter', true );
		}
	}

	/**
	 * Keeps the Site Editor's own template listing whole.
	 *
	 * @param mixed $routes REST routes the editor tags with a language.
	 * @return mixed
	 */
	public function keep_templates_unfiltered( $routes ) {
		if ( ! is_array( $routes ) ) {
			return $routes;
		}

		$object = get_post_type_object( Templates::POST_TYPE );
		$base   = is_object( $object ) && ! empty( $object->rest_base )
			? (string) $object->rest_base
			: Templates::POST_TYPE;

		return array_values( array_diff( $routes, array( 'wp/v2/' . $base ) ) );
	}

	/**
	 * Tells the editor which language the open template belongs to.
	 *
	 * @param mixed $language_id Language the editor reported so far.
	 * @param mixed $context     Block editor context, where one exists.
	 * @return mixed
	 */
	public function answer_editor_language( $language_id, $context = null ) {
		unset( $context );

		if ( is_string( $language_id ) && '' !== $language_id ) {
			return $language_id;
		}

		$slug = $this->templates->get_edited_slug();

		return '' === $slug ? $language_id : $this->templates->get_language_id( $slug );
	}

	/**
	 * Returns one hierarchy entry as it would be named in a language.
	 *
	 * The entries arrive as file names — `page-about.php` — and the suffix has to
	 * end up on the template's name rather than after its extension, or nothing
	 * will match it.
	 *
	 * @param string $template    Hierarchy entry.
	 * @param string $language_id Language identifier.
	 * @return string Empty when the entry already names a language.
	 */
	private function in_language( $template, $language_id ) {
		$extension = '';
		$name      = $template;
		$dot       = strrpos( $template, '.' );

		if ( false !== $dot ) {
			$candidate = substr( $template, $dot + 1 );

			if ( 'php' === $candidate || 'html' === $candidate ) {
				$name      = substr( $template, 0, $dot );
				$extension = substr( $template, $dot );
			}
		}

		$translated = $this->templates->slug_in_language( $name, $language_id );

		if ( '' === $translated || $translated === $name ) {
			return '';
		}

		return $translated . $extension;
	}

	/**
	 * Returns the language a template is being resolved for.
	 *
	 * The Site Editor renders templates to edit them, and an editor who opened
	 * `page` has to be shown `page`; the editor says which language it is working
	 * in through its own address, not through the language being read.
	 *
	 * @return string
	 */
	private function rendering_language_id() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}

		$language = $this->url_manager->get_current_language();

		/**
		 * Filters the language a template is rendered for.
		 *
		 * @param string $language_id Language identifier, or an empty string.
		 */
		$filtered = apply_filters(
			'shapetranslate_template_language',
			null === $language ? '' : (string) $language['id']
		);

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Reports whether full templates are translated on this site.
	 *
	 * @return bool
	 */
	private function translating() {
		/**
		 * Filters whether block theme templates are translated.
		 *
		 * Returning false leaves the post type untranslatable, so a site keeps one
		 * set of templates for every language and translates parts only.
		 *
		 * @param bool $translate Whether templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_translate_templates', true );
	}
}
