<?php
/**
 * Languages admin page.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageCatalog;
use ShapeTranslate\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Renders language listing and editor views.
 */
final class LanguagesPage {

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language setup catalog.
	 *
	 * @var LanguageCatalog
	 */
	private $language_catalog;

	/**
	 * Language flag registry.
	 *
	 * @var FlagRegistry
	 */
	private $flags;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager $language_manager Language manager.
	 * @param LanguageCatalog $language_catalog Language setup catalog.
	 */
	public function __construct( LanguageManager $language_manager, LanguageCatalog $language_catalog ) {
		$this->language_manager = $language_manager;
		$this->language_catalog = $language_catalog;
		$this->flags            = new FlagRegistry();
	}

	/**
	 * Renders the active page view.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage ShapeTranslate languages.', 'shapetranslate' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		echo '<div class="wrap shapetranslate-admin">';
		$this->render_notice();

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_editor( $action );
		} elseif ( ! $this->language_manager->has_languages() ) {
			$this->render_editor( 'setup' );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Renders the languages list.
	 *
	 * @return void
	 */
	private function render_list() {
		$languages = $this->language_manager->get_languages();
		$default   = $this->language_manager->get_default_id();
		$add_url   = add_query_arg(
			array(
				'page'   => 'shapetranslate',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="shapetranslate-heading">
			<h1><?php esc_html_e( 'Languages', 'shapetranslate' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $add_url ); ?>">
				<?php esc_html_e( 'Add Language', 'shapetranslate' ); ?>
			</a>
		</div>
		<hr class="wp-header-end">

		<table class="wp-list-table widefat fixed striped table-view-list shapetranslate-language-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Language', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Locale', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Code', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL slug', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Direction', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'shapetranslate' ); ?></th>
					<th scope="col" class="shapetranslate-order-column"><?php esc_html_e( 'Order', 'shapetranslate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $languages ) ) : ?>
					<tr class="no-items">
						<td colspan="7"><?php esc_html_e( 'No languages have been added yet.', 'shapetranslate' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $languages as $index => $language ) : ?>
						<?php $this->render_language_row( $language, $default, $index, count( $languages ) ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders one language row.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param string               $default_id Default language identifier.
	 * @param int                  $index    Row index.
	 * @param int                  $count    Total language count.
	 * @return void
	 */
	private function render_language_row( $language, $default_id, $index, $count ) {
		$is_default     = $default_id === $language['id'];
		$status_class   = ! empty( $language['enabled'] ) ? 'is-enabled' : 'is-disabled';
		$status_label   = ! empty( $language['enabled'] ) ? __( 'Enabled', 'shapetranslate' ) : __( 'Disabled', 'shapetranslate' );
		$direction      = ! empty( $language['is_rtl'] ) ? __( 'RTL', 'shapetranslate' ) : __( 'LTR', 'shapetranslate' );
		$toggle_label   = ! empty( $language['enabled'] ) ? __( 'Disable', 'shapetranslate' ) : __( 'Enable', 'shapetranslate' );
		$delete_confirm = __( 'Delete this language? This does not delete site content.', 'shapetranslate' );
		$edit_url       = add_query_arg(
			array(
				'page'        => 'shapetranslate',
				'action'      => 'edit',
				'language_id' => $language['id'],
			),
			admin_url( 'admin.php' )
		);

		?>
		<tr>
			<td class="column-primary" data-colname="<?php esc_attr_e( 'Language', 'shapetranslate' ); ?>">
				<strong>
					<?php echo $this->flags->get_flag_html( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by FlagRegistry::get_flag_html(). ?>
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $language['name'] ); ?></a>
				</strong>
				<span class="shapetranslate-native-name"><?php echo esc_html( $language['native_name'] ); ?></span>
				<div class="row-actions">
					<span class="edit">
						<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'shapetranslate' ); ?></a><?php echo $is_default ? '' : ' |'; ?>
					</span>
					<?php if ( ! $is_default ) : ?>
						<?php
						$this->render_action_form_start(
							'shapetranslate_delete_language',
							$language['id'],
							'shapetranslate_delete_language_' . $language['id'],
							'shapetranslate-inline-form shapetranslate-delete-form'
						);
						?>
						<button
							type="submit"
							class="button-link delete"
							data-shapetranslate-confirm="<?php echo esc_attr( $delete_confirm ); ?>"
						>
							<?php esc_html_e( 'Delete', 'shapetranslate' ); ?>
						</button>
						</form>
					<?php endif; ?>
				</div>
				<button type="button" class="toggle-row">
					<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'shapetranslate' ); ?></span>
				</button>
			</td>
			<td data-colname="<?php esc_attr_e( 'Locale', 'shapetranslate' ); ?>">
				<code><?php echo esc_html( $language['locale'] ); ?></code>
			</td>
			<td data-colname="<?php esc_attr_e( 'Code', 'shapetranslate' ); ?>">
				<code><?php echo esc_html( $language['language_code'] ); ?></code>
			</td>
			<td data-colname="<?php esc_attr_e( 'URL slug', 'shapetranslate' ); ?>">
				<code><?php echo esc_html( $language['url_slug'] ); ?></code>
			</td>
			<td data-colname="<?php esc_attr_e( 'Direction', 'shapetranslate' ); ?>">
				<?php echo esc_html( $direction ); ?>
			</td>
			<td data-colname="<?php esc_attr_e( 'Status', 'shapetranslate' ); ?>">
				<span class="shapetranslate-status <?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
				<?php if ( $is_default ) : ?>
					<span class="shapetranslate-default-label"><?php esc_html_e( 'Default', 'shapetranslate' ); ?></span>
				<?php endif; ?>
				<div class="shapetranslate-status-actions">
					<?php if ( ! $is_default ) : ?>
						<?php
						$this->render_simple_action(
							'shapetranslate_toggle_language',
							$language['id'],
							'shapetranslate_toggle_language_' . $language['id'],
							$toggle_label
						);
						?>
					<?php endif; ?>
					<?php if ( ! $is_default && ! empty( $language['enabled'] ) ) : ?>
						<?php
						$this->render_simple_action(
							'shapetranslate_set_default_language',
							$language['id'],
							'shapetranslate_set_default_language_' . $language['id'],
							__( 'Make default', 'shapetranslate' )
						);
						?>
					<?php endif; ?>
				</div>
			</td>
			<td class="shapetranslate-order-column" data-colname="<?php esc_attr_e( 'Order', 'shapetranslate' ); ?>">
				<?php $this->render_move_action( $language['id'], 'up', 0 === $index ); ?>
				<?php $this->render_move_action( $language['id'], 'down', $index === $count - 1 ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the add/edit form.
	 *
	 * @param string $action Current editor action.
	 * @return void
	 */
	private function render_editor( $action ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only record selection.
		$language_id = isset( $_GET['language_id'] ) ? sanitize_text_field( wp_unslash( $_GET['language_id'] ) ) : '';
		$language    = array(
			'name'          => '',
			'locale'        => '',
			'language_code' => '',
			'url_slug'      => '',
			'is_rtl'        => false,
			'native_name'   => '',
			'enabled'       => true,
			'domain'        => '',
		);

		if ( 'edit' === $action ) {
			$stored_language = $this->language_manager->find( $language_id );

			if ( null === $stored_language ) {
				echo '<div class="shapetranslate-notice notice notice-error"><p>';
				esc_html_e( 'The requested language could not be found.', 'shapetranslate' );
				echo '</p></div>';
				$this->render_list();
				return;
			}

			$language = wp_parse_args( $stored_language, $language );
		}

		$form_state = get_transient( 'shapetranslate_form_' . get_current_user_id() );

		if (
			is_array( $form_state )
			&& isset( $form_state['input'], $form_state['language_id'] )
			&& is_array( $form_state['input'] )
			&& $language_id === $form_state['language_id']
		) {
			$language = wp_parse_args( $form_state['input'], $language );
		}

		delete_transient( 'shapetranslate_form_' . get_current_user_id() );

		$list_url   = add_query_arg( 'page', 'shapetranslate', admin_url( 'admin.php' ) );
		$page_title = 'edit' === $action ? __( 'Edit Language', 'shapetranslate' ) : __( 'Add Language', 'shapetranslate' );
		$is_default = '' !== $language_id && $language_id === $this->language_manager->get_default_id();

		if ( 'setup' === $action ) {
			$page_title = __( 'Set Up ShapeTranslate', 'shapetranslate' );
		}
		?>
		<div class="shapetranslate-heading">
			<h1>
				<?php echo esc_html( $page_title ); ?>
			</h1>
		</div>
		<hr class="wp-header-end">

		<form
			class="shapetranslate-language-form"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		>
			<input type="hidden" name="action" value="shapetranslate_save_language">
			<input type="hidden" name="language_id" value="<?php echo esc_attr( $language_id ); ?>">
			<?php wp_nonce_field( 'shapetranslate_save_language_' . $language_id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php
					if ( 'edit' !== $action ) {
						$this->render_language_preset();
					}

					$this->render_text_field(
						'name',
						__( 'Name', 'shapetranslate' ),
						$language['name'],
						__( 'The administrative display name, for example French.', 'shapetranslate' ),
						true
					);
					$this->render_text_field(
						'native_name',
						__( 'Native name', 'shapetranslate' ),
						$language['native_name'],
						__( 'The language name as written by native speakers, for example Français.', 'shapetranslate' ),
						true
					);
					$this->render_text_field(
						'locale',
						__( 'Locale', 'shapetranslate' ),
						$language['locale'],
						__( 'A WordPress locale such as en_US, fr_FR, or de_DE_formal.', 'shapetranslate' ),
						true
					);
					$this->render_text_field(
						'language_code',
						__( 'Language code', 'shapetranslate' ),
						$language['language_code'],
						__( 'A two- or three-letter ISO 639 language code, such as en or fra.', 'shapetranslate' ),
						true
					);
					$this->render_text_field(
						'url_slug',
						__( 'URL slug', 'shapetranslate' ),
						$language['url_slug'],
						__( 'The unique path segment reserved for this language, such as en or french.', 'shapetranslate' ),
						true
					);
					$this->render_text_field(
						'domain',
						__( 'Domain', 'shapetranslate' ),
						isset( $language['domain'] ) ? (string) $language['domain'] : '',
						__( 'Only used when URL behavior is set to a separate domain per language, such as example.fr. Leave empty to serve this language from the site domain. The domain must already point at this WordPress installation.', 'shapetranslate' )
					);
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Text direction', 'shapetranslate' ); ?></th>
						<td>
							<label for="shapetranslate-is-rtl">
								<input type="hidden" name="language[is_rtl]" value="0">
								<input
									id="shapetranslate-is-rtl"
									type="checkbox"
									name="language[is_rtl]"
									value="1"
									<?php checked( ! empty( $language['is_rtl'] ) ); ?>
								>
								<?php esc_html_e( 'Right-to-left (RTL)', 'shapetranslate' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'shapetranslate' ); ?></th>
						<td>
							<label for="shapetranslate-enabled">
								<input
									type="hidden"
									name="language[enabled]"
									value="<?php echo esc_attr( $is_default ? '1' : '0' ); ?>"
								>
								<input
									id="shapetranslate-enabled"
									type="checkbox"
									name="language[enabled]"
									value="1"
									<?php checked( ! empty( $language['enabled'] ) ); ?>
									<?php disabled( $is_default ); ?>
								>
								<?php esc_html_e( 'Enabled', 'shapetranslate' ); ?>
							</label>
							<?php if ( $is_default ) : ?>
								<p class="description"><?php esc_html_e( 'The default language must remain enabled.', 'shapetranslate' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php
			if ( 'edit' === $action ) {
				$submit_label = __( 'Update Language', 'shapetranslate' );
			} elseif ( 'setup' === $action ) {
				$submit_label = __( 'Save and Continue', 'shapetranslate' );
			} else {
				$submit_label = __( 'Add Language', 'shapetranslate' );
			}

			submit_button( $submit_label );
			?>
			<?php if ( 'setup' !== $action ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $list_url ); ?>">
					<?php esc_html_e( 'Cancel', 'shapetranslate' ); ?>
				</a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Renders a WordPress language preset selector for setup and add views.
	 *
	 * @return void
	 */
	private function render_language_preset() {
		$catalog            = $this->language_catalog->get_languages();
		$registered         = $this->language_manager->get_languages();
		$registered_locales = wp_list_pluck( $registered, 'locale' );
		$used_slugs         = wp_list_pluck( $registered, 'url_slug' );
		$site_locale        = get_locale();
		?>
		<tr>
			<th scope="row">
				<label for="shapetranslate-language-preset"><?php esc_html_e( 'Choose a language', 'shapetranslate' ); ?></label>
			</th>
			<td>
				<div class="shapetranslate-flag-select" data-shapetranslate-flag-select data-search-label="<?php esc_attr_e( 'Search languages', 'shapetranslate' ); ?>" data-empty-text="<?php esc_attr_e( 'No languages found.', 'shapetranslate' ); ?>">
					<select id="shapetranslate-language-preset" class="regular-text">
						<option value=""><?php esc_html_e( 'Select a language', 'shapetranslate' ); ?></option>
						<?php foreach ( $catalog as $locale => $language ) : ?>
							<?php
							if ( in_array( $locale, $registered_locales, true ) ) {
								continue;
							}

							$suggested_slug = $this->suggest_slug( $language, $used_slugs );
							$flag_url       = $this->flags->get_flag_url( $language );
							$option_label   = $language['name'] === $language['native_name']
								? $language['name']
								: $language['name'] . ' - ' . $language['native_name'];
							?>
							<option
								value="<?php echo esc_attr( $locale ); ?>"
								data-name="<?php echo esc_attr( $language['name'] ); ?>"
								data-native-name="<?php echo esc_attr( $language['native_name'] ); ?>"
								data-language-code="<?php echo esc_attr( $language['language_code'] ); ?>"
								data-url-slug="<?php echo esc_attr( $suggested_slug ); ?>"
								data-is-rtl="<?php echo ! empty( $language['is_rtl'] ) ? '1' : '0'; ?>"
								data-flag="<?php echo esc_url( $flag_url ); ?>"
								<?php selected( $site_locale, $locale ); ?>
							>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<p class="description">
					<?php esc_html_e( 'Selecting a preset fills the editable language fields below.', 'shapetranslate' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Suggests a URL slug that does not collide with an existing language.
	 *
	 * @param array<string, mixed> $language  Catalog language.
	 * @param array<int, string>   $used_slugs Existing URL slugs.
	 * @return string
	 */
	private function suggest_slug( $language, $used_slugs ) {
		$slug = $language['url_slug'];

		if ( ! in_array( $slug, $used_slugs, true ) ) {
			return $slug;
		}

		$slug   = sanitize_title( str_replace( '_', '-', $language['locale'] ) );
		$base   = $slug;
		$suffix = 2;

		while ( in_array( $slug, $used_slugs, true ) ) {
			$slug = $base . '-' . $suffix;
			++$suffix;
		}

		return $slug;
	}

	/**
	 * Renders a standard language text field.
	 *
	 * @param string $field       Field key.
	 * @param string $label       Field label.
	 * @param mixed  $value       Current value.
	 * @param string $description Field help text.
	 * @param bool   $required    Whether the field is required.
	 * @return void
	 */
	private function render_text_field( $field, $label, $value, $description, $required = false ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		?>
		<tr>
			<th scope="row">
				<label for="shapetranslate-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					id="shapetranslate-<?php echo esc_attr( $field ); ?>"
					class="regular-text"
					type="text"
					name="language[<?php echo esc_attr( $field ); ?>]"
					value="<?php echo esc_attr( $value ); ?>"
					<?php if ( $required ) : ?>
						required
					<?php endif; ?>
				>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Starts an inline admin-post action form.
	 *
	 * @param string $action      Admin-post action.
	 * @param string $language_id Language identifier.
	 * @param string $nonce       Nonce action.
	 * @param string $class_name  Form classes.
	 * @return void
	 */
	private function render_action_form_start( $action, $language_id, $nonce, $class_name = 'shapetranslate-inline-form' ) {
		?>
		<form
			class="<?php echo esc_attr( $class_name ); ?>"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="language_id" value="<?php echo esc_attr( $language_id ); ?>">
			<?php wp_nonce_field( $nonce ); ?>
		<?php
	}

	/**
	 * Renders a text-link action form.
	 *
	 * @param string $action      Admin-post action.
	 * @param string $language_id Language identifier.
	 * @param string $nonce       Nonce action.
	 * @param string $label       Button label.
	 * @return void
	 */
	private function render_simple_action( $action, $language_id, $nonce, $label ) {
		$this->render_action_form_start( $action, $language_id, $nonce );
		?>
		<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders an ordering button.
	 *
	 * @param string $language_id Language identifier.
	 * @param string $direction   Move direction.
	 * @param bool   $disabled    Whether movement is unavailable.
	 * @return void
	 */
	private function render_move_action( $language_id, $direction, $disabled ) {
		$label = 'up' === $direction ? __( 'Move up', 'shapetranslate' ) : __( 'Move down', 'shapetranslate' );
		$icon  = 'up' === $direction ? 'arrow-up-alt2' : 'arrow-down-alt2';

		$this->render_action_form_start(
			'shapetranslate_move_language',
			$language_id,
			'shapetranslate_move_language_' . $language_id
		);
		?>
		<input type="hidden" name="direction" value="<?php echo esc_attr( $direction ); ?>">
		<button
			type="submit"
			class="button shapetranslate-icon-button"
			title="<?php echo esc_attr( $label ); ?>"
			<?php disabled( $disabled ); ?>
		>
			<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
		</button>
		</form>
		<?php
	}

	/**
	 * Renders a controlled success or error notice from query state.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted notice codes.
		$notice_code = isset( $_GET['lp_notice'] ) ? sanitize_key( wp_unslash( $_GET['lp_notice'] ) ) : '';
		$error_code   = isset( $_GET['lp_error'] ) ? sanitize_key( wp_unslash( $_GET['lp_error'] ) ) : '';
		$warning_code = isset( $_GET['lp_warning'] ) ? sanitize_key( wp_unslash( $_GET['lp_warning'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$notices  = $this->notice_messages();
		$errors   = $this->error_messages();
		$warnings = $this->warning_messages();

		if ( isset( $notices[ $notice_code ] ) ) {
			printf(
				'<div class="shapetranslate-notice notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $notices[ $notice_code ] )
			);
		}

		if ( isset( $warnings[ $warning_code ] ) ) {
			printf(
				'<div class="shapetranslate-notice notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( $warnings[ $warning_code ] )
			);
		}

		if ( isset( $errors[ $error_code ] ) ) {
			printf(
				'<div class="shapetranslate-notice notice notice-error"><p>%s</p></div>',
				esc_html( $errors[ $error_code ] )
			);
		}
	}

	/**
	 * Returns allowed success notices.
	 *
	 * @return array<string, string>
	 */
	private function notice_messages() {
		return array(
			'setup_complete'   => __( 'ShapeTranslate setup is complete.', 'shapetranslate' ),
			'language_added'   => __( 'Language added.', 'shapetranslate' ),
			'language_updated' => __( 'Language updated.', 'shapetranslate' ),
			'language_deleted' => __( 'Language deleted.', 'shapetranslate' ),
			'default_updated'  => __( 'Default language updated.', 'shapetranslate' ),
			'status_updated'   => __( 'Language status updated.', 'shapetranslate' ),
			'order_updated'    => __( 'Language order updated.', 'shapetranslate' ),
		);
	}

	/**
	 * Returns allowed warnings.
	 *
	 * @return array<string, string>
	 */
	private function warning_messages() {
		return array(
			'language_pack_failed' => __( 'The language was added, but the WordPress language files could not be downloaded. Install them from Settings → General, or manually.', 'shapetranslate' ),
		);
	}

	/**
	 * Returns allowed validation and operation errors.
	 *
	 * @return array<string, string>
	 */
	private function error_messages() {
		return array(
			'missing_name'            => __( 'Language name is required.', 'shapetranslate' ),
			'missing_native_name'     => __( 'Native name is required.', 'shapetranslate' ),
			'name_too_long'           => __( 'Language names must be 100 characters or fewer.', 'shapetranslate' ),
			'invalid_locale'          => __( 'Enter a valid WordPress locale, such as en_US or pt_BR.', 'shapetranslate' ),
			'invalid_language_code'   => __( 'Language code must contain two or three lowercase letters.', 'shapetranslate' ),
			'invalid_url_slug'        => __( 'URL slug is required and must be 80 characters or fewer.', 'shapetranslate' ),
			'duplicate_locale'        => __( 'That locale is already registered.', 'shapetranslate' ),
			'duplicate_url_slug'      => __( 'That URL slug is already in use.', 'shapetranslate' ),
			'language_not_found'      => __( 'The requested language could not be found.', 'shapetranslate' ),
			'default_must_be_enabled' => __( 'Enable a language before making it the default.', 'shapetranslate' ),
			'cannot_disable_default'  => __( 'The default language cannot be disabled.', 'shapetranslate' ),
			'invalid_direction'       => __( 'Invalid language ordering direction.', 'shapetranslate' ),
			'invalid_language_data'   => __( 'Language data is invalid.', 'shapetranslate' ),
			'language_in_use'         => __( 'This language is assigned to content and cannot be deleted.', 'shapetranslate' ),
		);
	}
}
