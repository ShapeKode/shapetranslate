<?php
/**
 * Plugin Name: ShapeTranslate
 * Description: A lightweight and flexible multilingual solution for WordPress, making it easy to manage languages, translate content, and build a multilingual website.
 * Plugin URI:  https://shapekode.com/
 * Author:      ShapeKode
 * Author URI:  https://profiles.wordpress.org/shapekode22/
 * Version:     1.0.3
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shapetranslate
 * Domain Path: /languages
 *
 * @package ShapeTranslate
 */


defined( 'ABSPATH' ) || exit;

defined( 'SHAPETRANSLATE_VERSION' ) || define( 'SHAPETRANSLATE_VERSION', '1.0.3' );
defined( 'SHAPETRANSLATE_MINIMUM_PHP_VERSION' ) || define( 'SHAPETRANSLATE_MINIMUM_PHP_VERSION', '7.4' );
defined( 'SHAPETRANSLATE_MINIMUM_WP_VERSION' ) || define( 'SHAPETRANSLATE_MINIMUM_WP_VERSION', '6.4' );
defined( 'SHAPETRANSLATE_FILE' ) || define( 'SHAPETRANSLATE_FILE', __FILE__ );
defined( 'SHAPETRANSLATE_PATH' ) || define( 'SHAPETRANSLATE_PATH', plugin_dir_path( __FILE__ ) );
defined( 'SHAPETRANSLATE_URL' ) || define( 'SHAPETRANSLATE_URL', plugin_dir_url( __FILE__ ) );
defined( 'SHAPETRANSLATE_BASENAME' ) || define( 'SHAPETRANSLATE_BASENAME', plugin_basename( __FILE__ ) );


defined( 'SHAPETRANSLATE_LOCAL_DIR' ) || define( 'SHAPETRANSLATE_LOCAL_DIR', WP_CONTENT_DIR . '/shapetranslate' );

if ( version_compare( PHP_VERSION, SHAPETRANSLATE_MINIMUM_PHP_VERSION, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'ShapeTranslate requires PHP %1$s or newer. This site is running PHP %2$s.', 'shapetranslate' ),
						SHAPETRANSLATE_MINIMUM_PHP_VERSION,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

if ( file_exists( SHAPETRANSLATE_PATH . 'vendor/autoload.php' ) ) {
	require_once SHAPETRANSLATE_PATH . 'vendor/autoload.php';
}

require_once SHAPETRANSLATE_PATH . 'src/class-autoloader.php';

( new ShapeTranslate\Autoloader( SHAPETRANSLATE_PATH . 'src/' ) )->register();
require_once SHAPETRANSLATE_PATH . 'includes/functions.php';
require_once SHAPETRANSLATE_PATH . 'includes/api.php';


/**
 * Initialize the plugin tracker
 *
 * @return void
 */
function shapetranslate_appsero_init_tracker() {

	if ( ! class_exists( 'Appsero\Client' ) ) {
		return;
	}

	$client = new Appsero\Client( 'f2118804-3b85-405c-a489-c3bb6b902841', 'ShapeTranslate &#8211; Translate Multilingual Sites with AI', __FILE__ );

	// Active insights.
	$client->insights()->init();
}

shapetranslate_appsero_init_tracker();

register_activation_hook( SHAPETRANSLATE_FILE, array( ShapeTranslate\Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( SHAPETRANSLATE_FILE, array( ShapeTranslate\Lifecycle\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		global $wp_version;

		if ( version_compare( $wp_version, SHAPETRANSLATE_MINIMUM_WP_VERSION, '<' ) ) {
			add_action(
				'admin_notices',
				static function () use ( $wp_version ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: 1: required WordPress version, 2: current WordPress version. */
								__( 'ShapeTranslate requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'shapetranslate' ),
								SHAPETRANSLATE_MINIMUM_WP_VERSION,
								$wp_version
							)
						)
					);
				}
			);

			return;
		}

		ShapeTranslate\Plugin::instance()->boot();
	}
);