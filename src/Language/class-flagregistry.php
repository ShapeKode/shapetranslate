<?php
/**
 * Language flag registry.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves flag images for language records.
 *
 * Flags are resolved from a country code derived from the language locale and
 * served from the bundled `assets/flags` directory. Sites may override a flag
 * per language by placing an image in `uploads/shapetranslate/flags/{locale}.{ext}`
 * or by filtering the resolved code, URL, or markup.
 */
final class FlagRegistry {

	/**
	 * Bundled flag directory, relative to the plugin root.
	 */
	const FLAG_DIRECTORY = 'assets/flags/';

	/**
	 * Rendered flag width in pixels.
	 */
	const FLAG_WIDTH = 18;

	/**
	 * Rendered flag height in pixels.
	 */
	const FLAG_HEIGHT = 12;

	/**
	 * Extensions accepted for custom flag overrides.
	 *
	 * @var array<int, string>
	 */
	private static $custom_extensions = array( 'svg', 'png', 'webp', 'jpg', 'jpeg', 'gif' );

	/**
	 * Cached bundled flag lookups keyed by flag code.
	 *
	 * @var array<string, bool>
	 */
	private static $bundled_cache = array();

	/**
	 * Cached custom flag URLs keyed by locale.
	 *
	 * @var array<string, string>
	 */
	private static $custom_cache = array();

	/**
	 * Returns the flag code for a language record.
	 *
	 * The region subtag of the locale is preferred, because it names the country
	 * a flag represents. Locales without a region fall back to a curated map, and
	 * then to the language code itself when a bundled flag matches it.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return string Lowercase flag code, or an empty string when unresolved.
	 */
	public function get_flag_code( $language ) {
		$code = '';

		if ( is_array( $language ) ) {
			$locale        = strtolower( $this->text_value( $language, 'locale' ) );
			$language_code = strtolower( $this->text_value( $language, 'language_code' ) );
			$explicit      = strtolower( $this->text_value( $language, 'flag_code' ) );
			$region        = $this->region_subtag( $locale );
			$fallbacks     = $this->fallback_codes();

			if ( $this->is_flag_code( $explicit ) ) {
				$code = $explicit;
			} elseif ( '' !== $region ) {
				$code = $region;
			} elseif ( isset( $fallbacks[ $locale ] ) ) {
				$code = $fallbacks[ $locale ];
			} elseif ( isset( $fallbacks[ $language_code ] ) ) {
				$code = $fallbacks[ $language_code ];
			} elseif ( $this->has_bundled_flag( $language_code ) ) {
				$code = $language_code;
			}
		}

		/**
		 * Filters the flag code resolved for a language.
		 *
		 * @param string $code     Resolved flag code.
		 * @param mixed  $language Language record.
		 */
		$code = apply_filters( 'shapetranslate_flag_code', $code, $language );

		return is_string( $code ) && $this->is_flag_code( strtolower( $code ) ) ? strtolower( $code ) : '';
	}

	/**
	 * Returns flag information for a language record.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return array<string, mixed> {
	 *     Flag information.
	 *
	 *     @type string $url    Flag URL, empty when no flag is available.
	 *     @type string $code   Resolved flag code.
	 *     @type int    $width  Rendered width in pixels.
	 *     @type int    $height Rendered height in pixels.
	 *     @type bool   $custom Whether the URL comes from a site override.
	 * }
	 */
	public function get_flag_information( $language ) {
		$code   = $this->get_flag_code( $language );
		$locale = is_array( $language ) ? $this->text_value( $language, 'locale' ) : '';
		$custom = $this->custom_flag_url( $locale );
		$url    = '' !== $custom ? $custom : $this->bundled_flag_url( $code );

		$flag = array(
			'url'    => $url,
			'code'   => $code,
			'width'  => self::FLAG_WIDTH,
			'height' => self::FLAG_HEIGHT,
			'custom' => '' !== $custom,
		);

		/**
		 * Filters the flag information resolved for a language.
		 *
		 * @param array<string, mixed> $flag     Flag information.
		 * @param mixed                $language Language record.
		 */
		$flag = apply_filters( 'shapetranslate_flag', $flag, $language );

		return $this->normalize_flag( $flag );
	}

	/**
	 * Returns the flag URL for a language record.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return string
	 */
	public function get_flag_url( $language ) {
		$flag = $this->get_flag_information( $language );

		return $flag['url'];
	}

	/**
	 * Reports whether a language resolves to a usable flag.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @return bool
	 */
	public function has_flag( $language ) {
		return '' !== $this->get_flag_url( $language );
	}

	/**
	 * Returns escaped flag markup for a language record.
	 *
	 * Flags are decorative by default because ShapeTranslate always renders one
	 * beside a visible language code or name.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param string               $alt      Optional alternative text.
	 * @return string Flag markup, or an empty string when no flag is available.
	 */
	public function get_flag_html( $language, $alt = '' ) {
		$flag = $this->get_flag_information( $language );
		$html = '';

		if ( '' !== $flag['url'] ) {
			$html = sprintf(
				'<img class="shapetranslate-flag" src="%1$s" alt="%2$s" width="%3$d" height="%4$d" loading="lazy" decoding="async" />',
				esc_url( $flag['url'] ),
				esc_attr( is_scalar( $alt ) ? (string) $alt : '' ),
				$flag['width'],
				$flag['height']
			);
		}

		/**
		 * Filters the flag markup rendered for a language.
		 *
		 * @param string               $html     Flag markup.
		 * @param array<string, mixed> $flag     Flag information.
		 * @param mixed                $language Language record.
		 */
		$html = apply_filters( 'shapetranslate_flag_html', $html, $flag, $language );

		return is_string( $html ) ? wp_kses( $html, $this->allowed_flag_html() ) : '';
	}

	/**
	 * Normalizes filtered flag information before use.
	 *
	 * @param mixed $flag Filtered flag information.
	 * @return array<string, mixed>
	 */
	private function normalize_flag( $flag ) {
		$defaults = array(
			'url'    => '',
			'code'   => '',
			'width'  => self::FLAG_WIDTH,
			'height' => self::FLAG_HEIGHT,
			'custom' => false,
		);

		if ( ! is_array( $flag ) ) {
			return $defaults;
		}

		$url  = isset( $flag['url'] ) && is_scalar( $flag['url'] ) ? esc_url_raw( (string) $flag['url'] ) : '';
		$code = isset( $flag['code'] ) && is_scalar( $flag['code'] ) ? strtolower( (string) $flag['code'] ) : '';

		return array(
			'url'    => $url,
			'code'   => $this->is_flag_code( $code ) ? $code : '',
			'width'  => isset( $flag['width'] ) ? absint( $flag['width'] ) : self::FLAG_WIDTH,
			'height' => isset( $flag['height'] ) ? absint( $flag['height'] ) : self::FLAG_HEIGHT,
			'custom' => ! empty( $flag['custom'] ),
		);
	}

	/**
	 * Returns the bundled flag URL for a flag code.
	 *
	 * @param string $code Flag code.
	 * @return string
	 */
	private function bundled_flag_url( $code ) {
		return $this->has_bundled_flag( $code )
			? SHAPETRANSLATE_URL . self::FLAG_DIRECTORY . $code . '.svg'
			: '';
	}

	/**
	 * Reports whether a bundled flag file exists for a flag code.
	 *
	 * @param string $code Flag code.
	 * @return bool
	 */
	private function has_bundled_flag( $code ) {
		if ( ! $this->is_flag_code( $code ) ) {
			return false;
		}

		if ( ! isset( self::$bundled_cache[ $code ] ) ) {
			self::$bundled_cache[ $code ] = is_readable( SHAPETRANSLATE_PATH . self::FLAG_DIRECTORY . $code . '.svg' );
		}

		return self::$bundled_cache[ $code ];
	}

	/**
	 * Returns a site-supplied flag URL for a locale, when one exists.
	 *
	 * @param string $locale Language locale.
	 * @return string
	 */
	private function custom_flag_url( $locale ) {
		if ( '' === $locale || ! preg_match( '/^[A-Za-z0-9_-]{2,20}$/', $locale ) ) {
			return '';
		}

		if ( ! isset( self::$custom_cache[ $locale ] ) ) {
			$uploads = wp_get_upload_dir();
			$found   = '';

			if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && ! empty( $uploads['baseurl'] ) ) {
				foreach ( self::$custom_extensions as $extension ) {
					$relative = 'shapetranslate/flags/' . $locale . '.' . $extension;

					if ( is_readable( trailingslashit( $uploads['basedir'] ) . $relative ) ) {
						$found = trailingslashit( $uploads['baseurl'] ) . $relative;
						break;
					}
				}
			}

			self::$custom_cache[ $locale ] = $found;
		}

		/**
		 * Filters the site-supplied flag URL for a locale.
		 *
		 * @param string $url    Custom flag URL, empty when none was found.
		 * @param string $locale Language locale.
		 */
		$url = apply_filters( 'shapetranslate_custom_flag_url', self::$custom_cache[ $locale ], $locale );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Returns the region subtag of a locale in flag-code form.
	 *
	 * @param string $locale Language locale.
	 * @return string
	 */
	private function region_subtag( $locale ) {
		$subtags = preg_split( '/[-_]/', (string) $locale );

		if ( ! is_array( $subtags ) || count( $subtags ) < 2 ) {
			return '';
		}

		foreach ( array_slice( $subtags, 1 ) as $subtag ) {
			if ( preg_match( '/^[A-Za-z]{2}$/', $subtag ) ) {
				return strtolower( $subtag );
			}
		}

		return '';
	}

	/**
	 * Reports whether a value has the shape of a flag code.
	 *
	 * @param mixed $code Candidate flag code.
	 * @return bool
	 */
	private function is_flag_code( $code ) {
		return is_string( $code ) && 1 === preg_match( '/^[a-z]{2,12}$/', $code );
	}

	/**
	 * Returns a scalar language field as a string.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param string               $key      Field name.
	 * @return string
	 */
	private function text_value( $language, $key ) {
		return isset( $language[ $key ] ) && is_scalar( $language[ $key ] )
			? (string) $language[ $key ]
			: '';
	}

	/**
	 * Returns the markup allowed in flag output.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_flag_html() {
		return array(
			'img'  => array(
				'class'    => true,
				'src'      => true,
				'srcset'   => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'loading'  => true,
				'decoding' => true,
				'style'    => true,
			),
			'span' => array(
				'class' => true,
				'style' => true,
			),
		);
	}

	/**
	 * Returns flag codes for locales and languages without a usable region.
	 *
	 * @return array<string, string>
	 */
	private function fallback_codes() {
		return array(
			'af'  => 'za',
			'am'  => 'et',
			'ar'  => 'sa',
			'arg' => 'es',
			'ary' => 'ma',
			'as'  => 'in',
			'azb' => 'az',
			'ba'  => 'ru',
			'bel' => 'by',
			'bho' => 'in',
			'bn'  => 'bd',
			'bo'  => 'cn',
			'ca'  => 'es',
			'ceb' => 'ph',
			'ckb' => 'kurdistan',
			'cs'  => 'cz',
			'cy'  => 'gb',
			'da'  => 'dk',
			'dsb' => 'de',
			'el'  => 'gr',
			'en'  => 'us',
			'eo'  => 'esperanto',
			'et'  => 'ee',
			'eu'  => 'es',
			'fa'  => 'ir',
			'fil' => 'ph',
			'fur' => 'it',
			'fy'  => 'nl',
			'ga'  => 'ie',
			'gax' => 'et',
			'gd'  => 'gb',
			'gu'  => 'in',
			'ha'  => 'ng',
			'haz' => 'af',
			'he'  => 'il',
			'hi'  => 'in',
			'hsb' => 'de',
			'hy'  => 'am',
			'ig'  => 'ng',
			'ja'  => 'jp',
			'kab' => 'dz',
			'kin' => 'rw',
			'kir' => 'kg',
			'kk'  => 'kz',
			'km'  => 'kh',
			'kn'  => 'in',
			'ko'  => 'kr',
			'lin' => 'cd',
			'lmo' => 'it',
			'lo'  => 'la',
			'lug' => 'ug',
			'mai' => 'in',
			'ml'  => 'in',
			'mlt' => 'mt',
			'mr'  => 'in',
			'mri' => 'nz',
			'ms'  => 'my',
			'my'  => 'mm',
			'nb'  => 'no',
			'ne'  => 'np',
			'nn'  => 'no',
			'oci' => 'fr',
			'pa'  => 'in',
			'ps'  => 'af',
			'rhg' => 'mm',
			'sah' => 'ru',
			'scn' => 'it',
			'si'  => 'lk',
			'skr' => 'pk',
			'sl'  => 'si',
			'sna' => 'zw',
			'snd' => 'pk',
			'sq'  => 'al',
			'sr'  => 'rs',
			'sv'  => 'se',
			'sw'  => 'ke',
			'szl' => 'pl',
			'ta'  => 'in',
			'tah' => 'pf',
			'te'  => 'in',
			'tg'  => 'tj',
			'tir' => 'et',
			'tl'  => 'ph',
			'uk'  => 'ua',
			'ur'  => 'pk',
			'vec' => 'veneto',
			'vi'  => 'vn',
			'wol' => 'sn',
			'xh'  => 'za',
			'yo'  => 'ng',
			'zh'  => 'cn',
			'zu'  => 'za',
		);
	}
}
