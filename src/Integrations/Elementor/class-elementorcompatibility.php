<?php
/**
 * Elementor document compatibility service.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Elementor;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the stable source data needed by a basic Elementor document.
 */
final class ElementorCompatibility {

	/**
	 * Elementor document data meta key.
	 */
	const DATA_META_KEY = '_elementor_data';

	/**
	 * Reports whether Elementor completed its load sequence.
	 *
	 * No Elementor class is autoloaded while the plugin is unavailable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$available = 0 < did_action( 'elementor/loaded' )
			&& defined( 'ELEMENTOR_VERSION' )
			&& class_exists( 'Elementor\\Plugin', false );

		/**
		 * Filters whether the optional Elementor integration is available.
		 *
		 * @param bool $available Whether Elementor completed loading.
		 */
		return (bool) apply_filters( 'shapetranslate_elementor_available', $available );
	}

	/**
	 * Reports whether a post is an Elementor document with stored element data.
	 *
	 * @param int $post_id Post identifier.
	 * @return bool
	 */
	public function is_elementor_document( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		$is_document = $post instanceof WP_Post
			&& 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true )
			&& metadata_exists( 'post', $post_id, self::DATA_META_KEY );

		/**
		 * Filters whether a post should be treated as an Elementor document.
		 *
		 * @param bool         $is_document Whether the post contains an Elementor document.
		 * @param int          $post_id     Post identifier.
		 * @param WP_Post|null $post        Post object when it exists.
		 */
		return (bool) apply_filters( 'shapetranslate_is_elementor_document', $is_document, $post_id, $post );
	}

	/**
	 * Copies one Elementor document into a newly created translated post.
	 *
	 * Element IDs remain document-local and are intentionally preserved so
	 * settings and internal references remain coherent. Generated records are
	 * removed and rebuilt for the target post ID by Elementor when needed.
	 *
	 * @param int  $target_post_id Target translated post identifier.
	 * @param int  $source_post_id Source post identifier.
	 * @param bool $copy_content   Whether to copy widget data.
	 * @return bool|WP_Error True when copied, false when not applicable.
	 */
	public function copy_document( $target_post_id, $source_post_id, $copy_content = true ) {
		$target_post_id = absint( $target_post_id );
		$source_post_id = absint( $source_post_id );

		if ( ! $this->is_available() || ! $this->is_elementor_document( $source_post_id ) ) {
			return false;
		}

		$source = get_post( $source_post_id );
		$target = get_post( $target_post_id );

		if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post || $source_post_id === $target_post_id ) {
			return new WP_Error(
				'invalid_elementor_translation_target',
				__( 'The Elementor translation source and target must be different existing posts.', 'shapetranslate' )
			);
		}

		if ( $source->post_type !== $target->post_type ) {
			return new WP_Error(
				'elementor_translation_post_type_mismatch',
				__( 'Elementor document data cannot be copied between different post types.', 'shapetranslate' )
			);
		}

		if ( metadata_exists( 'post', $target_post_id, self::DATA_META_KEY ) ) {
			return new WP_Error(
				'elementor_translation_target_not_empty',
				__( 'The translated post already contains Elementor document data.', 'shapetranslate' )
			);
		}

		$element_data = get_post_meta( $source_post_id, self::DATA_META_KEY, true );

		if ( $copy_content && ! $this->is_valid_element_data( $element_data ) ) {
			return new WP_Error(
				'invalid_elementor_document_data',
				__( 'The source Elementor document data is invalid and was not copied.', 'shapetranslate' )
			);
		}

		$meta_values = array(
			self::DATA_META_KEY => $copy_content ? $element_data : '[]',
		);

		foreach ( $this->get_copy_meta_keys( $source_post_id, $target_post_id ) as $meta_key ) {
			if ( metadata_exists( 'post', $source_post_id, $meta_key ) ) {
				$meta_values[ $meta_key ] = get_post_meta( $source_post_id, $meta_key, true );
			}
		}

		$previous_meta = array();

		foreach ( $meta_values as $meta_key => $meta_value ) {
			/**
			 * Filters one Elementor metadata value before it is copied.
			 *
			 * @param mixed  $meta_value     Metadata value.
			 * @param string $meta_key       Metadata key.
			 * @param int    $source_post_id Source post identifier.
			 * @param int    $target_post_id Target post identifier.
			 */
			$meta_value = apply_filters(
				'shapetranslate_elementor_copy_meta_value',
				$meta_value,
				$meta_key,
				$source_post_id,
				$target_post_id
			);

			if ( self::DATA_META_KEY === $meta_key && ! $this->is_valid_element_data( $meta_value ) ) {
				$this->rollback_meta( $target_post_id, $previous_meta );

				return new WP_Error(
					'invalid_filtered_elementor_document_data',
					__( 'Filtered Elementor document data is invalid and was not copied.', 'shapetranslate' )
				);
			}

			$previous_meta[ $meta_key ] = array(
				'exists' => metadata_exists( 'post', $target_post_id, $meta_key ),
				'value'  => get_post_meta( $target_post_id, $meta_key, true ),
			);

			$value_to_store = self::DATA_META_KEY === $meta_key && is_string( $meta_value )
				? wp_slash( $meta_value )
				: $meta_value;

			if ( ! update_metadata( 'post', $target_post_id, $meta_key, $value_to_store ) ) {
				$stored_value = get_post_meta( $target_post_id, $meta_key, true );

				if ( $stored_value !== $meta_value ) {
					$this->rollback_meta( $target_post_id, $previous_meta );

					return new WP_Error(
						'elementor_translation_meta_copy_failed',
						__( 'Elementor document metadata could not be copied to the translated post.', 'shapetranslate' )
					);
				}
			}
		}

		$this->clear_generated_meta( $target_post_id, $source_post_id );
		clean_post_cache( $target_post_id );

		/**
		 * Fires after basic Elementor document data is copied independently.
		 *
		 * @param int  $target_post_id Target translated post identifier.
		 * @param int  $source_post_id Source post identifier.
		 * @param bool $copy_content   Whether widget data was copied.
		 */
		do_action(
			'shapetranslate_elementor_document_copied',
			$target_post_id,
			$source_post_id,
			(bool) $copy_content
		);

		return true;
	}

	/**
	 * Returns stable source metadata copied for a basic document.
	 *
	 * @param int $source_post_id Source post identifier.
	 * @param int $target_post_id Target post identifier.
	 * @return array<int, string>
	 */
	private function get_copy_meta_keys( $source_post_id, $target_post_id ) {
		$meta_keys = array(
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_page_settings',
			'_elementor_version',
			'_wp_page_template',
			ElementorThemeBuilder::CONDITIONS_META_KEY,
			ElementorThemeBuilder::POPUP_SETTINGS_META_KEY,
			ElementorThemeBuilder::LOCATION_META_KEY,
		);

		/**
		 * Filters the stable Elementor source metadata copied to translations.
		 *
		 * Generated metadata remains excluded even when added by this filter.
		 *
		 * @param array<int, string> $meta_keys      Default source metadata keys.
		 * @param int                $source_post_id Source post identifier.
		 * @param int                $target_post_id Target post identifier.
		 */
		$filtered_keys = apply_filters(
			'shapetranslate_elementor_copy_meta_keys',
			$meta_keys,
			$source_post_id,
			$target_post_id
		);

		if ( is_array( $filtered_keys ) ) {
			$meta_keys = $filtered_keys;
		}

		$meta_keys = array_map( 'sanitize_key', array_filter( $meta_keys, 'is_string' ) );
		$meta_keys = array_diff( $meta_keys, $this->get_generated_meta_keys(), array( self::DATA_META_KEY ) );

		return array_values( array_unique( array_filter( $meta_keys ) ) );
	}

	/**
	 * Removes generated target records so Elementor rebuilds post-scoped state.
	 *
	 * @param int $target_post_id Target post identifier.
	 * @param int $source_post_id Source post identifier.
	 * @return void
	 */
	private function clear_generated_meta( $target_post_id, $source_post_id ) {
		$meta_keys = $this->get_generated_meta_keys();

		/**
		 * Filters generated Elementor metadata invalidated on a translated copy.
		 *
		 * @param array<int, string> $meta_keys      Generated metadata keys.
		 * @param int                $target_post_id Target post identifier.
		 * @param int                $source_post_id Source post identifier.
		 */
		$filtered_keys = apply_filters(
			'shapetranslate_elementor_generated_meta_keys',
			$meta_keys,
			$target_post_id,
			$source_post_id
		);

		if ( is_array( $filtered_keys ) ) {
			$meta_keys = $filtered_keys;
		}

		foreach ( array_unique( array_map( 'sanitize_key', array_filter( $meta_keys, 'is_string' ) ) ) as $meta_key ) {
			if ( '' !== $meta_key && metadata_exists( 'post', $target_post_id, $meta_key ) ) {
				delete_post_meta( $target_post_id, $meta_key );
			}
		}
	}

	/**
	 * Returns Elementor metadata that is generated for a specific post ID.
	 *
	 * @return array<int, string>
	 */
	private function get_generated_meta_keys() {
		return array(
			'_elementor_css',
			'_elementor_page_assets',
			'_elementor_controls_usage',
			'_elementor_element_cache',
			'_elementor_screenshot',
			'_elementor_inline_svg',
			'_elementor_markdown_cache',
			'elementor-interactions-cache',
		);
	}

	/**
	 * Validates that Elementor data contains a JSON element collection.
	 *
	 * @param mixed $element_data Stored Elementor data.
	 * @return bool
	 */
	private function is_valid_element_data( $element_data ) {
		if ( ! is_string( $element_data ) || '' === $element_data ) {
			return false;
		}

		$decoded = json_decode( $element_data, true );

		return JSON_ERROR_NONE === json_last_error() && is_array( $decoded );
	}

	/**
	 * Restores metadata changed during a failed copy.
	 *
	 * @param int                                             $target_post_id Target post identifier.
	 * @param array<string, array{exists: bool, value:mixed}> $previous_meta Previous metadata state.
	 * @return void
	 */
	private function rollback_meta( $target_post_id, array $previous_meta ) {
		foreach ( $previous_meta as $meta_key => $meta_state ) {
			if ( $meta_state['exists'] ) {
				update_metadata( 'post', $target_post_id, $meta_key, $meta_state['value'] );
			} else {
				delete_post_meta( $target_post_id, $meta_key );
			}
		}
	}
}
