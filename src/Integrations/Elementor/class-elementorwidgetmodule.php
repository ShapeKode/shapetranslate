<?php
/**
 * Elementor widget registration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Elementor;

use Elementor\Widgets_Manager;
use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the ShapeTranslate panel category and the widgets filed under it.
 *
 * Kept apart from ElementorModule, which copies a document when a translated
 * draft is created. That runs wherever translations are made, with or without
 * the editor open; this runs only where Elementor is drawing its panel.
 */
final class ElementorWidgetModule implements ModuleInterface {

	/**
	 * Panel category slug.
	 *
	 * Spelled out rather than read from the widget class. A constant borrowed
	 * from that class would be resolved as this file loads, which would pull in
	 * a class extending `Elementor\Widget_Base` on every request — including the
	 * ones where Elementor is not active and that parent does not exist.
	 */
	const CATEGORY = 'shapetranslate';

	/**
	 * Handle of the stylesheet loaded inside the Elementor editor.
	 */
	const EDITOR_STYLE_HANDLE = 'shapetranslate-elementor-editor';

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'enqueue_editor_styles' ) );
	}

	/**
	 * Loads the styles the widget placeholder needs.
	 *
	 * Enqueued on the editor's own hook, so the rule reaches the one place the
	 * placeholder is drawn and no visitor ever downloads it.
	 *
	 * @return void
	 */
	public function enqueue_editor_styles() {
		wp_enqueue_style(
			self::EDITOR_STYLE_HANDLE,
			SHAPETRANSLATE_URL . 'assets/css/elementor-editor.css',
			array(),
			Assets::version( 'assets/css/elementor-editor.css' )
		);
	}

	/**
	 * Adds the ShapeTranslate category to the Elementor panel.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'ShapeTranslate', 'shapetranslate' ),
				'icon'  => 'eicon-globe',
			)
		);
	}

	/**
	 * Registers the widgets ShapeTranslate contributes to the panel.
	 *
	 * @param Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		$widgets_manager->register( new LanguageSwitcherWidget() );
	}
}
