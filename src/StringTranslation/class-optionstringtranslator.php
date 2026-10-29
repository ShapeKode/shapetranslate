<?php
/**
 * Translation of values stored inside options.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and translates values stored inside WordPress options.
 *
 * Callers describe an option as a nested array of key names, and every plain
 * text value those keys reach becomes a registered string. The value then
 * arrives through `get_option()` already translated, so the code that owns the
 * option renders it without knowing a translation layer exists. Two sources
 * feed this today: ShapeTranslate's own catalog of core and widget options, and
 * the `admin-texts` declarations read from wpml-config.xml files.
 *
 * Three rules keep that safe. Originals are registered wherever the option is
 * read, so the Strings screen fills itself, but substitution happens on the
 * front end only: an administrator editing the plugin's own settings form must
 * see the stored value, never a translation they would then save over the
 * original. Only arrays and scalars are walked, because replacing values inside
 * a shared object would leak a translation into every later read of it. And a
 * value that is not already plain text is passed through untouched, since the
 * string store would strip its markup in both directions.
 */
final class OptionStringTranslator {

	/**
	 * Maximum string key length accepted by the string repository.
	 *
	 * @var int
	 */
	const MAX_KEY_LENGTH = 191;

	/**
	 * Maximum string group length accepted by the string repository.
	 *
	 * @var int
	 */
	const MAX_GROUP_LENGTH = 100;

	/**
	 * Longest original value worth registering.
	 *
	 * @var int
	 */
	const MAX_VALUE_LENGTH = 10000;

	/**
	 * Registered string service.
	 *
	 * @var StringManager
	 */
	private $strings;

	/**
	 * Declared options, keyed by option name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $options = array();

	/**
	 * Whether a value is currently being resolved.
	 *
	 * Resolving a string reads the language registry, and a configuration file
	 * is free to name any option at all, so the walk has to be non-reentrant.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * Constructor.
	 *
	 * @param StringManager $strings Registered string service.
	 */
	public function __construct( StringManager $strings ) {
		$this->strings = $strings;
	}

	/**
	 * Attaches the option filters for one set of declarations.
	 *
	 * Callers may register more than once. An option already claimed keeps the
	 * context it was first given and merges the new keys into the ones it has,
	 * so a later source can widen what an earlier one asked for without moving
	 * its strings to a different group on the translation screen.
	 *
	 * @param array<string, mixed> $declarations Option keys, keyed by context identifier.
	 * @return void
	 */
	public function register( array $declarations ) {
		foreach ( $declarations as $context => $options ) {
			if ( ! is_array( $options ) ) {
				continue;
			}

			foreach ( $options as $name => $keys ) {
				$this->add_option( (string) $context, (string) $name, $keys );
			}
		}
	}

	/**
	 * Returns the options this translator has attached to.
	 *
	 * @return array<int, string>
	 */
	public function get_option_names() {
		return array_keys( $this->options );
	}

	/**
	 * Registers and optionally substitutes the translatable parts of an option.
	 *
	 * @param mixed  $value  Stored option value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function translate_option( $value, $option = '' ) {
		$option = (string) $option;

		if ( $this->resolving || ! isset( $this->options[ $option ] ) ) {
			return $value;
		}

		$this->resolving = true;

		try {
			$rule = $this->options[ $option ];

			return $this->walk( $value, $rule['keys'], $rule['context'], array( $option ) );
		} finally {
			$this->resolving = false;
		}
	}

	/**
	 * Adds one declared option and attaches its filter.
	 *
	 * A name containing `*` is expanded against the options the site actually
	 * has, which is how a theme declares `theme_mods_*` once and covers every
	 * theme it ships.
	 *
	 * @param string $context Context identifier used as the string group.
	 * @param string $name    Option name or wildcard pattern.
	 * @param mixed  $keys    Nested option keys, or true for the whole value.
	 * @return void
	 */
	private function add_option( $context, $name, $keys ) {
		if ( '' === $context || '' === $name ) {
			return;
		}

		if ( ! $this->is_pattern( $name ) ) {
			$this->attach( $context, $name, $keys );

			return;
		}

		foreach ( array_keys( (array) wp_load_alloptions() ) as $option ) {
			if ( $this->matches( (string) $option, $name ) ) {
				$this->attach( $context, (string) $option, $keys );
			}
		}
	}

	/**
	 * Attaches the filter for one resolved option name.
	 *
	 * @param string $context Context identifier used as the string group.
	 * @param string $option  Option name.
	 * @param mixed  $keys    Nested option keys, or true for the whole value.
	 * @return void
	 */
	private function attach( $context, $option, $keys ) {

		if ( 0 === strpos( $option, 'shapetranslate' ) ) {
			return;
		}

		if ( isset( $this->options[ $option ] ) ) {
			$this->options[ $option ]['keys'] = $this->merge_keys( $this->options[ $option ]['keys'], $keys );

			return;
		}

		$this->options[ $option ] = array(
			'context' => $this->clamp( $context, self::MAX_GROUP_LENGTH ),
			'keys'    => $keys,
		);

		add_filter( 'option_' . $option, array( $this, 'translate_option' ), 10, 2 );
	}

	/**
	 * Walks a value, registering every translatable string it reaches.
	 *
	 * @param mixed              $value   Value at this level.
	 * @param mixed              $keys    Declared keys at this level.
	 * @param string             $group   String group.
	 * @param array<int, string> $path   Key path walked so far.
	 * @return mixed
	 */
	private function walk( $value, $keys, $group, array $path ) {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? $this->resolve( $value, $group, $path ) : $value;
		}

		$children = is_array( $keys ) ? $keys : array();

		if ( empty( $children ) ) {
			// The declaration stops here, so everything below it is translatable.
			foreach ( $value as $name => $item ) {
				$value[ $name ] = $this->walk( $item, $keys, $group, $this->extend( $path, $name ) );
			}

			return $value;
		}

		foreach ( $children as $name => $child ) {
			if ( array_key_exists( $name, $value ) ) {
				$value[ $name ] = $this->walk( $value[ $name ], $child, $group, $this->extend( $path, $name ) );

				continue;
			}

			if ( ! $this->is_pattern( (string) $name ) ) {
				continue;
			}

			foreach ( $value as $key => $item ) {
				if ( $this->matches( (string) $key, (string) $name ) ) {
					$value[ $key ] = $this->walk( $item, $child, $group, $this->extend( $path, $key ) );
				}
			}
		}

		return $value;
	}

	/**
	 * Registers one original value and returns the value to use.
	 *
	 * @param mixed              $value Stored scalar value.
	 * @param string             $group String group.
	 * @param array<int, string> $path  Key path identifying the value.
	 * @return mixed
	 */
	private function resolve( $value, $group, array $path ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$original = trim( $value );

		if ( '' === $original || is_numeric( $original ) || strlen( $value ) > self::MAX_VALUE_LENGTH ) {
			return $value;
		}

		$normalized = sanitize_textarea_field( wp_check_invalid_utf8( $value ) );

		if ( $normalized !== $original ) {
			return $value;
		}

		$key        = $this->string_key( $path );
		$translated = $this->strings->get_string( $group, $key, $value );

		if ( ! $this->should_substitute( $path ) ) {
			return $value;
		}

		if ( ! is_string( $translated ) || '' === $translated || $translated === $normalized ) {
			return $value;
		}

		return $translated;
	}

	/**
	 * Reports whether a translated value may replace the stored one.
	 *
	 * @param array<int, string> $path Key path identifying the value.
	 * @return bool
	 */
	private function should_substitute( array $path ) {
		$option    = isset( $path[0] ) ? (string) $path[0] : '';
		$front_end = ! is_admin() || wp_doing_ajax();

		/**
		 * Filters whether a declared option is translated for this request.
		 *
		 * Administration screens keep the stored value by default, so a settings
		 * form cannot save a translation over its own original. A site that reads
		 * an option to render front-end output from an admin-ajax handler of its
		 * own can opt that request back in here.
		 *
		 * @param bool   $front_end Whether the value may be substituted.
		 * @param string $option    Option name.
		 */
		return (bool) apply_filters( 'shapetranslate_translate_option', $front_end, $option );
	}

	/**
	 * Builds the stable string key for a key path.
	 *
	 * Long paths are shortened around a digest of the full path, so a key stays
	 * inside the repository limit without two different paths colliding.
	 *
	 * @param array<int, string> $path Key path.
	 * @return string
	 */
	private function string_key( array $path ) {
		$key = implode( '.', $path );

		if ( strlen( $key ) <= self::MAX_KEY_LENGTH ) {
			return $key;
		}

		return substr( $key, 0, self::MAX_KEY_LENGTH - 33 ) . '.' . md5( $key );
	}

	/**
	 * Appends a key to a path.
	 *
	 * @param array<int, string> $path Key path.
	 * @param mixed              $name Key to append.
	 * @return array<int, string>
	 */
	private function extend( array $path, $name ) {
		$path[] = (string) $name;

		return $path;
	}

	/**
	 * Merges two declarations for the same option.
	 *
	 * @param mixed $base      Existing keys.
	 * @param mixed $additions Keys to merge in.
	 * @return mixed
	 */
	private function merge_keys( $base, $additions ) {
		if ( ! is_array( $base ) || ! is_array( $additions ) ) {

			return is_array( $base ) ? $additions : $base;
		}

		foreach ( $additions as $name => $value ) {
			$base[ $name ] = isset( $base[ $name ] ) ? $this->merge_keys( $base[ $name ], $value ) : $value;
		}

		return $base;
	}

	/**
	 * Reports whether a declared name is a wildcard pattern.
	 *
	 * @param string $name Declared name.
	 * @return bool
	 */
	private function is_pattern( $name ) {
		return false !== strpos( $name, '*' );
	}

	/**
	 * Reports whether a key matches a wildcard pattern.
	 *
	 * @param string $key     Key to test.
	 * @param string $pattern Declared pattern.
	 * @return bool
	 */
	private function matches( $key, $pattern ) {
		$expression = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );

		return 1 === preg_match( '#^' . $expression . '$#', $key );
	}

	/**
	 * Shortens a value to a byte limit around a digest of the original.
	 *
	 * @param string $value  Value to clamp.
	 * @param int    $length Maximum length in bytes.
	 * @return string
	 */
	private function clamp( $value, $length ) {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		return substr( $value, 0, $length - 33 ) . '.' . md5( $value );
	}
}
