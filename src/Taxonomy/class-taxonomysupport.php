<?php
/**
 * Translatable taxonomy policy.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Taxonomy;

use ShapeTranslate\Settings\PluginSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers public taxonomies supported by the free translation engine.
 */
final class TaxonomySupport {

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Supported taxonomy names, once resolved.
	 *
	 * @var array<int, string>|null
	 */
	private $supported = null;

	/**
	 * Inputs the resolved supported list was built from.
	 *
	 * @var string
	 */
	private $supported_key = '';

	/**
	 * Eligible taxonomy names, once resolved.
	 *
	 * @var array<int, string>|null
	 */
	private $available = null;

	/**
	 * Registered taxonomies the resolved eligible list was built from.
	 *
	 * @var string
	 */
	private $available_key = '';

	/**
	 * Constructor.
	 *
	 * @param PluginSettings|null $settings Optional central settings service.
	 */
	public function __construct( ?PluginSettings $settings = null ) {
		$this->settings = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * Returns supported taxonomy names.
	 *
	 * Public taxonomies with an administrative UI use one generic term workflow.
	 * No commerce, attribute, or other taxonomy-specific data is handled here.
	 *
	 * The answer is held for as long as it stays true. supports() is asked once
	 * per term on a page that lists terms, and reading the taxonomy policy means
	 * re-normalizing every settings section, so asking it each time spends the
	 * whole configuration on a question whose answer cannot have changed. What
	 * can change it is which taxonomies are registered, which taxonomies core
	 * has finished registering at the moment of the call, and the stored
	 * configuration itself; each of those is in the key, and each is cheap to
	 * ask.
	 *
	 * @return array<int, string>
	 */
	public function get_taxonomies() {
		$available = $this->get_available_taxonomies();
		$key       = $this->settings->revision() . '|' . implode( ',', $available );

		if ( null !== $this->supported && $key === $this->supported_key ) {
			return $this->supported;
		}

		$taxonomies = $available;
		$content    = $this->settings->get_section( 'content' );

		if ( 'selected' === $content['taxonomies_mode'] ) {
			$taxonomies = array_values( array_intersect( $taxonomies, $content['taxonomies'] ) );
		}

		$this->supported_key = $key;
		$this->supported     = $taxonomies;

		return $taxonomies;
	}

	/**
	 * Returns all taxonomies eligible for configuration.
	 *
	 * Taxonomies are still being registered while `init` runs, so the registered
	 * set is what this is held against: a call made before a plugin registers
	 * its own must not be the answer given after it has.
	 *
	 * @return array<int, string>
	 */
	public function get_available_taxonomies() {
		$objects = get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		$key     = implode( ',', array_keys( $objects ) );

		if ( null !== $this->available && $key === $this->available_key ) {
			return $this->available;
		}

		$taxonomies = array_keys( $objects );

		/**
		 * Filters taxonomies supported by the core term translation engine.
		 *
		 * Returned taxonomies must remain public and expose an administrative UI.
		 *
		 * The filter is applied once for each set of registered taxonomies rather
		 * than on every question asked of the result, so it must answer from the
		 * taxonomies it is given rather than from the request around it.
		 *
		 * @param array<int, string>       $taxonomies Supported taxonomy names.
		 * @param array<string, \WP_Taxonomy> $objects Taxonomy objects.
		 */
		$filtered  = apply_filters( 'shapetranslate_supported_taxonomies', $taxonomies, $objects );
		$filtered  = is_array( $filtered ) ? $filtered : $taxonomies;
		$supported = array();

		foreach ( $filtered as $taxonomy ) {
			if ( ! is_scalar( $taxonomy ) ) {
				continue;
			}

			$taxonomy = sanitize_key( $taxonomy );
			$object   = get_taxonomy( $taxonomy );

			if ( ! $object || ! $object->public || ! $object->show_ui ) {
				continue;
			}

			$supported[] = $taxonomy;
		}

		$this->available_key = $key;
		$this->available     = array_values( array_unique( $supported ) );

		return $this->available;
	}

	/**
	 * Reports whether a taxonomy is supported.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function supports( $taxonomy ) {
		return in_array( sanitize_key( $taxonomy ), $this->get_taxonomies(), true );
	}

	/**
	 * Returns supported taxonomies that apply a default term.
	 *
	 * Categories always do, through the option WordPress reserves for them.
	 * Any other taxonomy applies one only when it was registered with a
	 * default_term, which is what gives it its own stored option.
	 *
	 * @return array<int, string>
	 */
	public function get_default_term_taxonomies() {
		$taxonomies = array();

		foreach ( $this->get_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( 'category' === $taxonomy || ( $object && ! empty( $object->default_term ) ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}
}
