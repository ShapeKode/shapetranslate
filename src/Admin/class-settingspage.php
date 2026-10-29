<?php
/**
 * ShapeTranslate settings page.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Content\PostTypeSupport;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Navigation\MenuLanguageManager;
use ShapeTranslate\Routing\LanguageHostResolver;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Settings\SyncCatalog;
use ShapeTranslate\Settings\WorkflowSettings;
use ShapeTranslate\Taxonomy\TaxonomySupport;
use ShapeTranslate\Taxonomy\TermTranslationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the tabbed ShapeTranslate configuration experience.
 */
final class SettingsPage {

	/**
	 * Largest number of terms offered per default term chooser.
	 *
	 * @var int
	 */
	const TERM_CHOICE_LIMIT = 500;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Menu language manager.
	 *
	 * @var MenuLanguageManager
	 */
	private $menu_manager;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings
	 */
	private $workflow_settings;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Post type support policy.
	 *
	 * @var PostTypeSupport
	 */
	private $post_type_support;

	/**
	 * Taxonomy support policy.
	 *
	 * @var TaxonomySupport
	 */
	private $taxonomy_support;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Constructor.
	 *
	 * @param LanguageManager        $language_manager  Language manager.
	 * @param MenuLanguageManager    $menu_manager      Menu language manager.
	 * @param WorkflowSettings       $workflow_settings Translation workflow settings.
	 * @param PluginSettings         $settings          Central plugin settings.
	 * @param PostTypeSupport        $post_type_support Post type support policy.
	 * @param TaxonomySupport        $taxonomy_support  Taxonomy support policy.
	 * @param TermTranslationManager $term_translations Term relationship manager.
	 */
	public function __construct(
		LanguageManager $language_manager,
		MenuLanguageManager $menu_manager,
		WorkflowSettings $workflow_settings,
		PluginSettings $settings,
		PostTypeSupport $post_type_support,
		TaxonomySupport $taxonomy_support,
		TermTranslationManager $term_translations
	) {
		$this->language_manager  = $language_manager;
		$this->menu_manager      = $menu_manager;
		$this->workflow_settings = $workflow_settings;
		$this->settings          = $settings;
		$this->post_type_support = $post_type_support;
		$this->taxonomy_support  = $taxonomy_support;
		$this->term_translations = $term_translations;
	}

	/**
	 * Returns allowlisted settings tabs.
	 *
	 * @return array<int, string>
	 */
	public static function tab_keys() {
		return array( 'general', 'url', 'content', 'sync', 'switcher', 'seo', 'advanced' );
	}

	/** Renders the settings screen. */
	public function render() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage ShapeTranslate settings.', 'shapetranslate' ), '', array( 'response' => 403 ) );
		}

		$tab = $this->current_tab();
		?>
		<div class="wrap shapetranslate-admin shapetranslate-settings">
			<h1><?php esc_html_e( 'ShapeTranslate Settings', 'shapetranslate' ); ?></h1>
			<?php $this->render_notice(); ?>
			<?php $this->render_tabs( $tab ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shapetranslate_save_settings" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( 'shapetranslate_save_settings' ); ?>
				<?php $this->render_tab( $tab ); ?>
				<?php submit_button( __( 'Save Changes', 'shapetranslate' ) ); ?>
			</form>

			<?php if ( 'advanced' === $tab ) : ?>
				<?php $this->render_transfer_tools(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders one settings section.
	 *
	 * @param string $tab Active tab.
	 */
	private function render_tab( $tab ) {
		$method = 'render_' . $tab;

		if ( method_exists( $this, $method ) ) {
			$this->{$method}();
		}
	}

	/** Renders default and enabled language controls. */
	private function render_general() {
		$languages = $this->language_manager->get_languages();
		$default   = $this->language_manager->get_default_id();
		?>
		<h2><?php esc_html_e( 'General', 'shapetranslate' ); ?></h2>
		<?php if ( empty( $languages ) ) : ?>
			<div class="shapetranslate-notice notice notice-warning inline"><p>
				<?php esc_html_e( 'Add a language before configuring ShapeTranslate.', 'shapetranslate' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate-setup' ) ); ?>"><?php esc_html_e( 'Run setup', 'shapetranslate' ); ?></a>
			</p></div>
		<?php else : ?>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="shapetranslate-default-language"><?php esc_html_e( 'Default language', 'shapetranslate' ); ?></label></th>
					<td>
						<select id="shapetranslate-default-language" name="default_language">
							<?php foreach ( $languages as $language ) : ?>
								<option value="<?php echo esc_attr( $language['id'] ); ?>" <?php selected( $default, $language['id'] ); ?>>
									<?php echo esc_html( $language['native_name'] . ' (' . $language['locale'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Unassigned content and unprefixed requests use this language.', 'shapetranslate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled languages', 'shapetranslate' ); ?></th>
					<td>
						<fieldset class="shapetranslate-option-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'Enabled languages', 'shapetranslate' ); ?></legend>
							<?php foreach ( $languages as $language ) : ?>
								<label>
									<input type="checkbox" name="enabled_languages[]" value="<?php echo esc_attr( $language['id'] ); ?>" <?php checked( ! empty( $language['enabled'] ) ); ?> />
									<?php echo esc_html( $language['native_name'] . ' (' . strtoupper( $language['language_code'] ) . ')' ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Disabling a language hides its routes and switcher entry but does not delete its translations.', 'shapetranslate' ); ?></p>
					</td>
				</tr>
			</tbody></table>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate' ) ); ?>"><?php esc_html_e( 'Manage language details and ordering', 'shapetranslate' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/** Renders URL behavior controls. */
	private function render_url() {
		$url  = $this->settings->get_section( 'url' );
		$mode = isset( $url['mode'] ) ? (string) $url['mode'] : LanguageHostResolver::MODE_DIRECTORY;
		?>
		<h2><?php esc_html_e( 'URL', 'shapetranslate' ); ?></h2>
		<div class="shapetranslate-notice notice notice-warning inline"><p><strong><?php esc_html_e( 'Changing URL behavior changes public canonical URLs.', 'shapetranslate' ); ?></strong> <?php esc_html_e( 'Update navigation links and clear page or CDN caches after saving.', 'shapetranslate' ); ?></p></div>
		<?php if ( '' === (string) get_option( 'permalink_structure' ) && LanguageHostResolver::MODE_QUERY !== $mode ) : ?>
			<div class="shapetranslate-notice notice notice-error inline"><p><?php esc_html_e( 'Language directories require pretty permalinks. Choose a permalink structure in WordPress, or select the query argument format below, which works without one.', 'shapetranslate' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><label for="shapetranslate-url-mode"><?php esc_html_e( 'Language URL behavior', 'shapetranslate' ); ?></label></th>
				<td>
					<?php
					$base_host = ( new LanguageHostResolver( $this->settings ) )->get_base_host();
					$base_host = '' === $base_host ? 'example.com' : $base_host;
					?>
					<select id="shapetranslate-url-mode" name="url_mode">
						<option value="directory" <?php selected( $mode, 'directory' ); ?>><?php esc_html_e( 'Language directories (/en/)', 'shapetranslate' ); ?></option>
						<option value="subdomain" <?php selected( $mode, 'subdomain' ); ?>>
							<?php
							printf(
								/* translators: %s: the site's base domain. */
								esc_html__( 'A subdomain per language (en.%s)', 'shapetranslate' ),
								esc_html( $base_host )
							);
							?>
						</option>
						<option value="domain" <?php selected( $mode, 'domain' ); ?>><?php esc_html_e( 'A separate domain per language (example.fr)', 'shapetranslate' ); ?></option>
						<option value="query" <?php selected( $mode, 'query' ); ?>>
							<?php
							printf(
								/* translators: %s: the language query argument, such as ?lang=en. */
								esc_html__( 'A query argument (%s)', 'shapetranslate' ),
								esc_html( '?' . LanguageUrlManager::PUBLIC_QUERY_VAR . '=en' )
							);
							?>
						</option>
					</select>
					<p class="description"><?php esc_html_e( 'Subdomains are built from each language URL slug. Separate domains are set on each language in Languages, and every host must already point at this WordPress installation with its own certificate.', 'shapetranslate' ); ?></p>
					<?php if ( in_array( $mode, array( 'subdomain', 'domain' ), true ) ) : ?>
						<p class="description"><?php esc_html_e( 'A page served from a language host also loads its images, scripts, and search results from that host, so nothing is blocked as a cross-origin request. Logging in and the admin stay on the site address, which is where the session lives.', 'shapetranslate' ); ?></p>
						<?php if ( ! empty( $url['prefix_default'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'While every language is prefixed, the site address itself serves no language and forwards to the default one. Point each language host at this server before saving.', 'shapetranslate' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'The query argument leaves every address exactly as WordPress builds it and names the language beside it, so it is the one format that also works without pretty permalinks. Directories read better and are the better choice when the site has them.', 'shapetranslate' ); ?></p>
					<?php if ( 'domain' === $mode ) : ?>
						<?php
						$missing = array();

						foreach ( $this->language_manager->get_languages() as $language ) {
							if ( empty( $language['domain'] ) ) {
								$missing[] = $language['name'];
							}
						}
						?>
						<?php if ( ! empty( $missing ) ) : ?>
							<p class="description"><strong>
								<?php
								printf(
									/* translators: %s: comma-separated language names. */
									esc_html__( 'Served from the site domain until a domain is set: %s', 'shapetranslate' ),
									esc_html( implode( ', ', $missing ) )
								);
								?>
							</strong></p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default language prefix', 'shapetranslate' ); ?></th>
				<td><fieldset class="shapetranslate-option-list">
					<label><input type="radio" name="prefix_default" value="1" <?php checked( ! empty( $url['prefix_default'] ) ); ?> /> <?php esc_html_e( 'Prefix every language, including the default', 'shapetranslate' ); ?></label>
					<label><input type="radio" name="prefix_default" value="0" <?php checked( empty( $url['prefix_default'] ) ); ?> /> <?php esc_html_e( 'Hide the prefix for the default language', 'shapetranslate' ); ?></label>
					<p class="description"><?php esc_html_e( 'The prefix is whichever form the behavior above uses: the directory, the subdomain, or the query argument. Hiding it serves the default language from the site address itself.', 'shapetranslate' ); ?></p>
				</fieldset></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visitor language', 'shapetranslate' ); ?></th>
				<td><fieldset class="shapetranslate-option-list">
					<label>
						<input type="checkbox" name="detect_browser" value="1" <?php checked( ! empty( $url['detect_browser'] ) ); ?> />
						<?php esc_html_e( 'Send a first-time visitor to the language their browser asks for', 'shapetranslate' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Applies only to the site home page when no language is in the URL. A shared or bookmarked language URL always wins, and following an internal link home never changes the language. While this is on, a cookie holding only the language slug remembers the choice so the browser header is read once.', 'shapetranslate' ); ?></p>
				</fieldset></td>
			</tr>
		</tbody></table>
		<?php if ( ! empty( $url['detect_browser'] ) ) : ?>
			<div class="shapetranslate-notice notice notice-info inline"><p><?php esc_html_e( 'The home page is the one address detection makes reader-dependent, so ShapeTranslate marks it uncacheable and declares what it varies on. Page cache plugins read that; no other page is affected.', 'shapetranslate' ); ?> <?php esc_html_e( 'A CDN or a server-level cache sits above WordPress and sees neither, so exclude the home page there, or let it vary on the Accept-Language header.', 'shapetranslate' ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	/** Renders content policy controls. */
	private function render_content() {
		$content    = $this->settings->get_section( 'content' );
		$post_types = $this->post_type_support->get_available_post_types();
		$taxonomies = $this->taxonomy_support->get_available_taxonomies();
		?>
		<h2><?php esc_html_e( 'Content', 'shapetranslate' ); ?></h2>
		<p><?php esc_html_e( 'Only the types selected here gain a language control and translation actions. Posts, pages, categories, and tags start selected; every other public post type and taxonomy stays untouched until you choose it.', 'shapetranslate' ); ?></p>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Translatable post types', 'shapetranslate' ); ?></th>
				<td><?php $this->render_object_policy( 'post_types', $content['post_types_mode'], $content['post_types'], $post_types, true ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Translatable taxonomies', 'shapetranslate' ); ?></th>
				<td><?php $this->render_object_policy( 'taxonomies', $content['taxonomies_mode'], $content['taxonomies'], $taxonomies, false ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default terms', 'shapetranslate' ); ?></th>
				<td>
					<p class="description">
						<?php esc_html_e( 'A post saved without a term of its own lands in the default chosen here for its language. Leave a language on the translation of the site default to follow the term set in Settings > Writing, which is what a translated Uncategorized already gives you.', 'shapetranslate' ); ?>
					</p>
					<?php $this->render_default_terms( $content ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Media', 'shapetranslate' ); ?></th>
				<td><fieldset class="shapetranslate-option-list">
					<label>
						<input type="checkbox" name="media_support" value="1" <?php checked( ! empty( $content['media_support'] ) ); ?> />
						<?php esc_html_e( 'Translate media titles, alternative text, captions, and descriptions', 'shapetranslate' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Each language gets its own media record pointing at the same file. Nothing is uploaded again and no file is duplicated. Leave this off if every language should share one description.', 'shapetranslate' ); ?>
					</p>
				</fieldset></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Homepage fallback', 'shapetranslate' ); ?></th>
				<td><fieldset class="shapetranslate-option-list">
					<label>
						<input type="checkbox" name="front_fallback" value="1" <?php checked( ! empty( $content['front_fallback'] ) ); ?> />
						<?php esc_html_e( 'Show the original homepage in languages it has not been translated into', 'shapetranslate' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Applies when a static page is set as the homepage. Without this, a language with no homepage translation shows its latest posts at its root address instead.', 'shapetranslate' ); ?>
					</p>
				</fieldset></td>
			</tr>
		</tbody></table>
		<?php
	}

	/** Renders the ongoing synchronization choices. */
	private function render_sync() {
		$workflow = $this->workflow_settings->get();
		$items    = SyncCatalog::items_with_labels();
		?>
		<h2><?php esc_html_e( 'Synchronization', 'shapetranslate' ); ?></h2>
		<p class="description shapetranslate-sync-intro">
			<?php esc_html_e( 'Creating a translation always copies the source content, taxonomies, public custom fields, page template, featured image, and the other core post fields, so an editor never starts from an empty screen. Nothing below is needed for that.', 'shapetranslate' ); ?>
		</p>
		<p class="description shapetranslate-sync-intro">
			<?php esc_html_e( 'Choose what should also stay aligned afterwards. Editing a synchronized item on any post in a translation group applies the change to every other language in that group.', 'shapetranslate' ); ?>
		</p>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Keep synchronized', 'shapetranslate' ); ?></th>
				<td><fieldset class="shapetranslate-option-list">
				<?php foreach ( $items as $item_id => $item ) : ?>
					<?php $key = SyncCatalog::sync_key( $item_id ); ?>
					<label>
						<input type="checkbox" name="workflow[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $workflow[ $key ] ) ); ?> />
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( '' !== $item['description'] ) : ?>
							<span class="description shapetranslate-sync-hint"><?php echo esc_html( $item['description'] ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
				</fieldset></td>
			</tr>
		</tbody></table>
		<p class="description">
			<?php esc_html_e( 'Synchronized items are shared by every language, so only editors who may edit all translations in a group can change them.', 'shapetranslate' ); ?>
		</p>
		<?php
	}

	/** Renders language switcher defaults and menu configuration. */
	private function render_switcher() {
		$switcher   = $this->settings->get_section( 'switcher' );
		$floater    = $this->settings->get_floater_settings();
		$languages  = $this->language_manager->get_languages();
		$menus      = wp_get_nav_menus();
		$locations  = get_registered_nav_menus();
		$assignment = $this->menu_manager->get_location_assignments();
		?>
		<h2><?php esc_html_e( 'Switcher', 'shapetranslate' ); ?></h2>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><label for="shapetranslate-switcher-display"><?php esc_html_e( 'Label', 'shapetranslate' ); ?></label></th><td>
				<select id="shapetranslate-switcher-display" name="switcher[display]">
					<option value="native_name" <?php selected( $switcher['display'], 'native_name' ); ?>><?php esc_html_e( 'Native name', 'shapetranslate' ); ?></option>
					<option value="name" <?php selected( $switcher['display'], 'name' ); ?>><?php esc_html_e( 'Language name', 'shapetranslate' ); ?></option>
					<option value="language_code" <?php selected( $switcher['display'], 'language_code' ); ?>><?php esc_html_e( 'Language code', 'shapetranslate' ); ?></option>
					<option value="flag" <?php selected( $switcher['display'], 'flag' ); ?>><?php esc_html_e( 'Flag only', 'shapetranslate' ); ?></option>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="shapetranslate-switcher-layout"><?php esc_html_e( 'Layout', 'shapetranslate' ); ?></label></th><td>
				<select id="shapetranslate-switcher-layout" name="switcher[layout]">
					<option value="horizontal" <?php selected( $switcher['layout'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal list', 'shapetranslate' ); ?></option>
					<option value="vertical" <?php selected( $switcher['layout'], 'vertical' ); ?>><?php esc_html_e( 'Vertical list', 'shapetranslate' ); ?></option>
					<option value="dropdown" <?php selected( $switcher['layout'], 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'shapetranslate' ); ?></option>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="shapetranslate-unavailable-behavior"><?php esc_html_e( 'Missing translation', 'shapetranslate' ); ?></label></th><td>
				<select id="shapetranslate-unavailable-behavior" name="switcher[unavailable_behavior]">
					<option value="hide" <?php selected( $switcher['unavailable_behavior'], 'hide' ); ?>><?php esc_html_e( 'Hide languages the site has no content in', 'shapetranslate' ); ?></option>
					<option value="disabled" <?php selected( $switcher['unavailable_behavior'], 'disabled' ); ?>><?php esc_html_e( 'Show as unavailable', 'shapetranslate' ); ?></option>
					<option value="home" <?php selected( $switcher['unavailable_behavior'], 'home' ); ?>><?php esc_html_e( 'Link to language homepage', 'shapetranslate' ); ?></option>
					<option value="current" <?php selected( $switcher['unavailable_behavior'], 'current' ); ?>><?php esc_html_e( 'Keep current URL', 'shapetranslate' ); ?></option>
				</select>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Visibility', 'shapetranslate' ); ?></th><td><fieldset class="shapetranslate-option-list">
				<label><input type="checkbox" name="switcher[hide_current]" value="1" <?php checked( $switcher['hide_current'] ); ?> /> <?php esc_html_e( 'Hide current language', 'shapetranslate' ); ?></label>
				<label><input type="checkbox" name="switcher[hide_missing]" value="1" <?php checked( $switcher['hide_missing'] ); ?> /> <?php esc_html_e( 'Hide languages the site has no content in', 'shapetranslate' ); ?></label>
				<label><input type="checkbox" name="switcher[show_disabled]" value="1" <?php checked( $switcher['show_disabled'] ); ?> /> <?php esc_html_e( 'Show disabled languages as unavailable', 'shapetranslate' ); ?></label>
				<label><input type="checkbox" name="switcher[show_flags]" value="1" <?php checked( $switcher['show_flags'] ); ?> /> <?php esc_html_e( 'Show flags', 'shapetranslate' ); ?></label>
			</fieldset>
			<p class="description">
				<?php esc_html_e( 'A language stays in the switcher as long as something on the site is published in it, even on pages that carry no translation of their own, such as a cart or a checkout. Only a language nothing has been written in yet is hidden.', 'shapetranslate' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'The “Hide languages the site has no content in” checkbox is the same choice as the Missing translation option of that name, and it wins: leave it off to use any of the other three.', 'shapetranslate' ); ?>
			</p>
			</td></tr>
		</tbody></table>

		<?php $this->render_floating_switcher( $floater ); ?>

		<?php $this->render_switcher_usage( $switcher ); ?>

		<h2><?php esc_html_e( 'Navigation Menus', 'shapetranslate' ); ?></h2>
		<?php $this->render_menu_languages( $menus, $languages ); ?>
		<h2><?php esc_html_e( 'Theme Locations', 'shapetranslate' ); ?></h2>
		<?php $this->render_location_matrix( $locations, $languages, $menus, $assignment ); ?>
		<?php
	}

	/**
	 * Renders the floating switcher controls.
	 *
	 * The one switcher the plugin places by itself, so that a site whose theme
	 * carries none is still navigable between its languages. Everything else on
	 * this screen describes how a switcher looks; these three say whether this
	 * particular one is printed at all and which corner it is pinned to.
	 *
	 * @param array<string, mixed> $floater Saved floating switcher settings.
	 * @return void
	 */
	private function render_floating_switcher( array $floater ) {
		$positions = array(
			'middle-right' => __( 'Middle right', 'shapetranslate' ),
			'middle-left'  => __( 'Middle left', 'shapetranslate' ),
			'bottom-right' => __( 'Bottom right', 'shapetranslate' ),
			'bottom-left'  => __( 'Bottom left', 'shapetranslate' ),
			'top-right'    => __( 'Top right', 'shapetranslate' ),
			'top-left'     => __( 'Top left', 'shapetranslate' ),
		);
		?>
		<h2><?php esc_html_e( 'Floating switcher', 'shapetranslate' ); ?></h2>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><?php esc_html_e( 'Display', 'shapetranslate' ); ?></th><td><fieldset class="shapetranslate-option-list">
				<label>
					<input type="checkbox" name="switcher[floater][enabled]" value="1" <?php checked( ! empty( $floater['enabled'] ) ); ?> />
					<?php esc_html_e( 'Show a floating switcher on the site', 'shapetranslate' ); ?>
				</label>
				<label>
					<input type="checkbox" name="switcher[floater][show_flags]" value="1" <?php checked( ! empty( $floater['show_flags'] ) ); ?> />
					<?php esc_html_e( 'Show flags', 'shapetranslate' ); ?>
				</label>
			</fieldset>
			<p class="description">
				<?php esc_html_e( 'Fixed to the edge of the screen on every page, over the content rather than inside it. On by default, so a new language is reachable before you have placed a switcher yourself; turn it off once one sits in your menu or template.', 'shapetranslate' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Flags here are separate from the Show flags checkbox above, which covers every other switcher.', 'shapetranslate' ); ?>
			</p>
			</td></tr>
			<tr><th scope="row"><label for="shapetranslate-floater-position"><?php esc_html_e( 'Position', 'shapetranslate' ); ?></label></th><td>
				<select id="shapetranslate-floater-position" name="switcher[floater][position]">
					<?php foreach ( $positions as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $floater['position'] ) ? $floater['position'] : '', $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'A middle edge sits halfway down the side of the screen, flush against it. A corner keeps a margin instead, for a site whose sides are already taken. Right stays right in a right-to-left language.', 'shapetranslate' ); ?>
				</p>
			</td></tr>
			<tr><th scope="row"><label for="shapetranslate-floater-layout"><?php esc_html_e( 'Layout', 'shapetranslate' ); ?></label></th><td>
				<select id="shapetranslate-floater-layout" name="switcher[floater][layout]">
					<option value="vertical" <?php selected( isset( $floater['layout'] ) ? $floater['layout'] : '', 'vertical' ); ?>><?php esc_html_e( 'Vertical list', 'shapetranslate' ); ?></option>
					<option value="horizontal" <?php selected( isset( $floater['layout'] ) ? $floater['layout'] : '', 'horizontal' ); ?>><?php esc_html_e( 'Horizontal list', 'shapetranslate' ); ?></option>
					<option value="dropdown" <?php selected( isset( $floater['layout'] ) ? $floater['layout'] : '', 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'shapetranslate' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'A list shows every language at once, the current one filled in. A dropdown collapses it to one control, which suits a site with many languages. Labels and missing-translation handling still come from the settings above.', 'shapetranslate' ); ?>
				</p>
			</td></tr>
		</tbody></table>
		<?php
	}

	/**
	 * Renders the shortcode that carries these settings into a post or a page.
	 *
	 * The controls above decide what every switcher on the site looks like. One
	 * placed by hand often wants its own settings just there, and the way to ask
	 * for that is to pass the same options along with it. Building the shortcode
	 * out of the controls on screen is what makes that connection visible:
	 * change a control, watch the option it writes change with it.
	 *
	 * Every option is written out rather than only the ones that differ from a
	 * default, so a shortcode someone copied keeps rendering the switcher they
	 * copied even after these settings are edited again later.
	 *
	 * @param array<string, mixed> $switcher Saved switcher settings.
	 * @return void
	 */
	private function render_switcher_usage( array $switcher ) {
		$values = array(
			'display'              => isset( $switcher['display'] ) ? (string) $switcher['display'] : 'native_name',
			'layout'               => isset( $switcher['layout'] ) ? (string) $switcher['layout'] : 'horizontal',
			'unavailable_behavior' => isset( $switcher['unavailable_behavior'] ) ? (string) $switcher['unavailable_behavior'] : 'hide',
			'show_flags'           => empty( $switcher['show_flags'] ) ? 'false' : 'true',
			'hide_current'         => empty( $switcher['hide_current'] ) ? 'false' : 'true',
			'hide_missing'         => empty( $switcher['hide_missing'] ) ? 'false' : 'true',
			'show_disabled'        => empty( $switcher['show_disabled'] ) ? 'false' : 'true',
		);

		$shortcode = '[shapetranslate_switcher';

		foreach ( $values as $option => $value ) {
			$shortcode .= ' ' . $option . '="' . $value . '"';
		}

		$shortcode .= ']';
		?>
		<h2><?php esc_html_e( 'Placing a switcher yourself', 'shapetranslate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The settings above apply to every switcher on the site. To give one placement its own settings instead, pass them along with it. The shortcode below stays in step with the controls above, so changing a control shows the option that carries it.', 'shapetranslate' ); ?>
		</p>
		<div class="shapetranslate-usage" data-shapetranslate-switcher-usage>
			<p class="shapetranslate-usage__label"><?php esc_html_e( 'Shortcode, for a post, a page, or a widget', 'shapetranslate' ); ?></p>
			<div class="shapetranslate-usage__snippet">
				<code data-shapetranslate-usage="shortcode"><?php echo esc_html( $shortcode ); ?></code>
				<button
					type="button"
					class="button button-small"
					data-shapetranslate-copy="shortcode"
					data-copied="<?php esc_attr_e( 'Copied', 'shapetranslate' ); ?>"
				><?php esc_html_e( 'Copy', 'shapetranslate' ); ?></button>
			</div>
		</div>
		<?php
	}

	/** Renders multilingual SEO controls. */
	private function render_seo() {
		$seo = $this->settings->get_section( 'seo' );
		?>
		<h2><?php esc_html_e( 'SEO', 'shapetranslate' ); ?></h2>
		<table class="form-table" role="presentation"><tbody><tr>
			<th scope="row"><?php esc_html_e( 'Alternate language URLs', 'shapetranslate' ); ?></th>
			<td><fieldset class="shapetranslate-option-list">
				<label><input type="checkbox" name="seo[hreflang_enabled]" value="1" <?php checked( $seo['hreflang_enabled'] ); ?> /> <?php esc_html_e( 'Output hreflang links for available translations', 'shapetranslate' ); ?></label>
				<label><input type="checkbox" name="seo[x_default_enabled]" value="1" <?php checked( $seo['x_default_enabled'] ); ?> /> <?php esc_html_e( 'Include x-default using the default language URL', 'shapetranslate' ); ?></label>
			</fieldset><p class="description"><?php esc_html_e( 'Canonical URL integration remains active when hreflang output is disabled.', 'shapetranslate' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sitemap', 'shapetranslate' ); ?></th>
			<td><fieldset class="shapetranslate-option-list">
				<label><input type="checkbox" name="seo[split_sitemaps]" value="1" <?php checked( $seo['split_sitemaps'] ); ?> /> <?php esc_html_e( 'List one sitemap per language in the sitemap index', 'shapetranslate' ); ?></label>
			</fieldset><p class="description"><?php esc_html_e( 'Each language gets a sitemap at its own address, under one shared index. Turn this off to keep a single sitemap holding every language. Languages on separate hosts always have their own sitemap.', 'shapetranslate' ); ?></p></td>
		</tr></tbody></table>
		<?php
	}

	/** Renders destructive advanced settings. */
	private function render_advanced() {
		$advanced = $this->settings->get_section( 'advanced' );
		?>
		<h2><?php esc_html_e( 'Advanced', 'shapetranslate' ); ?></h2>
		<table class="form-table" role="presentation"><tbody><tr>
			<th scope="row"><?php esc_html_e( 'Uninstall behavior', 'shapetranslate' ); ?></th>
			<td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $advanced['delete_data_on_uninstall'] ); ?> data-shapetranslate-confirm="<?php esc_attr_e( 'Enabling this option will permanently delete ShapeTranslate data when the plugin is uninstalled. Continue?', 'shapetranslate' ); ?>" /> <?php esc_html_e( 'Delete all ShapeTranslate data when the plugin is uninstalled', 'shapetranslate' ); ?></label>
			<p class="description"><?php esc_html_e( 'Deactivation never deletes data. This option applies only to uninstalling the plugin.', 'shapetranslate' ); ?></p></td>
		</tr></tbody></table>
		<?php
	}

	/**
	 * Renders all/selected object controls.
	 *
	 * @param string            $name        Field name.
	 * @param string            $mode        Current mode.
	 * @param array<int,string> $selected    Selected keys.
	 * @param array<int,string> $available   Available keys.
	 * @param bool              $post_types  Whether these are post types.
	 */
	private function render_object_policy( $name, $mode, $selected, $available, $post_types ) {
		?>
		<fieldset class="shapetranslate-option-list">
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>_mode" value="all" <?php checked( $mode, 'all' ); ?> /> <?php esc_html_e( 'All eligible types', 'shapetranslate' ); ?></label>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>_mode" value="selected" <?php checked( $mode, 'selected' ); ?> /> <?php esc_html_e( 'Only selected types', 'shapetranslate' ); ?></label>
			<span class="shapetranslate-checkbox-grid">
				<?php foreach ( $available as $object_name ) : ?>
					<?php $object = $post_types ? get_post_type_object( $object_name ) : get_taxonomy( $object_name ); ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $object_name ); ?>" <?php checked( in_array( $object_name, $selected, true ) ); ?> /> <?php echo esc_html( $object ? $object->labels->name : $object_name ); ?> <code><?php echo esc_html( $object_name ); ?></code></label>
				<?php endforeach; ?>
			</span>
		</fieldset>
		<?php
	}

	/**
	 * Renders the per-language default term matrix.
	 *
	 * @param array<string, mixed> $content Content settings.
	 */
	private function render_default_terms( $content ) {
		$taxonomies = $this->taxonomy_support->get_default_term_taxonomies();
		$languages  = $this->language_manager->get_languages();
		$map        = isset( $content['default_terms'] ) && is_array( $content['default_terms'] )
			? $content['default_terms']
			: array();

		if ( empty( $taxonomies ) || empty( $languages ) ) {
			?>
			<p class="description"><?php esc_html_e( 'Per-language defaults appear once a language exists and a taxonomy that applies a default term is translatable.', 'shapetranslate' ); ?></p>
			<?php
			return;
		}
		?>
		<div class="shapetranslate-matrix-scroll">
			<table class="widefat striped shapetranslate-settings-table shapetranslate-default-term-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Taxonomy', 'shapetranslate' ); ?></th>
						<?php foreach ( $languages as $language ) : ?>
							<th scope="col"><?php echo esc_html( $language['native_name'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $taxonomies as $taxonomy ) : ?>
						<?php
						$object  = get_taxonomy( $taxonomy );
						$label   = $object ? $object->labels->name : $taxonomy;
						$grouped = $this->terms_by_language( $taxonomy );
						?>
						<tr>
							<th scope="row">
								<span class="shapetranslate-matrix-label"><?php echo esc_html( $label ); ?></span>
								<code><?php echo esc_html( $taxonomy ); ?></code>
							</th>
							<?php foreach ( $languages as $language ) : ?>
								<?php
								$terms      = isset( $grouped[ $language['id'] ] ) ? $grouped[ $language['id'] ] : array();
								$selected   = isset( $map[ $taxonomy ][ $language['id'] ] ) ? absint( $map[ $taxonomy ][ $language['id'] ] ) : 0;
								$aria_label = sprintf(
									/* translators: 1: taxonomy label, 2: language native name. */
									__( '%1$s default for %2$s', 'shapetranslate' ),
									$label,
									$language['native_name']
								);
								?>
								<td>
									<select name="default_terms[<?php echo esc_attr( $taxonomy ); ?>][<?php echo esc_attr( $language['id'] ); ?>]" aria-label="<?php echo esc_attr( $aria_label ); ?>">
										<option value="0"><?php esc_html_e( 'Translation of the site default', 'shapetranslate' ); ?></option>
										<?php foreach ( $terms as $term ) : ?>
											<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $selected, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Returns a taxonomy's terms grouped by the language each one carries.
	 *
	 * The list is capped, because this is a chooser rather than a term browser:
	 * a taxonomy larger than the cap offers its first terms by name and the rest
	 * are set through the `shapetranslate_default_term_id` filter.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, array<int, \WP_Term>>
	 */
	private function terms_by_language( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => self::TERM_CHOICE_LIMIT,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$this->term_translations->prime_terms( wp_list_pluck( $terms, 'term_taxonomy_id' ) );
		$grouped = array();

		foreach ( $terms as $term ) {
			$language_id = $this->term_translations->get_term_language_id( $term->term_id, $taxonomy );

			if ( '' !== $language_id ) {
				$grouped[ $language_id ][] = $term;
			}
		}

		return $grouped;
	}

	/** Renders export and import forms. */
	private function render_transfer_tools() {
		?>
		<hr />
		<h2><?php esc_html_e( 'Settings Export', 'shapetranslate' ); ?></h2>
		<p><?php esc_html_e( 'The export includes language locales and portable plugin settings. Navigation menu IDs are excluded.', 'shapetranslate' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="shapetranslate_export_settings" />
			<?php wp_nonce_field( 'shapetranslate_export_settings' ); ?>
			<?php submit_button( __( 'Download Settings JSON', 'shapetranslate' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Settings Import', 'shapetranslate' ); ?></h2>
		<div class="shapetranslate-notice notice notice-warning inline"><p><?php esc_html_e( 'Import replaces portable settings and enabled-language choices. Every imported locale must already be registered on this site.', 'shapetranslate' ); ?></p></div>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="shapetranslate_import_settings" />
			<?php wp_nonce_field( 'shapetranslate_import_settings' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="shapetranslate-settings-file"><?php esc_html_e( 'JSON file', 'shapetranslate' ); ?></label></th><td><input id="shapetranslate-settings-file" type="file" name="settings_file" accept="application/json,.json" /></td></tr>
				<tr><th scope="row"><label for="shapetranslate-settings-json"><?php esc_html_e( 'Or paste JSON', 'shapetranslate' ); ?></label></th><td><textarea id="shapetranslate-settings-json" name="settings_json" rows="8" class="large-text code"></textarea></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Import Settings', 'shapetranslate' ), 'secondary', 'submit', false, array( 'data-shapetranslate-confirm' => __( 'Import these settings and replace the current portable configuration?', 'shapetranslate' ) ) ); ?>
		</form>
		<?php
	}

	/**
	 * Renders menu language assignments.
	 *
	 * @param array<int, \WP_Term>             $menus     Navigation menus.
	 * @param array<int, array<string, mixed>> $languages Languages.
	 */
	private function render_menu_languages( $menus, $languages ) {
		?>
		<table class="widefat striped shapetranslate-settings-table"><thead><tr><th scope="col"><?php esc_html_e( 'Menu', 'shapetranslate' ); ?></th><th scope="col"><?php esc_html_e( 'Language', 'shapetranslate' ); ?></th></tr></thead><tbody>
		<?php
		if ( empty( $menus ) ) :
			?>
			<tr><td colspan="2"><?php esc_html_e( 'No navigation menus exist for this site.', 'shapetranslate' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $menus as $menu ) : ?>
			<?php
			$assigned   = $this->menu_manager->get_menu_language_id( $menu->term_id );
			$aria_label = sprintf(
				/* translators: %s: navigation menu name. */
				__( 'Language for %s', 'shapetranslate' ),
				$menu->name
			);
			?>
			<tr><th scope="row"><?php echo esc_html( $menu->name ); ?></th><td><select name="menu_languages[<?php echo esc_attr( $menu->term_id ); ?>]" aria-label="<?php echo esc_attr( $aria_label ); ?>"><option value=""><?php esc_html_e( 'Not assigned', 'shapetranslate' ); ?></option>
			<?php
			foreach ( $languages as $language ) :
				?>
				<option value="<?php echo esc_attr( $language['id'] ); ?>" <?php selected( $assigned, $language['id'] ); ?>><?php echo esc_html( $language['native_name'] ); ?></option><?php endforeach; ?>
			</select></td></tr>
		<?php endforeach; ?></tbody></table>
		<?php
	}

	/**
	 * Renders language-specific theme location assignments.
	 *
	 * @param array<string, string>             $locations   Theme locations.
	 * @param array<int, array<string, mixed>>  $languages   Languages.
	 * @param array<int, \WP_Term>              $menus       Navigation menus.
	 * @param array<string, array<string, int>> $assignments Existing assignments.
	 */
	private function render_location_matrix( $locations, $languages, $menus, $assignments ) {
		$columns = count( $languages ) + 1;
		?>
		<div class="shapetranslate-matrix-scroll">
			<table class="widefat striped shapetranslate-settings-table shapetranslate-menu-location-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Theme location', 'shapetranslate' ); ?></th>
						<?php foreach ( $languages as $language ) : ?>
							<th scope="col"><?php echo esc_html( $language['native_name'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $locations ) ) : ?>
						<tr>
							<td colspan="<?php echo esc_attr( $columns ); ?>"><?php esc_html_e( 'The active theme has no registered menu locations.', 'shapetranslate' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $locations as $location => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<?php foreach ( $languages as $language ) : ?>
								<?php
								$selected   = isset( $assignments[ $location ][ $language['id'] ] ) ? absint( $assignments[ $location ][ $language['id'] ] ) : 0;
								$aria_label = sprintf(
									/* translators: 1: theme location label, 2: language native name. */
									__( '%1$s menu for %2$s', 'shapetranslate' ),
									$label,
									$language['native_name']
								);
								?>
								<td>
									<select name="menu_locations[<?php echo esc_attr( $location ); ?>][<?php echo esc_attr( $language['id'] ); ?>]" aria-label="<?php echo esc_attr( $aria_label ); ?>">
										<option value="0"><?php esc_html_e( 'Use WordPress default', 'shapetranslate' ); ?></option>
										<?php foreach ( $menus as $menu ) : ?>
											<option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( $selected, $menu->term_id ); ?>><?php echo esc_html( $menu->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders settings navigation tabs.
	 *
	 * @param string $current Current tab.
	 */
	private function render_tabs( $current ) {
		$labels = array(
			'general'  => __( 'General', 'shapetranslate' ),
			'url'      => __( 'URL', 'shapetranslate' ),
			'content'  => __( 'Content', 'shapetranslate' ),
			'sync'     => __( 'Synchronization', 'shapetranslate' ),
			'switcher' => __( 'Switcher', 'shapetranslate' ),
			'seo'      => __( 'SEO', 'shapetranslate' ),
			'advanced' => __( 'Advanced', 'shapetranslate' ),
		);
		?>
		<nav class="nav-tab-wrapper shapetranslate-settings-tabs" aria-label="<?php esc_attr_e( 'ShapeTranslate settings sections', 'shapetranslate' ); ?>">
		<?php foreach ( $labels as $tab => $label ) : ?>
			<a class="nav-tab <?php echo $current === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=shapetranslate-settings&tab=' . $tab ) ); ?>" <?php echo $current === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Returns the allowlisted current tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		return in_array( $tab, self::tab_keys(), true ) ? $tab : 'general';
	}

	/** Renders an allowlisted post-redirect-get notice. */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted notice code.
		$notice  = isset( $_GET['shapetranslate_notice'] ) ? sanitize_key( wp_unslash( $_GET['shapetranslate_notice'] ) ) : '';
		$notices = array(
			'settings_saved'                => array( 'success', __( 'ShapeTranslate settings saved.', 'shapetranslate' ) ),
			'url_settings_changed'          => array( 'warning', __( 'URL behavior changed. Review public links and clear page or CDN caches.', 'shapetranslate' ) ),
			'settings_imported'             => array( 'success', __( 'ShapeTranslate settings imported.', 'shapetranslate' ) ),
			'settings_imported_url_changed' => array( 'warning', __( 'Settings imported and URL behavior changed. Review public links and clear caches.', 'shapetranslate' ) ),
		);

		if ( in_array( $notice, array( 'settings_error', 'import_error' ), true ) ) {
			$key     = 'shapetranslate_settings_error_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );
			$notices[ $notice ] = array( 'error', is_string( $message ) ? $message : __( 'ShapeTranslate could not save these settings.', 'shapetranslate' ) );
		}

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		printf( '<div class="shapetranslate-notice notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
	}
}
