<?php
/**
 * Finds the gettext calls in a theme or plugin.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads PHP source and lists the literal strings passed to gettext.
 *
 * The source is tokenized rather than matched with a pattern, so a call split
 * across lines, a string holding a comma or a parenthesis, and a commented-out
 * call are all read the way PHP reads them. Only literal arguments count: a
 * string built at runtime cannot be known here, and is never the text gettext
 * is later asked for anyway.
 */
final class GettextScanner {

	/**
	 * Gettext functions and the positions of their arguments.
	 *
	 * Each entry lists the argument index of the text, the plural text, the
	 * context and the text domain, with -1 where the function has none.
	 *
	 * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
	 */
	const FUNCTIONS = array(
		'__'         => array( 0, -1, -1, 1 ),
		'_e'         => array( 0, -1, -1, 1 ),
		'esc_html__' => array( 0, -1, -1, 1 ),
		'esc_html_e' => array( 0, -1, -1, 1 ),
		'esc_attr__' => array( 0, -1, -1, 1 ),
		'esc_attr_e' => array( 0, -1, -1, 1 ),
		'_x'         => array( 0, -1, 1, 2 ),
		'_ex'        => array( 0, -1, 1, 2 ),
		'esc_html_x' => array( 0, -1, 1, 2 ),
		'esc_attr_x' => array( 0, -1, 1, 2 ),
		'_n'         => array( 0, 1, -1, 3 ),
		'_nx'        => array( 0, 1, 3, 4 ),
		'_n_noop'    => array( 0, 1, -1, 2 ),
		'_nx_noop'   => array( 0, 1, 2, 3 ),
	);

	/**
	 * Most files read in one scan.
	 *
	 * @var int
	 */
	const MAX_FILES = 5000;

	/**
	 * Largest file read, in bytes.
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 1048576;

	/**
	 * Longest text domain that still fits a string group.
	 *
	 * @var int
	 */
	const MAX_DOMAIN_LENGTH = 90;

	/**
	 * Directories never read: dependencies and build tooling, not the product.
	 *
	 * @var array<int, string>
	 */
	const SKIPPED_DIRECTORIES = array( 'node_modules', '.git', 'tests', 'test' );

	/**
	 * Lists the gettext strings in a directory or a single PHP file.
	 *
	 * @param string $path Directory or file to read.
	 * @return array<int, array{domain: string, context: string, text: string}>
	 */
	public function scan( $path ) {
		$entries = array();

		foreach ( $this->files( (string) $path ) as $file ) {
			// A theme or plugin file the site already runs; read only to find its strings.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$source = file_get_contents( $file );

			if ( ! is_string( $source ) || false === strpos( $source, '_' ) ) {
				continue;
			}

			foreach ( $this->scan_source( $source ) as $entry ) {
				$entries[ $entry['domain'] . "\x1F" . $entry['context'] . "\x04" . $entry['text'] ] = $entry;
			}
		}

		return array_values( $entries );
	}

	/**
	 * Lists the gettext strings in one piece of PHP source.
	 *
	 * @param string $source PHP source.
	 * @return array<int, array{domain: string, context: string, text: string}>
	 */
	public function scan_source( $source ) {
		$tokens  = token_get_all( (string) $source );
		$count   = count( $tokens );
		$entries = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			// PHP 8 reads a call written as `\__()` as one fully qualified name.
			$qualified = defined( 'T_NAME_FULLY_QUALIFIED' ) && is_array( $token ) && T_NAME_FULLY_QUALIFIED === $token[0];

			if ( ! is_array( $token ) || ( T_STRING !== $token[0] && ! $qualified ) ) {
				continue;
			}

			$name = strtolower( ltrim( $token[1], '\\' ) );

			if ( ! isset( self::FUNCTIONS[ $name ] ) || $this->is_method_or_declaration( $tokens, $index ) ) {
				continue;
			}

			$open = $this->next_meaningful( $tokens, $index + 1, $count );

			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$arguments = $this->read_arguments( $tokens, $open + 1, $count );

			foreach ( $this->entries_for( self::FUNCTIONS[ $name ], $arguments ) as $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Builds the entries one call yields from its literal arguments.
	 *
	 * @param array{0: int, 1: int, 2: int, 3: int} $positions Argument positions.
	 * @param array<int, string|null>               $arguments Literal values, null where not literal.
	 * @return array<int, array{domain: string, context: string, text: string}>
	 */
	private function entries_for( array $positions, array $arguments ) {
		list( $text_at, $plural_at, $context_at, $domain_at ) = $positions;

		$text    = isset( $arguments[ $text_at ] ) ? $arguments[ $text_at ] : null;
		$context = -1 === $context_at ? '' : ( isset( $arguments[ $context_at ] ) ? $arguments[ $context_at ] : null );

		// A missing domain argument is WordPress's own "default" domain; one that
		// is not a literal could be anything, so the call is skipped.
		if ( array_key_exists( $domain_at, $arguments ) ) {
			$domain = $arguments[ $domain_at ];
		} else {
			$domain = count( $arguments ) <= $domain_at ? 'default' : null;
		}

		if (
			null === $text || '' === $text || null === $context || null === $domain
			|| '' === $domain || self::MAX_DOMAIN_LENGTH < strlen( $domain )
		) {
			return array();
		}

		$entries = array(
			array(
				'domain'  => $domain,
				'context' => $context,
				'text'    => $text,
			),
		);

		if ( -1 !== $plural_at && isset( $arguments[ $plural_at ] ) && '' !== $arguments[ $plural_at ] ) {
			$entries[] = array(
				'domain'  => $domain,
				'context' => $context,
				'text'    => $arguments[ $plural_at ],
			);
		}

		return $entries;
	}

	/**
	 * Reads a call's arguments, keeping the value of each literal one.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  First token after the opening parenthesis.
	 * @param int               $count  Token count.
	 * @return array<int, string|null> Value per argument, null where not a single literal.
	 */
	private function read_arguments( array $tokens, $index, $count ) {
		$arguments = array();
		$depth     = 0;
		$parts     = array();

		for ( ; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( in_array( $token, array( '(', '[', '{' ), true ) || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( in_array( $token, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					break;
				}

				--$depth;
			} elseif ( ',' === $token && 0 === $depth ) {
				$arguments[] = $this->literal( $parts );
				$parts       = array();

				continue;
			}

			$parts[] = $token;
		}

		if ( ! empty( $parts ) ) {
			$arguments[] = $this->literal( $parts );
		}

		return $arguments;
	}

	/**
	 * Returns the value of an argument made of one string literal.
	 *
	 * @param array<int, mixed> $parts Tokens of one argument.
	 * @return string|null
	 */
	private function literal( array $parts ) {
		if ( 1 !== count( $parts ) || ! is_array( $parts[0] ) || T_CONSTANT_ENCAPSED_STRING !== $parts[0][0] ) {
			return null;
		}

		$raw   = $parts[0][1];
		$quote = $raw[0];
		$body  = substr( $raw, 1, -1 );

		if ( "'" === $quote ) {
			return str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), $body );
		}

		// A double-quoted literal with no variable in it, which the tokenizer
		// guarantees for this token type; its escapes are read as PHP reads them.
		return stripcslashes( str_replace( '\\$', '$', $body ) );
	}

	/**
	 * Reports whether a name is a method call or a function declaration.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Index of the name.
	 * @return bool
	 */
	private function is_method_or_declaration( array $tokens, $index ) {
		for ( $before = $index - 1; $before >= 0; $before-- ) {
			$token = $tokens[ $before ];

			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( ! is_array( $token ) ) {
				return false;
			}

			return in_array( $token[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true )
				|| ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $token[0] );
		}

		return false;
	}

	/**
	 * Returns the index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $index  Index to start from.
	 * @param int               $count  Token count.
	 * @return int|null
	 */
	private function next_meaningful( array $tokens, $index, $count ) {
		for ( ; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Lists the PHP files under a path, bounded in number and size.
	 *
	 * @param string $path Directory or file.
	 * @return array<int, string>
	 */
	private function files( $path ) {
		if ( is_file( $path ) ) {
			return 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ? array( $path ) : array();
		}

		if ( ! is_dir( $path ) ) {
			return array();
		}

		$files = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS )
			);
		} catch ( UnexpectedValueException $exception ) {
			return array();
		}

		/** @var SplFileInfo $file */
		foreach ( $iterator as $file ) {
			if ( self::MAX_FILES <= count( $files ) ) {
				break;
			}

			if (
				! $file->isFile()
				|| 'php' !== strtolower( $file->getExtension() )
				|| self::MAX_FILE_SIZE < $file->getSize()
				|| $this->is_skipped( $file->getPathname(), $path )
			) {
				continue;
			}

			$files[] = $file->getPathname();
		}

		return $files;
	}

	/**
	 * Reports whether a file sits in a directory that is never read.
	 *
	 * @param string $file File path.
	 * @param string $root Scanned root.
	 * @return bool
	 */
	private function is_skipped( $file, $root ) {
		$relative = str_replace( '\\', '/', substr( $file, strlen( $root ) ) );

		foreach ( explode( '/', trim( $relative, '/' ) ) as $segment ) {
			if ( in_array( strtolower( $segment ), self::SKIPPED_DIRECTORIES, true ) ) {
				return true;
			}
		}

		return false;
	}
}
