<?php
/**
 * SEO plugin integration contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Describes where one SEO plugin keeps the text a translation needs.
 *
 * Every SEO plugin stores the same handful of things — a title, a description,
 * social variants, a primary term — under names of its own. A provider is the
 * list of those names and nothing more: it reads nothing, writes nothing, and
 * loads none of the plugin it describes. SeoMetaModule does the work, so a new
 * plugin is supported by adding a list rather than by adding behavior.
 */
interface SeoProviderInterface {

	/**
	 * Returns a stable identifier for this provider.
	 *
	 * Used to name the provider in filters, so it stays lowercase and unchanging
	 * rather than following the plugin's display name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Reports whether the plugin this provider describes is loaded.
	 *
	 * Detection is by constant only. No class of the other plugin is loaded or
	 * called, so an inactive plugin's files are never touched.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Returns the meta keys holding text written for one language.
	 *
	 * These are copied into a new translation so nobody starts from an empty
	 * SEO panel, and then left alone: once translated, a Bengali meta
	 * description is not something a later edit of the English post should
	 * overwrite.
	 *
	 * @return array<int, string>
	 */
	public function get_translatable_meta_keys();

	/**
	 * Returns the meta keys carried across unchanged.
	 *
	 * Settings rather than text: a social image, a noindex flag, a cornerstone
	 * mark. They are copied once with the translation and then belong to it.
	 *
	 * @return array<int, string>
	 */
	public function get_copied_meta_keys();

	/**
	 * Returns the meta keys naming a term, mapped to the taxonomy they name.
	 *
	 * A primary category stored as an identifier points at one language's term.
	 * Carried across unchanged it would label a Bengali post with an English
	 * category, so these are resolved to the translation of the term instead.
	 *
	 * @return array<string, string> Meta key mapped to taxonomy name.
	 */
	public function get_primary_term_meta_keys();

	/**
	 * Returns the plugin's own options that hold translatable text.
	 *
	 * Shaped for OptionStringTranslator: a context, then the options in it, then
	 * the keys inside each option. This is where a title template lives — the
	 * pattern a post falls back to when it carries no title of its own.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_option_declarations();
}
