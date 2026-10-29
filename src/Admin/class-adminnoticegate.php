<?php
/**
 * ShapeTranslate admin notice gate.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Admin;

use Closure;
use ShapeTranslate\Contracts\ModuleInterface;
use WP_Hook;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps unrelated admin notices off ShapeTranslate screens.
 *
 * Other plugins and themes print promotions, review requests and opt-in
 * banners on every admin page. On ShapeTranslate screens they push the interface
 * down and compete with the plugin's own feedback, so they are removed.
 *
 * What is never removed is anything a reader has to act on. WordPress's own
 * notices stay — an available core update, maintenance mode, recovery mode, a
 * plugin core had to deactivate — and so does every notice printed as an
 * error, whoever printed it. A plugin telling a site that an update is waiting
 * is exactly the message that must survive a screen it did not expect to be
 * read on, so the gate takes only what is promotional by nature: the
 * informational and success banners plugins print everywhere.
 *
 * Two layers are used because notices arrive two different ways:
 *
 * 1. PHP - callbacks on the four core notice hooks are detached before those
 *    hooks run. Callbacks owned by ShapeTranslate, and WordPress's own, are kept.
 * 2. CSS - a small scoped rule hides notice markup that scripts inject into
 *    the page after PHP has finished, which the first layer cannot reach. It
 *    passes over update and error markup for the same reason.
 *
 * ShapeTranslate prints its own notices with the `shapetranslate-notice` class so
 * the CSS layer can tell them apart. Any new ShapeTranslate notice markup must
 * carry that class or it will be hidden along with the foreign ones.
 */
final class AdminNoticeGate implements ModuleInterface {

	/**
	 * Core hooks that print admin notices.
	 *
	 * @var array<int, string>
	 */
	const NOTICE_HOOKS = array(
		'admin_notices',
		'all_admin_notices',
		'user_admin_notices',
		'network_admin_notices',
	);

	/**
	 * WordPress's own notice callbacks, which are never detached.
	 *
	 * These carry updates, recovery mode, and the account and security messages
	 * a site is expected to act on. Hiding any of them would mean a reader who
	 * happens to be on a ShapeTranslate screen is the one reader not told.
	 *
	 * @var array<int, string>
	 */
	const CORE_CALLBACKS = array(
		'update_nag',
		'maintenance_nag',
		'site_admin_notice',
		'wp_recovery_mode_nag',
		'deactivated_plugins_notice',
		'paused_plugins_notice',
		'paused_themes_notice',
		'default_password_nag',
		'new_user_email_admin_notice',
	);

	/**
	 * Core class whose notice callbacks are never detached.
	 *
	 * @var string
	 */
	const CORE_NOTICE_CLASS = 'WP_Privacy_Policy_Content';

	/**
	 * Class prefix identifying callbacks owned by the plugin.
	 *
	 * @var string
	 */
	const OWN_NAMESPACE = 'ShapeTranslate\\';

	/**
	 * Function prefix identifying callbacks owned by the plugin.
	 *
	 * @var string
	 */
	const OWN_FUNCTION_PREFIX = 'shapetranslate';

	/**
	 * Style handle carrying the notice-hiding rule.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'shapetranslate-notice-gate';

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		
		add_action( 'in_admin_header', array( $this, 'detach_foreign_notices' ), PHP_INT_MAX );

		foreach ( self::NOTICE_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'detach_foreign_notices' ), PHP_INT_MIN );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Removes foreign callbacks from the core notice hooks.
	 *
	 * @return void
	 */
	public function detach_foreign_notices() {
		if ( ! $this->is_gated_screen() ) {
			return;
		}

		foreach ( self::NOTICE_HOOKS as $hook ) {
			$this->detach_hook( $hook );
		}
	}

	/**
	 * Hides notice markup injected by scripts after PHP has run.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! $this->is_gated_screen() ) {
			return;
		}

		/*
		 * Everything an update or a failure is announced with is passed over:
		 * the update nag and the inline update rows, and any notice printed as
		 * an error, which is what recovery mode, paused plugins, and a licence
		 * that can no longer fetch updates all use. The legacy `div.error` class
		 * is left alone for the same reason.
		 */
		$selectors = array(
			'#wpbody-content .notice:not(.shapetranslate-notice):not(.notice-error):not(.update-nag):not(.update-message)',
			'#wpbody-content div.updated:not(.shapetranslate-notice)',
		);

		/*
		 * A handle of its own rather than the admin stylesheet, because not
		 * every ShapeTranslate screen loads that one.
		 */
		wp_register_style( self::STYLE_HANDLE, false, array(), SHAPETRANSLATE_VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, implode( ',', $selectors ) . '{display:none!important;}' );
	}

	/**
	 * Determines whether foreign notices should be removed from this request.
	 *
	 * @return bool
	 */
	private function is_gated_screen() {
		$page = $this->current_admin_page();

		if ( '' === $page ) {
			return false;
		}

		/**
		 * Filters whether ShapeTranslate hides unrelated notices on its own screens.
		 *
		 * @param bool   $hide Whether unrelated admin notices should be removed.
		 * @param string $page Current ShapeTranslate admin page slug.
		 */
		return (bool) apply_filters( 'shapetranslate_hide_foreign_admin_notices', true, $page );
	}

	/**
	 * Returns the ShapeTranslate admin page slug for the current request.
	 *
	 * Read from the global WordPress fills in from the page query variable
	 * before any admin screen loads, rather than from the query string itself.
	 * It is the same value, already unslashed, and it is the one the menu
	 * system dispatched on — so this cannot disagree with the screen that is
	 * actually rendering.
	 *
	 * The screen identifier is not used because WordPress derives that from the
	 * translated menu title.
	 *
	 * @global string $plugin_page
	 *
	 * @return string Empty when this is not a ShapeTranslate screen.
	 */
	private function current_admin_page() {
		if ( wp_doing_ajax() || is_network_admin() || is_user_admin() ) {
			return '';
		}

		$page = isset( $GLOBALS['plugin_page'] ) && is_scalar( $GLOBALS['plugin_page'] )
			? sanitize_key( $GLOBALS['plugin_page'] )
			: '';

		return 'shapetranslate' === $page || 0 === strpos( $page, 'shapetranslate-' ) ? $page : '';
	}

	/**
	 * Removes every callback on one hook that ShapeTranslate does not own.
	 *
	 * `remove_all_actions()` is not used because it would also drop the
	 * plugin's own notices and this gate's second pass.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	private function detach_hook( $hook ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return;
		}

		// Copied so the hook can be modified while the copy is walked.
		$registered = $wp_filter[ $hook ]->callbacks;

		foreach ( $registered as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if (
					! isset( $callback['function'] )
					|| $this->is_own_callback( $callback['function'] )
					|| $this->is_core_callback( $callback['function'] )
				) {
					continue;
				}

				remove_action( $hook, $callback['function'], $priority );
			}
		}
	}

	/**
	 * Determines whether a callback is one of WordPress's own.
	 *
	 * Matched by name because core registers these as plain functions, and as
	 * one static method on a core class.
	 *
	 * @param mixed $callback Registered callback.
	 * @return bool
	 */
	private function is_core_callback( $callback ) {
		if ( is_string( $callback ) ) {
			return in_array( $callback, self::CORE_CALLBACKS, true );
		}

		if ( ! is_array( $callback ) || ! isset( $callback[0] ) ) {
			return false;
		}

		$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];

		return is_string( $owner ) && self::CORE_NOTICE_CLASS === ltrim( $owner, '\\' );
	}

	/**
	 * Determines whether a callback belongs to ShapeTranslate.
	 *
	 * Closures cannot be attributed to an owner, so they are treated as
	 * foreign. ShapeTranslate registers its notice callbacks as class methods.
	 *
	 * @param mixed $callback Registered callback.
	 * @return bool
	 */
	private function is_own_callback( $callback ) {
		if ( $callback instanceof Closure ) {
			return false;
		}

		if ( is_string( $callback ) ) {
			return 0 === strpos( $callback, self::OWN_FUNCTION_PREFIX )
				|| 0 === strpos( $callback, self::OWN_NAMESPACE );
		}

		if ( is_object( $callback ) ) {
			return 0 === strpos( get_class( $callback ), self::OWN_NAMESPACE );
		}

		if ( ! is_array( $callback ) || ! isset( $callback[0] ) ) {
			return false;
		}

		$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];

		return is_string( $owner ) && 0 === strpos( $owner, self::OWN_NAMESPACE );
	}
}
