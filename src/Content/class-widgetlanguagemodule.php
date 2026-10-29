<?php
/**
 * Per-language visibility for widgets.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Content;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Routing\LanguageUrlManager;
use WP_Widget;
use WP_Widget_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a widget be shown in one language only.
 *
 * A widget is shown in every language by default, its title and text
 * translated on the Strings screen. A site that would rather build a widget
 * per language — a block widget especially, whose whole markup is otherwise
 * one string to translate — picks the language a widget belongs to, and the
 * widget is left out of the pages of every other language. Polylang offers
 * the same choice on classic widgets (`pll_lang`).
 *
 * Classic widgets carry the choice in their instance settings, set from a
 * select added to their form. Blocks carry it as a block attribute, set from
 * the block settings panel of the widgets editor. A widget whose blocks all
 * belong to other languages is taken out of its sidebar, as a classic one is,
 * so neither leaves an empty wrapper or keeps `is_active_sidebar()` true.
 */
final class WidgetLanguageModule implements ModuleInterface {

	/**
	 * Classic widget setting holding the language.
	 *
	 * @var string
	 */
	const SETTING = 'shapetranslate_lang';

	/**
	 * Block attribute holding the language.
	 *
	 * @var string
	 */
	const ATTRIBUTE = 'shapetranslateLanguage';

	/**
	 * Blocks that never take the attribute: the classic widget wrapper has the
	 * classic select, and a widget area is the sidebar itself.
	 *
	 * @var array<int, string>
	 */
	const SKIPPED_BLOCKS = array( 'core/legacy-widget', 'core/widget-area' );

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Sidebars already filtered in this request, keyed by a hash of their widgets.
	 *
	 * @var array<string, array>
	 */
	private $filtered = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageUrlManager $url_manager Language URL service.
	 */
	public function __construct( LanguageUrlManager $url_manager ) {
		$this->url_manager = $url_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'in_widget_form', array( $this, 'render_widget_field' ), 10, 3 );
		add_filter( 'widget_update_callback', array( $this, 'save_widget_field' ), 10, 4 );
		add_filter( 'register_block_type_args', array( $this, 'add_block_attribute' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_control' ) );
		}

		add_filter( 'sidebars_widgets', array( $this, 'filter_sidebars' ) );
		add_filter( 'render_block', array( $this, 'hide_block' ), 10, 2 );
	}

	/**
	 * Prints the language select at the foot of a classic widget's form.
	 *
	 * @param mixed $widget   Widget.
	 * @param mixed $output   Whether the form has fields, passed by reference.
	 * @param mixed $instance Widget settings.
	 * @return void
	 */
	public function render_widget_field( $widget, $output, $instance ) {
		unset( $output );

		if ( ! $widget instanceof WP_Widget ) {
			return;
		}

		$languages = $this->url_manager->get_languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$current = is_array( $instance ) && ! empty( $instance[ self::SETTING ] ) ? (string) $instance[ self::SETTING ] : '';
		$id      = $widget->get_field_id( self::SETTING );

		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Show in language:', 'shapetranslate' ) . '</label> ';
		echo '<select class="widefat" id="' . esc_attr( $id ) . '" name="' . esc_attr( $widget->get_field_name( self::SETTING ) ) . '">';
		echo '<option value="">' . esc_html__( 'All languages', 'shapetranslate' ) . '</option>';

		foreach ( $languages as $language ) {
			if ( isset( $language['id'], $language['name'] ) ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $language['id'] ),
					selected( (string) $language['id'], $current, false ),
					esc_html( $language['name'] )
				);
			}
		}

		echo '</select></p>';
	}

	/**
	 * Stores a classic widget's language, or clears it for every language.
	 *
	 * @param mixed $instance     Settings about to be saved.
	 * @param mixed $new_instance Submitted settings.
	 * @param mixed $old_instance Previous settings.
	 * @param mixed $widget       Widget.
	 * @return mixed
	 */
	public function save_widget_field( $instance, $new_instance, $old_instance, $widget ) {
		unset( $old_instance, $widget );

		if ( ! is_array( $instance ) ) {
			return $instance;
		}

		$requested   = is_array( $new_instance ) && isset( $new_instance[ self::SETTING ] ) && is_scalar( $new_instance[ self::SETTING ] ) ? sanitize_text_field( (string) $new_instance[ self::SETTING ] ) : '';
		$language_id = '' === $requested ? '' : $this->url_manager->resolve_language_id( $requested );

		if ( '' === $language_id ) {
			unset( $instance[ self::SETTING ] );
		} else {
			$instance[ self::SETTING ] = $language_id;
		}

		return $instance;
	}

	/**
	 * Declares the language attribute on every block type.
	 *
	 * Declared on the server as well as in the editor, so a server-rendered
	 * block previewed in the editor is not refused for an attribute it does
	 * not know.
	 *
	 * @param mixed  $args       Block type arguments.
	 * @param string $block_name Block name.
	 * @return mixed
	 */
	public function add_block_attribute( $args, $block_name ) {
		if ( ! is_array( $args ) || in_array( $block_name, self::SKIPPED_BLOCKS, true ) ) {
			return $args;
		}

		$attributes = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();

		if ( ! isset( $attributes[ self::ATTRIBUTE ] ) ) {
			$attributes[ self::ATTRIBUTE ] = array(
				'type'    => 'string',
				'default' => '',
			);
		}

		$args['attributes'] = $attributes;

		return $args;
	}

	/**
	 * Adds the language select to the block settings panel of the widgets editors.
	 *
	 * @return void
	 */
	public function enqueue_block_control() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || ! in_array( $screen->id, array( 'widgets', 'customize' ), true ) ) {
			return;
		}

		$options = array(
			array(
				'label' => __( 'All languages', 'shapetranslate' ),
				'value' => '',
			),
		);

		foreach ( $this->url_manager->get_languages() as $language ) {
			if ( isset( $language['id'], $language['name'] ) ) {
				$options[] = array(
					'label' => (string) $language['name'],
					'value' => (string) $language['id'],
				);
			}
		}

		if ( count( $options ) < 3 ) {
			return;
		}

		$config = array(
			'attribute' => self::ATTRIBUTE,
			'skipped'   => self::SKIPPED_BLOCKS,
			'title'     => __( 'Language', 'shapetranslate' ),
			'label'     => __( 'Show in language', 'shapetranslate' ),
			'help'      => __( 'Pages in other languages leave this block out.', 'shapetranslate' ),
			'options'   => $options,
		);

		// In the head: the attribute filter has to be in place before the blocks register.
		wp_enqueue_script(
			'shapetranslate-widget-language',
			SHAPETRANSLATE_URL . 'assets/js/widget-language.js',
			array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-blocks' ),
			Assets::version( 'assets/js/widget-language.js' ),
			false
		);
		wp_add_inline_script( 'shapetranslate-widget-language', 'window.shapeTranslateWidgetLanguage = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Takes the widgets of other languages out of their sidebars.
	 *
	 * @param mixed $sidebars_widgets Widget IDs keyed by sidebar.
	 * @return mixed
	 */
	public function filter_sidebars( $sidebars_widgets ) {
		global $wp_registered_widgets;

		if ( ! is_array( $sidebars_widgets ) || empty( $wp_registered_widgets ) ) {
			return $sidebars_widgets;
		}

		$language_id = $this->page_language_id();

		if ( '' === $language_id ) {
			return $sidebars_widgets;
		}

		$key = md5( $language_id . wp_json_encode( $sidebars_widgets ) );

		if ( isset( $this->filtered[ $key ] ) ) {
			return $this->filtered[ $key ];
		}

		foreach ( $sidebars_widgets as $sidebar => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widget_ids ) ) {
				continue;
			}

			foreach ( $widget_ids as $i => $widget_id ) {
				$language = $this->widget_language_id( (string) $widget_id );

				if ( '' !== $language && $language !== $language_id ) {
					unset( $sidebars_widgets[ $sidebar ][ $i ] );
				}
			}

			$sidebars_widgets[ $sidebar ] = array_values( $sidebars_widgets[ $sidebar ] );
		}

		$this->filtered[ $key ] = $sidebars_widgets;

		return $sidebars_widgets;
	}

	/**
	 * Leaves a block of another language out of the page.
	 *
	 * @param mixed $content Rendered block.
	 * @param mixed $block   Parsed block.
	 * @return mixed
	 */
	public function hide_block( $content, $block ) {
		if ( ! is_array( $block ) || empty( $block['attrs'][ self::ATTRIBUTE ] ) || ! is_string( $block['attrs'][ self::ATTRIBUTE ] ) ) {
			return $content;
		}

		$language_id = $this->page_language_id();

		return '' === $language_id || $block['attrs'][ self::ATTRIBUTE ] === $language_id ? $content : '';
	}

	/**
	 * Returns the language a widget belongs to.
	 *
	 * A block widget belongs to a language when every block it holds does.
	 *
	 * @param string $widget_id Widget ID, such as `text-2`.
	 * @return string Empty when it belongs to every language.
	 */
	private function widget_language_id( $widget_id ) {
		global $wp_registered_widgets;

		if ( empty( $wp_registered_widgets[ $widget_id ]['callback'][0] ) || ! $wp_registered_widgets[ $widget_id ]['callback'][0] instanceof WP_Widget ) {
			return '';
		}

		$widget   = $wp_registered_widgets[ $widget_id ]['callback'][0];
		$number   = isset( $wp_registered_widgets[ $widget_id ]['params'][0]['number'] ) ? $wp_registered_widgets[ $widget_id ]['params'][0]['number'] : null;
		$settings = null === $number ? array() : $widget->get_settings();
		$instance = isset( $settings[ $number ] ) && is_array( $settings[ $number ] ) ? $settings[ $number ] : array();

		if ( ! empty( $instance[ self::SETTING ] ) && is_string( $instance[ self::SETTING ] ) ) {
			return $instance[ self::SETTING ];
		}

		if ( ! $widget instanceof WP_Widget_Block || empty( $instance['content'] ) || ! is_string( $instance['content'] ) ) {
			return '';
		}

		$languages = array();

		foreach ( parse_blocks( $instance['content'] ) as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$languages[] = ! empty( $block['attrs'][ self::ATTRIBUTE ] ) && is_string( $block['attrs'][ self::ATTRIBUTE ] ) ? $block['attrs'][ self::ATTRIBUTE ] : '';
		}

		$languages = array_unique( $languages );

		return 1 === count( $languages ) ? (string) reset( $languages ) : '';
	}

	/**
	 * Returns the language of the page being rendered.
	 *
	 * @return string Empty outside a rendered page, where every widget is kept.
	 */
	private function page_language_id() {
		if ( ! $this->url_manager->background()->scopes_rendered_page() ) {
			return '';
		}

		$language = $this->url_manager->get_current_language();

		return null === $language ? '' : (string) $language['id'];
	}
}
