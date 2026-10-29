<?php
/**
 * Reader for wpml-config.xml.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\Wpml;

use SimpleXMLElement;

defined( 'ABSPATH' ) || exit;

/**
 * Reads wpml-config.xml files into one normalized rule set.
 *
 * The format is the de facto standard plugins and themes already ship to
 * declare what a multilingual plugin should do with their content. Reading it
 * means an integration written for someone else's plugin works here with no
 * code on either side.
 *
 * Parsing is deliberately separated from applying: this class only turns XML
 * into arrays. WpmlConfigModule decides which of those arrays ShapeTranslate acts
 * on, so a rule the engine cannot honor yet is still parsed and exposed rather
 * than silently dropped.
 */
final class WpmlConfigReader {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'shapetranslate';

	/**
	 * Object cache key holding the parsed rules and their fingerprint.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'wpml_config_rules';

	/**
	 * Field actions the format defines.
	 *
	 * Anything else, including an omitted action, is treated as `ignore`: an
	 * author has to ask for a value to travel before it does.
	 *
	 * @var array<int, string>
	 */
	const ACTIONS = array( 'copy', 'copy-once', 'translate', 'ignore' );

	/**
	 * File locator.
	 *
	 * @var WpmlConfigFiles
	 */
	private $files;

	/**
	 * Rules resolved for this request.
	 *
	 * @var array<string, array>|null
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param WpmlConfigFiles|null $files Optional file locator.
	 */
	public function __construct( ?WpmlConfigFiles $files = null ) {
		$this->files = null === $files ? new WpmlConfigFiles() : $files;
	}

	/**
	 * Returns the normalized rules declared by every located file.
	 *
	 * @return array<string, array>
	 */
	public function get_rules() {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$this->rules = $this->resolve_rules();

		/**
		 * Filters the rules read from wpml-config.xml files.
		 *
		 * Every declaration is exposed here, including the ones the engine does
		 * not act on yet, so an addon can consume them without parsing the files
		 * a second time.
		 *
		 * @param array<string, array> $rules Normalized rules.
		 */
		$filtered = apply_filters( 'shapetranslate_wpml_config_rules', $this->rules );

		if ( is_array( $filtered ) ) {
			$this->rules = array_merge( $this->empty_rules(), $filtered );
		}

		return $this->rules;
	}

	/**
	 * Discards the cached rules.
	 *
	 * The fingerprint already invalidates the cache when a file changes. This
	 * exists for what a fingerprint cannot see, such as a filter that adds a
	 * file conditionally.
	 *
	 * @return void
	 */
	public function flush() {
		$this->rules = null;
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Parses a set of files into one normalized rule set.
	 *
	 * @param array<string, string> $files Absolute paths keyed by context identifier.
	 * @return array<string, array>
	 */
	public function read( array $files ) {
		$rules = $this->empty_rules();

		if ( ! extension_loaded( 'simplexml' ) ) {
			return $rules;
		}

		foreach ( $files as $context => $path ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_file( $path );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $xml instanceof SimpleXMLElement ) {
				continue;
			}

			$this->read_objects( $xml, 'custom-types/custom-type', $rules['post_types'] );
			$this->read_objects( $xml, 'taxonomies/taxonomy', $rules['taxonomies'] );
			$this->read_actions( $xml, 'custom-fields/custom-field', $rules['post_meta'] );
			$this->read_actions( $xml, 'custom-term-fields/custom-term-field', $rules['term_meta'] );
			$this->read_meta_texts( $xml, $rules['meta_texts'] );
			$this->read_admin_texts( $xml, (string) $context, $rules['admin_texts'] );
			$this->read_blocks( $xml, $rules['blocks'] );
		}

		return $rules;
	}

	/**
	 * Returns the rule set shape with every group empty.
	 *
	 * @return array<string, array>
	 */
	private function empty_rules() {
		return array(
			// Object name as key, true to translate and false to leave alone.
			'post_types'  => array(),
			'taxonomies'  => array(),
			// Meta key as key, a normalized action as value.
			'post_meta'   => array(),
			'term_meta'   => array(),
			// Meta keys whose stored text is itself translatable, as nested keys.
			'meta_texts'  => array(),
			// Context as key, then option name as key, then nested option keys.
			'admin_texts' => array(),
			// Block name as key, then xpath expressions and attribute keys.
			'blocks'      => array(),
		);
	}

	/**
	 * Resolves rules from the cache, or by reading the located files.
	 *
	 * @return array<string, array>
	 */
	private function resolve_rules() {
		$files = $this->files->locate();

		if ( empty( $files ) ) {
			return $this->empty_rules();
		}

		$fingerprint = $this->files->fingerprint( $files );
		$cached      = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

		if (
			is_array( $cached )
			&& isset( $cached['fingerprint'], $cached['rules'] )
			&& $cached['fingerprint'] === $fingerprint
			&& is_array( $cached['rules'] )
		) {
			return array_merge( $this->empty_rules(), $cached['rules'] );
		}

		$rules = $this->read( $files );

		wp_cache_set(
			self::CACHE_KEY,
			array(
				'fingerprint' => $fingerprint,
				'rules'       => $rules,
			),
			self::CACHE_GROUP
		);

		return $rules;
	}

	/**
	 * Reads post type or taxonomy declarations.
	 *
	 * A later file wins, so the site's own file can overrule a plugin.
	 *
	 * @param SimpleXMLElement    $xml     Parsed document.
	 * @param string              $xpath   Expression selecting the declarations.
	 * @param array<string, bool> $objects Collected declarations, by reference.
	 * @return void
	 */
	private function read_objects( SimpleXMLElement $xml, $xpath, array &$objects ) {
		foreach ( $this->select( $xml, $xpath ) as $node ) {
			$name = trim( (string) $node );

			if ( '' === $name ) {
				continue;
			}

			$objects[ $name ] = '1' === $this->attribute( $node, 'translate' );
		}
	}

	/**
	 * Reads custom field declarations and their actions.
	 *
	 * @param SimpleXMLElement      $xml    Parsed document.
	 * @param string                $xpath  Expression selecting the declarations.
	 * @param array<string, string> $fields Collected actions keyed by meta key, by reference.
	 * @return void
	 */
	private function read_actions( SimpleXMLElement $xml, $xpath, array &$fields ) {
		foreach ( $this->select( $xml, $xpath ) as $node ) {
			$name = trim( (string) $node );

			if ( '' === $name ) {
				continue;
			}

			$action = $this->attribute( $node, 'action' );

			$fields[ $name ] = in_array( $action, self::ACTIONS, true ) ? $action : 'ignore';
		}
	}

	/**
	 * Reads the meta keys whose stored text is translatable.
	 *
	 * @param SimpleXMLElement     $xml   Parsed document.
	 * @param array<string, mixed> $texts Collected keys, by reference.
	 * @return void
	 */
	private function read_meta_texts( SimpleXMLElement $xml, array &$texts ) {
		foreach ( $this->select( $xml, 'custom-fields-texts/key' ) as $node ) {
			$texts = $this->merge_keys( $texts, $this->keys_to_array( $node ) );
		}
	}

	/**
	 * Reads the option keys a file asks to translate.
	 *
	 * @param SimpleXMLElement     $xml     Parsed document.
	 * @param string               $context Context identifier of the file.
	 * @param array<string, mixed> $texts   Collected options keyed by context, by reference.
	 * @return void
	 */
	private function read_admin_texts( SimpleXMLElement $xml, $context, array &$texts ) {
		foreach ( $this->select( $xml, 'admin-texts/key' ) as $node ) {
			$option = $this->keys_to_array( $node );

			if ( empty( $option ) ) {
				continue;
			}

			if ( ! isset( $texts[ $context ] ) ) {
				$texts[ $context ] = array();
			}

			$texts[ $context ] = $this->merge_keys( $texts[ $context ], $option );
		}
	}

	/**
	 * Reads block translation rules.
	 *
	 * ShapeTranslate has no block string extractor of its own yet, so these rules
	 * are parsed and exposed rather than applied. Reading them now keeps a file
	 * that declares both blocks and options useful for its options today.
	 *
	 * @param SimpleXMLElement     $xml    Parsed document.
	 * @param array<string, mixed> $blocks Collected block rules, by reference.
	 * @return void
	 */
	private function read_blocks( SimpleXMLElement $xml, array &$blocks ) {
		foreach ( $this->select( $xml, 'gutenberg-blocks/gutenberg-block' ) as $node ) {
			if ( '1' !== $this->attribute( $node, 'translate' ) ) {
				continue;
			}

			$name = $this->attribute( $node, 'type' );

			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $blocks[ $name ] ) ) {
				$blocks[ $name ] = array(
					'xpath'     => array(),
					'keys'      => array(),
					'encodings' => array(),
				);
			}

			foreach ( $node->children() as $child ) {
				if ( ! $this->is_supported( $child ) ) {
					continue;
				}

				if ( 'xpath' === $child->getName() ) {
					$expression = trim( (string) $child );

					if ( '' !== $expression ) {
						$blocks[ $name ]['xpath'][] = $expression;
					}

					continue;
				}

				if ( 'key' !== $child->getName() ) {
					continue;
				}

				$keys = $this->keys_to_array( $child );

				if ( empty( $keys ) ) {
					continue;
				}

				$blocks[ $name ]['keys'] = $this->merge_keys( $blocks[ $name ]['keys'], $keys );

				// The format allows one encoding here, and `json` always means a
				// URL encoded JSON payload.
				if ( 'json' === $this->attribute( $child, 'encoding' ) ) {
					$attribute = (string) key( $keys );

					$blocks[ $name ]['encodings'][ $attribute ] = 'json,urlencode';
				}
			}

			$blocks[ $name ]['xpath'] = array_values( array_unique( $blocks[ $name ]['xpath'] ) );
		}
	}

	/**
	 * Turns a key node and its children into a nested array of key names.
	 *
	 * A leaf carries true. Only names are meaningful; the value marks the end of
	 * a path the engine may translate.
	 *
	 * @param SimpleXMLElement $node Key node.
	 * @return array<string, mixed>
	 */
	private function keys_to_array( SimpleXMLElement $node ) {
		if ( ! $this->is_supported( $node ) ) {
			return array();
		}

		$name = $this->attribute( $node, 'name' );

		if ( '' === $name ) {
			return array();
		}

		$children = $node->children();

		if ( 0 === $children->count() ) {
			return array( $name => true );
		}

		$nested = array();

		foreach ( $children as $child ) {
			$nested = $this->merge_keys( $nested, $this->keys_to_array( $child ) );
		}

		// A parent whose children are all unsupported still marks a translatable
		// value, exactly as a leaf would.
		return array( $name => empty( $nested ) ? true : $nested );
	}

	/**
	 * Merges two nested key arrays without changing the type of a leaf.
	 *
	 * `array_merge_recursive()` cannot be used here: it turns two identical
	 * leaves into a list, which would break the path walk that consumes these
	 * arrays.
	 *
	 * @param array<string, mixed> $base      Existing keys.
	 * @param array<string, mixed> $additions Keys to merge in.
	 * @return array<string, mixed>
	 */
	private function merge_keys( array $base, array $additions ) {
		foreach ( $additions as $name => $value ) {
			if ( ! isset( $base[ $name ] ) ) {
				$base[ $name ] = $value;
				continue;
			}

			if ( is_array( $base[ $name ] ) && is_array( $value ) ) {
				$base[ $name ] = $this->merge_keys( $base[ $name ], $value );
				continue;
			}

			// A leaf and a branch describe the same path; the branch is the more
			// specific of the two and wins.
			if ( is_array( $value ) ) {
				$base[ $name ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Reports whether a node uses only the features this reader implements.
	 *
	 * An unknown lookup strategy is skipped rather than approximated, because a
	 * wrong match would translate a value the author never declared.
	 *
	 * @param SimpleXMLElement $node Node to test.
	 * @return bool
	 */
	private function is_supported( SimpleXMLElement $node ) {
		if ( 'key' !== $node->getName() ) {
			return true;
		}

		if ( '' !== $this->attribute( $node, 'type' ) ) {
			return false;
		}

		return in_array( $this->attribute( $node, 'search-method' ), array( '', 'wildcards' ), true );
	}

	/**
	 * Runs an xpath expression and always returns a list.
	 *
	 * @param SimpleXMLElement $xml   Parsed document.
	 * @param string           $xpath Expression.
	 * @return array<int, SimpleXMLElement>
	 */
	private function select( SimpleXMLElement $xml, $xpath ) {
		$nodes = $xml->xpath( $xpath );

		return is_array( $nodes ) ? $nodes : array();
	}

	/**
	 * Returns a trimmed attribute value.
	 *
	 * @param SimpleXMLElement $node Node carrying the attribute.
	 * @param string           $name Attribute name.
	 * @return string
	 */
	private function attribute( SimpleXMLElement $node, $name ) {
		$attributes = $node->attributes();

		if ( null === $attributes || ! isset( $attributes[ $name ] ) ) {
			return '';
		}

		return trim( (string) $attributes[ $name ] );
	}
}
