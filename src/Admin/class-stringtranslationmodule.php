<?php
/**
 * String translation admin module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\StringTranslation\GettextScanner;
use ShapeTranslate\StringTranslation\GettextStrings;
use ShapeTranslate\StringTranslation\StringManager;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and secures the central registered-string editor.
 */
final class StringTranslationModule implements ModuleInterface {

	/**
	 * String manager.
	 *
	 * @var StringManager
	 */
	private $string_manager;

	/**
	 * String translation query service.
	 *
	 * @var StringTranslationQuery
	 */
	private $query;

	/**
	 * Theme and plugin source scanner.
	 *
	 * @var GettextScanner
	 */
	private $scanner;

	/**
	 * Gettext string storage.
	 *
	 * @var GettextStrings
	 */
	private $gettext;

	/**
	 * Registered admin screen hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param StringManager          $string_manager String manager.
	 * @param StringTranslationQuery $query          Query service.
	 * @param GettextScanner         $scanner        Theme and plugin source scanner.
	 * @param GettextStrings         $gettext        Gettext string storage.
	 */
	public function __construct( StringManager $string_manager, StringTranslationQuery $query, GettextScanner $scanner, GettextStrings $gettext ) {
		$this->string_manager = $string_manager;
		$this->query          = $query;
		$this->scanner        = $scanner;
		$this->gettext        = $gettext;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shapetranslate_save_string_translations', array( $this, 'save_translations' ) );
		add_action( 'admin_post_shapetranslate_scan_gettext', array( $this, 'scan_gettext' ) );
		add_filter( 'set_screen_option_shapetranslate_strings_per_page', array( $this, 'save_per_page' ), 10, 3 );
	}

	/**
	 * Registers ShapeTranslate -> String Translation.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'shapetranslate',
			esc_html__( 'ShapeTranslate String Translation', 'shapetranslate' ),
			esc_html__( 'String Translation', 'shapetranslate' ),
			AdminModule::capability(),
			'shapetranslate-string-translations',
			array( $this, 'render' )
		);

		if ( '' !== $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'add_screen_options' ) );
		}
	}

	/**
	 * Adds the standard per-page screen option.
	 *
	 * @return void
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Strings per page', 'shapetranslate' ),
				'default' => 20,
				'option'  => 'shapetranslate_strings_per_page',
			)
		);
	}

	/**
	 * Validates the per-page preference.
	 *
	 * @param mixed  $status Existing screen option value.
	 * @param string $option Screen option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_per_page( $status, $option, $value ) {
		if ( 'shapetranslate_strings_per_page' !== $option ) {
			return $status;
		}

		return min( 100, max( 1, absint( $value ) ) );
	}

	/**
	 * Loads admin styling only on the string translation screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'shapetranslate-admin',
			SHAPETRANSLATE_URL . 'assets/css/admin.css',
			array(),
			Assets::version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Renders filters and the registered-string editor.
	 *
	 * @return void
	 */
	public function render() {
		$this->authorize();
		$this->string_manager->flush_registered_strings();

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new StringTranslationListTable( $this->query );
		$table->prepare_items();
		$args        = $table->get_query_args();
		$languages   = $table->get_languages();
		$language_id = $args['language_id'];

		echo '<div class="wrap shapetranslate-string-translation-page">';
		echo '<h1>' . esc_html__( 'String Translation', 'shapetranslate' ) . '</h1>';
		$this->render_notice();

		if ( empty( $languages ) ) {
			echo '<div class="shapetranslate-notice notice notice-warning inline"><p>';
			esc_html_e( 'Enable at least one language before translating registered strings.', 'shapetranslate' );
			echo '</p></div>';
		}

		$this->render_scan_form();
		$this->render_filters( $table, $args );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="shapetranslate_save_string_translations" />';
		echo '<input type="hidden" name="language_id" value="' . esc_attr( $language_id ) . '" />';
		$this->render_redirect_fields( $args );
		// Own field name: the list table prints its `bulk-` nonce as `_wpnonce` inside this form, which would replace ours.
		wp_nonce_field( 'shapetranslate_save_string_translations_' . $language_id, 'shapetranslate_strings_nonce' );
		$table->display();

		if ( '' !== $language_id && $table->has_items() ) {
			submit_button( __( 'Save translations', 'shapetranslate' ) );
		}

		echo '</form></div>';
	}

	/**
	 * Saves the submitted page of translations, one language at a time.
	 *
	 * Fields are always posted as `translations[language][string]`, so a page
	 * showing every language saves through exactly the same path as a page
	 * showing one. Each language is validated against the enabled registry
	 * before the manager sees it, and an unknown language is skipped rather
	 * than failing the whole page.
	 *
	 * @return void
	 */
	public function save_translations() {
		$this->authorize();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Selects the nonce action below.
		$view = isset( $_POST['language_id'] ) && is_scalar( $_POST['language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( 'shapetranslate_save_string_translations_' . $view, 'shapetranslate_strings_nonce' );

		// Verified above; the manager validates every ID and value before mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['translations'] ) && is_array( $_POST['translations'] )
			? wp_unslash( $_POST['translations'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$enabled = array_values( wp_list_pluck( $this->query->get_languages(), 'id' ) );
		$error   = null;

		foreach ( $submitted as $language_id => $translations ) {
			$language_id = is_scalar( $language_id ) ? sanitize_text_field( (string) $language_id ) : '';

			if ( ! in_array( $language_id, $enabled, true ) || ! is_array( $translations ) ) {
				continue;
			}

			$result = $this->string_manager->save_translations( $language_id, $translations );

			if ( is_wp_error( $result ) ) {
				$error = $result;

				break;
			}
		}

		$this->redirect_after_save( $error );
	}

	/**
	 * Reads one active theme or plugin and stores the text it passes to gettext.
	 *
	 * The component is chosen from a list built here, never from a path sent
	 * by the browser, so only code the site already runs is ever read.
	 *
	 * @return void
	 */
	public function scan_gettext() {
		$this->authorize();
		check_admin_referer( 'shapetranslate_scan_gettext' );

		$components = $this->get_scannable_components();
		$selected   = isset( $_POST['component'] ) && is_scalar( $_POST['component'] )
			? sanitize_text_field( wp_unslash( $_POST['component'] ) )
			: '';
		$args       = array( 'page' => 'shapetranslate-string-translations' );

		if ( ! isset( $components[ $selected ] ) ) {
			$args['shapetranslate_string_notice'] = 'scan_invalid';
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) ) {
			// A large plugin holds thousands of files; reading them can outlast the default limit.
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$entries = $this->scanner->scan( $components[ $selected ]['path'] );
		$this->gettext->save_scanned( $entries );

		$domains = array_values( array_unique( wp_list_pluck( $entries, 'domain' ) ) );

		$args['shapetranslate_string_notice'] = 'scanned';
		$args['shapetranslate_scanned']       = count( $entries );

		// One domain is what a theme or plugin normally uses; show its strings straight away.
		if ( 1 === count( $domains ) ) {
			$args['shapetranslate_group'] = GettextStrings::group( $domains[0] );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Returns the active themes and plugins that can be scanned.
	 *
	 * @return array<string, array{label: string, path: string}> Keyed by component ID.
	 */
	private function get_scannable_components() {
		$components = array();
		$theme      = wp_get_theme();

		$components[ 'theme:' . $theme->get_stylesheet() ] = array(
			/* translators: %s: theme name. */
			'label' => sprintf( __( 'Theme: %s', 'shapetranslate' ), $theme->get( 'Name' ) ),
			'path'  => $theme->get_stylesheet_directory(),
		);

		$parent = $theme->parent();

		if ( $parent ) {
			$components[ 'theme:' . $parent->get_stylesheet() ] = array(
				/* translators: %s: parent theme name. */
				'label' => sprintf( __( 'Parent theme: %s', 'shapetranslate' ), $parent->get( 'Name' ) ),
				'path'  => $parent->get_stylesheet_directory(),
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $plugin ) {
			if ( ! is_plugin_active( $file ) || SHAPETRANSLATE_BASENAME === $file ) {
				continue;
			}

			$directory = dirname( $file );

			$components[ 'plugin:' . $file ] = array(
				/* translators: %s: plugin name. */
				'label' => sprintf( __( 'Plugin: %s', 'shapetranslate' ), $plugin['Name'] ),
				'path'  => '.' === $directory ? WP_PLUGIN_DIR . '/' . $file : WP_PLUGIN_DIR . '/' . $directory,
			);
		}

		return $components;
	}

	/**
	 * Renders the theme and plugin scan form.
	 *
	 * @return void
	 */
	private function render_scan_form() {
		echo '<div class="shapetranslate-gettext-scan">';
		echo '<h2>' . esc_html__( 'Theme and plugin texts', 'shapetranslate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Scan an active theme or plugin to list the texts written in its code, such as button labels and headings, and translate them below. Translations entered here replace the ones in the theme or plugin language files.', 'shapetranslate' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="shapetranslate_scan_gettext" />';
		wp_nonce_field( 'shapetranslate_scan_gettext' );
		echo '<label class="screen-reader-text" for="shapetranslate-gettext-component">' . esc_html__( 'Theme or plugin to scan', 'shapetranslate' ) . '</label>';
		echo '<select id="shapetranslate-gettext-component" name="component">';

		foreach ( $this->get_scannable_components() as $id => $component ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $id ), esc_html( $component['label'] ) );
		}

		echo '</select> ';
		submit_button( __( 'Scan for texts', 'shapetranslate' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Renders the GET filter form.
	 *
	 * @param StringTranslationListTable $table Prepared table.
	 * @param array<string, mixed>       $args  Normalized filters.
	 * @return void
	 */
	private function render_filters( $table, $args ) {
		echo '<form method="get" class="shapetranslate-string-filters">';
		echo '<input type="hidden" name="page" value="shapetranslate-string-translations" />';
		echo '<label class="screen-reader-text" for="shapetranslate-string-group">' . esc_html__( 'Filter by string group', 'shapetranslate' ) . '</label>';
		echo '<select id="shapetranslate-string-group" name="shapetranslate_group">';
		echo '<option value="">' . esc_html__( 'All string groups', 'shapetranslate' ) . '</option>';

		foreach ( $table->get_groups() as $group ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $group ),
				selected( $args['group'], $group, false ),
				esc_html( $group )
			);
		}

		echo '</select>';
		echo '<label class="screen-reader-text" for="shapetranslate-string-language">' . esc_html__( 'Select translation language', 'shapetranslate' ) . '</label>';
		echo '<select id="shapetranslate-string-language" name="shapetranslate_language">';

		if ( empty( $table->get_languages() ) ) {
			echo '<option value="">' . esc_html__( 'No enabled languages', 'shapetranslate' ) . '</option>';
		} else {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( StringTranslationQuery::ALL_LANGUAGES ),
				selected( $args['language_id'], StringTranslationQuery::ALL_LANGUAGES, false ),
				esc_html__( 'All languages', 'shapetranslate' )
			);
		}

		foreach ( $table->get_languages() as $language_id => $language ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $language_id ),
				selected( $args['language_id'], $language_id, false ),
				esc_html( $language['name'] )
			);
		}

		echo '</select>';
		submit_button( __( 'Filter', 'shapetranslate' ), '', 'filter_action', false );
		$table->search_box( __( 'Search strings', 'shapetranslate' ), 'shapetranslate-strings' );
		echo '</form>';
	}

	/**
	 * Preserves allowlisted filters after a save redirect.
	 *
	 * @param array<string, mixed> $args Normalized filters.
	 * @return void
	 */
	private function render_redirect_fields( $args ) {
		$fields = array(
			'shapetranslate_group' => $args['group'],
			's'                 => $args['search'],
			'paged'             => $args['page'],
			'orderby'           => $args['orderby'],
			'order'             => $args['order'],
		);

		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="redirect[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '" />';
		}
	}

	/**
	 * Redirects to a controlled dashboard URL after a mutation.
	 *
	 * @param WP_Error|null $error Optional save error.
	 * @return void
	 */
	private function redirect_after_save( $error ) {
		// Save nonce verified before this helper; individual values are sanitized below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect    = isset( $_POST['redirect'] ) && is_array( $_POST['redirect'] )
			? wp_unslash( $_POST['redirect'] )
			: array();
		$language_id = isset( $_POST['language_id'] ) && is_scalar( $_POST['language_id'] )
			? sanitize_text_field( wp_unslash( $_POST['language_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$args = array(
			'page'                      => 'shapetranslate-string-translations',
			'shapetranslate_language'      => $language_id,
			'shapetranslate_group'         => isset( $redirect['shapetranslate_group'] ) && is_scalar( $redirect['shapetranslate_group'] )
				? sanitize_text_field( $redirect['shapetranslate_group'] )
				: '',
			's'                         => isset( $redirect['s'] ) && is_scalar( $redirect['s'] )
				? sanitize_text_field( $redirect['s'] )
				: '',
			'paged'                     => isset( $redirect['paged'] ) ? max( 1, absint( $redirect['paged'] ) ) : 1,
			'orderby'                   => isset( $redirect['orderby'] ) && is_scalar( $redirect['orderby'] )
				? sanitize_key( $redirect['orderby'] )
				: 'group',
			'order'                     => isset( $redirect['order'] ) && 'DESC' === strtoupper( sanitize_text_field( $redirect['order'] ) )
				? 'DESC'
				: 'ASC',
			'shapetranslate_string_notice' => null === $error ? 'saved' : ( 'string_translation_placeholders' === $error->get_error_code() ? 'placeholders' : 'invalid' ),
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders a controlled status notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted notice.
		$notice = isset( $_GET['shapetranslate_string_notice'] )
			? sanitize_key( wp_unslash( $_GET['shapetranslate_string_notice'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'saved' === $notice ) {
			echo '<div class="shapetranslate-notice notice notice-success is-dismissible"><p>' . esc_html__( 'String translations saved.', 'shapetranslate' ) . '</p></div>';
		} elseif ( 'invalid' === $notice ) {
			echo '<div class="shapetranslate-notice notice notice-error"><p>' . esc_html__( 'The string translations could not be saved.', 'shapetranslate' ) . '</p></div>';
		} elseif ( 'placeholders' === $notice ) {
			echo '<div class="shapetranslate-notice notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: example placeholder "%s", 2: example placeholder "%1$s". */
					__( 'The string translations could not be saved. A translation must keep the same placeholders, such as %1$s or %2$s, as its original text.', 'shapetranslate' ),
					'%s',
					'%1$s'
				)
			);
			echo '</p></div>';
		} elseif ( 'scan_invalid' === $notice ) {
			echo '<div class="shapetranslate-notice notice notice-error"><p>' . esc_html__( 'Choose an active theme or plugin to scan.', 'shapetranslate' ) . '</p></div>';
		} elseif ( 'scanned' === $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only count for a notice.
			$count = isset( $_GET['shapetranslate_scanned'] ) ? absint( $_GET['shapetranslate_scanned'] ) : 0;

			printf(
				'<div class="shapetranslate-notice notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of texts found. */
						_n( 'Scan complete: %s text found.', 'Scan complete: %s texts found.', $count, 'shapetranslate' ),
						number_format_i18n( $count )
					)
				)
			);
		}
	}

	/**
	 * Enforces the shared ShapeTranslate management capability.
	 *
	 * @return void
	 */
	private function authorize() {
		if ( current_user_can( AdminModule::capability() ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You are not allowed to manage string translations.', 'shapetranslate' ),
			'',
			array( 'response' => 403 )
		);
	}
}
