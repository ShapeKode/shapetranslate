<?php
/**
 * Catalog of options ShapeTranslate offers for translation on its own.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the options every site has, so its first translation needs no code.
 *
 * A site owner should not have to write PHP or XML to translate their own site
 * title or a footer widget heading. Those values live in options WordPress
 * itself defines, so ShapeTranslate can name them up front. Everything a plugin or
 * theme owns still comes from its own wpml-config.xml, or from
 * `shapetranslate_register_string()`.
 *
 * The shape is the one `OptionStringTranslator` consumes: a context, then the
 * options in it, then the nested keys inside each option. A `*` matches any
 * name at that level. Contexts are stored as string groups, so they are stable
 * identifiers rather than translated labels.
 */
final class CoreStringCatalog {

	/**
	 * Context holding the options WordPress defines for the site itself.
	 *
	 * @var string
	 */
	const SITE_CONTEXT = 'WordPress';

	/**
	 * Context holding widget instance values.
	 *
	 * @var string
	 */
	const WIDGET_CONTEXT = 'Widgets';

	/**
	 * Returns the declarations to register, keyed by context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function declarations() {
		$declarations = array(
			self::SITE_CONTEXT   => array(
				'blogname'        => true,
				'blogdescription' => true,
				'date_format'     => true,
				'time_format'     => true,
			),
			self::WIDGET_CONTEXT => array(

				/*
				 * Every widget type stores its instances in `widget_{$id_base}`,
				 * numbered by instance, so one wildcard covers the widgets a site
				 * has today and the ones it adds later. Only the text-bearing
				 * fields are named; a widget's post counts, menu IDs, and feed
				 * URLs are left alone.
				 */
				'widget_*' => array(
					'*' => array(
						'title'   => true,
						'text'    => true,
						'content' => true,
					),
				),
			),
		);

		/**
		 * Filters the options ShapeTranslate registers for translation on its own.
		 *
		 * The array is keyed by context, then by option name or wildcard, then by
		 * the nested keys to translate inside that option. Use `true` to translate
		 * every plain text value below a key.
		 *
		 *     add_filter( 'shapetranslate_core_string_catalog', function ( $catalog ) {
		 *         $catalog['Acme'] = array(
		 *             'acme_settings' => array( 'header_text' => true ),
		 *         );
		 *         return $catalog;
		 *     } );
		 *
		 * @param array<string, array<string, mixed>> $declarations Declarations keyed by context.
		 */
		$filtered = apply_filters( 'shapetranslate_core_string_catalog', $declarations );

		return self::normalize( is_array( $filtered ) ? $filtered : $declarations );
	}

	/**
	 * Drops entries a filter could have made unusable.
	 *
	 * @param array<string, mixed> $declarations Raw declarations.
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize( array $declarations ) {
		$normalized = array();

		foreach ( $declarations as $context => $options ) {
			$context = trim( (string) $context );

			if ( '' === $context || ! is_array( $options ) ) {
				continue;
			}

			foreach ( $options as $name => $keys ) {
				$name = trim( (string) $name );

				if ( '' === $name ) {
					continue;
				}

				$normalized[ $context ][ $name ] = is_array( $keys ) ? $keys : true;
			}
		}

		return $normalized;
	}
}
