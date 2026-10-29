<?php
/**
 * Public developer API for themes and plugins.
 *
 * Every function here is a thin, guarded wrapper over the ShapeTranslate services.
 * None of them fail when ShapeTranslate is deactivated, has not booted yet, or has
 * no languages configured: they return an empty value instead. Integrators
 * should still wrap calls in function_exists() so their theme or plugin keeps
 * working when ShapeTranslate is not installed at all.
 *
 * A "language reference" accepted by these functions may be a URL slug (`bn`),
 * a language code (`bn`), a WordPress locale (`bn_BD`), a stable language ID,
 * or a full language record. An empty reference always means the language of
 * the current request.
 *
 * Call these on or after `init`. The `shapetranslate_loaded` action fires once the
 * services are available.
 *
 * @package ShapeTranslate
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'shapetranslate_is_active' ) ) {
	/**
	 * Reports whether ShapeTranslate has booted and holds at least one language.
	 *
	 * Use this as the single guard around multilingual code paths.
	 *
	 * @return bool
	 */
	function shapetranslate_is_active() {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return false;
		}

		$manager = ShapeTranslate\Plugin::instance()->languages();

		return $manager instanceof ShapeTranslate\Language\LanguageManager && $manager->has_languages();
	}
}

if ( ! function_exists( 'shapetranslate_get_language' ) ) {
	/**
	 * Resolves any language reference to its full language record.
	 *
	 * A record contains `id`, `name`, `native_name`, `locale`, `language_code`,
	 * `url_slug`, `is_rtl`, and `enabled`.
	 *
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return array<string, mixed>|null Language record, or null when unknown.
	 */
	function shapetranslate_get_language( $language = '' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return null;
		}

		$plugin  = ShapeTranslate\Plugin::instance();
		$manager = $plugin->languages();

		if ( ! $manager instanceof ShapeTranslate\Language\LanguageManager ) {
			return null;
		}

		if ( is_array( $language ) && isset( $language['id'] ) ) {
			$language = $language['id'];
		}

		$reference = is_scalar( $language ) ? trim( (string) $language ) : '';

		if ( '' === $reference ) {
			return $plugin->current_language();
		}

		$urls = $plugin->urls();

		if ( $urls instanceof ShapeTranslate\Routing\LanguageUrlManager ) {
			$record = $urls->resolve_language( $reference );

			if ( is_array( $record ) ) {
				return $record;
			}
		}

		// Locale references, and any request made before routing has booted.
		$normalized = strtolower( str_replace( '-', '_', $reference ) );

		foreach ( $manager->get_languages() as $record ) {
			foreach ( array( 'id', 'url_slug', 'language_code', 'locale' ) as $field ) {
				if (
					isset( $record[ $field ] )
					&& is_scalar( $record[ $field ] )
					&& strtolower( str_replace( '-', '_', (string) $record[ $field ] ) ) === $normalized
				) {
					return $record;
				}
			}
		}

		return null;
	}
}

if ( ! function_exists( 'shapetranslate_get_language_field' ) ) {
	/**
	 * Reads one field from a language reference or record.
	 *
	 * Accepted fields: `slug` (default), `id`, `code`, `locale`, `name`,
	 * `native_name`, `is_rtl`, `enabled`, and `all` for the whole record.
	 *
	 * @param mixed  $language Language reference or record.
	 * @param string $field    Requested field.
	 * @return mixed Field value, false for booleans, or an empty string.
	 */
	function shapetranslate_get_language_field( $language, $field = 'slug' ) {
		$record = is_array( $language ) && isset( $language['url_slug'] )
			? $language
			: shapetranslate_get_language( $language );

		$field = is_scalar( $field ) ? strtolower( trim( (string) $field ) ) : 'slug';

		if ( in_array( $field, array( 'is_rtl', 'rtl', 'enabled' ), true ) ) {
			$key = 'rtl' === $field ? 'is_rtl' : $field;

			return null !== $record && ! empty( $record[ $key ] );
		}

		if ( null === $record ) {
			return '';
		}

		if ( in_array( $field, array( '', 'all', 'record' ), true ) ) {
			return $record;
		}

		$map = array(
			'id'            => 'id',
			'slug'          => 'url_slug',
			'url_slug'      => 'url_slug',
			'code'          => 'language_code',
			'language_code' => 'language_code',
			'locale'        => 'locale',
			'name'          => 'name',
			'native_name'   => 'native_name',
		);

		$key = isset( $map[ $field ] ) ? $map[ $field ] : $field;

		return isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ? (string) $record[ $key ] : '';
	}
}

if ( ! function_exists( 'shapetranslate_current_language' ) ) {
	/**
	 * Returns the language of the current request.
	 *
	 * @param string $field Field to return. See shapetranslate_get_language_field().
	 * @return mixed Field value, or an empty string when unavailable.
	 */
	function shapetranslate_current_language( $field = 'slug' ) {
		return shapetranslate_get_language_field( shapetranslate_get_language(), $field );
	}
}

if ( ! function_exists( 'shapetranslate_default_language' ) ) {
	/**
	 * Returns the configured default language.
	 *
	 * @param string $field Field to return. See shapetranslate_get_language_field().
	 * @return mixed Field value, or an empty string when unavailable.
	 */
	function shapetranslate_default_language( $field = 'slug' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return shapetranslate_get_language_field( null, $field );
		}

		$manager = ShapeTranslate\Plugin::instance()->languages();
		$record  = $manager instanceof ShapeTranslate\Language\LanguageManager
			? $manager->get_default_language()
			: null;

		return shapetranslate_get_language_field( $record, $field );
	}
}

if ( ! function_exists( 'shapetranslate_languages_list' ) ) {
	/**
	 * Returns the registered languages in their configured order.
	 *
	 * Arguments:
	 * - `fields`        Field to return per language, or `all` for full records.
	 *                   Defaults to `slug`.
	 * - `hide_disabled` Whether to skip disabled languages. Defaults to true.
	 *
	 * @param array<string, mixed> $args Optional arguments.
	 * @return array<int, mixed>
	 */
	function shapetranslate_languages_list( $args = array() ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return array();
		}

		$manager = ShapeTranslate\Plugin::instance()->languages();

		if ( ! $manager instanceof ShapeTranslate\Language\LanguageManager ) {
			return array();
		}

		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'fields'        => 'slug',
				'hide_disabled' => true,
			)
		);

		$languages = $manager->get_languages( ! empty( $args['hide_disabled'] ) );
		$field     = is_scalar( $args['fields'] ) ? strtolower( trim( (string) $args['fields'] ) ) : 'slug';

		if ( in_array( $field, array( '', 'all', 'record' ), true ) ) {
			return $languages;
		}

		$values = array();

		foreach ( $languages as $language ) {
			$values[] = shapetranslate_get_language_field( $language, $field );
		}

		return $values;
	}
}

if ( ! function_exists( 'shapetranslate_is_rtl' ) ) {
	/**
	 * Reports whether a language is written right to left.
	 *
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return bool
	 */
	function shapetranslate_is_rtl( $language = '' ) {
		return (bool) shapetranslate_get_language_field( $language, 'is_rtl' );
	}
}

if ( ! function_exists( 'shapetranslate_home_url' ) ) {
	/**
	 * Returns the site home URL for one language.
	 *
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string Home URL, or an empty string when unavailable.
	 */
	function shapetranslate_home_url( $language = '' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return '';
		}

		$urls   = ShapeTranslate\Plugin::instance()->urls();
		$record = shapetranslate_get_language( $language );

		if ( ! $urls instanceof ShapeTranslate\Routing\LanguageUrlManager || null === $record ) {
			return '';
		}

		return $urls->get_language_home_url( $record );
	}
}

if ( ! function_exists( 'shapetranslate_get_post' ) ) {
	/**
	 * Returns the translated post for one language.
	 *
	 * @param int   $post_id  Post identifier.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return int Translated post ID, or zero when there is none.
	 */
	function shapetranslate_get_post( $post_id, $language = '' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return 0;
		}

		$manager = ShapeTranslate\Plugin::instance()->translations();
		$record  = shapetranslate_get_language( $language );

		if ( ! $manager instanceof ShapeTranslate\Content\PostTranslationManager || null === $record ) {
			return 0;
		}

		return absint( $manager->get_translation( absint( $post_id ), (string) $record['id'] ) );
	}
}

if ( ! function_exists( 'shapetranslate_get_post_language' ) ) {
	/**
	 * Returns the language assigned to a post.
	 *
	 * @param int    $post_id Post identifier.
	 * @param string $field   Field to return. See shapetranslate_get_language_field().
	 * @return mixed Field value, or an empty string when unassigned.
	 */
	function shapetranslate_get_post_language( $post_id, $field = 'slug' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return shapetranslate_get_language_field( null, $field );
		}

		$manager = ShapeTranslate\Plugin::instance()->translations();
		$record  = $manager instanceof ShapeTranslate\Content\PostTranslationManager
			? $manager->get_post_language( absint( $post_id ) )
			: null;

		return shapetranslate_get_language_field( $record, $field );
	}
}

if ( ! function_exists( 'shapetranslate_get_post_translations' ) ) {
	/**
	 * Returns every post in a translation group, keyed by language URL slug.
	 *
	 * The requested post is included in the returned map.
	 *
	 * @param int $post_id Post identifier.
	 * @return array<string, int>
	 */
	function shapetranslate_get_post_translations( $post_id ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return array();
		}

		$manager = ShapeTranslate\Plugin::instance()->translations();

		if ( ! $manager instanceof ShapeTranslate\Content\PostTranslationManager ) {
			return array();
		}

		return shapetranslate_key_translations_by_slug( $manager->get_translations( absint( $post_id ) ) );
	}
}

if ( ! function_exists( 'shapetranslate_set_post_language' ) ) {
	/**
	 * Assigns a language to a post.
	 *
	 * @param int   $post_id  Post identifier.
	 * @param mixed $language Language reference.
	 * @return array<string, mixed>|WP_Error Stored assignment, or an error.
	 */
	function shapetranslate_set_post_language( $post_id, $language ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return shapetranslate_api_unavailable_error();
		}

		$manager = ShapeTranslate\Plugin::instance()->translations();

		if ( ! $manager instanceof ShapeTranslate\Content\PostTranslationManager ) {
			return shapetranslate_api_unavailable_error();
		}

		$record = shapetranslate_get_language( $language );

		if ( null === $record ) {
			return shapetranslate_api_unknown_language_error();
		}

		return $manager->set_post_language( absint( $post_id ), (string) $record['id'] );
	}
}

if ( ! function_exists( 'shapetranslate_save_post_translations' ) ) {
	/**
	 * Links posts as translations of one another.
	 *
	 * Keys may be URL slugs, language codes, locales, or language IDs.
	 *
	 *     shapetranslate_save_post_translations( array( 'en' => 12, 'bn' => 34 ) );
	 *
	 * @param array<string, int> $translations   Post IDs keyed by language reference.
	 * @param int                $source_post_id Optional source post for a new group.
	 * @return array<string, mixed>|WP_Error Stored group, or an error.
	 */
	function shapetranslate_save_post_translations( $translations, $source_post_id = 0 ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return shapetranslate_api_unavailable_error();
		}

		$manager = ShapeTranslate\Plugin::instance()->translations();

		if ( ! $manager instanceof ShapeTranslate\Content\PostTranslationManager ) {
			return shapetranslate_api_unavailable_error();
		}

		$resolved = shapetranslate_key_translations_by_id( $translations );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return $manager->link_translations( $resolved, absint( $source_post_id ) );
	}
}

if ( ! function_exists( 'shapetranslate_get_term' ) ) {
	/**
	 * Returns the translated term for one language.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param mixed  $language Optional language reference. Empty means current.
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return int Translated term ID, or zero when there is none.
	 */
	function shapetranslate_get_term( $term_id, $language = '', $taxonomy = '' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return 0;
		}

		$manager  = ShapeTranslate\Plugin::instance()->term_translations();
		$record   = shapetranslate_get_language( $language );
		$taxonomy = shapetranslate_resolve_term_taxonomy( $term_id, $taxonomy );

		if (
			! $manager instanceof ShapeTranslate\Taxonomy\TermTranslationManager
			|| null === $record
			|| '' === $taxonomy
		) {
			return 0;
		}

		return absint( $manager->get_translation( absint( $term_id ), $taxonomy, (string) $record['id'] ) );
	}
}

if ( ! function_exists( 'shapetranslate_get_term_language' ) ) {
	/**
	 * Returns the language assigned to a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $field    Field to return. See shapetranslate_get_language_field().
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return mixed Field value, or an empty string when unassigned.
	 */
	function shapetranslate_get_term_language( $term_id, $field = 'slug', $taxonomy = '' ) {
		$record = null;

		if ( class_exists( 'ShapeTranslate\\Plugin' ) ) {
			$manager  = ShapeTranslate\Plugin::instance()->term_translations();
			$taxonomy = shapetranslate_resolve_term_taxonomy( $term_id, $taxonomy );

			if ( $manager instanceof ShapeTranslate\Taxonomy\TermTranslationManager && '' !== $taxonomy ) {
				$record = $manager->get_term_language( absint( $term_id ), $taxonomy );
			}
		}

		return shapetranslate_get_language_field( $record, $field );
	}
}

if ( ! function_exists( 'shapetranslate_get_term_translations' ) ) {
	/**
	 * Returns every term in a translation group, keyed by language URL slug.
	 *
	 * The requested term is included in the returned map.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return array<string, int>
	 */
	function shapetranslate_get_term_translations( $term_id, $taxonomy = '' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return array();
		}

		$manager  = ShapeTranslate\Plugin::instance()->term_translations();
		$taxonomy = shapetranslate_resolve_term_taxonomy( $term_id, $taxonomy );

		if ( ! $manager instanceof ShapeTranslate\Taxonomy\TermTranslationManager || '' === $taxonomy ) {
			return array();
		}

		return shapetranslate_key_translations_by_slug( $manager->get_translations( absint( $term_id ), $taxonomy ) );
	}
}

if ( ! function_exists( 'shapetranslate_set_term_language' ) ) {
	/**
	 * Assigns a language to a term.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param mixed  $language Language reference.
	 * @param string $taxonomy Optional taxonomy. Detected from the term when omitted.
	 * @return array<string, mixed>|WP_Error Stored assignment, or an error.
	 */
	function shapetranslate_set_term_language( $term_id, $language, $taxonomy = '' ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return shapetranslate_api_unavailable_error();
		}

		$manager  = ShapeTranslate\Plugin::instance()->term_translations();
		$taxonomy = shapetranslate_resolve_term_taxonomy( $term_id, $taxonomy );

		if ( ! $manager instanceof ShapeTranslate\Taxonomy\TermTranslationManager || '' === $taxonomy ) {
			return shapetranslate_api_unavailable_error();
		}

		$record = shapetranslate_get_language( $language );

		if ( null === $record ) {
			return shapetranslate_api_unknown_language_error();
		}

		return $manager->set_term_language( absint( $term_id ), $taxonomy, (string) $record['id'] );
	}
}

if ( ! function_exists( 'shapetranslate_save_term_translations' ) ) {
	/**
	 * Links terms as translations of one another.
	 *
	 * Keys may be URL slugs, language codes, locales, or language IDs.
	 *
	 *     shapetranslate_save_term_translations( array( 'en' => 5, 'bn' => 9 ) );
	 *
	 * @param array<string, int> $translations   Term IDs keyed by language reference.
	 * @param string             $taxonomy       Optional taxonomy. Detected when omitted.
	 * @param int                $source_term_id Optional source term for a new group.
	 * @return array<string, mixed>|WP_Error Stored group, or an error.
	 */
	function shapetranslate_save_term_translations( $translations, $taxonomy = '', $source_term_id = 0 ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return shapetranslate_api_unavailable_error();
		}

		$manager = ShapeTranslate\Plugin::instance()->term_translations();

		if ( ! $manager instanceof ShapeTranslate\Taxonomy\TermTranslationManager ) {
			return shapetranslate_api_unavailable_error();
		}

		$resolved = shapetranslate_key_translations_by_id( $translations );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$first    = (int) reset( $resolved );
		$taxonomy = shapetranslate_resolve_term_taxonomy( $first, $taxonomy );

		if ( '' === $taxonomy ) {
			return new WP_Error(
				'shapetranslate_unknown_taxonomy',
				__( 'The taxonomy for these terms could not be determined.', 'shapetranslate' )
			);
		}

		return $manager->link_translations( $resolved, $taxonomy, absint( $source_term_id ) );
	}
}

if ( ! function_exists( 'shapetranslate_is_translated_post_type' ) ) {
	/**
	 * Reports whether a post type participates in translation.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	function shapetranslate_is_translated_post_type( $post_type ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return false;
		}

		$manager = ShapeTranslate\Plugin::instance()->translations();

		return $manager instanceof ShapeTranslate\Content\PostTranslationManager
			&& $manager->supports_post_type( $post_type );
	}
}

if ( ! function_exists( 'shapetranslate_is_translated_taxonomy' ) ) {
	/**
	 * Reports whether a taxonomy participates in translation.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	function shapetranslate_is_translated_taxonomy( $taxonomy ) {
		if ( ! class_exists( 'ShapeTranslate\\Plugin' ) ) {
			return false;
		}

		$manager = ShapeTranslate\Plugin::instance()->term_translations();

		return $manager instanceof ShapeTranslate\Taxonomy\TermTranslationManager
			&& $manager->supports_taxonomy( $taxonomy );
	}
}

if ( ! function_exists( 'shapetranslate_object_id' ) ) {
	/**
	 * Returns the translated counterpart of a post or a term.
	 *
	 * This is the single call a theme, a page builder, or a header and footer
	 * builder needs so that a stored object ID follows the language being
	 * rendered. It deliberately mirrors the shape those integrations already use
	 * for other multilingual plugins, so an existing branch can gain ShapeTranslate
	 * support without being restructured:
	 *
	 *     if ( function_exists( 'shapetranslate_object_id' ) ) {
	 *         $header_id = shapetranslate_object_id( $header_id, 'elementor_library' );
	 *     }
	 *
	 * `$type` may name a post type or a taxonomy; taxonomies are recognised by
	 * name. When the object is already in the requested language its own ID
	 * comes back, so the call is safe to make on every request without first
	 * checking which language is current.
	 *
	 * Unlike shapetranslate_get_post() and shapetranslate_get_term(), which report a
	 * missing translation as zero, this function returns the original ID by
	 * default. A builder that swapped in zero would render nothing at all, which
	 * is a worse outcome than showing the untranslated template.
	 *
	 * @param int    $object_id                  Post or term identifier.
	 * @param string $type                       Post type or taxonomy name. Defaults to `post`.
	 * @param bool   $return_original_if_missing Whether to fall back to $object_id when
	 *                                           there is no translation. Defaults to true.
	 * @param mixed  $language                   Optional language reference. Empty means current.
	 * @return int|null Translated object ID, the original ID, or null.
	 */
	function shapetranslate_object_id( $object_id, $type = 'post', $return_original_if_missing = true, $language = '' ) {
		$object_id = absint( $object_id );
		$type      = is_scalar( $type ) ? (string) $type : 'post';
		$fallback  = $return_original_if_missing ? $object_id : null;

		if ( 0 === $object_id ) {
			return $fallback;
		}

		$translated = taxonomy_exists( $type )
			? shapetranslate_get_term( $object_id, $language, $type )
			: shapetranslate_get_post( $object_id, $language );

		return $translated > 0 ? $translated : $fallback;
	}
}

if ( ! function_exists( 'shapetranslate_the_languages' ) ) {
	/**
	 * Renders the language switcher.
	 *
	 * Accepts every argument of shapetranslate_get_language_switcher(), plus `echo`
	 * to return the markup instead of printing it.
	 *
	 * @param array<string, mixed> $args Optional switcher arguments.
	 * @return string Markup when `echo` is false, otherwise an empty string.
	 */
	function shapetranslate_the_languages( $args = array() ) {
		$args  = is_array( $args ) ? $args : array();
		$print = ! isset( $args['echo'] ) || (bool) $args['echo'];

		unset( $args['echo'] );

		$markup = function_exists( 'shapetranslate_get_language_switcher' )
			? shapetranslate_get_language_switcher( $args )
			: '';

		if ( ! $print ) {
			return $markup;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped in LanguageSwitcher::render().
		echo $markup;

		return '';
	}
}

if ( ! function_exists( 'shapetranslate__' ) ) {
	/**
	 * Returns a registered string for one language.
	 *
	 * Registered strings are stored as plain text, so the caller decides how to
	 * escape the result.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string
	 */
	function shapetranslate__( $group, $key, $fallback = '', $language = '' ) {
		if ( ! function_exists( 'shapetranslate_translate_string' ) ) {
			return is_scalar( $fallback ) ? (string) $fallback : '';
		}

		$record      = '' === $language ? null : shapetranslate_get_language( $language );
		$language_id = null === $record ? '' : (string) $record['id'];

		return shapetranslate_translate_string( $group, $key, $fallback, $language_id );
	}
}

if ( ! function_exists( 'shapetranslate_e' ) ) {
	/**
	 * Echoes a registered string, escaped for HTML output.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return void
	 */
	function shapetranslate_e( $group, $key, $fallback = '', $language = '' ) {
		echo esc_html( shapetranslate__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'shapetranslate_esc_html__' ) ) {
	/**
	 * Returns a registered string escaped for HTML output.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string
	 */
	function shapetranslate_esc_html__( $group, $key, $fallback = '', $language = '' ) {
		return esc_html( shapetranslate__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'shapetranslate_esc_html_e' ) ) {
	/**
	 * Echoes a registered string escaped for HTML output.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return void
	 */
	function shapetranslate_esc_html_e( $group, $key, $fallback = '', $language = '' ) {
		echo esc_html( shapetranslate__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'shapetranslate_esc_attr__' ) ) {
	/**
	 * Returns a registered string escaped for an HTML attribute.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return string
	 */
	function shapetranslate_esc_attr__( $group, $key, $fallback = '', $language = '' ) {
		return esc_attr( shapetranslate__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'shapetranslate_esc_attr_e' ) ) {
	/**
	 * Echoes a registered string escaped for an HTML attribute.
	 *
	 * @param mixed $group    Developer-defined group.
	 * @param mixed $key      Stable key within the group.
	 * @param mixed $fallback Optional original value, also used to register.
	 * @param mixed $language Optional language reference. Empty means current.
	 * @return void
	 */
	function shapetranslate_esc_attr_e( $group, $key, $fallback = '', $language = '' ) {
		echo esc_attr( shapetranslate__( $group, $key, $fallback, $language ) );
	}
}

if ( ! function_exists( 'shapetranslate_resolve_term_taxonomy' ) ) {
	/**
	 * Returns the taxonomy for a term, reading it from the term when omitted.
	 *
	 * @param int    $term_id  Term identifier.
	 * @param string $taxonomy Provided taxonomy, if any.
	 * @return string Taxonomy name, or an empty string when unresolved.
	 */
	function shapetranslate_resolve_term_taxonomy( $term_id, $taxonomy = '' ) {
		$taxonomy = is_scalar( $taxonomy ) ? sanitize_key( (string) $taxonomy ) : '';

		if ( '' !== $taxonomy ) {
			return $taxonomy;
		}

		$term = get_term( absint( $term_id ) );

		return $term instanceof WP_Term ? $term->taxonomy : '';
	}
}

if ( ! function_exists( 'shapetranslate_key_translations_by_slug' ) ) {
	/**
	 * Rewrites a language-ID keyed translation map to URL slug keys.
	 *
	 * @param array<string, int> $translations Object IDs keyed by language ID.
	 * @return array<string, int>
	 */
	function shapetranslate_key_translations_by_slug( $translations ) {
		$mapped = array();

		foreach ( (array) $translations as $language_id => $object_id ) {
			$slug = (string) shapetranslate_get_language_field( (string) $language_id, 'slug' );

			if ( '' !== $slug ) {
				$mapped[ $slug ] = absint( $object_id );
			}
		}

		return $mapped;
	}
}

if ( ! function_exists( 'shapetranslate_key_translations_by_id' ) ) {
	/**
	 * Rewrites a caller-supplied translation map to language-ID keys.
	 *
	 * @param array<string, int> $translations Object IDs keyed by language reference.
	 * @return array<string, int>|WP_Error
	 */
	function shapetranslate_key_translations_by_id( $translations ) {
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return new WP_Error(
				'shapetranslate_empty_translations',
				__( 'Provide at least one object keyed by language.', 'shapetranslate' )
			);
		}

		$mapped = array();

		foreach ( $translations as $language => $object_id ) {
			$record = shapetranslate_get_language( $language );

			if ( null === $record ) {
				return shapetranslate_api_unknown_language_error();
			}

			$mapped[ (string) $record['id'] ] = absint( $object_id );
		}

		return $mapped;
	}
}

if ( ! function_exists( 'shapetranslate_api_unavailable_error' ) ) {
	/**
	 * Returns the shared error used when ShapeTranslate services are unavailable.
	 *
	 * @return WP_Error
	 */
	function shapetranslate_api_unavailable_error() {
		return new WP_Error(
			'shapetranslate_not_ready',
			__( 'ShapeTranslate is not available yet. Call this on init or later.', 'shapetranslate' )
		);
	}
}

if ( ! function_exists( 'shapetranslate_api_unknown_language_error' ) ) {
	/**
	 * Returns the shared error used for an unresolved language reference.
	 *
	 * @return WP_Error
	 */
	function shapetranslate_api_unknown_language_error() {
		return new WP_Error(
			'shapetranslate_unknown_language',
			__( 'That language is not registered in ShapeTranslate.', 'shapetranslate' )
		);
	}
}
