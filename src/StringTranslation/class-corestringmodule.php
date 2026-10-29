<?php
/**
 * Registration of the options ShapeTranslate translates on its own.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\StringTranslation;

use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Language\LanguageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Claims the catalog options so a new site has strings to translate on day one.
 *
 * This runs before the wpml-config.xml layer, so an option WordPress itself
 * defines keeps its `WordPress` or `Widgets` group even if a plugin's
 * configuration file happens to name it too.
 *
 * Nothing is claimed until a site has languages. Before that there is no
 * translation to resolve, and registering originals would only add rows to a
 * screen the site cannot use yet.
 */
final class CoreStringModule implements ModuleInterface {

	/**
	 * Shared option translator.
	 *
	 * @var OptionStringTranslator
	 */
	private $options;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param OptionStringTranslator $options   Shared option translator.
	 * @param LanguageManager        $languages Language manager.
	 */
	public function __construct( OptionStringTranslator $options, LanguageManager $languages ) {
		$this->options   = $options;
		$this->languages = $languages;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->options->register( CoreStringCatalog::declarations() );
	}

	/**
	 * Reports whether the catalog should be claimed on this request.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		if ( ! $this->languages->has_languages() ) {
			return false;
		}

		/**
		 * Filters whether ShapeTranslate registers its own catalog of options.
		 *
		 * Turning this off leaves the site title, tagline, date and time
		 * patterns, and widget values untranslated, and removes them from the
		 * string translation screen.
		 *
		 * @param bool $enabled Whether the catalog is registered.
		 */
		return (bool) apply_filters( 'shapetranslate_register_core_strings', true );
	}
}
