<?php
/**
 * Language panel inside the WordPress Site Editor.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

use ShapeTranslate\Admin\AdminModule;
use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Navigation\NavigationMenus;

defined( 'ABSPATH' ) || exit;

/**
 * Says which language the open template is, and leads to the others.
 *
 * The Site Editor shows a template's name and nothing else about it, so a site
 * holding two headers shows two rows that read almost the same and gives an
 * editor no way to tell which one a reader in Bengali sees.
 *
 * This panel is the missing sentence, and it is the only place these are
 * managed. It names the language of what is on screen and lets that be changed,
 * it lists the languages beside it, and in each row offers the two things a
 * language can need: the version where it exists, and where it does not, either
 * starting one or adopting something the site already built by hand. Nothing
 * here leaves the Site Editor, because the work being done is editing.
 *
 * It serves whichever of the two translated types is open. They are managed the
 * same way and the panel is the same panel; only the words change.
 */
final class SiteEditorSidebar implements ModuleInterface {

	/**
	 * Editor script handle.
	 */
	const SCRIPT_HANDLE = 'shapetranslate-site-editor';

	/**
	 * Template part translation service.
	 *
	 * @var TemplateParts
	 */
	private $parts;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Panel description builder, shared with the route that serves it.
	 *
	 * @var SiteEditorPanel
	 */
	private $panel;

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
		$this->language_manager = $language_manager;
		$this->panel            = new SiteEditorPanel( $parts, $templates, $language_manager, $menus );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->parts->is_available() ) {
			return;
		}

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Loads the panel on the Site Editor only.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->parts->is_site_editor() || ! current_user_can( AdminModule::capability() ) ) {
			return;
		}

		if ( ! $this->language_manager->has_languages() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SHAPETRANSLATE_URL . 'assets/js/site-editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-i18n', 'wp-editor', 'wp-api-fetch', 'wp-data' ),
			Assets::version( 'assets/js/site-editor.js' ),
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'shapetranslate', SHAPETRANSLATE_PATH . 'languages' );

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.shapeTranslateSiteEditor = ' . wp_json_encode( $this->panel_data() ) . ';',
			'before'
		);

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			SHAPETRANSLATE_URL . 'assets/css/site-editor.css',
			array(),
			Assets::version( 'assets/css/site-editor.css' )
		);
	}

	/**
	 * Returns what the panel needs to describe what is open.
	 *
	 * Worked out from the address the editor loaded on, which is true for that
	 * first screen only: the Site Editor never reloads, so the panel asks the
	 * same builder again over REST every time it moves.
	 *
	 * @return array<string, mixed>
	 */
	private function panel_data() {
		return $this->panel->initial_data();
	}
}
