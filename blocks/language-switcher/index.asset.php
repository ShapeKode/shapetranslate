<?php
// phpcs:ignoreFile -- WordPress requires the generated block asset filename.
/**
 * Language switcher block editor asset metadata.
 *
 * @package ShapeTranslate
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-i18n',
		'wp-server-side-render',
	),
	/*
	 * Versioned against the script file rather than pinned, for the reason
	 * src/class-assets.php sets out: a version bumped once per release leaves
	 * every editor that already has this script serving the old one until the
	 * next release, which is invisible to whoever made the change.
	 *
	 * The fallback covers this file being read before the plugin has booted,
	 * where the class is not loaded and there is nothing to measure against.
	 */
	'version'      => class_exists( 'ShapeTranslate\Assets' )
		? ShapeTranslate\Assets::version( 'blocks/language-switcher/index.js' )
		: '1.0.0',
);
