<?php
/**
 * A template part slug and the language it names.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Integrations\SiteEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the language a template part slug carries.
 *
 * A block theme chooses a template part by slug and nothing else, so the slug is
 * the only place a language can be written where the choice is being made. A
 * part in the default language keeps the name the theme gave it — `header` — and
 * every other language suffixes it: `header___bn`.
 *
 * Leaving the default language unsuffixed is what makes a fallback possible in
 * one query. A request can ask for `header___bn` and `header` together and take
 * whichever comes first, so a language nobody has written this part for renders
 * the part the site already had rather than nothing at all.
 *
 * Three underscores, because a dash is what theme authors use to tell two parts
 * apart — `header-small`, `header-dark` — and a theme shipping one of those
 * beside a language whose identifier matched would find it read as a
 * translation. Nothing is named with three underscores by accident.
 */
final class TemplateSlug {

	/**
	 * Separator between a part's own name and its language.
	 */
	const SEPARATOR = '___';

	/**
	 * The slug as it was given.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * The slug without its language suffix.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * The language identifier the slug carries.
	 *
	 * @var string
	 */
	private $language = '';

	/**
	 * Constructor.
	 *
	 * @param string             $slug         A template part slug.
	 * @param array<int, string> $language_ids Languages the site registers.
	 */
	public function __construct( $slug, array $language_ids = array() ) {
		$this->slug = is_scalar( $slug ) ? (string) $slug : '';
		$this->base = $this->slug;

		$position = strrpos( $this->slug, self::SEPARATOR );

		if ( false === $position || 0 === $position ) {
			return;
		}

		$candidate = substr( $this->slug, $position + strlen( self::SEPARATOR ) );

		/*
		 * Only a language the site actually has. Without the list a part named
		 * `header___old` would read as a translation into a language called
		 * `old`, and the site would stop rendering the part it has.
		 */
		if ( '' === $candidate || ! in_array( $candidate, $language_ids, true ) ) {
			return;
		}

		$this->base     = substr( $this->slug, 0, $position );
		$this->language = $candidate;
	}

	/**
	 * Returns the slug without its language suffix.
	 *
	 * @return string
	 */
	public function base() {
		return $this->base;
	}

	/**
	 * Returns the language the slug names.
	 *
	 * @return string Empty for a part in the default language.
	 */
	public function language() {
		return $this->language;
	}

	/**
	 * Reports whether the slug names a language.
	 *
	 * @return bool
	 */
	public function has_language() {
		return '' !== $this->language;
	}

	/**
	 * Returns the slug this part would have in one language.
	 *
	 * @param string $language_id         Target language identifier.
	 * @param string $default_language_id Identifier of the site's default language.
	 * @return string
	 */
	public function in_language( $language_id, $default_language_id ) {
		$language_id = is_scalar( $language_id ) ? (string) $language_id : '';

		if ( '' === $this->base || '' === $language_id || $language_id === (string) $default_language_id ) {
			return $this->base;
		}

		return $this->base . self::SEPARATOR . $language_id;
	}

	/**
	 * Returns the slug as it was given.
	 *
	 * @return string
	 */
	public function __toString() {
		return $this->slug;
	}
}
