<?php
/**
 * Term translation admin action helper.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes translated-term URLs and capability decisions.
 */
final class TermTranslationActions {

	/**
	 * Builds a nonce-protected translated-term action URL.
	 *
	 * @param int    $source_term_id Source term identifier.
	 * @param string $taxonomy      Taxonomy name.
	 * @param string $language_id   Target language identifier.
	 * @return string
	 */
	public function get_create_url( $source_term_id, $taxonomy, $language_id ) {
		$url = add_query_arg(
			array(
				'action'         => 'shapetranslate_create_term_translation',
				'source_term_id' => absint( $source_term_id ),
				'taxonomy'       => sanitize_key( $taxonomy ),
				'language_id'    => $language_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url(
			$url,
			'shapetranslate_create_term_translation_' . absint( $source_term_id ) . '_' . sanitize_key( $taxonomy ) . '_' . $language_id
		);
	}

	/**
	 * Returns the core editor URL for a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public function get_edit_url( $term_id, $taxonomy ) {
		$taxonomy_object = get_taxonomy( $taxonomy );
		$object_type     = $taxonomy_object && ! empty( $taxonomy_object->object_type )
			? reset( $taxonomy_object->object_type )
			: '';
		$url             = get_edit_term_link( absint( $term_id ), $taxonomy, $object_type );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Reports whether the current user can create a translation from a term.
	 *
	 * @param WP_Term $term Source term.
	 * @return bool
	 */
	public function can_create_translation( WP_Term $term ) {
		$taxonomy = get_taxonomy( $term->taxonomy );

		return $taxonomy
			&& current_user_can( 'edit_term', $term->term_id )
			&& current_user_can( $taxonomy->cap->manage_terms );
	}
}
