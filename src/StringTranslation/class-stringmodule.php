<?php
/**
 * Registered string lifecycle module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Flushes queued registrations and cleans values for deleted languages.
 */
final class StringModule implements ModuleInterface {

	/**
	 * String manager.
	 *
	 * @var StringManager
	 */
	private $string_manager;

	/**
	 * Constructor.
	 *
	 * @param StringManager $string_manager String manager.
	 */
	public function __construct( StringManager $string_manager ) {
		$this->string_manager = $string_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'shutdown', array( $this->string_manager, 'flush_registered_strings' ) );
		add_action( 'shapetranslate_language_deleted', array( $this, 'delete_language_translations' ) );
	}

	/**
	 * Deletes string translations that can no longer be selected.
	 *
	 * @param string $language_id Deleted language ID.
	 * @return void
	 */
	public function delete_language_translations( $language_id ) {
		$this->string_manager->delete_language_translations( $language_id );
	}
}
