<?php
/**
 * Jeg Kit for Elementor header and footer integration module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\JegKit;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Jeg Kit headers and footers across languages.
 *
 * Everything the builder does with a location goes through one question, asked
 * once per candidate template: does this template claim the page being
 * rendered? It asks it while choosing what to print, and again while telling
 * Elementor which documents to write styles for, and it stops at the first
 * template that says yes. That question is filterable, so it is the only place
 * this module has to speak — answer it in the language being read and the
 * printing and the styles follow on their own.
 *
 * Answering one template at a time is not enough, though, because the answer
 * depends on the other candidates: a Bengali header should win only if there is
 * one, and the English header should keep its place when there is not. So the
 * first time the builder asks about a location, the whole walk is done here —
 * every candidate is asked the unfiltered question, and the winner is chosen
 * from those that claimed the page — and each of the builder's own questions is
 * then answered by whether it names the winner.
 *
 * The candidate list is left unnarrowed for the same reason. Narrowing it would
 * answer a language whose header nobody has translated yet with no header at
 * all, where leaving it whole answers with the one the site already had.
 */
final class JegKitModule implements ModuleInterface {

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
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Templates chosen per location, keyed by post type, page and language.
	 *
	 * @var array<string, int>
	 */
	private $resolved = array();

	/**
	 * Whether a location is being walked right now.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * Constructor.
	 *
	 * @param JegKitTemplates        $templates         Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageUrlManager     $url_manager       Language URL service.
	 */
	public function __construct(
		JegKitTemplates $templates,
		PostTranslationManager $post_translations,
		LanguageUrlManager $url_manager
	) {
		$this->templates         = $templates;
		$this->post_translations = $post_translations;
		$this->url_manager       = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() ) {
			return;
		}

		add_filter( 'register_post_type_args', array( $this, 'expose_template_ui' ), 10, 2 );
		add_filter( 'shapetranslate_settings', array( $this, 'enable_template_translation' ) );
		add_filter( 'shapetranslate_filter_secondary_query_by_language', array( $this, 'skip_template_query' ), 10, 2 );

		add_filter( 'shapetranslate_translation_copy_post_data', array( $this, 'name_translated_template' ), 10, 3 );
		add_filter( 'shapetranslate_elementor_copy_meta_keys', array( $this, 'add_copy_meta_keys' ), 10, 2 );
		add_filter( 'shapetranslate_elementor_copy_meta_value', array( $this, 'translate_copied_conditions' ), 10, 4 );
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_template_meta' ), 20, 2 );

		add_filter( 'jkit_check_template_conditions', array( $this, 'filter_template_conditions' ), 10, 4 );
	}

	/**
	 * Gives the template post types the administrative UI they are missing.
	 *
	 * The builder registers them public but without one, because it manages them
	 * from a screen of its own and nothing needs a post list. ShapeTranslate will not
	 * translate a post type that has no administrative UI, and for good reason:
	 * a translation nobody can open is a translation nobody can write. So the UI
	 * is given rather than the requirement waived — the templates gain a post
	 * list carrying the language column and the translation controls every other
	 * post type has, and gain nothing else. They stay out of the menus, so the
	 * builder's own screen remains the way into them and the admin sidebar looks
	 * exactly as it did.
	 *
	 * @param mixed $args      Arguments the post type is being registered with.
	 * @param mixed $post_type Post type being registered.
	 * @return mixed
	 */
	public function expose_template_ui( $args, $post_type ) {
		if (
			! is_array( $args )
			|| ! $this->templates->is_template_post_type( $post_type )
			|| ! $this->translating_templates()
		) {
			return $args;
		}

		/**
		 * Filters whether Jeg Kit templates are given an administrative UI.
		 *
		 * Returning false leaves the post types registered exactly as the builder
		 * registered them, which also leaves them untranslatable — for a site that
		 * would rather reach its translations some other way.
		 *
		 * @param bool   $expose    Whether the post type gains an administrative UI.
		 * @param string $post_type Post type being registered.
		 */
		if ( ! apply_filters( 'shapetranslate_jegkit_expose_templates', true, (string) $post_type ) ) {
			return $args;
		}

		$args['show_ui']           = true;
		$args['show_in_menu']      = false;
		$args['show_in_admin_bar'] = false;

		return $args;
	}

	/**
	 * Selects the template post types alongside the site's own content.
	 *
	 * They are selected here rather than left for a site owner to tick, because
	 * until they are ticked the rest of this module has nothing to work with:
	 * templates carry no language, so there is nothing to resolve and nothing to
	 * copy. The checkboxes therefore read as selected and stay that way; a site
	 * that would rather keep one header and footer for every language says so
	 * through the filter below, which leaves the rest of the module inert.
	 *
	 * @param mixed $settings Normalized ShapeTranslate configuration.
	 * @return mixed
	 */
	public function enable_template_translation( $settings ) {
		if ( ! is_array( $settings ) || ! $this->translating_templates() ) {
			return $settings;
		}

		$content    = isset( $settings['content'] ) && is_array( $settings['content'] ) ? $settings['content'] : array();
		$post_types = isset( $content['post_types'] ) && is_array( $content['post_types'] ) ? $content['post_types'] : array();
		$changed    = false;

		foreach ( JegKitTemplates::post_types() as $post_type ) {
			if ( ! in_array( $post_type, $post_types, true ) ) {
				$post_types[] = $post_type;
				$changed      = true;
			}
		}

		if ( ! $changed ) {
			return $settings;
		}

		$content['post_types'] = $post_types;
		$settings['content']   = $content;

		return $settings;
	}

	/**
	 * Leaves the builder's own template lookup unnarrowed.
	 *
	 * Both the builder's walk and this module's own read the same list, and it
	 * has to hold every language: the walk is what a location falls back to when
	 * this language has no template of its own, and narrowing it would answer an
	 * untranslated language with no header rather than with the site's.
	 *
	 * @param mixed    $filter Whether language filtering should run.
	 * @param WP_Query $query  Secondary frontend query.
	 * @return mixed
	 */
	public function skip_template_query( $filter, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $filter;
		}

		$post_types = $query->get( 'post_type' );
		$post_types = array_values( array_unique( array_filter( (array) $post_types, 'is_string' ) ) );

		if ( empty( $post_types ) ) {
			return $filter;
		}

		$others = array_diff( $post_types, JegKitTemplates::post_types() );

		return empty( $others ) ? false : $filter;
	}

	/**
	 * Names a translated template after the language it was made for.
	 *
	 * A translation is otherwise given its source's title exactly, which is right
	 * for a page — a reader never sees two of them at once — and wrong for a
	 * template, because the builder's own screen lists every language together
	 * and identifies each row by its name alone. Two rows both reading "Header
	 * Title" is a list nobody can act on, and that screen has no column this
	 * module could add a language to. So the language goes where the builder
	 * already looks: into the name. It is a real title, stored once when the
	 * translation is made, which leaves it free to be renamed afterwards and
	 * leaves every screen that reads it — the builder's, the post list, the
	 * translation dashboard — agreeing about what it says.
	 *
	 * @param mixed $post_data New draft fields.
	 * @param mixed $source    Source post.
	 * @param mixed $language  Target language record.
	 * @return mixed
	 */
	public function name_translated_template( $post_data, $source, $language ) {
		if (
			! is_array( $post_data )
			|| ! $source instanceof WP_Post
			|| ! is_array( $language )
			|| ! $this->templates->is_template_post_type( $source->post_type )
			|| ! $this->translating_templates()
		) {
			return $post_data;
		}

		$label = $this->language_label( $language );
		$title = isset( $post_data['post_title'] ) ? (string) $post_data['post_title'] : '';

		if ( '' === $label ) {
			return $post_data;
		}

		$named = '' === $title
			? $label
			/* translators: 1: template name, 2: language name. */
			: sprintf( _x( '%1$s (%2$s)', 'translated template name', 'shapetranslate' ), $title, $label );

		/**
		 * Filters the name a translated Jeg Kit template is created under.
		 *
		 * Returning the title unchanged keeps the source's name, for a site that
		 * would rather tell its templates apart some other way.
		 *
		 * @param string               $named    Name the translation is created under.
		 * @param string               $title    Name the source carries.
		 * @param array<string, mixed> $language Target language record.
		 * @param WP_Post              $source   Source template.
		 */
		$named = apply_filters( 'shapetranslate_jegkit_template_title', $named, $title, $language, $source );

		if ( is_string( $named ) && '' !== $named ) {
			$post_data['post_title'] = $named;
		}

		return $post_data;
	}

	/**
	 * Returns what a language is called, in its own words where it has them.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	private function language_label( array $language ) {
		foreach ( array( 'native_name', 'name', 'id' ) as $field ) {
			if ( isset( $language[ $field ] ) && is_string( $language[ $field ] ) && '' !== $language[ $field ] ) {
				return $language[ $field ];
			}
		}

		return '';
	}

	/**
	 * Adds the builder metadata a translated template needs.
	 *
	 * @param mixed $meta_keys      Elementor metadata keys about to be copied.
	 * @param int   $source_post_id Source post identifier.
	 * @return mixed
	 */
	public function add_copy_meta_keys( $meta_keys, $source_post_id ) {
		if ( ! is_array( $meta_keys ) || ! $this->is_template( $source_post_id ) ) {
			return $meta_keys;
		}

		return array_merge( $meta_keys, JegKitTemplates::meta_keys() );
	}

	/**
	 * Points copied conditions at the target language's own content.
	 *
	 * @param mixed  $meta_value     Metadata value about to be copied.
	 * @param string $meta_key       Metadata key.
	 * @param int    $source_post_id Source post identifier.
	 * @param int    $target_post_id Target post identifier.
	 * @return mixed
	 */
	public function translate_copied_conditions( $meta_value, $meta_key, $source_post_id, $target_post_id ) {
		if ( JegKitTemplates::CONDITION_META_KEY !== $meta_key || ! $this->is_template( $source_post_id ) ) {
			return $meta_value;
		}

		$target_post_id = absint( $target_post_id );
		$language_id    = $this->post_translations->get_post_language_id( $target_post_id );

		if ( '' === $language_id || ! $this->translating_conditions( $target_post_id, $language_id ) ) {
			return $meta_value;
		}

		return $this->templates->translate_conditions( $meta_value, $language_id );
	}

	/**
	 * Copies builder metadata a translation did not receive from Elementor.
	 *
	 * The Elementor copy runs only for a post that already holds element data, so
	 * a template translated before anyone opened it in the editor would arrive
	 * with no conditions at all — and a Jeg Kit template with no conditions
	 * claims nothing, so the location would quietly fall back to the source
	 * language. Keys already written are left alone, which is what makes this
	 * safe to run after that copy rather than instead of it.
	 *
	 * @param int $target_post_id Target translated post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	public function copy_template_meta( $target_post_id, $source_post_id ) {
		$target_post_id = absint( $target_post_id );
		$source_post_id = absint( $source_post_id );

		if ( ! $this->is_template( $source_post_id ) || 0 === $target_post_id ) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $target_post_id );
		$translate   = '' !== $language_id && $this->translating_conditions( $target_post_id, $language_id );

		foreach ( JegKitTemplates::meta_keys() as $meta_key ) {
			if (
				metadata_exists( 'post', $target_post_id, $meta_key )
				|| ! metadata_exists( 'post', $source_post_id, $meta_key )
			) {
				continue;
			}

			$meta_value = get_post_meta( $source_post_id, $meta_key, true );

			if ( $translate && JegKitTemplates::CONDITION_META_KEY === $meta_key ) {
				$meta_value = $this->templates->translate_conditions( $meta_value, $language_id );
			}

			update_post_meta( $target_post_id, $meta_key, $meta_value );
		}
	}

	/**
	 * Answers whether one template claims the page, in the language being read.
	 *
	 * @param mixed $flag        Whether the builder's own conditions matched.
	 * @param mixed $post_id     Post the request resolved to.
	 * @param mixed $template_id Template being asked about.
	 * @param mixed $conditions  Conditions the template carries.
	 * @return mixed
	 */
	public function filter_template_conditions( $flag, $post_id, $template_id, $conditions ) {
		if ( $this->resolving || ! $this->resolving_for_visitor() || ! $this->translating_templates() ) {
			return $flag;
		}

		$template_id = absint( $template_id );
		$post_type   = 0 === $template_id ? '' : get_post_type( $template_id );

		if ( ! $this->templates->is_template_post_type( $post_type ) || ! $this->templates->can_resolve() ) {
			return $flag;
		}

		$language_id = $this->current_language_id();

		if ( '' === $language_id ) {
			return $flag;
		}

		$post_id  = absint( $post_id );
		$resolved = $this->resolve_location( $post_type, $post_id, $language_id );

		/**
		 * Filters the template one Jeg Kit location renders.
		 *
		 * @param int    $resolved_id Template identifier after language resolution.
		 * @param string $post_type   Location being filled: the header or footer post type.
		 * @param int    $post_id     Post the request resolved to.
		 * @param string $language_id Language being rendered.
		 */
		$resolved = absint(
			apply_filters(
				'shapetranslate_jegkit_template_id',
				$resolved,
				$post_type,
				$post_id,
				$language_id
			)
		);

		// No candidate claimed the page. The builder's own answer already says so
		// for every template, and saying it again here would only risk disagreeing.
		if ( 0 === $resolved ) {
			return $flag;
		}

		return $template_id === $resolved;
	}

	/**
	 * Chooses the template one location renders in the language being read.
	 *
	 * The candidates are the ones the builder itself would have accepted, asked
	 * in its own order, and the choice among them runs through three questions.
	 * A template written in this language wins outright. Failing that, one
	 * carrying no language at all wins, because a site that never translated its
	 * header meant it for everyone. Failing both, the builder's own winner is
	 * kept — but handed over as its translation when it has a published one,
	 * which is what covers a translated header whose conditions name a page this
	 * language does not have.
	 *
	 * @param string $post_type   Template post type.
	 * @param int    $post_id     Post the request resolved to.
	 * @param string $language_id Language being rendered.
	 * @return int Zero when no template claims the page.
	 */
	private function resolve_location( $post_type, $post_id, $language_id ) {
		$cache_key = $post_type . ':' . $post_id . ':' . $language_id;

		if ( isset( $this->resolved[ $cache_key ] ) ) {
			return $this->resolved[ $cache_key ];
		}

		$matched = $this->get_matching_templates( $post_type, $post_id );
		$chosen  = 0;

		foreach ( $matched as $template_id ) {
			if ( $language_id === $this->templates->get_template_language_id( $template_id ) ) {
				$chosen = $template_id;
				break;
			}
		}

		if ( 0 === $chosen ) {
			foreach ( $matched as $template_id ) {
				if ( '' === $this->templates->get_template_language_id( $template_id ) ) {
					$chosen = $template_id;
					break;
				}
			}
		}

		if ( 0 === $chosen && ! empty( $matched ) ) {
			$chosen = $matched[0];

			foreach ( $matched as $template_id ) {
				$translated = absint( $this->templates->translate_template_id( $template_id, $language_id ) );

				if ( 0 < $translated ) {
					$chosen = $translated;
					break;
				}
			}
		}

		$this->resolved[ $cache_key ] = $chosen;

		return $chosen;
	}

	/**
	 * Returns the templates of one location that claim the page, in order.
	 *
	 * The builder is asked, rather than the conditions read here, so that a rule
	 * means on this walk exactly what it means on the builder's own. Its answer
	 * passes back through this module's filter, which is what the guard is for:
	 * while the walk is running the question is left to the builder alone.
	 *
	 * @param string $post_type Template post type.
	 * @param int    $post_id   Post the request resolved to.
	 * @return array<int, int> Template identifiers.
	 */
	private function get_matching_templates( $post_type, $post_id ) {
		$matched = array();

		$this->resolving = true;

		foreach ( $this->templates->get_templates( $post_type ) as $template_id ) {
			if ( $this->templates->conditions_match( $post_id, $template_id ) ) {
				$matched[] = $template_id;
			}
		}

		$this->resolving = false;

		return $matched;
	}

	/**
	 * Reports whether a post is a builder template.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	private function is_template( $post_id ) {
		return $this->templates->is_template_post_type( get_post_type( absint( $post_id ) ) );
	}

	/**
	 * Reports whether builder templates are translated at all.
	 *
	 * @return bool
	 */
	private function translating_templates() {
		/**
		 * Filters whether Jeg Kit templates are translated.
		 *
		 * Returning false leaves the post types as the builder registered them and
		 * the settings screen configured them, so a site can keep one header and
		 * footer for every language.
		 *
		 * @param bool $translate Whether Jeg Kit templates are translatable.
		 */
		return (bool) apply_filters( 'shapetranslate_jegkit_translate_templates', true );
	}

	/**
	 * Reports whether copied conditions are rewritten for the target language.
	 *
	 * @param int    $target_post_id Target post identifier.
	 * @param string $language_id    Target language identifier.
	 * @return bool
	 */
	private function translating_conditions( $target_post_id, $language_id ) {
		/**
		 * Filters whether copied display conditions are rewritten for the target.
		 *
		 * Returning false copies the conditions verbatim, which is what a site
		 * wants when a translated template should keep claiming exactly the pages
		 * the source named.
		 *
		 * @param bool   $translate      Whether condition identifiers are rewritten.
		 * @param int    $target_post_id Target post identifier.
		 * @param string $language_id    Target language identifier.
		 */
		return (bool) apply_filters(
			'shapetranslate_jegkit_translate_conditions',
			true,
			absint( $target_post_id ),
			(string) $language_id
		);
	}

	/**
	 * Reports whether a location is being resolved for someone reading the site.
	 *
	 * The builder's own screens ask the same question to describe a template, and
	 * an editor asking which pages a header claims must be answered with what
	 * they saved, not with what another language makes of it.
	 *
	 * @return bool
	 */
	private function resolving_for_visitor() {
		return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
	}

	/**
	 * Returns the language identifier of the current request.
	 *
	 * @return string
	 */
	private function current_language_id() {
		$language = $this->url_manager->get_current_language();

		return null === $language ? '' : (string) $language['id'];
	}
}
