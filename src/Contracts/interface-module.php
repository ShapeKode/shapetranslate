<?php
/**
 * Module contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract implemented by bootable ShapeTranslate modules.
 */
interface ModuleInterface {

	/**
	 * Registers the module's WordPress hooks.
	 *
	 * @return void
	 */
	public function register();
}
