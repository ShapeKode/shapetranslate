<?php
/**
 * ShapeTranslate setup wizard page.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Language\FlagRegistry;
use ShapeTranslate\Language\LanguageCatalog;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Routing\LanguageHostResolver;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the five-step setup workflow.
 */
final class SetupWizardPage {

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language catalog.
	 *
	 * @var LanguageCatalog
	 */
	private $catalog;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

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
	 * @param LanguageCatalog $catalog          Language catalog.
	 * @param PluginSettings  $settings         Central plugin settings.
	 */
	public function __construct( LanguageManager $language_manager, LanguageCatalog $catalog, PluginSettings $settings ) {
		$this->language_manager = $language_manager;
		$this->catalog          = $catalog;
		$this->settings         = $settings;
		$this->flags            = new FlagRegistry();
	}

	/** Renders the current wizard step. */
	public function render() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to set up ShapeTranslate.', 'shapetranslate' ), '', array( 'response' => 403 ) );
		}

		$step = $this->current_step();
		?>
		<div class="wrap shapetranslate-admin shapetranslate-setup-wizard">
			<h1><?php esc_html_e( 'Set Up ShapeTranslate', 'shapetranslate' ); ?></h1>
			<?php $this->render_notice(); ?>
			<?php $this->render_progress( $step ); ?>
			<div class="shapetranslate-wizard-content">
				<?php $this->render_step( $step ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders one wizard step.
	 *
	 * @param int $step Current step.
	 */
	private function render_step( $step ) {
		$method = 'render_step_' . $step;
		$this->{$method}();
	}

	/** Selects the site default language. */
	private function render_step_1() {
		$languages = $this->language_manager->get_languages();
		$catalog   = $this->catalog->get_languages();
		?>
		<h2><?php esc_html_e( 'Site Language', 'shapetranslate' ); ?></h2>
		<p><?php esc_html_e( 'Choose the language used by your existing content.', 'shapetranslate' ); ?></p>
		<?php $this->form_start( 1 ); ?>
		<table class="form-table" role="presentation"><tbody><tr>
			<th scope="row"><label for="shapetranslate-setup-language"><?php esc_html_e( 'Default language', 'shapetranslate' ); ?></label></th>
			<td><div class="shapetranslate-flag-select" data-shapetranslate-flag-select data-search-label="<?php esc_attr_e( 'Search languages', 'shapetranslate' ); ?>" data-empty-text="<?php esc_attr_e( 'No languages found.', 'shapetranslate' ); ?>"><select id="shapetranslate-setup-language" name="<?php echo empty( $languages ) ? 'locale' : 'language_id'; ?>" class="regular-text">
			<?php if ( empty( $languages ) ) : ?>
				<?php
				foreach ( $catalog as $locale => $language ) :
					?>
					<option value="<?php echo esc_attr( $locale ); ?>" data-flag="<?php echo esc_url( $this->flags->get_flag_url( $language ) ); ?>" <?php selected( get_locale(), $locale ); ?>><?php echo esc_html( $language['name'] . ' - ' . $language['native_name'] ); ?></option><?php endforeach; ?>
			<?php else : ?>
				<?php
				foreach ( $languages as $language ) :
					?>
					<option value="<?php echo esc_attr( $language['id'] ); ?>" data-flag="<?php echo esc_url( $this->flags->get_flag_url( $language ) ); ?>" <?php selected( $this->language_manager->get_default_id(), $language['id'] ); ?>><?php echo esc_html( $language['native_name'] . ' (' . $language['locale'] . ')' ); ?></option><?php endforeach; ?>
			<?php endif; ?>
			</select></div></td>
		</tr></tbody></table>
		<?php $this->form_end( __( 'Continue', 'shapetranslate' ) ); ?>
		<?php
	}

	/** Builds the language list and marks the default. */
	private function render_step_2() {
		$languages = $this->language_manager->get_languages();
		$default   = $this->language_manager->get_default_id();
		$catalog   = $this->catalog->get_languages();
		$used      = wp_list_pluck( $languages, 'locale' );
		?>
		<h2><?php esc_html_e( 'Languages', 'shapetranslate' ); ?></h2>
		<p><?php esc_html_e( 'Add every language this site will be translated into, and mark the one your existing content is written in. More can be added later from the Languages screen.', 'shapetranslate' ); ?></p>
		<?php $this->form_start( 2 ); ?>
		<table class="widefat striped shapetranslate-wizard-languages">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Language', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Locale', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL slug', 'shapetranslate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Default', 'shapetranslate' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'shapetranslate' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $languages as $language ) : ?>
				<?php
				$make_default = sprintf(
					/* translators: %s: language name. */
					__( 'Make %s the default language', 'shapetranslate' ),
					$language['native_name']
				);
				$confirm = sprintf(
					/* translators: %s: language name. */
					__( 'Remove %s? Content already assigned to it keeps its language until the content itself is deleted.', 'shapetranslate' ),
					$language['native_name']
				);
				// The default language stays, so the site keeps the language its
				// untranslated content is written in.
				$is_default = $default === $language['id'];
				?>
				<tr>
					<td>
						<span class="shapetranslate-language-code">
							<?php echo $this->flags->get_flag_html( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by FlagRegistry::get_flag_html(). ?>
							<?php echo esc_html( $language['native_name'] ); ?>
						</span>
					</td>
					<td><code><?php echo esc_html( $language['locale'] ); ?></code></td>
					<td><code><?php echo esc_html( $language['url_slug'] ); ?></code></td>
					<td>
						<input
							type="radio"
							name="default_language"
							value="<?php echo esc_attr( $language['id'] ); ?>"
							<?php checked( $default, $language['id'] ); ?>
							aria-label="<?php echo esc_attr( $make_default ); ?>"
						/>
					</td>
					<td>
						<?php if ( ! $is_default ) : ?>
							<a
								class="shapetranslate-wizard-remove"
								href="<?php echo esc_url( $this->remove_language_url( $language['id'] ) ); ?>"
								data-shapetranslate-confirm="<?php echo esc_attr( $confirm ); ?>"
							><?php esc_html_e( 'Remove', 'shapetranslate' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="shapetranslate-wizard-add">
			<label for="shapetranslate-add-language"><?php esc_html_e( 'Add a language', 'shapetranslate' ); ?></label>
			<div class="shapetranslate-flag-select" data-shapetranslate-flag-select data-search-label="<?php esc_attr_e( 'Search languages', 'shapetranslate' ); ?>" data-empty-text="<?php esc_attr_e( 'No languages found.', 'shapetranslate' ); ?>">
				<select id="shapetranslate-add-language" name="add_locale">
					<option value=""><?php esc_html_e( 'Select a language', 'shapetranslate' ); ?></option>
					<?php foreach ( $catalog as $locale => $language ) : ?>
						<?php if ( ! in_array( $locale, $used, true ) ) : ?>
							<option value="<?php echo esc_attr( $locale ); ?>" data-flag="<?php echo esc_url( $this->flags->get_flag_url( $language ) ); ?>"><?php echo esc_html( $language['name'] . ' - ' . $language['native_name'] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="submit" class="button" name="wizard_action" value="add"><?php esc_html_e( 'Add language', 'shapetranslate' ); ?></button>
		</div>
		<?php $this->form_end( __( 'Continue', 'shapetranslate' ), 1, 'continue' ); ?>
		<?php
	}

	/**
	 * Returns the nonce-protected URL that removes one language from setup.
	 *
	 * Removal is a link rather than another submit button in the language form.
	 * A browser submits the first submit button when Enter is pressed in a field,
	 * and with a Remove button per row that key would delete a language instead of
	 * moving on.
	 *
	 * @param string $language_id Language identifier.
	 * @return string
	 */
	private function remove_language_url( $language_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'shapetranslate_setup_remove_language',
					'language' => $language_id,
				),
				admin_url( 'admin-post.php' )
			),
			'shapetranslate_setup_remove_language_' . $language_id
		);
	}

	/** Chooses how a language appears in public URLs. */
	private function render_step_3() {
		$url       = $this->settings->get_section( 'url' );
		$mode      = isset( $url['mode'] ) ? (string) $url['mode'] : LanguageHostResolver::MODE_DIRECTORY;
		$languages = $this->language_manager->get_languages();
		$hosts     = new LanguageHostResolver( $this->settings );
		$base      = $hosts->get_base_host();
		$sample    = empty( $languages ) ? 'en' : $languages[0]['url_slug'];
		$site      = untrailingslashit( LanguageHostResolver::site_url() );
		$scheme    = 0 === strpos( $site, 'https://' ) ? 'https://' : 'http://';
		?>
		<h2><?php esc_html_e( 'Language URLs', 'shapetranslate' ); ?></h2>
		<div class="shapetranslate-notice notice notice-warning inline"><p><?php esc_html_e( 'This choice sets public URLs and canonical links. Changing it later may require link and cache updates.', 'shapetranslate' ); ?></p></div>
		<?php $this->form_start( 3 ); ?>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'URL format', 'shapetranslate' ); ?></th>
				<td>
					<fieldset class="shapetranslate-option-list shapetranslate-option-list--examples" data-shapetranslate-url-mode>
						<label>
							<input type="radio" name="url_mode" value="directory" <?php checked( $mode, LanguageHostResolver::MODE_DIRECTORY ); ?> />
							<?php esc_html_e( 'The language is set from the directory name', 'shapetranslate' ); ?>
							<code><?php echo esc_html( $site . '/' . $sample . '/my-post/' ); ?></code>
						</label>
						<label>
							<input type="radio" name="url_mode" value="subdomain" <?php checked( $mode, LanguageHostResolver::MODE_SUBDOMAIN ); ?> />
							<?php esc_html_e( 'The language is set from a subdomain', 'shapetranslate' ); ?>
							<code><?php echo esc_html( $scheme . $sample . '.' . $base . '/my-post/' ); ?></code>
						</label>
						<label>
							<input type="radio" name="url_mode" value="domain" <?php checked( $mode, LanguageHostResolver::MODE_DOMAIN ); ?> />
							<?php esc_html_e( 'The language is set from a separate domain', 'shapetranslate' ); ?>
							<code><?php echo esc_html( $scheme . 'example.fr/my-post/' ); ?></code>
						</label>
						<label>
							<input type="radio" name="url_mode" value="query" <?php checked( $mode, LanguageHostResolver::MODE_QUERY ); ?> />
							<?php esc_html_e( 'The language is set from a query argument', 'shapetranslate' ); ?>
							<code><?php echo esc_html( $site . '/my-post/?' . LanguageUrlManager::PUBLIC_QUERY_VAR . '=' . $sample ); ?></code>
						</label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Subdomains and separate domains must already point at this WordPress installation, each with its own certificate. The query argument is the only format that works on a site without pretty permalinks.', 'shapetranslate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default language', 'shapetranslate' ); ?></th>
				<td><fieldset class="shapetranslate-option-list shapetranslate-option-list--examples">
					<label>
						<input type="radio" name="prefix_default" value="0" <?php checked( empty( $url['prefix_default'] ) ); ?> />
						<?php esc_html_e( 'Hide the language for the default language', 'shapetranslate' ); ?>
						<code><?php echo esc_html( $site . '/my-post/' ); ?></code>
					</label>
					<label>
						<input type="radio" name="prefix_default" value="1" <?php checked( ! empty( $url['prefix_default'] ) ); ?> />
						<?php esc_html_e( 'Show the language for every language', 'shapetranslate' ); ?>
						<code><?php echo esc_html( $site . '/' . $sample . '/my-post/' ); ?></code>
					</label>
				</fieldset></td>
			</tr>
			<tr class="shapetranslate-domain-panel" data-shapetranslate-mode-panel="domain" <?php echo LanguageHostResolver::MODE_DOMAIN === $mode ? '' : 'hidden'; ?>>
				<th scope="row"><?php esc_html_e( 'Domains', 'shapetranslate' ); ?></th>
				<td>
					<fieldset class="shapetranslate-option-list">
					<?php foreach ( $languages as $language ) : ?>
						<label>
							<span class="shapetranslate-language-code">
								<?php echo $this->flags->get_flag_html( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized by FlagRegistry::get_flag_html(). ?>
								<?php echo esc_html( $language['native_name'] ); ?>
							</span>
							<input
								type="text"
								class="regular-text"
								name="domain[<?php echo esc_attr( $language['id'] ); ?>]"
								value="<?php echo esc_attr( isset( $language['domain'] ) ? (string) $language['domain'] : '' ); ?>"
								placeholder="<?php echo esc_attr( 'https://example.' . $language['url_slug'] ); ?>"
							/>
						</label>
					<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'A language left empty is served from the site domain. Domains are saved whichever format is selected, so they can be filled in before the switch.', 'shapetranslate' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php $this->form_end( __( 'Continue', 'shapetranslate' ), 2 ); ?>
		<?php
	}

	/** Configures and optionally inserts the switcher. */
	private function render_step_4() {
		$switcher = $this->settings->get_section( 'switcher' );
		$menus    = current_user_can( 'edit_theme_options' ) ? wp_get_nav_menus() : array();
		?>
		<h2><?php esc_html_e( 'Language Switcher', 'shapetranslate' ); ?></h2>
		<?php $this->form_start( 4 ); ?>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="shapetranslate-setup-display"><?php esc_html_e( 'Label', 'shapetranslate' ); ?></label></th><td><select id="shapetranslate-setup-display" name="display"><option value="native_name" <?php selected( $switcher['display'], 'native_name' ); ?>><?php esc_html_e( 'Native name', 'shapetranslate' ); ?></option><option value="name" <?php selected( $switcher['display'], 'name' ); ?>><?php esc_html_e( 'Language name', 'shapetranslate' ); ?></option><option value="language_code" <?php selected( $switcher['display'], 'language_code' ); ?>><?php esc_html_e( 'Language code', 'shapetranslate' ); ?></option><option value="flag" <?php selected( $switcher['display'], 'flag' ); ?>><?php esc_html_e( 'Flag only', 'shapetranslate' ); ?></option></select></td></tr>
			<tr><th scope="row"><label for="shapetranslate-setup-layout"><?php esc_html_e( 'Layout', 'shapetranslate' ); ?></label></th><td><select id="shapetranslate-setup-layout" name="layout"><option value="horizontal" <?php selected( $switcher['layout'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal list', 'shapetranslate' ); ?></option><option value="vertical" <?php selected( $switcher['layout'], 'vertical' ); ?>><?php esc_html_e( 'Vertical list', 'shapetranslate' ); ?></option><option value="dropdown" <?php selected( $switcher['layout'], 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'shapetranslate' ); ?></option></select></td></tr>
			<tr><th scope="row"><label for="shapetranslate-setup-menu"><?php esc_html_e( 'Add to menu', 'shapetranslate' ); ?></label></th><td><select id="shapetranslate-setup-menu" name="menu_id"><option value="0"><?php esc_html_e( 'Do not add to a menu', 'shapetranslate' ); ?></option>
			<?php
			foreach ( $menus as $menu ) :
				?>
				<option value="<?php echo esc_attr( $menu->term_id ); ?>"><?php echo esc_html( $menu->name ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'The switcher can also be added later with the block, shortcode, or Appearance > Menus.', 'shapetranslate' ); ?></p></td></tr>
		</tbody></table>
		<?php $this->form_end( __( 'Continue', 'shapetranslate' ), 3 ); ?>
		<?php
	}

	/** Displays the completion summary. */
	private function render_step_5() {
		$languages = $this->language_manager->get_languages( true );
		?>
		<h2><?php esc_html_e( 'Ready to Translate', 'shapetranslate' ); ?></h2>
		<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of enabled languages. */
				_n( '%d language is enabled.', '%d languages are enabled.', count( $languages ), 'shapetranslate' ),
				count( $languages )
			)
		);
		?>
		</p>
		<p><code>[shapetranslate_switcher]</code></p>
		<p class="description"><?php esc_html_e( 'A single placement can override these settings by passing them along with it. Settings > Switcher writes that snippet for you.', 'shapetranslate' ); ?></p>
		<?php $this->form_start( 5 ); ?>
		<?php $this->form_end( __( 'Finish Setup', 'shapetranslate' ), 4 ); ?>
		<p class="shapetranslate-wizard-links"><a href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate' ) ); ?>"><?php esc_html_e( 'Languages', 'shapetranslate' ); ?></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate-translations' ) ); ?>"><?php esc_html_e( 'Translations', 'shapetranslate' ); ?></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'shapetranslate' ); ?></a></p>
		<?php
	}

	/**
	 * Opens one wizard form with its nonce.
	 *
	 * @param int $step Step.
	 */
	private function form_start( $step ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="shapetranslate_setup_step" />
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
			<?php wp_nonce_field( 'shapetranslate_setup_step_' . $step ); ?>
		<?php
	}

	/**
	 * Closes one wizard form with navigation actions.
	 *
	 * @param string $label     Button label.
	 * @param int    $back_step Back step.
	 * @param string $action    Optional wizard action carried by the submit button.
	 */
	private function form_end( $label, $back_step = 0, $action = '' ) {
		?>
		<p class="submit shapetranslate-wizard-actions">
			<?php
			if ( 0 < $back_step ) :
				?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate-setup&step=' . $back_step ) ); ?>"><?php esc_html_e( 'Back', 'shapetranslate' ); ?></a><?php endif; ?>
			<button type="submit" class="button button-primary" <?php echo '' === $action ? '' : 'name="wizard_action" value="' . esc_attr( $action ) . '"'; ?>><?php echo esc_html( $label ); ?></button>
		</p></form>
		<?php
	}

	/**
	 * Renders the setup progress indicator.
	 *
	 * @param int $current Current step.
	 */
	private function render_progress( $current ) {
		$labels = array( __( 'Site Language', 'shapetranslate' ), __( 'Languages', 'shapetranslate' ), __( 'URL Format', 'shapetranslate' ), __( 'Switcher', 'shapetranslate' ), __( 'Finish', 'shapetranslate' ) );
		?>
		<ol class="shapetranslate-wizard-progress">
		<?php
		foreach ( $labels as $index => $label ) :
			?>
			<?php $step = $index + 1; ?><li class="<?php echo $step === $current ? 'is-current' : ( $step < $current ? 'is-complete' : '' ); ?>" <?php echo $step === $current ? 'aria-current="step"' : ''; ?>><span><?php echo esc_html( $step ); ?></span><?php echo esc_html( $label ); ?></li><?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Returns the requested or resumable setup step.
	 *
	 * @return int
	 */
	private function current_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only wizard navigation.
		$step = isset( $_GET['step'] ) && is_scalar( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : $this->settings->get_setup_step();

		return min( 5, max( 1, $step ) );
	}

	/** Renders a wizard notice. */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted notice.
		$notice = isset( $_GET['shapetranslate_notice'] ) ? sanitize_key( wp_unslash( $_GET['shapetranslate_notice'] ) ) : '';

		if ( 'setup_error' === $notice ) {
			$key     = 'shapetranslate_setup_error_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );
			printf( '<div class="shapetranslate-notice notice notice-error"><p>%s</p></div>', esc_html( is_string( $message ) ? $message : __( 'ShapeTranslate could not save this setup step.', 'shapetranslate' ) ) );
		} elseif ( 'setup_complete' === $notice ) {
			echo '<div class="shapetranslate-notice notice notice-success"><p>' . esc_html__( 'ShapeTranslate setup is complete.', 'shapetranslate' ) . '</p></div>';
		}
	}
}
