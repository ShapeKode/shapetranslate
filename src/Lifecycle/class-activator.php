<?php
/**
 * Plugin activation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Lifecycle;

use ShapeTranslate\Routing\RoutingModule;

defined( 'ABSPATH' ) || exit;

/**
 * Performs non-destructive installation tasks.
 */
final class Activator {

	/**
	 * Short-lived marker consumed by the first eligible admin request.
	 *
	 * @var string
	 */
	const SETUP_REDIRECT_TRANSIENT = 'shapetranslate_setup_redirect';

	/**
	 * Activates ShapeTranslate.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		global $wp_version;

		if ( version_compare( PHP_VERSION, SHAPETRANSLATE_MINIMUM_PHP_VERSION, '<' ) ) {
			wp_die(
				esc_html__( 'ShapeTranslate could not be activated because the PHP requirement is not met.', 'shapetranslate' ),
				esc_html__( 'Plugin activation failed', 'shapetranslate' ),
				array( 'back_link' => true )
			);
		}

		if ( version_compare( $wp_version, SHAPETRANSLATE_MINIMUM_WP_VERSION, '<' ) ) {
			wp_die(
				esc_html__( 'ShapeTranslate could not be activated because the WordPress requirement is not met.', 'shapetranslate' ),
				esc_html__( 'Plugin activation failed', 'shapetranslate' ),
				array( 'back_link' => true )
			);
		}

		$activate_site = static function () {
			Installer::install();
			delete_option( RoutingModule::REWRITE_SIGNATURE_OPTION );
		};

		if ( $network_wide && is_multisite() ) {
			Installer::for_each_site( $activate_site );
		} else {
			$activate_site();
		}

		if (
			! $network_wide
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			set_transient( self::SETUP_REDIRECT_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		}

		do_action( 'shapetranslate_activated' );
	}
}
