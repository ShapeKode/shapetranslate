<?php
/**
 * Essential Addons Theme Builder administration integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\EssentialAddons;

use ShapeTranslate\Admin\TranslationActions;
use ShapeTranslate\Admin\TranslationListTable;
use ShapeTranslate\Assets;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;
use ReflectionException;
use ReflectionProperty;
use WP_List_Table;
use WP_Post;
use WP_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the Theme Builder dashboard the Language and Translations columns.
 *
 * The builder replaces the post type's own list screen with a dashboard of its
 * own — `edit.php?post_type=ea_theme_builder` redirects there — and the table on
 * it is a WP_List_Table the builder wrote. Neither half of what the post list
 * columns need is reachable from outside it. The table names its columns by
 * assigning them straight onto the list table, past the filter WordPress offers
 * for exactly that, and it answers every column it does not know with an empty
 * cell through a method no hook reaches.
 *
 * So both halves are done to the table itself. The column list is read off the
 * prepared table and written back with two more in it, which is enough for the
 * headings and for an empty cell per row in the right place, and the cells are
 * filled in the markup: the dashboard's one render is captured, each empty cell
 * is given what the post list puts there, and the page is printed.
 *
 * Every step is guarded and every failure is the same failure — the columns do
 * not appear — because this reaches into a table ShapeTranslate does not own. A
 * builder that reorganized it would leave this screen exactly as it found it.
 */
final class EssentialAddonsAdmin implements ModuleInterface {

	/**
	 * Menu slug of the Theme Builder dashboard.
	 */
	const PAGE_SLUG = 'eael-theme-builder';

	/**
	 * Theme Builder module class, which hands out its components.
	 */
	const MODULE_CLASS = '\\Essential_Addons_Elementor\\Theme_Builder\\Theme_Builder';

	/**
	 * Matches one of the two cells the table leaves empty.
	 */
	const CELL_PATTERN = '#<td class=(["\'])(shapetranslate_language|shapetranslate_translations)([^"\']*)\1([^>]*)></td>#';

	/**
	 * Theme Builder language resolution service.
	 *
	 * @var EssentialAddonsTemplates
	 */
	private $templates;

	/**
	 * Post translation relationships.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Shared list column renderer.
	 *
	 * @var TranslationListTable
	 */
	private $list_table;

	/**
	 * The dashboard's prepared list table, once the columns are on it.
	 *
	 * @var WP_List_Table|null
	 */
	private $table = null;

	/**
	 * Whether this screen's output is being captured.
	 *
	 * @var bool
	 */
	private $capturing = false;

	/**
	 * Constructor.
	 *
	 * @param EssentialAddonsTemplates $templates         Theme Builder language resolution.
	 * @param PostTranslationManager   $post_translations Post translation manager.
	 * @param LanguageManager          $language_manager  Language manager.
	 */
	public function __construct(
		EssentialAddonsTemplates $templates,
		PostTranslationManager $post_translations,
		LanguageManager $language_manager
	) {
		$this->templates         = $templates;
		$this->post_translations = $post_translations;
		$this->list_table        = new TranslationListTable(
			$post_translations,
			$language_manager,
			new TranslationActions()
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->templates->is_available() ) {
			return;
		}

		add_action( 'current_screen', array( $this, 'prepare_screen' ) );
	}

	/**
	 * Hooks the dashboard's own load and render.
	 *
	 * @param WP_Screen $screen Current administration screen.
	 * @return void
	 */
	public function prepare_screen( $screen ) {
		if ( ! $screen instanceof WP_Screen || ! $this->is_theme_builder_screen() ) {
			return;
		}

		if ( ! $this->post_translations->supports_post_type( EssentialAddonsTemplates::POST_TYPE ) ) {
			return;
		}

		$page_hook = isset( $GLOBALS['page_hook'] ) && is_string( $GLOBALS['page_hook'] )
			? $GLOBALS['page_hook']
			: '';

		if ( '' === $page_hook ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// After the dashboard's own load handler, which is what builds the table.
		add_action( 'load-' . $page_hook, array( $this, 'extend_table' ), 11 );

		// The dashboard prints itself from this hook at the default priority, so
		// the capture opens ahead of everything on it and closes behind.
		add_action( $page_hook, array( $this, 'start_capture' ), 0 );
		add_action( $page_hook, array( $this, 'print_captured' ), 20 );
	}

	/**
	 * Adds the two columns to the table the dashboard prepared.
	 *
	 * @return void
	 */
	public function extend_table() {
		$table = $this->find_list_table();

		if ( null === $table ) {
			return;
		}

		$headers = $this->read_column_headers( $table );

		if ( null === $headers || ! isset( $headers[0] ) || ! is_array( $headers[0] ) ) {
			return;
		}

		$headers[0] = $this->list_table->add_columns( $headers[0] );

		if ( $this->write_column_headers( $table, $headers ) ) {
			$this->table = $table;
		}
	}

	/**
	 * Begins capturing the dashboard's output.
	 *
	 * @return void
	 */
	public function start_capture() {
		$this->capturing = ob_start();
	}

	/**
	 * Prints the dashboard with the two columns filled in.
	 *
	 * @return void
	 */
	public function print_captured() {
		if ( ! $this->capturing ) {
			return;
		}

		$this->capturing = false;
		$html            = ob_get_clean();

		if ( ! is_string( $html ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Administration markup already printed by the screen, with cells filled by escaped column renderers.
		echo $this->fill_columns( $html );
	}

	/**
	 * Loads the translation UI styles on the Theme Builder dashboard.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'shapetranslate-admin',
			SHAPETRANSLATE_URL . 'assets/css/admin.css',
			array(),
			Assets::version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Writes each row's markup into the cell left empty for it.
	 *
	 * The rows print in the order the table holds them, and each row prints one
	 * cell per column, so the nth empty cell of a column belongs to the nth
	 * template. Nothing else in the table is read, which is what keeps this
	 * indifferent to how the rest of a row is built.
	 *
	 * @param string $html Captured dashboard markup.
	 * @return string
	 */
	private function fill_columns( $html ) {
		if ( null === $this->table || ! is_array( $this->table->items ) ) {
			return $html;
		}

		$items = array_values( $this->table->items );
		$rows  = array(
			'shapetranslate_language'     => 0,
			'shapetranslate_translations' => 0,
		);

		$filled = preg_replace_callback(
			self::CELL_PATTERN,
			function ( $matches ) use ( &$rows, $items ) {
				$quote  = $matches[1];
				$column = $matches[2];
				$row    = $rows[ $column ];

				++$rows[ $column ];

				$post_id = isset( $items[ $row ] ) && $items[ $row ] instanceof WP_Post
					? absint( $items[ $row ]->ID )
					: 0;

				if ( 0 === $post_id ) {
					return $matches[0];
				}

				$content = 'shapetranslate_language' === $column
					? $this->list_table->get_language_markup( $post_id )
					: $this->list_table->get_translations_markup( $post_id );

				return '<td class=' . $quote . $column . $matches[3] . $quote . $matches[4] . '>'
					. $content . '</td>';
			},
			$html
		);

		return is_string( $filled ) ? $filled : $html;
	}

	/**
	 * Returns the list table the dashboard prepared for this request.
	 *
	 * @return WP_List_Table|null
	 */
	private function find_list_table() {
		if ( ! class_exists( 'WP_List_Table' ) || ! class_exists( self::MODULE_CLASS ) ) {
			return null;
		}

		$module = call_user_func( array( self::MODULE_CLASS, 'instance' ) );

		if ( ! is_object( $module ) || ! method_exists( $module, 'get_component' ) ) {
			return null;
		}

		$admin = $module->get_component( 'admin' );

		if ( ! is_object( $admin ) || ! property_exists( $admin, 'list_table' ) ) {
			return null;
		}

		try {
			$property = new ReflectionProperty( $admin, 'list_table' );
			$property->setAccessible( true );
			$table = $property->getValue( $admin );
		} catch ( ReflectionException $exception ) {
			return null;
		}

		return $table instanceof WP_List_Table ? $table : null;
	}

	/**
	 * Returns the column list a prepared table is holding.
	 *
	 * @param WP_List_Table $table Prepared list table.
	 * @return array<int, mixed>|null
	 */
	private function read_column_headers( WP_List_Table $table ) {
		$property = $this->column_headers_property();

		if ( null === $property ) {
			return null;
		}

		$headers = $property->getValue( $table );

		return is_array( $headers ) ? $headers : null;
	}

	/**
	 * Puts an extended column list back on a prepared table.
	 *
	 * @param WP_List_Table     $table   Prepared list table.
	 * @param array<int, mixed> $headers Column list, hidden, sortable, primary.
	 * @return bool
	 */
	private function write_column_headers( WP_List_Table $table, array $headers ) {
		$property = $this->column_headers_property();

		if ( null === $property ) {
			return false;
		}

		$property->setValue( $table, $headers );

		return true;
	}

	/**
	 * Returns the readable column list property, or null when it is gone.
	 *
	 * @return ReflectionProperty|null
	 */
	private function column_headers_property() {
		try {
			$property = new ReflectionProperty( 'WP_List_Table', '_column_headers' );
			$property->setAccessible( true );
		} catch ( ReflectionException $exception ) {
			return null;
		}

		return $property;
	}

	/**
	 * Reports whether the Theme Builder dashboard is the page being rendered.
	 *
	 * @return bool
	 */
	private function is_theme_builder_screen() {
		return isset( $GLOBALS['plugin_page'] ) && self::PAGE_SLUG === $GLOBALS['plugin_page'];
	}
}
