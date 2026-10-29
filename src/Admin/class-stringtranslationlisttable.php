<?php
/**
 * String translation list table.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageTag;
use ShapeTranslate\StringTranslation\RegisteredStringValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Renders registered originals and the selected languages' editable values.
 */
final class StringTranslationListTable extends \WP_List_Table {

	/**
	 * Query service.
	 *
	 * @var StringTranslationQuery
	 */
	private $query;

	/**
	 * Normalized query arguments.
	 *
	 * @var array<string, mixed>
	 */
	private $query_args = array();

	/**
	 * Enabled languages keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $languages = array();

	/**
	 * Registered groups.
	 *
	 * @var array<int, string>
	 */
	private $groups = array();

	/**
	 * Language flag registry.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Constructor.
	 *
	 * @param StringTranslationQuery $query Query service.
	 */
	public function __construct( StringTranslationQuery $query ) {
		parent::__construct(
			array(
				'singular' => 'shapetranslate_string',
				'plural'   => 'shapetranslate_strings',
				'ajax'     => false,
				'screen'   => 'shapetranslate_page_shapetranslate-string-translations',
			)
		);

		$this->query  = $query;
		$this->groups = $query->get_groups();
		$this->flags  = new FlagRegistry();

		foreach ( $query->get_languages() as $language ) {
			$this->languages[ $language['id'] ] = $language;
		}
	}

	/**
	 * Loads one paginated page of registered strings.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only table filters.
		$language_id = isset( $_GET['shapetranslate_language'] ) && is_scalar( $_GET['shapetranslate_language'] )
			? sanitize_text_field( wp_unslash( $_GET['shapetranslate_language'] ) )
			: '';
		$group       = isset( $_GET['shapetranslate_group'] ) && is_scalar( $_GET['shapetranslate_group'] )
			? sanitize_text_field( wp_unslash( $_GET['shapetranslate_group'] ) )
			: '';
		$search      = isset( $_GET['s'] ) && is_scalar( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		$orderby     = isset( $_GET['orderby'] ) && is_scalar( $_GET['orderby'] )
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'group';
		$order       = isset( $_GET['order'] ) && is_scalar( $_GET['order'] )
			? sanitize_text_field( wp_unslash( $_GET['order'] ) )
			: 'ASC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_items_per_page( 'shapetranslate_strings_per_page', 20 );
		$result   = $this->query->query(
			array(
				'language_id' => $language_id,
				'group'       => $group,
				'search'      => $search,
				'page'        => $this->get_pagenum(),
				'per_page'    => $per_page,
				'orderby'     => $orderby,
				'order'       => $order,
			)
		);

		$this->items           = $result['items'];
		$this->query_args      = $result['args'];
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'original',
		);

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $result['args']['per_page'],
			)
		);
	}

	/**
	 * Returns table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		$language_id = $this->selected_filter();
		$language    = isset( $this->languages[ $language_id ] ) ? $this->languages[ $language_id ] : null;
		$flag        = null !== $language ? $this->flags->get_flag_html( $language ) : '';
		$heading     = StringTranslationQuery::ALL_LANGUAGES === $language_id
			? esc_html__( 'Translations', 'shapetranslate' )
			: $flag . esc_html__( 'Translation', 'shapetranslate' );

		return array(
			'identity'    => __( 'Registered string', 'shapetranslate' ),
			'original'    => __( 'Original string', 'shapetranslate' ),
			'translation' => $heading,
		);
	}

	/**
	 * Returns table classes, marking the view that stacks several languages.
	 *
	 * The all-languages view holds one field per language in a single cell, so
	 * it needs a wider column than the view that holds one.
	 *
	 * @return array<int, string>
	 */
	protected function get_table_classes() {
		$classes = parent::get_table_classes();

		if ( StringTranslationQuery::ALL_LANGUAGES === $this->selected_filter() ) {
			$classes[] = 'shapetranslate-strings-all-languages';
		}

		return $classes;
	}

	/**
	 * Returns sortable columns.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	protected function get_sortable_columns() {
		return array(
			'identity' => array( 'group', true ),
			'original' => array( 'original', false ),
		);
	}

	/**
	 * Returns normalized filters used by this page.
	 *
	 * @return array<string, mixed>
	 */
	public function get_query_args() {
		return $this->query_args;
	}

	/**
	 * Returns enabled language records keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_languages() {
		return $this->languages;
	}

	/**
	 * Returns registered group names.
	 *
	 * @return array<int, string>
	 */
	public function get_groups() {
		return $this->groups;
	}

	/**
	 * Renders the group and stable key.
	 *
	 * @param array<string, mixed> $item Registered definition.
	 * @return string
	 */
	protected function column_identity( $item ) {
		return sprintf(
			'<strong>%1$s</strong><code class="shapetranslate-string-key">%2$s</code>',
			esc_html( $item['string_group'] ),
			esc_html( $item['string_key'] )
		);
	}

	/**
	 * Renders the immutable original string.
	 *
	 * @param array<string, mixed> $item Registered definition.
	 * @return string
	 */
	protected function column_original( $item ) {
		return '<div class="shapetranslate-string-original">' . nl2br( esc_html( $item['original_string'] ) ) . '</div>';
	}

	/**
	 * Renders an editable value for every selected language.
	 *
	 * Fields are always named per language, so the save handler reads one shape
	 * whether the page is showing a single language or all of them.
	 *
	 * @param array<string, mixed> $item Registered definition.
	 * @return string
	 */
	protected function column_translation( $item ) {
		$selected = $this->selected_languages();

		if ( empty( $selected ) ) {
			return '<span class="shapetranslate-dashboard-unavailable">' . esc_html__( 'No enabled language', 'shapetranslate' ) . '</span>';
		}

		// One language needs no label beside its field; the column already names
		// it. Several do, or an editor cannot tell which box is which. The
		// modifier class is what turns the cell into a label and field grid, so
		// a single field still fills the column even in the all-languages view.
		$labelled = count( $selected ) > 1;
		$classes  = 'shapetranslate-string-translations';
		$fields   = '';

		if ( $labelled ) {
			$classes .= ' shapetranslate-string-translations--labelled';
		}

		foreach ( $selected as $language ) {
			$fields .= $this->render_translation_field( $item, $language, $labelled );
		}

		return '<div class="' . esc_attr( $classes ) . '">' . $fields . '</div>';
	}

	/**
	 * Renders one language's editable value.
	 *
	 * @param array<string, mixed> $item     Registered definition.
	 * @param array<string, mixed> $language Enabled language record.
	 * @param bool                 $labelled Whether to render a visible heading.
	 * @return string
	 */
	private function render_translation_field( array $item, array $language, $labelled ) {
		$language_id = (string) $language['id'];
		$value       = isset( $item['translations'][ $language_id ] ) ? $item['translations'][ $language_id ] : '';
		$label       = sprintf(
			/* translators: 1: native language name, 2: original string. */
			__( '%1$s translation for %2$s', 'shapetranslate' ),
			$language['native_name'],
			$item['original_string']
		);
		$field_id = 'shapetranslate-string-' . $item['string_id'] . '-' . $language_id;
		$heading  = '';

		if ( $labelled ) {
			// The code is the label because it is the same width for every
			// language, so the boxes beside it line up and get the rest of the
			// column. The flag carries the recognition, and the full name stays
			// one hover away.
			$heading = sprintf(
				'<label class="shapetranslate-string-language" for="%1$s" title="%2$s">%3$s<span>%4$s</span></label>',
				esc_attr( $field_id ),
				esc_attr( $language['native_name'] ),
				$this->flags->get_flag_html( $language ),
				esc_html( $this->language_label( $language ) )
			);
		}

		/*
		 * The screen is in the editor's language and the box is in the
		 * reader's. Without these a right-to-left translation is typed into a
		 * left-to-right field, which misplaces the caret and every number,
		 * bracket, and Latin word in the line.
		 */
		$code      = isset( $language['language_code'] ) ? sanitize_key( $language['language_code'] ) : '';
		$direction = LanguageTag::direction( $language );

		return $heading . sprintf(
			'<textarea id="%1$s" class="large-text shapetranslate-string-translation" name="translations[%2$s][%3$s]" rows="3" maxlength="%4$d" aria-label="%5$s" lang="%7$s" dir="%8$s">%6$s</textarea>',
			esc_attr( $field_id ),
			esc_attr( $language_id ),
			esc_attr( $item['string_id'] ),
			RegisteredStringValidator::MAX_STRING_LENGTH,
			esc_attr( $label ),
			esc_textarea( $value ),
			esc_attr( $code ),
			esc_attr( $direction )
		);
	}

	/**
	 * Returns the short label shown beside a language's field.
	 *
	 * @param array<string, mixed> $language Enabled language record.
	 * @return string
	 */
	private function language_label( array $language ) {
		$code = isset( $language['language_code'] ) && is_scalar( $language['language_code'] )
			? strtoupper( trim( (string) $language['language_code'] ) )
			: '';

		return '' === $code ? (string) $language['native_name'] : $code;
	}

	/**
	 * Returns the language records the current filter selects, in registry order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function selected_languages() {
		$selected = array();

		foreach ( $this->query->selected_language_ids( $this->selected_filter() ) as $language_id ) {
			if ( isset( $this->languages[ $language_id ] ) ) {
				$selected[] = $this->languages[ $language_id ];
			}
		}

		return $selected;
	}

	/**
	 * Returns the normalized language filter.
	 *
	 * @return string
	 */
	private function selected_filter() {
		return isset( $this->query_args['language_id'] ) ? (string) $this->query_args['language_id'] : '';
	}

	/**
	 * Renders no unknown columns.
	 *
	 * @param array<string, mixed> $item        Registered definition.
	 * @param string               $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		unset( $item, $column_name );

		return '';
	}

	/**
	 * Message displayed when no definitions match.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No registered strings matched the current filters.', 'shapetranslate' );
	}
}
