<?php
/**
 * Classic language switcher widget.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Switcher;

use WP_Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Offers the switcher in a classic widget area.
 *
 * Widget areas became block canvases in WordPress 5.8, and the block covers
 * those. What it does not cover is a site running the Classic Widgets plugin,
 * or a theme that registers sidebars the block editor never reaches: there the
 * only way to place a switcher is to type a shortcode into a Text widget, which
 * hides every option behind attributes an editor has to look up. A widget puts
 * the same controls where the rest of the sidebar is arranged.
 */
final class LanguageSwitcherWidget extends WP_Widget {

	/**
	 * Widget identifier base.
	 *
	 * @var string
	 */
	const ID_BASE = 'shapetranslate_language_switcher';

	/**
	 * Language switcher service.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher $switcher Language switcher service.
	 */
	public function __construct( LanguageSwitcher $switcher ) {
		$this->switcher = $switcher;

		parent::__construct(
			self::ID_BASE,
			__( 'Language Switcher', 'shapetranslate' ),
			array(
				'classname'                   => 'widget_shapetranslate_switcher',
				'description'                 => __( 'Links to this page in the other languages of the site.', 'shapetranslate' ),
				'customize_selective_refresh' => true,
				'show_instance_in_rest'       => true,
			)
		);
	}

	/**
	 * Returns the settings a fresh widget starts with.
	 *
	 * The site's own switcher settings are the starting point, so a widget
	 * dragged into a sidebar looks like every other switcher until someone
	 * changes it here.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults() {
		$defaults = $this->switcher->normalize_args();

		return array(
			'title'                => '',
			'display'              => $defaults['display'],
			'layout'               => $defaults['layout'],
			'unavailable_behavior' => $defaults['unavailable_behavior'],
			'hide_current'         => $defaults['hide_current'],
			'show_flags'           => $defaults['show_flags'],
			'show_disabled'        => $defaults['show_disabled'],
		);
	}

	/**
	 * Renders the widget on the frontend.
	 *
	 * @param array<string, mixed> $args     Sidebar arguments.
	 * @param array<string, mixed> $instance Saved widget settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$instance = wp_parse_args( is_array( $instance ) ? $instance : array(), $this->defaults() );

		$html = $this->switcher->render(
			array(
				'display'              => $instance['display'],
				'layout'               => $instance['layout'],
				'unavailable_behavior' => $instance['unavailable_behavior'],
				'hide_current'         => ! empty( $instance['hide_current'] ),
				'show_flags'           => ! empty( $instance['show_flags'] ),
				'show_disabled'        => ! empty( $instance['show_disabled'] ),
				'context'              => 'widget',
			)
		);

		/*
		 * A site with nothing to switch between prints no wrapper and no title
		 * either. The sidebar arguments carry the theme's markup, so emitting
		 * them around an empty switcher would leave a heading over nothing.
		 */
		if ( '' === $html ) {
			return;
		}

		$args = is_array( $args ) ? $args : array();

		echo isset( $args['before_widget'] ) ? $args['before_widget'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );

		if ( '' !== (string) $title ) {
			echo isset( $args['before_title'] ) ? $args['before_title'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
			echo esc_html( $title );
			echo isset( $args['after_title'] ) ? $args['after_title'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by LanguageSwitcher::render().
		echo isset( $args['after_widget'] ) ? $args['after_widget'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup.
	}

	/**
	 * Sanitizes submitted widget settings.
	 *
	 * Only the title is validated here. Everything else is passed through the
	 * switcher's own normalization, which is what the shortcode, the block, and
	 * the settings screen are already held to, so a value this form cannot
	 * produce falls back to the site default rather than reaching a renderer.
	 *
	 * @param array<string, mixed> $new_instance Submitted settings.
	 * @param array<string, mixed> $old_instance Previously saved settings.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );

		$new_instance = is_array( $new_instance ) ? $new_instance : array();
		$normalized   = $this->switcher->normalize_args(
			array(
				'display'              => isset( $new_instance['display'] ) ? $new_instance['display'] : '',
				'layout'               => isset( $new_instance['layout'] ) ? $new_instance['layout'] : '',
				'unavailable_behavior' => isset( $new_instance['unavailable_behavior'] ) ? $new_instance['unavailable_behavior'] : '',
				'hide_current'         => ! empty( $new_instance['hide_current'] ),
				'show_flags'           => ! empty( $new_instance['show_flags'] ),
				'show_disabled'        => ! empty( $new_instance['show_disabled'] ),
			)
		);

		return array(
			'title'                => isset( $new_instance['title'] ) && is_scalar( $new_instance['title'] )
				? sanitize_text_field( $new_instance['title'] )
				: '',
			'display'              => $normalized['display'],
			'layout'               => $normalized['layout'],
			'unavailable_behavior' => $normalized['unavailable_behavior'],
			'hide_current'         => $normalized['hide_current'],
			'show_flags'           => $normalized['show_flags'],
			'show_disabled'        => $normalized['show_disabled'],
		);
	}

	/**
	 * Renders the widget settings form.
	 *
	 * @param array<string, mixed> $instance Saved widget settings.
	 * @return void
	 */
	public function form( $instance ) {
		$instance = wp_parse_args( is_array( $instance ) ? $instance : array(), $this->defaults() );

		$this->render_text_field( 'title', __( 'Title:', 'shapetranslate' ), (string) $instance['title'] );

		$this->render_select(
			'display',
			__( 'Show each language as:', 'shapetranslate' ),
			(string) $instance['display'],
			array(
				'native_name'   => __( 'Native name', 'shapetranslate' ),
				'name'          => __( 'Name', 'shapetranslate' ),
				'language_code' => __( 'Language code', 'shapetranslate' ),
				'flag'          => __( 'Flag only', 'shapetranslate' ),
			)
		);

		$this->render_select(
			'layout',
			__( 'Layout:', 'shapetranslate' ),
			(string) $instance['layout'],
			array(
				'horizontal' => __( 'Horizontal list', 'shapetranslate' ),
				'vertical'   => __( 'Vertical list', 'shapetranslate' ),
				'dropdown'   => __( 'Dropdown', 'shapetranslate' ),
			)
		);

		$this->render_select(
			'unavailable_behavior',
			__( 'Missing translation:', 'shapetranslate' ),
			(string) $instance['unavailable_behavior'],
			array(
				'hide'     => __( 'Hide languages the site has no content in', 'shapetranslate' ),
				'disabled' => __( 'Show as unavailable', 'shapetranslate' ),
				'home'     => __( 'Link to language homepage', 'shapetranslate' ),
				'current'  => __( 'Keep current URL', 'shapetranslate' ),
			)
		);

		$this->render_checkbox( 'hide_current', __( 'Hide current language', 'shapetranslate' ), ! empty( $instance['hide_current'] ) );
		$this->render_checkbox( 'show_flags', __( 'Show flags', 'shapetranslate' ), ! empty( $instance['show_flags'] ) );
		$this->render_checkbox( 'show_disabled', __( 'Show disabled languages as unavailable', 'shapetranslate' ), ! empty( $instance['show_disabled'] ) );
	}

	/**
	 * Renders a labelled text input.
	 *
	 * @param string $field Field name.
	 * @param string $label Field label.
	 * @param string $value Current value.
	 * @return void
	 */
	private function render_text_field( $field, $label, $value ) {
		printf(
			'<p><label for="%1$s">%2$s</label><input class="widefat" id="%1$s" name="%3$s" type="text" value="%4$s" /></p>',
			esc_attr( $this->get_field_id( $field ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $field ) ),
			esc_attr( $value )
		);
	}

	/**
	 * Renders a labelled select.
	 *
	 * @param string                $field   Field name.
	 * @param string                $label   Field label.
	 * @param string                $value   Current value.
	 * @param array<string, string> $choices Value to label map.
	 * @return void
	 */
	private function render_select( $field, $label, $value, array $choices ) {
		printf(
			'<p><label for="%1$s">%2$s</label><select class="widefat" id="%1$s" name="%3$s">',
			esc_attr( $this->get_field_id( $field ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $field ) )
		);

		foreach ( $choices as $choice => $choice_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $choice ),
				selected( $value, $choice, false ),
				esc_html( $choice_label )
			);
		}

		echo '</select></p>';
	}

	/**
	 * Renders a checkbox.
	 *
	 * @param string $field   Field name.
	 * @param string $label   Field label.
	 * @param bool   $checked Whether the box is ticked.
	 * @return void
	 */
	private function render_checkbox( $field, $label, $checked ) {
		printf(
			'<p><input class="checkbox" id="%1$s" name="%2$s" type="checkbox" value="1"%3$s /> <label for="%1$s">%4$s</label></p>',
			esc_attr( $this->get_field_id( $field ) ),
			esc_attr( $this->get_field_name( $field ) ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}
}
