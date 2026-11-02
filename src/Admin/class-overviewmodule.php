<?php
/**
 * Overview admin module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use ShapeTranslate\Assets;
use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers ShapeTranslate -> Overview, a Free vs Pro feature comparison.
 */
final class OverviewModule implements ModuleInterface {

	/**
	 * Product page where Pro is sold.
	 *
	 * @var string
	 */
	const PRO_URL = 'https://shapekode.com/product/shapetranslate';

	/**
	 * Registered admin screen hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/** {@inheritdoc} */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'print_upgrade_menu_style' ) );
	}

	/**
	 * Returns the Pro product page URL tagged with where the click came from.
	 *
	 * @param string $medium Admin location of the link.
	 * @return string
	 */
	private static function pro_url( $medium ) {
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => $medium,
				'utm_campaign' => 'shapetranslate',
			),
			self::PRO_URL
		);
	}

	/**
	 * Registers the Overview submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'shapetranslate',
			esc_html__( 'ShapeTranslate Overview', 'shapetranslate' ),
			esc_html__( 'Overview', 'shapetranslate' ),
			AdminModule::capability(),
			'shapetranslate-overview',
			array( $this, 'render' )
		);

		/*
		 * Added to the global directly: add_submenu_page() runs the slug
		 * through plugin_basename(), which can mangle an external URL. A slug
		 * with no page hook is printed as the link itself.
		 */
		global $submenu;

		if ( current_user_can( AdminModule::capability() ) ) {
			$submenu['shapetranslate'][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Appending an external link item.
				'<span class="shapetranslate-upgrade-menu">' . esc_html__( 'Upgrade to Pro', 'shapetranslate' ) . '</span>',
				AdminModule::capability(),
				self::pro_url( 'menu' ),
			);
		}
	}

	/**
	 * Highlights the Upgrade to Pro menu item on every admin screen.
	 *
	 * @return void
	 */
	public function print_upgrade_menu_style() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			return;
		}
		?>
		<style>
			#adminmenu .toplevel_page_shapetranslate .wp-submenu li:has(> a > .shapetranslate-upgrade-menu) { margin-top: 4px; }
			#adminmenu .toplevel_page_shapetranslate .wp-submenu a:has(> .shapetranslate-upgrade-menu) { background: #10ac84; color: #fff; font-weight: 600; }
			#adminmenu .toplevel_page_shapetranslate .wp-submenu a:has(> .shapetranslate-upgrade-menu):hover,
			#adminmenu .toplevel_page_shapetranslate .wp-submenu a:has(> .shapetranslate-upgrade-menu):focus { background: #0d9470; color: #fff; box-shadow: none; }
		</style>
		<?php
	}

	/**
	 * Loads admin styles only on this screen.
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
	 * Renders the comparison table.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( AdminModule::capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to access this page.', 'shapetranslate' ),
				'',
				array( 'response' => 403 )
			);
		}

		$pro_url = self::pro_url( 'overview' );
		?>
		<div class="wrap shapetranslate-overview">
			<h1><?php esc_html_e( 'Overview', 'shapetranslate' ); ?></h1>

			<div class="shapetranslate-overview-intro">
				<div>
					<h2><?php esc_html_e( 'Compare ShapeTranslate and ShapeTranslate Pro', 'shapetranslate' ); ?></h2>
					<p><?php esc_html_e( 'ShapeTranslate is free with unlimited languages. Pro adds WooCommerce, AI translation and more.', 'shapetranslate' ); ?></p>
				</div>
				<a class="button button-primary button-hero" href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Pro', 'shapetranslate' ); ?></a>
			</div>

			<table class="widefat shapetranslate-overview-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Feature comparison between ShapeTranslate and ShapeTranslate Pro', 'shapetranslate' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Features', 'shapetranslate' ); ?></th>
						<th scope="col" class="shapetranslate-overview-plan"><?php esc_html_e( 'Free', 'shapetranslate' ); ?></th>
						<th scope="col" class="shapetranslate-overview-plan"><?php esc_html_e( 'Pro', 'shapetranslate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->sorted_features() as $feature => $in_free ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $feature ); ?></th>
							<td class="shapetranslate-overview-plan"><?php $this->render_mark( $in_free ); ?></td>
							<td class="shapetranslate-overview-plan"><?php $this->render_mark( true ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="shapetranslate-overview-footer">
				<a class="button button-primary" href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Pro', 'shapetranslate' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Prints an included / not included mark.
	 *
	 * @param bool $included Whether the plan includes the feature.
	 * @return void
	 */
	private function render_mark( $included ) {
		if ( $included ) {
			echo '<span class="shapetranslate-overview-mark is-yes" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="12"/><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
			echo '<span class="screen-reader-text">' . esc_html__( 'Included', 'shapetranslate' ) . '</span>';
			return;
		}

		echo '<span class="shapetranslate-overview-mark is-no" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="12"/><path d="M8.5 8.5l7 7m0-7l-7 7" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg></span>';
		echo '<span class="screen-reader-text">' . esc_html__( 'Not included', 'shapetranslate' ) . '</span>';
	}

	/**
	 * Returns every feature as one list, Pro-only features first.
	 *
	 * Both halves keep the order the groups list them in.
	 *
	 * @return array<string, bool> Feature label => whether Free includes it.
	 */
	private function sorted_features() {
		$pro_only = array();
		$shared   = array();

		foreach ( $this->groups() as $group ) {
			foreach ( $group['features'] as $feature => $in_free ) {
				if ( $in_free ) {
					$shared[ $feature ] = true;
				} else {
					$pro_only[ $feature ] = false;
				}
			}
		}

		return $pro_only + $shared;
	}

	/**
	 * Returns the feature groups; each feature maps to whether Free includes it.
	 * Pro includes every feature.
	 *
	 * @return array<int, array{label: string, features: array<string, bool>}>
	 */
	private function groups() {
		return array(
			array(
				'label'    => __( 'Getting started', 'shapetranslate' ),
				'features' => array(
					__( 'Unlimited languages', 'shapetranslate' )                     => true,
					__( 'Setup wizard', 'shapetranslate' )                            => true,
					__( 'Language catalog with flags and RTL', 'shapetranslate' )     => true,
					__( 'Translation dashboard (what is missing)', 'shapetranslate' ) => true,
					__( 'Settings export / import', 'shapetranslate' )                => true,
				),
			),
			array(
				'label'    => __( 'Content translation', 'shapetranslate' ),
				'features' => array(
					__( 'Posts, pages and custom post types', 'shapetranslate' )                     => true,
					__( 'Categories, tags and custom taxonomies', 'shapetranslate' )                 => true,
					__( 'Menus and Navigation block', 'shapetranslate' )                             => true,
					__( 'Media title, alt, caption and description (opt-in)', 'shapetranslate' )     => true,
					__( 'Strings: site title, tagline, widgets, theme & plugin options', 'shapetranslate' ) => true,
					__( 'Copy and sync fields between translations', 'shapetranslate' )              => true,
					__( 'Translation columns and admin language filter', 'shapetranslate' )          => true,
					__( 'Default category and terms per language', 'shapetranslate' )                => true,
					__( 'Outdated translation detection', 'shapetranslate' )                         => false,
					__( 'Show a widget in one language only', 'shapetranslate' )                     => false,
				),
			),
			array(
				'label'    => __( 'URLs & language switcher', 'shapetranslate' ),
				'features' => array(
					__( 'Directory, subdomain, domain or parameter URLs', 'shapetranslate' )             => true,
					__( 'Browser language detection', 'shapetranslate' )                                 => true,
					__( 'Per-language front page, search and comments', 'shapetranslate' )              => true,
					__( 'Switcher block, widget, menu item, shortcode and floating', 'shapetranslate' ) => true,
					__( 'Elementor language switcher widget', 'shapetranslate' )                         => true,
					__( 'Same slug allowed in every language', 'shapetranslate' )                        => false,
					__( 'Translated permalink bases (/de/kategorie/)', 'shapetranslate' )                => false,
				),
			),
			array(
				'label'    => __( 'SEO', 'shapetranslate' ),
				'features' => array(
					__( 'hreflang, x-default and canonical URLs', 'shapetranslate' ) => true,
					__( 'Multilingual WordPress sitemaps', 'shapetranslate' )        => true,
					__( 'Canonicals for Yoast, Rank Math, SEOPress, AIOSEO, Slim SEO and The SEO Framework', 'shapetranslate' ) => true,
					__( 'Yoast, Rank Math and SEOPress meta copied to translations', 'shapetranslate' ) => true,
					__( 'Language-aware Yoast, Rank Math and SEOPress sitemaps', 'shapetranslate' ) => false,
					__( 'Translatable schema and Open Graph locale alternates', 'shapetranslate' )  => false,
					__( 'Breadcrumbs and home links per language', 'shapetranslate' )             => false,
				),
			),
			array(
				'label'    => __( 'AI & machine translation', 'shapetranslate' ),
				'features' => array(
					__( 'AI through WordPress 7.0 Connectors (OpenAI, Anthropic, Google…)', 'shapetranslate' ) => false,
					__( 'DeepL (Free and Pro API keys)', 'shapetranslate' )                                   => false,
					__( 'One-click translate from the editor', 'shapetranslate' )                             => false,
					__( 'Bulk translation queue with retry, pause and failed-job list', 'shapetranslate' )   => false,
					__( 'Auto-translate on first publish', 'shapetranslate' )                                 => false,
					__( 'Tone presets and custom instructions', 'shapetranslate' )                            => false,
					__( 'Glossary and translation memory', 'shapetranslate' )                                 => false,
					__( 'AI translation of SEO fields, ACF fields and variation descriptions', 'shapetranslate' ) => false,
					__( 'On-page editing of titles, headings and paragraphs', 'shapetranslate' )              => false,
				),
			),
			array(
				'label'    => __( 'Workflow & team', 'shapetranslate' ),
				'features' => array(
					__( 'Translating, In review, Approved, with assignments and email alerts', 'shapetranslate' ) => false,
					__( 'Translator role limited to assigned languages', 'shapetranslate' )                      => false,
				),
			),
			array(
				'label'    => __( 'WooCommerce', 'shapetranslate' ),
				'features' => array(
					__( 'Products, categories, tags, global attributes and brands', 'shapetranslate' )      => false,
					__( 'Price, SKU, stock and variation sync', 'shapetranslate' )                          => false,
					__( 'One shared inventory, including reserved stock', 'shapetranslate' )                => false,
					__( 'Reviews and ratings shared across languages', 'shapetranslate' )                   => false,
					__( 'Shop, cart, checkout and My Account per language', 'shapetranslate' )              => false,
					__( 'Translatable endpoint and product permalink slugs', 'shapetranslate' )             => false,
					__( 'Emails in the customer’s language', 'shapetranslate' )                             => false,
					__( 'Order language and remembered customer language', 'shapetranslate' )              => false,
					__( 'Payment, shipping and email strings', 'shapetranslate' )                           => false,
					__( 'Cart kept across languages, and across domains via the switcher', 'shapetranslate' ) => false,
					__( 'Coupons valid in every language', 'shapetranslate' )                               => false,
					__( 'Missing store pages created in one click', 'shapetranslate' )                      => false,
					__( 'Duplicate a product with all its translations', 'shapetranslate' )                 => false,
					__( 'Analytics and reports count each product once', 'shapetranslate' )                => false,
					__( 'Product CSV import / export with language columns', 'shapetranslate' )             => false,
					__( 'WooCommerce language packs installed automatically', 'shapetranslate' )           => false,
					__( 'Subscriptions, Product Bundles, Composite, Mix and Match', 'shapetranslate' )      => false,
					__( 'Checkout Field Editor and Stripe (Apple Pay / Google Pay)', 'shapetranslate' )    => false,
				),
			),
			array(
				'label'    => __( 'Themes, builders & fields', 'shapetranslate' ),
				'features' => array(
					__( 'Block themes: templates and parts per language', 'shapetranslate' ) => true,
					__( 'Elementor pages and Theme Builder templates', 'shapetranslate' )    => true,
					__( 'Elementor header & footer add-ons', 'shapetranslate' )              => true,
					__( 'ACF / Secure Custom Fields (translate, copy, sync)', 'shapetranslate' ) => false,
					__( 'Bricks Builder (layouts copied, not AI-translated)', 'shapetranslate' ) => false,
				),
			),
			array(
				'label'    => __( 'Developers', 'shapetranslate' ),
				'features' => array(
					__( 'Public PHP API (30+ functions) and 190+ hooks', 'shapetranslate' ) => true,
					__( 'lang parameter on the REST API', 'shapetranslate' )                => true,
					__( 'wpml-config.xml support', 'shapetranslate' )                       => true,
					__( 'WooCommerce REST lang and translations fields', 'shapetranslate' ) => false,
					__( 'Read-only WordPress Abilities for AI agents (MCP via MCP Adapter)', 'shapetranslate' ) => false,
				),
			),
			array(
				'label'    => __( 'Help & support', 'shapetranslate' ),
				'features' => array(
					__( 'Documentation', 'shapetranslate' )          => true,
					__( 'Community support', 'shapetranslate' )      => true,
					__( 'Priority email support', 'shapetranslate' ) => false,
				),
			),
		);
	}
}
