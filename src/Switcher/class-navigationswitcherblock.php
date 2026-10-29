<?php
/**
 * Language switcher for the Navigation block.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Switcher;

use ShapeTranslate\Language\LanguageTag;
use WP_Block;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Renders language links as native Navigation block items.
 *
 * Block themes build their header menu from `core/navigation`, which only styles
 * its own child blocks. Rather than emitting standalone markup that a theme would
 * not recognize, each language is rendered through `core/navigation-link` (or one
 * `core/navigation-submenu` in dropdown mode) so the switcher inherits the menu's
 * spacing, colors, typography, and responsive overlay for free.
 */
final class NavigationSwitcherBlock {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'shapetranslate/navigation-language-switcher';

	/**
	 * Token swapped for a language label after WordPress renders the link.
	 *
	 * WordPress escapes the label it receives, so a flag image cannot be passed
	 * through as markup. A plain token survives escaping unchanged and is replaced
	 * once the surrounding link markup exists.
	 *
	 * @var string
	 */
	const LABEL_TOKEN = 'shapetranslate-switcher-label-token';

	/**
	 * Language switcher service.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Language tag formatter.
	 *
	 * @var LanguageTag
	 */
	private $language_tag;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher $switcher     Language switcher service.
	 * @param LanguageTag|null $language_tag Optional language tag formatter.
	 */
	public function __construct( LanguageSwitcher $switcher, ?LanguageTag $language_tag = null ) {
		$this->switcher     = $switcher;
		$this->language_tag = null === $language_tag ? new LanguageTag() : $language_tag;
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Saved content, unused for a dynamic block.
	 * @param WP_Block|null        $block      Parsed block instance.
	 * @return string
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $content );
		$attributes = is_array( $attributes ) ? $attributes : array();

		if ( ! $this->switcher->is_available() ) {
			return '';
		}

		$args  = $this->build_args( $attributes );
		$items = $this->switcher->get_items( $args );

		if ( empty( $items ) ) {
			return '';
		}

		$context = $block instanceof WP_Block ? $block->context : array();

		return ! empty( $attributes['dropdown'] )
			? $this->render_submenu( $items, $attributes, $context )
			: $this->render_links( $items, $attributes, $context );
	}

	/**
	 * Renders one navigation item per language.
	 *
	 * @param array<int, array<string, mixed>> $items      Switcher items.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @param array<string, mixed>             $context    Parent block context.
	 * @return string
	 */
	private function render_links( array $items, array $attributes, array $context ) {
		$output = '';

		foreach ( $items as $item ) {
			$output .= $this->render_navigation_block( 'core/navigation-link', $item, $attributes, $context );
		}

		return $output;
	}

	/**
	 * Renders the current language as a submenu holding every language.
	 *
	 * The language on the toggle is listed inside it as well, marked as the
	 * current one. That is how a language menu is read everywhere else: the
	 * closed control names where you are, and opening it shows the full set with
	 * your place in it — a list that silently omits the language you are reading
	 * reads as a list of the languages that exist. "Hide the current language"
	 * takes it out for anyone who wants the shorter list.
	 *
	 * @param array<int, array<string, mixed>> $items      Switcher items.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @param array<string, mixed>             $context    Parent block context.
	 * @return string
	 */
	private function render_submenu( array $items, array $attributes, array $context ) {
		$parent = null;

		foreach ( $items as $item ) {
			if ( ! empty( $item['current'] ) ) {
				$parent = $item;
				break;
			}
		}

		if ( null === $parent ) {
			$parent = reset( $items );
		}

		$children = array();

		foreach ( $items as $item ) {
			if ( $item['id'] === $parent['id'] && ! empty( $attributes['hideCurrent'] ) ) {
				continue;
			}

			$children[] = $this->build_navigation_block( 'core/navigation-link', $item, $attributes, $context );
		}

		/*
		 * A submenu with nothing under it is a menu item wearing an arrow that
		 * opens onto nothing, so the one language left renders as the plain link
		 * it would have been.
		 */
		if ( empty( $children ) ) {
			return $this->render_navigation_block( 'core/navigation-link', $parent, $attributes, $context );
		}

		$submenu = new WP_Block(
			array(
				'blockName'   => 'core/navigation-submenu',
				'attrs'       => $this->build_item_attributes( $parent, $attributes ),
				'innerBlocks' => $children,
			),
			$context
		);

		return $this->prepare_output( $submenu->render(), $this->collect_labels( $items, $parent, $attributes ), $attributes );
	}

	/**
	 * Builds and renders one navigation block for a language.
	 *
	 * @param string               $block_name Core block name.
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $context    Parent block context.
	 * @return string
	 */
	private function render_navigation_block( $block_name, array $item, array $attributes, array $context ) {
		$block = $this->build_navigation_block( $block_name, $item, $attributes, $context );

		return $this->prepare_output( $block->render(), array( $item ), $attributes );
	}

	/**
	 * Turns rendered core markup into the switcher's final output.
	 *
	 * The language attributes go on first, while the markup is still exactly
	 * what core produced: the flag is an image, and running a parser over a
	 * document that already has one inside a link label buys nothing.
	 *
	 * @param string                           $output     Rendered navigation markup.
	 * @param array<int, array<string, mixed>> $items      Items the markup was built from.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @return string
	 */
	private function prepare_output( $output, array $items, array $attributes ) {
		return $this->replace_label_tokens(
			$this->add_language_attributes( $output, $items ),
			$items,
			$attributes
		);
	}

	/**
	 * Marks each language link with the language it leads to.
	 *
	 * A switcher is the one menu whose links do not speak the language of the
	 * page around them, which is the case `hreflang` and `lang` exist for: the
	 * first tells a crawler this is the same page in another language, the
	 * second stops a screen reader from pronouncing the name with the wrong
	 * voice. The rest of the plugin already says both — the widget, the
	 * shortcode, and the classic menu item — and only this block was silent.
	 *
	 * Links are matched to languages through the class the block puts on each
	 * list item rather than by position or URL, because two languages can share
	 * a URL whenever an untranslated page sends them both to the home page.
	 *
	 * @param string                           $output Rendered navigation markup.
	 * @param array<int, array<string, mixed>> $items  Items the markup was built from.
	 * @return string
	 */
	private function add_language_attributes( $output, array $items ) {
		if ( ! is_string( $output ) || '' === $output || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return is_string( $output ) ? $output : '';
		}

		$languages = array();

		foreach ( $items as $item ) {
			if ( ! isset( $item['id'], $item['language'] ) || ! is_array( $item['language'] ) ) {
				continue;
			}

			$tag = $this->language_tag->format( $item['language'] );

			if ( '' !== $tag ) {
				$languages[ $this->item_class( $item ) ] = $tag;
			}
		}

		if ( empty( $languages ) ) {
			return $output;
		}

		$tags = new WP_HTML_Tag_Processor( $output );
		$tag  = '';

		while ( $tags->next_tag() ) {
			$name = $tags->get_tag();

			if ( 'LI' === $name ) {
				$tag = $this->language_for_classes( $tags->get_attribute( 'class' ), $languages );
				continue;
			}

			if ( 'A' !== $name || '' === $tag ) {
				continue;
			}

			$tags->set_attribute( 'hreflang', $tag );
			$tags->set_attribute( 'lang', $tag );

			if ( null === $tags->get_attribute( 'rel' ) ) {
				$tags->set_attribute( 'rel', 'alternate' );
			}

			/*
			 * One link belongs to each item. What follows is the submenu nested
			 * under this one, and every language in it opens its own list item.
			 */
			$tag = '';
		}

		return $tags->get_updated_html();
	}

	/**
	 * Returns the language tag owned by a list item's class attribute.
	 *
	 * @param string|true|null      $classes   Class attribute as the parser reports it.
	 * @param array<string, string> $languages Language tags keyed by item class.
	 * @return string
	 */
	private function language_for_classes( $classes, array $languages ) {
		if ( ! is_string( $classes ) || '' === $classes ) {
			return '';
		}

		foreach ( preg_split( '/\s+/', trim( $classes ) ) as $class ) {
			if ( isset( $languages[ $class ] ) ) {
				return $languages[ $class ];
			}
		}

		return '';
	}

	/**
	 * Returns the class that identifies one language among the rendered items.
	 *
	 * @param array<string, mixed> $item Switcher item.
	 * @return string
	 */
	private function item_class( array $item ) {
		return 'shapetranslate-switcher__item--' . sanitize_html_class( $item['id'] );
	}

	/**
	 * Builds one core navigation block instance for a language.
	 *
	 * @param string               $block_name Core block name.
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $context    Parent block context.
	 * @return WP_Block
	 */
	private function build_navigation_block( $block_name, array $item, array $attributes, array $context ) {
		return new WP_Block(
			array(
				'blockName' => $block_name,
				'attrs'     => $this->build_item_attributes( $item, $attributes ),
			),
			$context
		);
	}

	/**
	 * Builds the core block attributes representing one language.
	 *
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function build_item_attributes( array $item, array $attributes ) {
		$classes = array( 'shapetranslate-switcher__item', $this->item_class( $item ) );

		if ( ! empty( $item['current'] ) ) {
			$classes[] = 'is-current-language';
		}

		if ( empty( $item['available'] ) ) {
			$classes[] = 'shapetranslate-switcher__item--unavailable';
		}

		$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';

		return array(
			'label'       => $this->build_label( $item, $attributes ),
			'url'         => $url,
			'kind'        => 'custom',
			'type'        => 'custom',
			'className'   => implode( ' ', $classes ),
			'description' => '',
		);
	}

	/**
	 * Returns the label WordPress should render for one language.
	 *
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function build_label( array $item, array $attributes ) {
		$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';

		if ( ! $this->uses_flag( $item, $attributes ) ) {
			return $label;
		}

		return self::LABEL_TOKEN . '-' . sanitize_html_class( $item['id'] );
	}

	/**
	 * Collects every item whose label may still contain a token.
	 *
	 * @param array<int, array<string, mixed>> $items      Switcher items.
	 * @param array<string, mixed>             $parent_item Item rendered as the submenu parent.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_labels( array $items, array $parent_item, array $attributes ) {
		unset( $attributes );
		$collected   = $items;
		$collected[] = $parent_item;

		return $collected;
	}

	/**
	 * Swaps rendered tokens for the label, optionally preceded by a flag.
	 *
	 * @param string                           $output     Rendered navigation markup.
	 * @param array<int, array<string, mixed>> $items      Items that may own a token.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @return string
	 */
	private function replace_label_tokens( $output, array $items, array $attributes ) {
		foreach ( $items as $item ) {
			if ( ! $this->uses_flag( $item, $attributes ) ) {
				continue;
			}

			$token = self::LABEL_TOKEN . '-' . sanitize_html_class( $item['id'] );
			$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';

			/*
			 * A label does not only appear where it is read. A submenu builds the
			 * accessible name of its toggle out of the same string, so the token
			 * lands inside an attribute value as well as in the text, and an image
			 * put there would end the value at its own first quote and spill the
			 * rest of the tag out as attributes of the button. Every attribute
			 * position is therefore given the plain name first, leaving only the
			 * visible label for the flag.
			 */
			$in_attributes = preg_replace_callback(
				'/="[^"]*"/',
				static function ( $matches ) use ( $token, $label ) {
					return str_replace( $token, esc_attr( $label ), $matches[0] );
				},
				$output
			);

			if ( ! is_string( $in_attributes ) ) {

				$output = str_replace( $token, esc_attr( $label ), $output );
				continue;
			}

			$flag = sprintf(
				'<img class="shapetranslate-switcher__flag" src="%1$s" alt="" width="16" height="11" loading="lazy" decoding="async" /> ',
				esc_url( $item['flag_url'] )
			);

			// Flag only keeps the name for screen readers; core styles this class.
			$text = isset( $attributes['display'] ) && 'flag' === $attributes['display']
				? '<span class="screen-reader-text">' . esc_html( $label ) . '</span>'
				: esc_html( $label );

			$output = str_replace( $token, $flag . $text, $in_attributes );
		}

		return $output;
	}

	/**
	 * Reports whether one item should render a flag image.
	 *
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return bool
	 */
	private function uses_flag( array $item, array $attributes ) {
		return ( ! empty( $attributes['showFlags'] ) || ( isset( $attributes['display'] ) && 'flag' === $attributes['display'] ) )
			&& isset( $item['flag_url'] )
			&& is_string( $item['flag_url'] )
			&& '' !== $item['flag_url'];
	}

	/**
	 * Translates block attributes into switcher arguments.
	 *
	 * Layout is fixed: the Navigation block owns the presentation, so only the
	 * dropdown choice — a submenu or a flat list of items — belongs to this block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function build_args( array $attributes ) {
		return array(
			'display'              => isset( $attributes['display'] ) && is_string( $attributes['display'] )
				? $attributes['display']
				: 'native_name',
			'hide_current'         => ! empty( $attributes['hideCurrent'] ) && empty( $attributes['dropdown'] ),
			'hide_missing'         => ! empty( $attributes['hideMissing'] ),
			'unavailable_behavior' => isset( $attributes['unavailableBehavior'] ) && is_string( $attributes['unavailableBehavior'] )
				? $attributes['unavailableBehavior']
				: 'home',
			'show_flags'           => ! empty( $attributes['showFlags'] ),
			'show_disabled'        => ! empty( $attributes['showDisabled'] ),
			'context'              => 'navigation_block',
		);
	}
}
