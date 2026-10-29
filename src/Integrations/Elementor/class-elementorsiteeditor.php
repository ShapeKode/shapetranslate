<?php
/**
 * Elementor Theme Builder application integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Elementor;

use ShapeTranslate\Admin\TranslationActions;
use ShapeTranslate\Assets;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Names the language of every template on the Theme Builder screen.
 *
 * Elementor Pro's Theme Builder is a React application served from a document
 * of its own — it renders on `admin_init` and stops the request there, so none
 * of the hooks an administration screen normally offers ever run. Its template
 * cards take no extra columns, and the markup they leave behind names the
 * template only in the address of a link. So the language is written into each
 * card from the browser, after the application has drawn it.
 *
 * What makes that safe is where the answer comes from. Elementor hands every
 * card's data through a filter of its own before the application sees it, and
 * each template is handed back carrying the language it was written in and what
 * its translations are. The script therefore never guesses: it reads the same
 * list the cards were drawn from, and finds the card again by the identifier
 * its own links carry.
 *
 * Every step fails the same way, by leaving the screen exactly as Elementor drew
 * it, because all of this reaches into an application ShapeTranslate does not own.
 */
final class ElementorSiteEditor implements ModuleInterface {

	/**
	 * Handle shared by the script and the stylesheet.
	 */
	const ASSET_HANDLE = 'shapetranslate-elementor-site-editor';

	/**
	 * Key the language is handed back under, beside Elementor's own.
	 */
	const PAYLOAD_KEY = 'shapetranslate';

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var ElementorThemeBuilder
	 */
	private $theme_builder;

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
	 * Translated-copy URLs and capability decisions.
	 *
	 * @var TranslationActions
	 */
	private $actions;

	/**
	 * Flag lookup.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Language records already described, keyed by identifier.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $described = array();

	/**
	 * Constructor.
	 *
	 * @param ElementorThemeBuilder  $theme_builder     Theme Builder language resolution.
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param TranslationActions     $actions           Translation action helper.
	 */
	public function __construct(
		ElementorThemeBuilder $theme_builder,
		PostTranslationManager $post_translations,
		LanguageManager $language_manager,
		TranslationActions $actions
	) {
		$this->theme_builder     = $theme_builder;
		$this->post_translations = $post_translations;
		$this->language_manager  = $language_manager;
		$this->actions           = $actions;
		$this->flags             = new FlagRegistry();
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->theme_builder->is_available() ) {
			return;
		}

		add_filter( 'elementor-pro/site-editor/data/template', array( $this, 'answer_template_language' ) );

		/*
		 * Elementor's own hook, fired while the application is being prepared and
		 * before it enqueues anything of its own. The application prints its
		 * document and ends the request, so there is no `admin_enqueue_scripts`
		 * here to wait for: this is the last moment a stylesheet or a script can
		 * still join the ones it is about to print.
		 */
		add_action( 'elementor/app/init', array( $this, 'enqueue_app_assets' ) );
	}

	/**
	 * Hands one template back carrying the language it was written in.
	 *
	 * @param mixed $data Template data Elementor answers the application with.
	 * @return mixed
	 */
	public function answer_template_language( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['id'] ) ) {
			return $data;
		}

		$described = $this->describe_template( absint( $data['id'] ) );

		if ( array() === $described ) {
			return $data;
		}

		$data[ self::PAYLOAD_KEY ] = $described;

		return $data;
	}

	/**
	 * Loads what the Theme Builder screen needs to name its languages.
	 *
	 * @return void
	 */
	public function enqueue_app_assets() {
		if ( ! $this->translating_templates() ) {
			return;
		}

		wp_enqueue_style(
			self::ASSET_HANDLE,
			SHAPETRANSLATE_URL . 'assets/css/elementor-site-editor.css',
			array(),
			Assets::version( 'assets/css/elementor-site-editor.css' )
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			SHAPETRANSLATE_URL . 'assets/js/elementor-site-editor.js',
			array(),
			Assets::version( 'assets/js/elementor-site-editor.js' ),
			true
		);

		wp_localize_script(
			self::ASSET_HANDLE,
			'shapeTranslateElementorSiteEditor',
			array(
				'payloadKey'   => self::PAYLOAD_KEY,
				'templates'    => $this->describe_templates(),
				'labels'       => array(
					/* translators: %s: language name. */
					'language' => __( 'Language: %s', 'shapetranslate' ),
					/* translators: %s: language name. */
					'edit'     => __( 'Edit the %s translation', 'shapetranslate' ),
					/* translators: %s: language name. */
					'create'   => __( 'Add a %s translation', 'shapetranslate' ),
					'untitled' => __( 'No language', 'shapetranslate' ),
				),
			)
		);
	}

	/**
	 * Describes every Theme Builder template the screen can show.
	 *
	 * Sent with the screen rather than waited for, so a card drawn from data
	 * Elementor had already cached is named as quickly as one drawn from a
	 * request this answered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function describe_templates() {
		$described = array();

		$template_ids = get_posts(
			array(
				'post_type'                        => ElementorThemeBuilder::POST_TYPE,
				'post_status'                      => 'any',
				'posts_per_page'                   => 200,
				'fields'                           => 'ids',
				'orderby'                          => 'date',
				'order'                            => 'DESC',
				'no_found_rows'                    => true,
				'suppress_filters'                 => false,
				'shapetranslate_skip_language_filter' => true,
			)
		);

		if ( ! is_array( $template_ids ) ) {
			return $described;
		}

		$this->post_translations->prime_posts( array_map( 'absint', $template_ids ) );

		foreach ( $template_ids as $template_id ) {
			$template_id = absint( $template_id );
			$one         = $this->describe_template( $template_id );

			if ( array() !== $one ) {
				$described[ $template_id ] = $one;
			}
		}

		return $described;
	}

	/**
	 * Describes one template's language and where its translations are.
	 *
	 * @param int $template_id Template post identifier.
	 * @return array<string, mixed> Empty when the template carries no language.
	 */
	private function describe_template( $template_id ) {
		$template_id = absint( $template_id );
		$post        = $template_id > 0 ? get_post( $template_id ) : null;

		if (
			null === $post
			|| ElementorThemeBuilder::POST_TYPE !== $post->post_type
			|| ! $this->post_translations->supports_post_type( $post->post_type )
		) {
			return array();
		}

		$language_id  = $this->post_translations->get_post_language_id( $template_id );
		$translations = $this->post_translations->get_translations( $template_id );
		$may_create   = $this->actions->can_create_translation( $post );
		$languages    = array();

		foreach ( $this->language_manager->get_languages( true ) as $language ) {
			$id = isset( $language['id'] ) ? (string) $language['id'] : '';

			if ( '' === $id || $id === $language_id ) {
				continue;
			}

			$target = isset( $translations[ $id ] ) ? absint( $translations[ $id ] ) : 0;

			if ( 0 === $target && ! $may_create ) {
				continue;
			}

			$url = 0 < $target
				? get_edit_post_link( $target, 'raw' )
				: $this->plain_url( $this->actions->get_create_url( $template_id, $id ) );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$languages[] = array_merge(
				$this->describe_language( $id ),
				array(
					'exists' => 0 < $target,
					'url'    => $url,
				)
			);
		}

		return array(
			'id'           => $template_id,
			'language'     => '' === $language_id ? null : $this->describe_language( $language_id ),
			'translations' => $languages,
		);
	}

	/**
	 * Returns one address as an address rather than as markup.
	 *
	 * `wp_nonce_url()` hands back a string meant to be printed into an HTML
	 * attribute, so the separators between its arguments arrive written as
	 * `&amp;`. Everywhere else in ShapeTranslate that string is printed, and a
	 * browser reading an attribute turns the entities back into separators on
	 * the way out. This one is not printed: it travels as JSON and is assigned
	 * to a link from a script, where nothing decodes anything. Left alone, the
	 * request that follows carries one argument named `amp;_wpnonce` and no
	 * nonce at all, and WordPress answers it by saying the link has expired.
	 *
	 * @param mixed $url Address as `wp_nonce_url()` returned it.
	 * @return string Address with its separators intact.
	 */
	private function plain_url( $url ) {
		return is_string( $url ) ? html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * Describes one language the way a chip needs it.
	 *
	 * @param string $language_id Language identifier.
	 * @return array<string, mixed>
	 */
	private function describe_language( $language_id ) {
		$language_id = (string) $language_id;

		if ( isset( $this->described[ $language_id ] ) ) {
			return $this->described[ $language_id ];
		}

		$language = $this->language_manager->find( $language_id );
		$label    = $language_id;

		if ( is_array( $language ) ) {
			foreach ( array( 'native_name', 'name' ) as $field ) {
				if ( isset( $language[ $field ] ) && is_string( $language[ $field ] ) && '' !== $language[ $field ] ) {
					$label = $language[ $field ];
					break;
				}
			}
		}

		$this->described[ $language_id ] = array(
			'id'    => $language_id,
			'label' => $label,
			'flag'  => is_array( $language ) ? (string) $this->flags->get_flag_url( $language ) : '',
		);

		return $this->described[ $language_id ];
	}

	/**
	 * Reports whether Theme Builder templates are translated at all.
	 *
	 * The same answer the rest of the integration works from, asked again here
	 * so a site that keeps one set of headers for every language is not shown
	 * language controls it has turned off.
	 *
	 * @return bool
	 */
	private function translating_templates() {
		return (bool) apply_filters( 'shapetranslate_elementor_translate_templates', true )
			&& $this->post_translations->supports_post_type( ElementorThemeBuilder::POST_TYPE )
			&& $this->language_manager->has_languages();
	}
}
