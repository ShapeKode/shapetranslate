<?php
/**
 * Per-language default term module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Taxonomy;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\CurrentLanguageResolver;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Settings\PluginSettings;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each language its own default category and default terms.
 *
 * WordPress stores one default term per taxonomy, so a post saved without a
 * category lands in that single term whatever language it was written in. The
 * module answers the option read with the term that belongs to the language the
 * post is being saved in: the one chosen for that language in ShapeTranslate >
 * Settings > Content, or, when nothing was chosen there, the translation of the
 * stored default. A site that translated Uncategorized therefore gets a working
 * per-language default without configuring anything, and a site that wants a
 * different term per language says so once.
 *
 * Two paths are covered because the editors differ. Classic saves, translated
 * copies, and front-end insertions carry their language before the term is
 * applied, so the option itself is filtered. The block editor assigns a new
 * post's language in the separate meta box request that follows its REST save,
 * so a post left holding only the site-wide default term is realigned once its
 * language is known.
 *
 * The translation a language falls back to is also created for it. Enabling a
 * language translates the default terms it has no copy of, so the fallback is
 * there before anything is written rather than only on a site that translated
 * Uncategorized by hand. Storing a new default term deliberately seeds nothing:
 * an administrator who points the option at a term is usually about to link the
 * translation they already have, and a copy created underneath them would
 * collide with it.
 */
final class DefaultTermModule implements ModuleInterface {

	/**
	 * Option holding the default category.
	 *
	 * @var string
	 */
	const CATEGORY_OPTION = 'default_category';

	/**
	 * Option recording the languages whose default terms have been translated.
	 *
	 * @var string
	 */
	const SEEDED_OPTION = 'shapetranslate_seeded_default_term_languages';

	/**
	 * Option prefix holding a custom taxonomy default term.
	 *
	 * @var string
	 */
	const TERM_OPTION_PREFIX = 'default_term_';

	/**
	 * Taxonomy whose default term lives in its own option.
	 *
	 * @var string
	 */
	const CATEGORY_TAXONOMY = 'category';

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Post translation relationship manager.
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
	 * Supported taxonomy policy.
	 *
	 * @var TaxonomySupport
	 */
	private $taxonomy_support;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private $current_language;

	/**
	 * Language identifier requested by the current REST call.
	 *
	 * @var string
	 */
	private $request_language_id = '';

	/**
	 * Whether the stored option value is being read.
	 *
	 * @var bool
	 */
	private $reading_option = false;

	/**
	 * Constructor.
	 *
	 * @param TermTranslationManager  $term_translations Term relationship manager.
	 * @param PostTranslationManager  $post_translations Post relationship manager.
	 * @param LanguageManager         $language_manager  Language manager.
	 * @param TaxonomySupport         $taxonomy_support  Supported taxonomy policy.
	 * @param CurrentLanguageResolver $current_language  Current language resolver.
	 * @param PluginSettings          $settings          Central plugin settings.
	 */
	public function __construct(
		TermTranslationManager $term_translations,
		PostTranslationManager $post_translations,
		LanguageManager $language_manager,
		TaxonomySupport $taxonomy_support,
		CurrentLanguageResolver $current_language,
		PluginSettings $settings
	) {
		$this->term_translations = $term_translations;
		$this->post_translations = $post_translations;
		$this->language_manager  = $language_manager;
		$this->taxonomy_support  = $taxonomy_support;
		$this->current_language  = $current_language;
		$this->settings          = $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_filter( 'pre_option_' . self::CATEGORY_OPTION, array( $this, 'filter_default_term' ), 10, 2 );

		// Custom taxonomy defaults are filtered once their taxonomies exist.
		add_action( 'wp_loaded', array( $this, 'register_taxonomy_filters' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'capture_request_language' ), 10, 3 );

		add_action( 'shapetranslate_language_registered', array( $this, 'seed_registered_language' ) );
		add_action( 'shapetranslate_language_updated', array( $this, 'seed_enabled_language' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'seed_existing_languages' ) );

		add_action( 'wp_after_insert_post', array( $this, 'realign_default_terms' ), 25, 2 );
	}

	/**
	 * Filters the default term option of every supported custom taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy_filters() {
		foreach ( $this->taxonomy_support->get_default_term_taxonomies() as $taxonomy ) {
			if ( self::CATEGORY_TAXONOMY === $taxonomy ) {
				continue;
			}

			add_filter(
				'pre_option_' . self::TERM_OPTION_PREFIX . $taxonomy,
				array( $this, 'filter_default_term' ),
				10,
				2
			);
		}
	}

	/**
	 * Translates the default terms into a newly registered language.
	 *
	 * @param array<string, mixed> $language Registered language.
	 * @return void
	 */
	public function seed_registered_language( $language ) {
		if ( ! is_array( $language ) || empty( $language['enabled'] ) ) {
			return;
		}

		$this->seed_language( isset( $language['id'] ) ? (string) $language['id'] : '' );
	}

	/**
	 * Translates the default terms into languages that predate the seeding.
	 *
	 * A language registered from now on is seeded as it is registered, but one
	 * that already existed never was: its posts fall back to another language's
	 * default term, which the editor cannot list and the author cannot uncheck.
	 * Each language is recorded once it has been handled, so this costs one
	 * option read on every later screen.
	 *
	 * @return void
	 */
	public function seed_existing_languages() {
		if ( ! $this->language_manager->has_languages() || ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$recorded = get_option( self::SEEDED_OPTION, array() );
		$recorded = is_array( $recorded ) ? array_map( 'strval', $recorded ) : array();
		$pending  = array();

		foreach ( $this->language_manager->get_languages( true ) as $language ) {
			if ( isset( $language['id'] ) && ! in_array( (string) $language['id'], $recorded, true ) ) {
				$pending[] = (string) $language['id'];
			}
		}

		if ( empty( $pending ) ) {
			return;
		}

		foreach ( $pending as $language_id ) {
			$this->seed_language( $language_id );
		}

		update_option(
			self::SEEDED_OPTION,
			array_values( array_unique( array_merge( $recorded, $pending ) ) ),
			false
		);
	}

	/**
	 * Translates the default terms into a language that has just been enabled.
	 *
	 * @param array<string, mixed> $language Updated language.
	 * @param array<string, mixed> $previous Language before the update.
	 * @return void
	 */
	public function seed_enabled_language( $language, $previous ) {
		if ( ! is_array( $language ) || empty( $language['enabled'] ) ) {
			return;
		}

		if ( is_array( $previous ) && ! empty( $previous['enabled'] ) ) {
			return;
		}

		$this->seed_language( isset( $language['id'] ) ? (string) $language['id'] : '' );
	}

	/**
	 * Stores the language requested by the current REST call.
	 *
	 * @param mixed  $result  Response to replace the requested version with.
	 * @param mixed  $server  REST server instance.
	 * @param object $request Dispatched request.
	 * @return mixed Untouched result.
	 */
	public function capture_request_language( $result, $server, $request ) {
		unset( $server );
		$this->request_language_id = '';

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return $result;
		}

		$requested = $request->get_param( 'lang' );

		if ( is_scalar( $requested ) && '' !== (string) $requested ) {
			$this->request_language_id = $this->resolve_requested_language( (string) $requested );
		}

		return $result;
	}

	/**
	 * Replaces a default term with its translation in the saving language.
	 *
	 * @param mixed  $pre_option Short-circuit value supplied by earlier filters.
	 * @param string $option     Option being read.
	 * @return mixed
	 */
	public function filter_default_term( $pre_option, $option ) {
		if ( false !== $pre_option || $this->reading_option ) {
			return $pre_option;
		}

		$option   = (string) $option;
		$taxonomy = $this->option_taxonomy( $option );

		if (
			'' === $taxonomy
			|| ! $this->language_manager->has_languages()
			|| ! $this->taxonomy_support->supports( $taxonomy )
		) {
			return $pre_option;
		}

		$language_id = $this->resolve_language_id( $taxonomy );

		if ( '' === $language_id ) {
			return $pre_option;
		}

		$stored = $this->get_stored_term_id( $option );

		if ( 1 > $stored ) {
			return $pre_option;
		}

		$term_id = $this->translate_term( $stored, $taxonomy, $language_id );

		return $term_id === $stored ? $pre_option : (string) $term_id;
	}

	/**
	 * Moves a post left in the site-wide default term to its own language.
	 *
	 * Only a post whose taxonomy holds exactly that one term is touched: any
	 * other assignment is an editorial choice and is never rewritten.
	 *
	 * @param int     $post_id Post identifier.
	 * @param WP_Post $post    Saved post.
	 * @return void
	 */
	public function realign_default_terms( $post_id, $post ) {
		if (
			! $post instanceof WP_Post
			|| 'auto-draft' === $post->post_status
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| ! $this->language_manager->has_languages()
		) {
			return;
		}

		$language_id = $this->post_translations->get_post_language_id( $post_id );

		if ( '' === $language_id ) {
			return;
		}

		$taxonomies = array_intersect(
			get_object_taxonomies( $post->post_type ),
			$this->taxonomy_support->get_default_term_taxonomies()
		);

		foreach ( $taxonomies as $taxonomy ) {
			$stored = $this->get_stored_term_id( $this->option_name( $taxonomy ) );

			if ( 1 > $stored ) {
				continue;
			}

			$assigned = wp_get_object_terms( absint( $post_id ), $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $assigned ) || array( $stored ) !== array_map( 'absint', (array) $assigned ) ) {
				continue;
			}

			$term_id = $this->translate_term( $stored, $taxonomy, $language_id );

			if ( $term_id === $stored ) {
				continue;
			}

			/**
			 * Filters whether a lone default term is moved into the post language.
			 *
			 * @param bool   $realign     Whether the assignment is rewritten.
			 * @param int    $post_id     Post identifier.
			 * @param string $taxonomy    Taxonomy name.
			 * @param string $language_id Post language identifier.
			 */
			$realign = apply_filters(
				'shapetranslate_realign_default_term',
				true,
				absint( $post_id ),
				$taxonomy,
				$language_id
			);

			if ( $realign ) {
				wp_set_object_terms( absint( $post_id ), array( $term_id ), $taxonomy );
			}
		}
	}

	/**
	 * Returns the translated default term for one language.
	 *
	 * A term chosen for the language wins. Without one, the translation of the
	 * stored default is used, and the stored default is kept when the language
	 * already owns it or has no translation of it, so a partly translated taxonomy
	 * never loses its default.
	 *
	 * @param int    $stored      Stored default term identifier.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $language_id Target language identifier.
	 * @return int
	 */
	private function translate_term( $stored, $taxonomy, $language_id ) {
		$term_id   = $stored;
		$candidate = $this->settings->get_default_term( $taxonomy, $language_id );

		if (
			1 > $candidate
			&& $this->term_translations->get_term_language_id( $stored, $taxonomy ) !== $language_id
		) {
			$candidate = absint( $this->term_translations->get_translation( $stored, $taxonomy, $language_id ) );
		}

		if ( 0 < $candidate ) {

			$term    = get_term( $candidate, $taxonomy );
			$term_id = $term instanceof WP_Term ? (int) $term->term_id : $stored;
		}

		/**
		 * Filters the default term used for one language.
		 *
		 * @param int    $term_id     Resolved default term identifier.
		 * @param string $taxonomy    Taxonomy name.
		 * @param string $language_id Target language identifier.
		 * @param int    $stored      Stored site-wide default term identifier.
		 */
		$filtered = absint(
			apply_filters( 'shapetranslate_default_term_id', $term_id, $taxonomy, $language_id, $stored )
		);

		return 1 > $filtered ? $stored : $filtered;
	}

	/**
	 * Translates every stored default term into one language.
	 *
	 * @param string $language_id Target language identifier.
	 * @return void
	 */
	private function seed_language( $language_id ) {
		if ( '' === $language_id ) {
			return;
		}

		foreach ( $this->taxonomy_support->get_default_term_taxonomies() as $taxonomy ) {
			$stored = $this->get_stored_term_id( $this->option_name( $taxonomy ) );

			if ( 0 < $stored ) {
				$this->seed_term( $stored, $taxonomy, $language_id );
			}
		}
	}

	/**
	 * Gives one language a translation of a default term when it has none.
	 *
	 * The stored default is what the language falls back to, so translating it
	 * once means a site gets a working per-language default without opening the
	 * settings screen. A language that already owns the default term, or already
	 * has a translation of it, is left alone.
	 *
	 * @param int    $stored      Stored default term identifier.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $language_id Target language identifier.
	 * @return void
	 */
	private function seed_term( $stored, $taxonomy, $language_id ) {
		/**
		 * Filters whether a default term is translated for a language automatically.
		 *
		 * @param bool   $seed        Whether the translation is created.
		 * @param int    $stored      Stored default term identifier.
		 * @param string $taxonomy    Taxonomy name.
		 * @param string $language_id Target language identifier.
		 */
		$seed = apply_filters( 'shapetranslate_seed_default_term', true, $stored, $taxonomy, $language_id );

		if ( ! $seed || ! get_term( $stored, $taxonomy ) instanceof WP_Term ) {
			return;
		}

		$source_language_id = $this->term_translations->get_term_language_id( $stored, $taxonomy );

		if ( '' === $source_language_id ) {
			$assignment = $this->term_translations->assign_default_language( $stored, $taxonomy );

			if ( is_wp_error( $assignment ) ) {
				return;
			}

			$source_language_id = isset( $assignment['language_id'] ) ? (string) $assignment['language_id'] : '';
		}

		if ( '' === $source_language_id || $source_language_id === $language_id ) {
			return;
		}

		if ( 0 < absint( $this->term_translations->get_translation( $stored, $taxonomy, $language_id ) ) ) {
			return;
		}

		$this->term_translations->create_translation( $stored, $taxonomy, $language_id );
	}

	/**
	 * Resolves the language the current save belongs to.
	 *
	 * @param string $taxonomy Taxonomy the default term is read for.
	 * @return string
	 */
	private function resolve_language_id( $taxonomy ) {
		$language_id = $this->post_translations->get_creating_language_id();

		if ( '' === $language_id ) {
			$language_id = $this->posted_language_id();
		}

		if ( '' === $language_id ) {
			$language_id = $this->request_language_id;
		}

		if ( '' === $language_id ) {
			$language_id = $this->edited_post_language_id();
		}

		/*
		 * A front-end insertion carries no editor fields, so it follows the
		 * language of the request it was made in. Administration and cron reads
		 * stay on the stored default until a save names its language, which keeps
		 * the writing settings screen showing the option that is stored.
		 */
		if ( '' === $language_id && ! is_admin() && ! wp_doing_cron() ) {
			$current     = $this->current_language->resolve();
			$language_id = isset( $current['id'] ) ? (string) $current['id'] : '';
		}

		/**
		 * Filters the language a default term is resolved for.
		 *
		 * @param string $language_id Resolved language identifier.
		 * @param string $taxonomy    Taxonomy name.
		 */
		$filtered = apply_filters( 'shapetranslate_default_term_language_id', $language_id, $taxonomy );

		return is_scalar( $filtered ) ? $this->validate_language_id( (string) $filtered ) : '';
	}

	/**
	 * Returns the language chosen in the classic editor meta box.
	 *
	 * @return string
	 */
	private function posted_language_id() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The meta box nonce is verified below.
		if ( ! isset( $_POST['shapetranslate_language_id'] ) || ! is_scalar( $_POST['shapetranslate_language_id'] ) ) {
			return '';
		}

		$post_id = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] )
			? absint( wp_unslash( $_POST['post_ID'] ) )
			: 0;
		$nonce   = isset( $_POST['shapetranslate_language_nonce'] ) && is_scalar( $_POST['shapetranslate_language_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['shapetranslate_language_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'shapetranslate_save_post_language_' . $post_id ) ) {
			return '';
		}

		$language_id = sanitize_text_field( wp_unslash( $_POST['shapetranslate_language_id'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->validate_language_id( $language_id );
	}

	/**
	 * Returns the stored language of the post the editor is submitting.
	 *
	 * Only a save WordPress itself has signed is trusted: the classic editor's
	 * `update-post_{ID}` nonce or the Quick Edit nonce, and only for a post the
	 * current user may edit.
	 *
	 * @return string
	 */
	private function edited_post_language_id() {
		if ( ! isset( $_POST['post_ID'] ) || ! is_scalar( $_POST['post_ID'] ) ) {
			return '';
		}

		$is_editor_save = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . absint( wp_unslash( $_POST['post_ID'] ) ) );
		$is_quick_edit  = isset( $_POST['_inline_edit'] ) && is_scalar( $_POST['_inline_edit'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' );

		if ( ! $is_editor_save && ! $is_quick_edit ) {
			return '';
		}

		$post_id = absint( wp_unslash( $_POST['post_ID'] ) );

		if ( 1 > $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		return $this->post_translations->get_post_language_id( $post_id );
	}

	/**
	 * Reads the stored option value without re-entering the filter.
	 *
	 * @param string $option Option name.
	 * @return int
	 */
	private function get_stored_term_id( $option ) {
		$this->reading_option = true;

		try {
			$stored = get_option( $option, 0 );
		} finally {
			$this->reading_option = false;
		}

		return is_scalar( $stored ) ? absint( $stored ) : 0;
	}

	/**
	 * Returns the option name holding a taxonomy default term.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function option_name( $taxonomy ) {
		return self::CATEGORY_TAXONOMY === $taxonomy
			? self::CATEGORY_OPTION
			: self::TERM_OPTION_PREFIX . $taxonomy;
	}

	/**
	 * Returns the taxonomy a default term option belongs to.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private function option_taxonomy( $option ) {
		if ( self::CATEGORY_OPTION === $option ) {
			return self::CATEGORY_TAXONOMY;
		}

		return 0 === strpos( $option, self::TERM_OPTION_PREFIX )
			? sanitize_key( substr( $option, strlen( self::TERM_OPTION_PREFIX ) ) )
			: '';
	}

	/**
	 * Resolves a requested language identifier or URL slug.
	 *
	 * @param string $requested Requested language identifier or URL slug.
	 * @return string
	 */
	private function resolve_requested_language( $requested ) {
		$language_id = $this->validate_language_id( $requested );

		if ( '' !== $language_id ) {
			return $language_id;
		}

		$slug = sanitize_title( $requested );

		foreach ( $this->language_manager->get_languages() as $candidate ) {
			if ( isset( $candidate['url_slug'] ) && $slug === (string) $candidate['url_slug'] ) {
				return (string) $candidate['id'];
			}
		}

		return '';
	}

	/**
	 * Keeps only an identifier that names a registered language.
	 *
	 * @param string $language_id Language identifier.
	 * @return string
	 */
	private function validate_language_id( $language_id ) {
		$language = '' === $language_id ? null : $this->language_manager->find( $language_id );

		return is_array( $language ) && isset( $language['id'] ) ? (string) $language['id'] : '';
	}
}
