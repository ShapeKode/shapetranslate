<?php
/**
 * Plugin installation and upgrades.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Lifecycle;

use ShapeTranslate\Infrastructure\DatabaseStringRepository;
use ShapeTranslate\Infrastructure\OptionsLanguageRepository;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Settings\WorkflowSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the current schema and provides a migration boundary for updates.
 */
final class Installer {

	/**
	 * Number of sites processed in one network lifecycle batch.
	 *
	 * @var int
	 */
	const SITE_BATCH_SIZE = 100;

	/**
	 * Installed plugin version option.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'shapetranslate_version';

	/**
	 * Installs the current plugin schema.
	 *
	 * @return void
	 */
	public static function install() {
		OptionsLanguageRepository::install();
		DatabaseStringRepository::install();
		PluginSettings::install();
		WorkflowSettings::install();
		update_option( self::VERSION_OPTION, SHAPETRANSLATE_VERSION, true );
	}

	/**
	 * Runs a lifecycle callback once for every site in the current network.
	 *
	 * Site switching is restored even when the callback throws. Callers are
	 * responsible for invoking this only for network-wide operations.
	 *
	 * @param callable $callback Site-local lifecycle callback.
	 * @return void
	 */
	public static function for_each_site( $callback ) {
		if ( ! is_callable( $callback ) ) {
			return;
		}

		$offset = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => self::SITE_BATCH_SIZE,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );

				try {
					call_user_func( $callback );
				} finally {
					restore_current_blog();
				}
			}

			$site_count = count( $site_ids );
			$offset    += self::SITE_BATCH_SIZE;
		} while ( self::SITE_BATCH_SIZE === $site_count );
	}

	/**
	 * Runs required upgrades once after a plugin version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed_version = (string) get_option( self::VERSION_OPTION, '' );

		if ( '' !== $installed_version && version_compare( $installed_version, SHAPETRANSLATE_VERSION, '>=' ) ) {
			return;
		}

		/**
		 * Fires before ShapeTranslate upgrades its core schema.
		 *
		 * @param string $installed_version Previously installed version.
		 * @param string $target_version    Version being installed.
		 */
		do_action( 'shapetranslate_before_upgrade', $installed_version, SHAPETRANSLATE_VERSION );

		self::install();

		/**
		 * Fires after ShapeTranslate upgrades its core schema.
		 *
		 * @param string $installed_version Previously installed version.
		 * @param string $target_version    Installed version.
		 */
		do_action( 'shapetranslate_upgraded', $installed_version, SHAPETRANSLATE_VERSION );
	}
}
