<?php
/**
 * Language switcher WordPress integrations.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Switcher;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Settings\PluginSettings;
use WP_Block_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shortcode, dynamic block, assets, and menu integration.
 */
final class SwitcherModule implements ModuleInterface {

	/**
	 * Switcher service.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Navigation menu integration.
	 *
	 * @var NavigationMenuIntegration
	 */
	private $navigation_menu;

	/**
	 * Navigation block switcher renderer.
	 *
	 * @var NavigationSwitcherBlock
	 */
	private $navigation_block;

	/**
	 * Floating switcher renderer.
	 *
	 * @var FloatingSwitcher
	 */
	private $floating_switcher;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager|null
	 */
	private $url_manager;

	/**
	 * Script handles the navigation block's editor preview is attached to.
	 *
	 * @var array<int, string>
	 */
	private $preview_handles = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher          $switcher         Switcher service.
	 * @param NavigationMenuIntegration $navigation_menu  Navigation menu integration.
	 * @param LanguageManager           $language_manager Language manager.
	 * @param PluginSettings|null       $settings         Optional central settings service.
	 * @param LanguageUrlManager|null   $url_manager      Optional language URL service.
	 */
	public function __construct(
		LanguageSwitcher $switcher,
		NavigationMenuIntegration $navigation_menu,
		LanguageManager $language_manager,
		?PluginSettings $settings = null,
		?LanguageUrlManager $url_manager = null
	) {
		$this->switcher          = $switcher;
		$this->navigation_menu   = $navigation_menu;
		$this->language_manager  = $language_manager;
		$this->url_manager       = $url_manager;
		$this->navigation_block  = new NavigationSwitcherBlock( $switcher );
		$this->floating_switcher = new FloatingSwitcher( $switcher, $settings, $url_manager );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_shortcode( 'shapetranslate_switcher', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_assets_and_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'add_preview_languages' ) );
		add_action( 'widgets_init', array( $this, 'register_classic_widget' ) );
		$this->navigation_menu->register();
		$this->floating_switcher->register();
	}

	/**
	 * Registers the classic widget.
	 *
	 * The instance is registered rather than the class name so the widget is
	 * handed the same switcher service every other placement uses; constructing
	 * it from a name would leave it building its own.
	 *
	 * @return void
	 */
	public function register_classic_widget() {
		if ( ! function_exists( 'register_widget' ) ) {
			return;
		}

		register_widget( new LanguageSwitcherWidget( $this->switcher ) );
	}

	/**
	 * Registers the minimal stylesheet and server-rendered Gutenberg block.
	 *
	 * @return void
	 */
	public function register_assets_and_block() {
		wp_register_style(
			'shapetranslate-switcher',
			SHAPETRANSLATE_URL . 'assets/css/switcher.css',
			array(),
			Assets::version( 'assets/css/switcher.css' )
		);

		/*
		 * Only the dropdown layout loads this, and only when one is rendered: it
		 * measures the room around an open panel, which a list layout has no use
		 * for. Nothing depends on it, so a page that never enqueues it keeps the
		 * behavior the stylesheet alone describes.
		 */
		wp_register_script(
			'shapetranslate-switcher',
			SHAPETRANSLATE_URL . 'assets/js/switcher.js',
			array(),
			Assets::version( 'assets/js/switcher.js' ),
			true
		);

		/*
		 * Only the floating switcher loads this, and it says nothing about how a
		 * switcher looks — only where that one is pinned. A site that placed its
		 * switcher itself never fetches it.
		 */
		wp_register_style(
			'shapetranslate-floating-switcher',
			SHAPETRANSLATE_URL . 'assets/css/floating-switcher.css',
			array( 'shapetranslate-switcher' ),
			Assets::version( 'assets/css/floating-switcher.css' )
		);

		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_style(
			'shapetranslate-navigation-switcher-editor',
			SHAPETRANSLATE_URL . 'assets/css/navigation-switcher-editor.css',
			array(),
			Assets::version( 'assets/css/navigation-switcher-editor.css' )
		);

		$this->prepare_block_type(
			register_block_type(
				SHAPETRANSLATE_PATH . 'blocks/language-switcher',
				array( 'render_callback' => array( $this, 'render_block' ) )
			),
			false
		);
		$this->prepare_block_type(
			register_block_type(
				SHAPETRANSLATE_PATH . 'blocks/navigation-language-switcher',
				array( 'render_callback' => array( $this->navigation_block, 'render' ) )
			),
			true
		);
	}

	/**
	 * Attaches translations, and language data, to a registered block script.
	 *
	 * @param WP_Block_Type|false|null $block_type    Registered block type.
	 * @param bool                     $needs_languages Whether the editor preview lists languages.
	 * @return void
	 */
	private function prepare_block_type( $block_type, $needs_languages ) {
		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			/*
			 * The editor controls are translated in JavaScript, so the generated
			 * block script handle needs its own JSON catalogue: the PHP text domain
			 * alone never reaches the inspector panel.
			 */
			wp_set_script_translations( $handle, 'shapetranslate', SHAPETRANSLATE_PATH . 'languages' );

			if ( $needs_languages ) {
				$this->preview_handles[] = $handle;
			}
		}
	}

	/**
	 * Hands the editor preview the languages it draws the switcher from.
	 *
	 * This waits for an editor screen rather than riding along with the block
	 * registration on `init`. Deciding whether a language has anything published
	 * in it is a database question, and asking it on every front end request to
	 * describe a panel nobody is looking at would be a page's worth of queries
	 * spent on nothing.
	 *
	 * @return void
	 */
	public function add_preview_languages() {
		if ( empty( $this->preview_handles ) ) {
			return;
		}

		$payload = 'window.shapeTranslateSwitcherLanguages = '
			. wp_json_encode( $this->get_editor_languages() ) . ';';

		foreach ( $this->preview_handles as $handle ) {
			wp_add_inline_script( $handle, $payload, 'before' );
		}
	}

	/**
	 * Returns the languages the editor preview renders, and what it judges them by.
	 *
	 * Every language is sent, disabled ones included, because the panel offers to
	 * show them and a preview cannot honor that from a list they were already
	 * filtered out of. Each one carries what a control here can ask about it: the
	 * flag it would show, whether the site publishes in it at all, and whether it
	 * is the language the switcher marks as current.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_editor_languages() {
		$languages  = array();
		$default_id = $this->language_manager->get_default_id();
		$flags      = new FlagRegistry();

		foreach ( $this->language_manager->get_languages( false ) as $language ) {
			$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

			if ( '' === $language_id ) {
				continue;
			}

			$languages[] = array(
				'id'            => $language_id,
				'name'          => isset( $language['name'] ) ? (string) $language['name'] : '',
				'native_name'   => isset( $language['native_name'] ) ? (string) $language['native_name'] : '',
				'language_code' => isset( $language['language_code'] ) ? (string) $language['language_code'] : '',
				'enabled'       => ! empty( $language['enabled'] ),
				'is_default'    => $language_id === $default_id,
				'flag_url'      => $flags->get_flag_url( $language ),
				/*
				 * The renderer asks this of the page being read. A template has no
				 * page, so the preview asks it of the site, which is the part of
				 * the same question a template can be judged by.
				 */
				'has_content'   => null === $this->url_manager
					? true
					: (bool) $this->url_manager->language_has_content( $language_id ),
			);
		}

		return $languages;
	}

	/**
	 * Renders the language switcher shortcode.
	 *
	 * @param array<string, mixed>|string $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $attributes = array() ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$defaults   = $this->switcher->normalize_args();

		/*
		 * `hide_missing` is the older spelling of `unavailable_behavior="hide"`,
		 * and the renderer lets it win so the two can never disagree. Inherited
		 * from the settings it would quietly overrule a behavior this shortcode
		 * spells out, leaving an author changing an attribute that has no effect,
		 * so a shortcode naming one and not the other is taken at its word.
		 */
		if ( isset( $attributes['unavailable_behavior'] ) && ! isset( $attributes['hide_missing'] ) ) {
			$defaults['hide_missing'] = false;
		}

		$attributes = shortcode_atts(
			array(
				'display'              => $defaults['display'],
				'layout'               => $defaults['layout'],
				'hide_current'         => $defaults['hide_current'],
				'hide_missing'         => $defaults['hide_missing'],
				'unavailable_behavior' => $defaults['unavailable_behavior'],
				'show_flags'           => $defaults['show_flags'],
				'show_disabled'        => $defaults['show_disabled'],
				'aria_label'           => '',
				'class'                => '',
			),
			$attributes,
			'shapetranslate_switcher'
		);

		return $this->switcher->render(
			array(
				'display'              => $attributes['display'],
				'layout'               => $attributes['layout'],
				'hide_current'         => $attributes['hide_current'],
				'hide_missing'         => $attributes['hide_missing'],
				'unavailable_behavior' => $attributes['unavailable_behavior'],
				'show_flags'           => $attributes['show_flags'],
				'show_disabled'        => $attributes['show_disabled'],
				'aria_label'           => $attributes['aria_label'],
				'class_name'           => $attributes['class'],
				'context'              => 'shortcode',
			)
		);
	}

	/**
	 * Renders the dynamic Gutenberg block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return $this->switcher->render(
			array(
				'display'              => isset( $attributes['display'] ) ? $attributes['display'] : 'native_name',
				'layout'               => isset( $attributes['layout'] ) ? $attributes['layout'] : 'horizontal',
				'hide_current'         => ! empty( $attributes['hideCurrent'] ),
				'hide_missing'         => ! empty( $attributes['hideMissing'] ),
				'unavailable_behavior' => isset( $attributes['unavailableBehavior'] )
					? $attributes['unavailableBehavior']
					: LanguageSwitcher::DEFAULT_ARGS['unavailable_behavior'],
				'show_flags'           => ! empty( $attributes['showFlags'] ),
				'show_disabled'        => ! empty( $attributes['showDisabled'] ),
				'aria_label'           => isset( $attributes['ariaLabel'] ) ? $attributes['ariaLabel'] : '',
				'class_name'           => isset( $attributes['className'] ) ? $attributes['className'] : '',
				'context'              => 'block',
			)
		);
	}
}
