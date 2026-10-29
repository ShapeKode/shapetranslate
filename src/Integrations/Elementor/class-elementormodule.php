<?php
/**
 * Elementor compatibility module.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Elementor;

use ShapeTranslate\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Connects translated-draft creation to optional Elementor document copying.
 */
final class ElementorModule implements ModuleInterface {

	/**
	 * Elementor compatibility service.
	 *
	 * @var ElementorCompatibility
	 */
	private $compatibility;

	/**
	 * Constructor.
	 *
	 * @param ElementorCompatibility $compatibility Elementor compatibility service.
	 */
	public function __construct( ElementorCompatibility $compatibility ) {
		$this->compatibility = $compatibility;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		add_action( 'shapetranslate_translation_created', array( $this, 'copy_translated_document' ), 10, 5 );
	}

	/**
	 * Copies basic Elementor state after a translated draft is linked.
	 *
	 * @param int                  $new_post_id  Target translated post identifier.
	 * @param int                  $source_id    Source post identifier.
	 * @param array<string, mixed> $language     Target language record.
	 * @param string               $group_id     Translation group identifier.
	 * @param array<string, bool>  $copy_options Applied copy behavior.
	 * @return void
	 */
	public function copy_translated_document(
		$new_post_id,
		$source_id,
		$language = array(),
		$group_id = '',
		$copy_options = array()
	) {
		$copy_content = ! isset( $copy_options['copy_content'] ) || (bool) $copy_options['copy_content'];
		$result       = $this->compatibility->copy_document( $new_post_id, $source_id, $copy_content );

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		/**
		 * Fires when Elementor metadata cannot be copied to a translated draft.
		 *
		 * @param \WP_Error            $result       Copy failure.
		 * @param int                  $new_post_id  Target translated post identifier.
		 * @param int                  $source_id    Source post identifier.
		 * @param array<string, mixed> $language     Target language record.
		 * @param string               $group_id     Translation group identifier.
		 */
		do_action(
			'shapetranslate_elementor_document_copy_failed',
			$result,
			absint( $new_post_id ),
			absint( $source_id ),
			$language,
			$group_id
		);
	}
}
