<?php
/**
 * Translation dashboard list table.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Language\FlagRegistry;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a paginated translation-status matrix using WordPress conventions.
 */
final class TranslationDashboardListTable extends \WP_List_Table {

	/**
	 * Dashboard query service.
	 *
	 * @var TranslationDashboardQuery
	 */
	private $dashboard_query;

	/**
	 * Translation manager.
	 *
	 * @var PostTranslationManager
	 */
	private $translation_manager;

	/**
	 * Translation action helper.
	 *
	 * @var TranslationActions
	 */
	private $actions;

	/**
	 * Language flag registry.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Enabled languages keyed by language ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $languages = array();

	/**
	 * Visible supported post types.
	 *
	 * @var array<string, \WP_Post_Type>
	 */
	private $post_types = array();

	/**
	 * Normalized query arguments for this request.
	 *
	 * @var array<string, mixed>
	 */
	private $query_args = array();

	/**
	 * Constructor.
	 *
	 * @param TranslationDashboardQuery $dashboard_query    Dashboard query service.
	 * @param PostTranslationManager    $translation_manager Translation manager.
	 * @param TranslationActions        $actions             Translation action helper.
	 */
	public function __construct(
		TranslationDashboardQuery $dashboard_query,
		PostTranslationManager $translation_manager,
		TranslationActions $actions
	) {
		parent::__construct(
			array(
				'singular' => 'shapetranslate_translation',
				'plural'   => 'shapetranslate_translations',
				'ajax'     => false,
				'screen'   => 'shapetranslate_page_shapetranslate-translations',
			)
		);

		$this->dashboard_query     = $dashboard_query;
		$this->translation_manager = $translation_manager;
		$this->actions             = $actions;
		$this->flags               = new FlagRegistry();
		$this->post_types          = $dashboard_query->get_post_types();

		foreach ( $dashboard_query->get_languages() as $language ) {
			$this->languages[ $language['id'] ] = $language;
		}
	}

	/**
	 * Loads the current page of rows and primes its related objects.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only table filters.
		$post_type = isset( $_GET['shapetranslate_post_type'] ) && is_scalar( $_GET['shapetranslate_post_type'] )
			? sanitize_key( wp_unslash( $_GET['shapetranslate_post_type'] ) )
			: '';
		$language  = isset( $_GET['shapetranslate_language'] ) && is_scalar( $_GET['shapetranslate_language'] )
			? sanitize_text_field( wp_unslash( $_GET['shapetranslate_language'] ) )
			: '';
		$status    = isset( $_GET['shapetranslate_status'] ) && is_scalar( $_GET['shapetranslate_status'] )
			? sanitize_key( wp_unslash( $_GET['shapetranslate_status'] ) )
			: 'all';
		$search    = isset( $_GET['s'] ) && is_scalar( $_GET['s'] )
			? sanitize_text_field( wp_unslash( $_GET['s'] ) )
			: '';
		$orderby   = isset( $_GET['orderby'] ) && is_scalar( $_GET['orderby'] )
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'title';
		$order     = isset( $_GET['order'] ) && is_scalar( $_GET['order'] )
			? sanitize_text_field( wp_unslash( $_GET['order'] ) )
			: 'ASC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_items_per_page( 'shapetranslate_translations_per_page', 20 );
		$result   = $this->dashboard_query->query(
			array(
				'post_type'   => $post_type,
				'language_id' => $language,
				'status'      => $status,
				'search'      => $search,
				'page'        => $this->get_pagenum(),
				'per_page'    => $per_page,
				'orderby'     => $orderby,
				'order'       => $order,
			)
		);

		$this->items      = $result['items'];
		$this->query_args = $result['args'];
		$this->process_bulk_action();
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'content',
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
		$columns = array();

		if ( ! empty( $this->get_bulk_actions() ) ) {
			$columns['cb'] = '<input type="checkbox" />';
		}

		$columns['content'] = __( 'Content', 'shapetranslate' );

		foreach ( $this->languages as $language_id => $language ) {
			$columns[ 'language_' . $language_id ] = $this->flags->get_flag_html( $language ) . esc_html(
				sprintf(
					/* translators: 1: native language name, 2: language code. */
					_x( '%1$s (%2$s)', 'translation dashboard language heading', 'shapetranslate' ),
					$language['native_name'],
					strtoupper( $language['language_code'] )
				)
			);
		}

		return $columns;
	}

	/**
	 * Returns sortable columns.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	protected function get_sortable_columns() {
		return array(
			'content' => array( 'title', true ),
		);
	}

	/**
	 * Returns addon-provided bulk actions.
	 *
	 * ShapeTranslate Free intentionally provides no mutating bulk operation.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		/**
		 * Filters translation dashboard bulk actions.
		 *
		 * Action callbacks can use the shapetranslate_translation_dashboard_bulk_action
		 * hook. The dashboard validates the nonce and edit capability for every row.
		 *
		 * @param array<string, string> $actions    Bulk actions keyed by action name.
		 * @param array<string, mixed>  $query_args Current normalized query arguments.
		 */
		$filtered = apply_filters( 'shapetranslate_translation_dashboard_bulk_actions', array(), $this->query_args );
		$actions  = array();

		if ( ! is_array( $filtered ) ) {
			return $actions;
		}

		foreach ( $filtered as $action => $label ) {
			$action = is_scalar( $action ) ? sanitize_key( (string) $action ) : '';

			if ( '' !== $action && is_scalar( $label ) && '' !== (string) $label ) {
				$actions[ $action ] = (string) $label;
			}
		}

		return $actions;
	}

	/**
	 * Handles validated addon bulk-action dispatch.
	 *
	 * @return void
	 */
	private function process_bulk_action() {
		$action  = $this->current_action();
		$actions = $this->get_bulk_actions();

		if ( false === $action || ! isset( $actions[ $action ] ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// Verified above, then limited, scalar-checked, and passed through absint below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$requested_ids = isset( $_REQUEST['content'] ) && is_array( $_REQUEST['content'] )
			? array_slice( wp_unslash( $_REQUEST['content'] ), 0, 100 )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$requested_ids = array_filter( $requested_ids, 'is_scalar' );
		$post_ids      = array_values( array_unique( array_filter( array_map( 'absint', $requested_ids ) ) ) );

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( $post_ids, false, false );
			$this->translation_manager->prime_posts( $post_ids );
		}

		$supported_post_types = $this->translation_manager->get_supported_post_types();
		$post_ids             = array_values(
			array_filter(
				$post_ids,
				function ( $post_id ) use ( $supported_post_types ) {
					$post = get_post( $post_id );

					return $post instanceof WP_Post
						&& in_array( $post->post_type, $supported_post_types, true )
						&& $post_id === $this->translation_manager->get_source_post_id( $post_id )
						&& current_user_can( 'edit_post', $post_id );
				}
			)
		);

		/**
		 * Fires for a registered translation dashboard bulk action.
		 *
		 * Addon handlers remain responsible for operation-specific validation.
		 *
		 * @param string               $action     Sanitized action name.
		 * @param array<int, int>      $post_ids   Editable source post IDs.
		 * @param array<string, mixed> $query_args Current normalized filters.
		 */
		do_action( 'shapetranslate_translation_dashboard_bulk_action', $action, $post_ids, $this->query_args );
	}

	/**
	 * Renders the source row checkbox when bulk actions are registered.
	 *
	 * @param array<string, mixed> $item Dashboard row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$post_id = absint( $item['source_post_id'] );
		$title   = get_the_title( $post_id );
		$label   = sprintf(
			/* translators: %s: source content title. */
			__( 'Select %s', 'shapetranslate' ),
			'' !== $title ? $title : __( '(no title)', 'shapetranslate' )
		);

		return sprintf(
			'<input id="cb-select-%1$d" type="checkbox" name="content[]" value="%1$d" /><label class="screen-reader-text" for="cb-select-%1$d">%2$s</label>',
			$post_id,
			esc_html( $label )
		);
	}

	/**
	 * Renders source content details and row actions.
	 *
	 * @param array<string, mixed> $item Dashboard row.
	 * @return string
	 */
	protected function column_content( $item ) {
		$post = get_post( absint( $item['source_post_id'] ) );

		if ( ! $post instanceof WP_Post ) {
			return esc_html__( 'Content unavailable', 'shapetranslate' );
		}

		$title            = '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'shapetranslate' );
		$edit_url         = get_edit_post_link( $post->ID, '' );
		$can_edit         = current_user_can( 'edit_post', $post->ID ) && is_string( $edit_url );
		$post_type_object = get_post_type_object( $post->post_type );
		$status_object    = get_post_status_object( $post->post_status );
		$type_label       = $post_type_object ? $post_type_object->labels->singular_name : $post->post_type;
		$status_label     = $status_object ? $status_object->label : $post->post_status;
		$title_html       = $can_edit
			? sprintf( '<a class="row-title" href="%1$s">%2$s</a>', esc_url( $edit_url ), esc_html( $title ) )
			: '<strong>' . esc_html( $title ) . '</strong>';
		$row_actions      = array();

		if ( $can_edit ) {
			$row_actions['edit'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'shapetranslate' )
			);
		}

		if ( is_post_publicly_viewable( $post ) ) {
			$row_actions['view'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( get_permalink( $post ) ),
				esc_html__( 'View', 'shapetranslate' )
			);
		}

		$html  = $title_html;
		$html .= sprintf(
			'<div class="shapetranslate-dashboard-content-meta">%1$s &middot; %2$s</div>',
			esc_html( $type_label ),
			esc_html( $status_label )
		);
		$html .= $this->row_actions( $row_actions );

		/**
		 * Filters one translation dashboard content cell.
		 *
		 * @param string               $html Rendered cell HTML.
		 * @param array<string, mixed> $item Dashboard row.
		 * @param WP_Post              $post Source post.
		 */
		return apply_filters( 'shapetranslate_translation_dashboard_content_html', $html, $item, $post );
	}

	/**
	 * Renders one language matrix cell.
	 *
	 * @param array<string, mixed> $item        Dashboard row.
	 * @param string               $column_name Current column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$language_id = 0 === strpos( $column_name, 'language_' )
			? substr( $column_name, strlen( 'language_' ) )
			: '';

		if ( '' === $language_id || ! isset( $this->languages[ $language_id ] ) ) {
			return '';
		}

		$language     = $this->languages[ $language_id ];
		$translations = isset( $item['translations'] ) && is_array( $item['translations'] )
			? $item['translations']
			: array();
		$target_id    = isset( $translations[ $language_id ] ) ? absint( $translations[ $language_id ] ) : 0;
		$source       = get_post( absint( $item['source_post_id'] ) );
		$target       = $target_id ? get_post( $target_id ) : null;

		if ( $target instanceof WP_Post ) {
			$html = $this->render_existing_translation( $target, $language );
		} elseif ( $source instanceof WP_Post && $this->actions->can_create_translation( $source ) ) {
			$html = $this->render_missing_translation( $source, $language );
		} else {
			$html = sprintf(
				'<span class="shapetranslate-dashboard-unavailable" aria-hidden="true">&mdash;</span><span class="screen-reader-text">%s</span>',
				esc_html__( 'Translation unavailable', 'shapetranslate' )
			);
		}

		/**
		 * Filters one translation dashboard language cell.
		 *
		 * @param string               $html        Rendered cell HTML.
		 * @param array<string, mixed> $item        Dashboard row.
		 * @param array<string, mixed> $language    Language record.
		 * @param WP_Post|null         $target      Existing translated post, if any.
		 * @param string               $column_name Current column name.
		 */
		return apply_filters(
			'shapetranslate_translation_dashboard_cell_html',
			$html,
			$item,
			$language,
			$target,
			$column_name
		);
	}

	/**
	 * Renders an existing translation status and edit action.
	 *
	 * @param WP_Post              $post     Translation post.
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	private function render_existing_translation( WP_Post $post, $language ) {
		$status_object = get_post_status_object( $post->post_status );
		$status_label  = $status_object ? $status_object->label : $post->post_status;
		$is_complete   = $status_object && ! empty( $status_object->public );
		$is_trash      = 'trash' === $post->post_status;
		$icon          = $is_trash ? 'dismiss' : ( $is_complete ? 'yes-alt' : 'edit' );
		$class         = $is_trash ? 'is-unavailable' : ( $is_complete ? 'is-complete' : 'is-draft' );
		$label         = $is_complete
			? sprintf(
				/* translators: %s: native language name. */
				__( '%s translation is complete', 'shapetranslate' ),
				$language['native_name']
			)
			: sprintf(
				/* translators: 1: native language name, 2: post status. */
				__( '%1$s translation: %2$s', 'shapetranslate' ),
				$language['native_name'],
				$status_label
			);
		$contents = sprintf(
			'<span class="dashicons dashicons-%1$s" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span>',
			esc_attr( $icon ),
			esc_html( $label )
		);
		$edit_url = get_edit_post_link( $post->ID, '' );

		if ( ! $is_trash && current_user_can( 'edit_post', $post->ID ) && is_string( $edit_url ) ) {
			return sprintf(
				'<a class="shapetranslate-dashboard-state %1$s" href="%2$s" aria-label="%3$s">%4$s</a>',
				esc_attr( $class ),
				esc_url( $edit_url ),
				esc_attr( $label ),
				$contents
			);
		}

		return sprintf(
			'<span class="shapetranslate-dashboard-state %1$s">%2$s</span>',
			esc_attr( $class ),
			$contents
		);
	}

	/**
	 * Renders a translated-copy action.
	 *
	 * @param WP_Post              $source   Source post.
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	private function render_missing_translation( WP_Post $source, $language ) {
		$label = sprintf(
			/* translators: %s: native language name. */
			__( 'Add %s translation', 'shapetranslate' ),
			$language['native_name']
		);

		return sprintf(
			'<a class="shapetranslate-dashboard-state is-missing" href="%1$s" aria-label="%2$s"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span class="screen-reader-text">%3$s</span></a>',
			esc_url( $this->actions->get_create_url( $source->ID, $language['id'] ) ),
			esc_attr( $label ),
			esc_html( $label )
		);
	}

	/**
	 * Renders post type, language, and status filters above the table.
	 *
	 * @param string $which Current table navigation location.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$post_type  = isset( $this->query_args['post_type'] ) ? $this->query_args['post_type'] : '';
		$language   = isset( $this->query_args['language_id'] ) ? $this->query_args['language_id'] : '';
		$status     = isset( $this->query_args['status'] ) ? $this->query_args['status'] : 'all';
		$status_map = array(
			'all'       => __( 'All translation statuses', 'shapetranslate' ),
			'missing'   => __( 'Missing translations', 'shapetranslate' ),
			'completed' => __( 'Completed translations', 'shapetranslate' ),
			'draft'     => __( 'Draft translations', 'shapetranslate' ),
		);

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="shapetranslate-post-type">' . esc_html__( 'Filter by content type', 'shapetranslate' ) . '</label>';
		echo '<select id="shapetranslate-post-type" name="shapetranslate_post_type">';
		echo '<option value="">' . esc_html__( 'All content types', 'shapetranslate' ) . '</option>';

		foreach ( $this->post_types as $post_type_id => $object ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $post_type_id ),
				selected( $post_type, $post_type_id, false ),
				esc_html( $object->labels->name )
			);
		}

		echo '</select>';
		echo '<label class="screen-reader-text" for="shapetranslate-language-filter">' . esc_html__( 'Filter by translation language', 'shapetranslate' ) . '</label>';
		echo '<select id="shapetranslate-language-filter" name="shapetranslate_language">';
		echo '<option value="">' . esc_html__( 'All languages', 'shapetranslate' ) . '</option>';

		foreach ( $this->languages as $language_id => $language_record ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $language_id ),
				selected( $language, $language_id, false ),
				esc_html( $language_record['name'] )
			);
		}

		echo '</select>';
		echo '<label class="screen-reader-text" for="shapetranslate-status-filter">' . esc_html__( 'Filter by translation status', 'shapetranslate' ) . '</label>';
		echo '<select id="shapetranslate-status-filter" name="shapetranslate_status">';

		foreach ( $status_map as $status_id => $status_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status_id ),
				selected( $status, $status_id, false ),
				esc_html( $status_label )
			);
		}

		echo '</select>';
		submit_button( __( 'Filter', 'shapetranslate' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Message displayed when no rows match.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No content matched the current translation filters.', 'shapetranslate' );
	}
}
