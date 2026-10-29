<?php
/**
 * Accept-Language negotiation.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Matches a browser Accept-Language header against registered languages.
 *
 * The parser follows RFC 7231 section 5.3.5: comma-separated language ranges,
 * each optionally carrying a `q` weight between 0 and 1. Ranges are compared in
 * descending weight order, and equal weights keep the order the browser sent.
 */
final class BrowserLanguageDetector {

	/**
	 * Maximum ranges read from one header.
	 *
	 * Real browsers send a handful. The cap keeps a hostile header cheap.
	 */
	const MAX_RANGES = 20;

	/**
	 * Parses an Accept-Language header into weighted language ranges.
	 *
	 * Wildcards, malformed ranges, and explicitly unacceptable ranges (`q=0`)
	 * are discarded.
	 *
	 * @param mixed $header Raw header value.
	 * @return array<int, array{tag: string, language: string, quality: float}>
	 */
	public function parse( $header ) {
		if ( ! is_scalar( $header ) ) {
			return array();
		}

		$ranges = array();
		$index  = 0;

		foreach ( explode( ',', (string) $header ) as $part ) {
			if ( count( $ranges ) >= self::MAX_RANGES ) {
				break;
			}

			$segments = explode( ';', $part );
			$tag      = strtolower( str_replace( '_', '-', trim( (string) array_shift( $segments ) ) ) );

			if ( '' === $tag || '*' === $tag || ! preg_match( '/^[a-z]{1,8}(?:-[a-z0-9]{1,8})*$/', $tag ) ) {
				continue;
			}

			$quality = 1.0;

			foreach ( $segments as $segment ) {
				$segment = str_replace( ' ', '', strtolower( $segment ) );

				if ( 0 === strpos( $segment, 'q=' ) && is_numeric( substr( $segment, 2 ) ) ) {
					$quality = (float) substr( $segment, 2 );
				}
			}

			if ( 0.0 >= $quality || 1.0 < $quality ) {
				$quality = 0.0 >= $quality ? 0.0 : 1.0;
			}

			if ( 0.0 === $quality ) {
				continue;
			}

			$ranges[] = array(
				'tag'      => $tag,
				'language' => strtok( $tag, '-' ),
				'quality'  => $quality,
				'order'    => $index,
			);

			++$index;
		}

		usort(
			$ranges,
			static function ( $a, $b ) {
				if ( $a['quality'] === $b['quality'] ) {
					return $a['order'] <=> $b['order'];
				}

				return $b['quality'] <=> $a['quality'];
			}
		);

		foreach ( $ranges as $key => $range ) {
			unset( $ranges[ $key ]['order'] );
		}

		return array_values( $ranges );
	}

	/**
	 * Returns the best registered language for one Accept-Language header.
	 *
	 * Each range is tried in preference order against three progressively looser
	 * comparisons before moving to the next range, so a lower-weighted exact
	 * match never wins over a higher-weighted approximate one.
	 *
	 * @param mixed                            $header    Raw header value.
	 * @param array<int, array<string, mixed>> $languages Candidate language records.
	 * @return array<string, mixed>|null Matched language, or null when none applies.
	 */
	public function match( $header, array $languages ) {
		$candidates = $this->prepare_candidates( $languages );

		if ( empty( $candidates ) ) {
			return null;
		}

		foreach ( $this->parse( $header ) as $range ) {
			foreach ( array( 'locale', 'identity', 'primary' ) as $comparison ) {
				foreach ( $candidates as $candidate ) {
					if ( $this->range_matches( $range, $candidate, $comparison ) ) {
						return $candidate['language'];
					}
				}
			}
		}

		return null;
	}

	/**
	 * Reduces language records to the normalized values used for comparison.
	 *
	 * @param array<int, array<string, mixed>> $languages Candidate language records.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_candidates( array $languages ) {
		$candidates = array();

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || ! isset( $language['id'] ) ) {
				continue;
			}

			$locale = $this->normalize( isset( $language['locale'] ) ? $language['locale'] : '' );
			$code   = $this->normalize( isset( $language['language_code'] ) ? $language['language_code'] : '' );
			$slug   = $this->normalize( isset( $language['url_slug'] ) ? $language['url_slug'] : '' );

			if ( '' === $locale && '' === $code ) {
				continue;
			}

			$candidates[] = array(
				'language' => $language,
				'locale'   => $locale,
				'code'     => $code,
				'slug'     => $slug,
				'primary'  => '' === $locale ? $code : strtok( $locale, '-' ),
			);
		}

		return $candidates;
	}

	/**
	 * Compares one header range to one candidate under a single comparison.
	 *
	 * @param array<string, mixed> $range      Parsed header range.
	 * @param array<string, mixed> $candidate  Prepared candidate.
	 * @param string               $comparison Comparison name.
	 * @return bool
	 */
	private function range_matches( array $range, array $candidate, $comparison ) {
		if ( 'locale' === $comparison ) {
			return '' !== $candidate['locale'] && $range['tag'] === $candidate['locale'];
		}

		if ( 'identity' === $comparison ) {
			return ( '' !== $candidate['code'] && $range['tag'] === $candidate['code'] )
				|| ( '' !== $candidate['slug'] && $range['tag'] === $candidate['slug'] );
		}

		return '' !== $candidate['primary'] && $range['language'] === $candidate['primary'];
	}

	/**
	 * Normalizes a locale, code, or slug for comparison.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return strtolower( str_replace( '_', '-', trim( (string) $value ) ) );
	}
}
