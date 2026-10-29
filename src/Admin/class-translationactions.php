<?php
/**
 * Translation admin action helper.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes translated-copy URLs and capability decisions.
 */
final class TranslationActions {

	/**
	 * Builds a nonce-protected translated-copy action URL.
	 *
	 * @param int    $source_post_id Source post identifier.
	 * @param string $language_id    Target language identifier.
	 * @return string
	 */
	public function get_create_url( $source_post_id, $language_id ) {
		$url = add_query_arg(
			array(
				'action'         => 'shapetranslate_create_translation',
				'source_post_id' => absint( $source_post_id ),
				'language_id'    => $language_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url(
			$url,
			'shapetranslate_create_translation_' . absint( $source_post_id ) . '_' . $language_id
		);
	}

	/**
	 * Reports whether the current user can create a translation from a post.
	 *
	 * @param WP_Post $post Source post.
	 * @return bool
	 */
	public function can_create_translation( WP_Post $post ) {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		$capability = isset( $post_type->cap->create_posts )
			? $post_type->cap->create_posts
			: $post_type->cap->edit_posts;

		return current_user_can( $capability );
	}
}
