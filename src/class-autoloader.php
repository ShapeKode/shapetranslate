<?php
/**
 * ShapeTranslate class autoloader.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Loads classes from the ShapeTranslate namespace.
 */
final class Autoloader {

	/**
	 * Namespace prefix.
	 *
	 * @var string
	 */
	private $prefix = 'ShapeTranslate\\';

	/**
	 * Source directory.
	 *
	 * @var string
	 */
	private $base_directory;

	/**
	 * Constructor.
	 *
	 * @param string $base_directory Absolute source directory.
	 */
	public function __construct( $base_directory ) {
		$this->base_directory = trailingslashit( $base_directory );
	}

	/**
	 * Registers the autoloader.
	 *
	 * @return void
	 */
	public function register() {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Loads a class when it belongs to the plugin namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public function load( $class_name ) {
		if ( 0 !== strpos( $class_name, $this->prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $this->prefix ) );
		$class_parts    = explode( '\\', $relative_class );
		$short_name     = array_pop( $class_parts );
		$is_interface   = 'Interface' === substr( $short_name, -9 );

		if ( $is_interface ) {
			$short_name = substr( $short_name, 0, -9 );
		}

		if ( $is_interface ) {
			$file_name = preg_replace( '/(?<!^)[A-Z]/', '-$0', $short_name );
			$file_name = 'interface-' . strtolower( $file_name ) . '.php';
		} else {
			$file_name = 'class-' . strtolower( $short_name ) . '.php';
		}

		$directory = empty( $class_parts ) ? '' : implode( '/', $class_parts ) . '/';
		$file      = $this->base_directory . $directory . $file_name;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
