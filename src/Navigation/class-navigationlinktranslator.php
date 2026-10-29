<?php
/**
 * Rewrites a copied menu's links so they point at one language's content.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Navigation;

use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Taxonomy\TermTranslationManager;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Points every link in a menu at the version of what it names in one language.
 *
 * A menu is translated by copying it, which is the only honest starting point:
 * the copy has the same items in the same order, and an editor changes what they
 * want changed. But a copy taken literally is wrong from the first second — the
 * Bengali menu opens with links to the English pages, and a reader who followed
 * one would leave the language they were reading in without being told.
 *
 * So each link is asked what it names. A page, a post, a category that has a
 * version in this language is replaced by it, label and address together. One
 * that has no version yet is left exactly as it was: the item stays in the menu,
 * where an editor can see it and decide, rather than disappearing into a menu
 * that is quietly shorter than the one it was made from.
 */
final class NavigationLinkTranslator {

	/**
	 * Blocks that name one piece of content.
	 *
	 * A submenu is a link that also holds links, and carries the same attributes
	 * as one when its own entry points somewhere.
	 *
	 * @var array<int, string>
	 */
	const LINK_BLOCKS = array( 'core/navigation-link', 'core/navigation-submenu' );

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager
	 */
	private $post_translations;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager
	 */
	private $term_translations;

	/**
	 * Constructor.
	 *
	 * @param PostTranslationManager $post_translations Post translation manager.
	 * @param TermTranslationManager $term_translations Term translation manager.
	 */
	public function __construct( PostTranslationManager $post_translations, TermTranslationManager $term_translations ) {
		$this->post_translations = $post_translations;
		$this->term_translations = $term_translations;
	}

	/**
	 * Returns one menu's content with every link read in one language.
	 *
	 * @param string $content     Serialized blocks.
	 * @param string $language_id Language the menu is being written in.
	 * @return string Content, unchanged when nothing in it had a translation.
	 */
	public function translate_content( $content, $language_id ) {
		$content     = (string) $content;
		$language_id = (string) $language_id;

		if ( '' === trim( $content ) || '' === $language_id ) {
			return $content;
		}

		$blocks = $this->translate_blocks( parse_blocks( $content ), $language_id );

		return serialize_blocks( $blocks );
	}

	/**
	 * Walks a tree of blocks, translating every link it holds.
	 *
	 * Every block's inner blocks are walked, not only a submenu's: a menu may
	 * hold links inside a group, a row, or anything else a theme allows, and a
	 * link three levels down leads out of the language just as surely as one at
	 * the top.
	 *
	 * @param array<int, array<string, mixed>> $blocks      Parsed blocks.
	 * @param string                           $language_id Target language.
	 * @return array<int, array<string, mixed>>
	 */
	private function translate_blocks( array $blocks, $language_id ) {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( in_array( $name, self::LINK_BLOCKS, true ) ) {
				$blocks[ $index ] = $this->translate_link( $block, $language_id );
			} elseif ( NavigationMenus::NAVIGATION_BLOCK === $name ) {
				$blocks[ $index ] = $this->translate_reference( $block, $language_id );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = $this->translate_blocks( $block['innerBlocks'], $language_id );
			}
		}

		return $blocks;
	}

	/**
	 * Points one link at the translation of whatever it names.
	 *
	 * @param array<string, mixed> $block       Link or submenu block.
	 * @param string               $language_id Target language.
	 * @return array<string, mixed>
	 */
	private function translate_link( array $block, $language_id ) {
		$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$id         = isset( $attributes['id'] ) && is_numeric( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
		$kind       = isset( $attributes['kind'] ) && is_string( $attributes['kind'] ) ? $attributes['kind'] : '';

		/*
		 * A custom link names an address rather than a record — there is nothing
		 * to look up, and rewriting it would be guessing at what an editor typed.
		 */
		if ( 0 === $id || ! in_array( $kind, array( 'post-type', 'taxonomy' ), true ) ) {
			return $block;
		}

		$translated = 'post-type' === $kind
			? $this->translated_post( $id, $attributes, $language_id )
			: $this->translated_term( $id, $attributes, $language_id );

		if ( empty( $translated ) ) {
			return $block;
		}

		$block['attrs'] = array_merge( $attributes, $translated );

		return $block;
	}

	/**
	 * Returns the attributes naming a post's version in one language.
	 *
	 * @param int                  $post_id     Post the link names.
	 * @param array<string, mixed> $attributes  Link attributes.
	 * @param string               $language_id Target language.
	 * @return array<string, mixed> Empty when there is no version to point at.
	 */
	private function translated_post( $post_id, array $attributes, $language_id ) {
		if ( ! $this->post_translations->supports_post_type( get_post_type( $post_id ) ) ) {
			return array();
		}

		$translated = absint( $this->post_translations->get_translation( $post_id, $language_id ) );

		if ( 0 === $translated || $translated === $post_id ) {
			return array();
		}

		$post = get_post( $translated );

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$url = get_permalink( $post );

		return array(
			'id'    => $translated,
			'label' => $this->label( $post->post_title, $attributes ),
			'url'   => is_string( $url ) ? $url : '',
		);
	}

	/**
	 * Returns the attributes naming a term's version in one language.
	 *
	 * @param int                  $term_id     Term the link names.
	 * @param array<string, mixed> $attributes  Link attributes.
	 * @param string               $language_id Target language.
	 * @return array<string, mixed> Empty when there is no version to point at.
	 */
	private function translated_term( $term_id, array $attributes, $language_id ) {
		/*
		 * The taxonomy is on the link as `type`, and a term identifier alone is
		 * not enough to read one back.
		 */
		$taxonomy = isset( $attributes['type'] ) && is_string( $attributes['type'] ) ? $attributes['type'] : '';
		$term     = '' === $taxonomy ? null : get_term( $term_id, $taxonomy );

		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$translated = absint( $this->term_translations->get_translation( $term_id, $taxonomy, $language_id ) );

		if ( 0 === $translated || $translated === $term_id ) {
			return array();
		}

		$target = get_term( $translated, $taxonomy );

		if ( ! $target instanceof WP_Term ) {
			return array();
		}

		$url = get_term_link( $target );

		return array(
			'id'    => $translated,
			'label' => $this->label( $target->name, $attributes ),
			'url'   => is_wp_error( $url ) ? '' : $url,
		);
	}

	/**
	 * Points a nested navigation block at one language's menu.
	 *
	 * Only where that menu already exists. Making one here would start a menu
	 * nobody asked for, halfway through copying another.
	 *
	 * @param array<string, mixed> $block       Navigation block.
	 * @param string               $language_id Target language.
	 * @return array<string, mixed>
	 */
	private function translate_reference( array $block, $language_id ) {
		$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$reference  = isset( $attributes['ref'] ) && is_numeric( $attributes['ref'] ) ? absint( $attributes['ref'] ) : 0;

		if ( 0 === $reference ) {
			return $block;
		}

		$translated = absint( $this->post_translations->get_translation( $reference, $language_id ) );

		if ( 0 === $translated || $translated === $reference ) {
			return $block;
		}

		$attributes['ref'] = $translated;
		$block['attrs']    = $attributes;

		return $block;
	}

	/**
	 * Returns the label a translated link should carry.
	 *
	 * A link whose label was never the title — an editor renamed it — keeps the
	 * name they gave it, because that name was a decision and the title was not.
	 *
	 * @param string               $title      Title of the translation.
	 * @param array<string, mixed> $attributes Link attributes as they stand.
	 * @return string
	 */
	private function label( $title, array $attributes ) {
		$current = isset( $attributes['label'] ) && is_string( $attributes['label'] ) ? $attributes['label'] : '';
		$source  = isset( $attributes['id'] ) && is_numeric( $attributes['id'] ) ? get_the_title( absint( $attributes['id'] ) ) : '';

		if ( '' !== $current && '' !== $source && $this->same_text( $current, $source ) ) {
			return (string) $title;
		}

		return '' !== $current ? $current : (string) $title;
	}

	/**
	 * Reports whether two labels say the same thing.
	 *
	 * Core stores a link's label HTML-encoded, and a title read back from the
	 * database is not, so "Tom &amp; Jerry" and "Tom & Jerry" are one name.
	 *
	 * @param string $left  One label.
	 * @param string $right The other.
	 * @return bool
	 */
	private function same_text( $left, $right ) {
		$normalize = static function ( $text ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
		};

		return $normalize( $left ) === $normalize( $right );
	}
}
