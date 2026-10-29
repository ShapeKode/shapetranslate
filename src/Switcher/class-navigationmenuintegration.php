<?php
/**
 * Classic navigation menu language switcher integration.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Switcher;

use ShapeTranslate\Language\LanguageTag;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a configurable virtual language switcher item to classic menus.
 */
final class NavigationMenuIntegration {

	/**
	 * Virtual custom-link URL used to identify switcher menu items.
	 *
	 * @var string
	 */
	const MENU_ITEM_URL = '#shapetranslate-language-switcher';

	/**
	 * Menu item settings post meta key.
	 *
	 * @var string
	 */
	const SETTINGS_META_KEY = '_shapetranslate_switcher_settings';

	/**
	 * Switcher service.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Language tag formatter.
	 *
	 * @var LanguageTag
	 */
	private $language_tag;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher $switcher Switcher service.
	 */
	public function __construct( LanguageSwitcher $switcher ) {
		$this->switcher     = $switcher;
		$this->language_tag = new LanguageTag();
	}

	/**
	 * Registers classic navigation menu hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_head-nav-menus.php', array( $this, 'register_meta_box' ) );
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_item_fields' ), 10, 5 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_item_fields' ), 10, 3 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_items' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'menu_link_attributes' ), 10, 2 );
	}

	/**
	 * Adds the ShapeTranslate menu-item selector to Appearance > Menus.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		add_meta_box(
			'add-shapetranslate-language-switcher',
			__( 'ShapeTranslate', 'shapetranslate' ),
			array( $this, 'render_meta_box' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Renders the virtual menu item selector.
	 *
	 * @return void
	 */
	public function render_meta_box() {
		$item_index = -1;
		?>
		<div id="shapetranslate-language-switcher" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-object-id]" value="-1" />
							<?php esc_html_e( 'Language switcher', 'shapetranslate' ); ?>
						</label>
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-object]" value="shapetranslate_switcher" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-parent-id]" value="0" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-type]" value="custom" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-title]" value="<?php echo esc_attr__( 'Language switcher', 'shapetranslate' ); ?>" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-url]" value="<?php echo esc_attr( self::MENU_ITEM_URL ); ?>" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-target]" value="" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-classes]" value="shapetranslate-menu-switcher" />
						<input type="hidden" name="menu-item[<?php echo esc_attr( $item_index ); ?>][menu-item-xfn]" value="" />
					</li>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" id="submit-shapetranslate-language-switcher" class="button submit-add-to-menu right" value="<?php echo esc_attr__( 'Add to Menu', 'shapetranslate' ); ?>" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders switcher controls inside a saved menu item.
	 *
	 * @param int       $item_id           Menu item identifier.
	 * @param WP_Post   $menu_item         Menu item object.
	 * @param int       $depth             Menu depth.
	 * @param \stdClass $args              Menu arguments.
	 * @param int       $current_object_id Current object identifier.
	 * @return void
	 */
	public function render_item_fields( $item_id, $menu_item, $depth, $args, $current_object_id ) {
		unset( $depth, $args, $current_object_id );

		if ( ! $this->is_switcher_item( $menu_item ) ) {
			return;
		}

		$settings = $this->get_item_settings( $item_id );
		$field    = 'shapetranslate-switcher[' . absint( $item_id ) . ']';
		?>
		<p class="description description-wide">
			<label for="edit-menu-item-shapetranslate-display-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Display', 'shapetranslate' ); ?><br />
				<select id="edit-menu-item-shapetranslate-display-<?php echo esc_attr( $item_id ); ?>" name="<?php echo esc_attr( $field ); ?>[display]" class="widefat">
					<option value="native_name" <?php selected( $settings['display'], 'native_name' ); ?>><?php esc_html_e( 'Native name', 'shapetranslate' ); ?></option>
					<option value="name" <?php selected( $settings['display'], 'name' ); ?>><?php esc_html_e( 'Language name', 'shapetranslate' ); ?></option>
					<option value="language_code" <?php selected( $settings['display'], 'language_code' ); ?>><?php esc_html_e( 'Language code', 'shapetranslate' ); ?></option>
					<option value="flag" <?php selected( $settings['display'], 'flag' ); ?>><?php esc_html_e( 'Flag only', 'shapetranslate' ); ?></option>
				</select>
			</label>
		</p>
		<p class="description description-wide">
			<label for="edit-menu-item-shapetranslate-layout-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Layout', 'shapetranslate' ); ?><br />
				<select id="edit-menu-item-shapetranslate-layout-<?php echo esc_attr( $item_id ); ?>" name="<?php echo esc_attr( $field ); ?>[layout]" class="widefat">
					<option value="dropdown" <?php selected( $settings['layout'], 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'shapetranslate' ); ?></option>
					<option value="horizontal" <?php selected( $settings['layout'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal list', 'shapetranslate' ); ?></option>
					<option value="vertical" <?php selected( $settings['layout'], 'vertical' ); ?>><?php esc_html_e( 'Vertical list', 'shapetranslate' ); ?></option>
				</select>
			</label>
		</p>
		<p class="description description-wide">
			<label for="edit-menu-item-shapetranslate-unavailable-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Unavailable translations', 'shapetranslate' ); ?><br />
				<select id="edit-menu-item-shapetranslate-unavailable-<?php echo esc_attr( $item_id ); ?>" name="<?php echo esc_attr( $field ); ?>[unavailable_behavior]" class="widefat">
					<option value="hide" <?php selected( $settings['unavailable_behavior'], 'hide' ); ?>><?php esc_html_e( 'Hide languages the site has no content in', 'shapetranslate' ); ?></option>
					<option value="disabled" <?php selected( $settings['unavailable_behavior'], 'disabled' ); ?>><?php esc_html_e( 'Show unavailable', 'shapetranslate' ); ?></option>
					<option value="home" <?php selected( $settings['unavailable_behavior'], 'home' ); ?>><?php esc_html_e( 'Link to language home', 'shapetranslate' ); ?></option>
					<option value="current" <?php selected( $settings['unavailable_behavior'], 'current' ); ?>><?php esc_html_e( 'Keep current URL', 'shapetranslate' ); ?></option>
				</select>
			</label>
		</p>
		<?php $this->render_checkbox( $field, $item_id, 'hide_current', $settings['hide_current'], __( 'Hide current language', 'shapetranslate' ) ); ?>
		<?php $this->render_checkbox( $field, $item_id, 'hide_missing', $settings['hide_missing'], __( 'Hide languages the site has no content in', 'shapetranslate' ) ); ?>
		<?php $this->render_checkbox( $field, $item_id, 'show_flags', $settings['show_flags'], __( 'Show flags', 'shapetranslate' ) ); ?>
		<?php $this->render_checkbox( $field, $item_id, 'show_disabled', $settings['show_disabled'], __( 'Show disabled languages as unavailable', 'shapetranslate' ) ); ?>
		<p class="description description-wide">
			<label for="edit-menu-item-shapetranslate-label-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Accessible label', 'shapetranslate' ); ?><br />
				<input type="text" id="edit-menu-item-shapetranslate-label-<?php echo esc_attr( $item_id ); ?>" name="<?php echo esc_attr( $field ); ?>[aria_label]" class="widefat" value="<?php echo esc_attr( $settings['aria_label'] ); ?>" />
			</label>
		</p>
		<?php
	}

	/**
	 * Saves nonce-protected menu item switcher settings.
	 *
	 * @param int                  $menu_id         Menu identifier.
	 * @param int                  $menu_item_db_id Menu item identifier.
	 * @param array<string, mixed> $args            Core menu item arguments.
	 * @return void
	 */
	public function save_item_fields( $menu_id, $menu_item_db_id, $args ) {
		unset( $menu_id );

		$url = isset( $args['menu-item-url'] ) && is_scalar( $args['menu-item-url'] )
			? (string) $args['menu-item-url']
			: (string) get_post_meta( $menu_item_db_id, '_menu_item_url', true );

		if ( self::MENU_ITEM_URL !== $url || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$nonce = isset( $_POST['update-nav-menu-nonce'] ) && is_scalar( $_POST['update-nav-menu-nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'update-nav_menu' ) ) {
			return;
		}

		$posted = isset( $_POST['shapetranslate-switcher'][ $menu_item_db_id ] )
			&& is_array( $_POST['shapetranslate-switcher'][ $menu_item_db_id ] )
			? map_deep(
				wp_unslash( $_POST['shapetranslate-switcher'][ $menu_item_db_id ] ),
				'sanitize_text_field'
			)
			: array();

		foreach ( array( 'hide_current', 'hide_missing', 'show_flags', 'show_disabled' ) as $boolean_key ) {
			$posted[ $boolean_key ] = isset( $posted[ $boolean_key ] );
		}

		$settings = $this->switcher->normalize_args( $posted );
		$settings = array_intersect_key( $settings, LanguageSwitcher::DEFAULT_ARGS );
		unset( $settings['class_name'], $settings['context'] );

		update_post_meta( $menu_item_db_id, self::SETTINGS_META_KEY, $settings );
	}

	/**
	 * Expands the virtual switcher item into one menu item per language.
	 *
	 * Emitting real menu items, rather than injecting switcher markup into a
	 * single item's anchor, lets the theme's own walker render each language.
	 * Spacing, colors, hover states, and the responsive overlay then come from
	 * the theme for free, exactly as they do for every other menu item.
	 *
	 * This runs on `wp_nav_menu_objects` instead of the earlier
	 * `wp_get_nav_menu_items` so that the generated items appear after
	 * NavigationModule has translated the menu. Each generated URL already
	 * points at its own language, and NavigationModule would otherwise rewrite
	 * every one of them to the language currently being viewed.
	 *
	 * @param array<int, WP_Post> $items Menu items.
	 * @param \stdClass           $args  Menu arguments.
	 * @return array<int, WP_Post>
	 */
	public function filter_menu_items( $items, $args ) {
		$expanded = array();
		$offset   = 0;

		foreach ( $items as $item ) {
			if ( ! $this->is_switcher_item( $item ) ) {
				if ( 0 !== $offset && isset( $item->menu_order ) ) {
					$item->menu_order = (int) $item->menu_order + $offset;
				}

				$expanded[] = $item;
				continue;
			}

			if ( ! $this->switcher->is_available() ) {
				continue;
			}

			$generated = $this->build_menu_items( $item, $args, $offset );

			if ( empty( $generated ) ) {
				continue;
			}

			$offset  += count( $generated ) - 1;
			$expanded = array_merge( $expanded, $generated );
		}

		return $expanded;
	}

	/**
	 * Adds language metadata to a generated switcher link.
	 *
	 * @param array<string, string> $atts      Anchor attributes.
	 * @param WP_Post               $menu_item Menu item object.
	 * @return array<string, string>
	 */
	public function menu_link_attributes( $atts, $menu_item ) {
		if ( ! $menu_item instanceof WP_Post || ! isset( $menu_item->shapetranslate_language ) ) {
			return $atts;
		}

		$language = $menu_item->shapetranslate_language;

		if ( ! is_array( $language ) ) {
			return $atts;
		}

		$tag = $this->language_tag->format( $language );

		if ( '' !== $tag ) {
			$atts['lang']     = $tag;
			$atts['hreflang'] = $tag;
			$atts['rel']      = isset( $atts['rel'] ) && '' !== (string) $atts['rel']
				? $atts['rel'] . ' alternate'
				: 'alternate';
		}

		$atts['dir'] = LanguageTag::direction( $language );

		if ( empty( $menu_item->shapetranslate_available ) && empty( $atts['href'] ) ) {
			$atts['aria-disabled'] = 'true';
		}

		return $atts;
	}

	/**
	 * Builds the menu items that replace one virtual switcher item.
	 *
	 * @param WP_Post   $item   Virtual switcher menu item.
	 * @param \stdClass $args   Menu arguments.
	 * @param int       $offset Menu order offset accumulated so far.
	 * @return array<int, WP_Post>
	 */
	private function build_menu_items( WP_Post $item, $args, $offset ) {
		$settings            = $this->get_item_settings( $item->ID );
		$settings['context'] = 'menu';

		/**
		 * Filters switcher arguments for a classic navigation menu item.
		 *
		 * @param array<string, mixed> $settings  Switcher settings.
		 * @param WP_Post              $menu_item Menu item object.
		 * @param \stdClass             $args      Menu arguments.
		 */
		$filtered = apply_filters( 'shapetranslate_switcher_menu_args', $settings, $item, $args );
		$settings = is_array( $filtered ) ? $this->switcher->normalize_args( $filtered ) : $settings;

		/*
		 * A dropdown labels its parent with the current language, which the
		 * hide_current option would otherwise remove, so the full list is
		 * resolved first and trimmed afterwards.
		 */
		$languages = $this->switcher->get_items( array_merge( $settings, array( 'hide_current' => false ) ) );
		$current   = null;

		foreach ( $languages as $language_item ) {
			if ( ! empty( $language_item['current'] ) ) {
				$current = $language_item;
				break;
			}
		}

		if ( ! empty( $settings['hide_current'] ) ) {
			$languages = array_values(
				array_filter(
					$languages,
					static function ( $language_item ) {
						return empty( $language_item['current'] );
					}
				)
			);
		}

		if ( empty( $languages ) ) {
			return array();
		}

		/*
		 * render() enqueues this for the markup it owns, and menus no longer go
		 * through it. Only the flag and label rules apply here; the theme styles
		 * everything else, so arriving late costs no layout shift.
		 */
		wp_enqueue_style( 'shapetranslate-switcher' );

		$dropdown = 'dropdown' === $settings['layout'];
		$order    = (int) $item->menu_order + (int) $offset;
		$built    = array();
		$index    = 0;

		if ( $dropdown ) {
			$parent             = clone $item;
			$parent->title      = $this->switcher->get_item_content(
				null !== $current ? $current : $languages[0],
				$settings
			);
			$parent->url        = '';
			$parent->attr_title = '';
			$parent->target     = '';
			$parent->xfn        = '';
			$parent->current    = false;
			$parent->menu_order = $order;

			/*
			 * WordPress adds menu-item-has-children from the stored menu structure,
			 * before the filter this runs on, so a parent whose children appear here
			 * never receives it. Themes hang their submenu arrow and their mobile
			 * toggle off that class, so it has to be set by hand.
			 */
			$parent->classes = $this->item_classes(
				$item,
				array( 'shapetranslate-switcher-parent', 'menu-item-has-children' )
			);
			$built[]         = $parent;
			++$index;
		}

		foreach ( $languages as $language_item ) {
			$language = isset( $language_item['language'] ) && is_array( $language_item['language'] )
				? $language_item['language']
				: array();
			$classes  = array( 'shapetranslate-switcher-language' );

			if ( ! empty( $language_item['id'] ) ) {
				$classes[] = 'shapetranslate-language-' . sanitize_html_class( (string) $language_item['id'] );
			}

			if ( ! empty( $language_item['current'] ) ) {
				$classes[] = 'current-lang';
			}

			if ( empty( $language_item['available'] ) ) {
				$classes[] = 'shapetranslate-switcher-unavailable';
			}

			$language_menu_item = clone $item;

			/*
			 * Cloned items would otherwise share the source item's db_id, which
			 * makes a dropdown child its own parent and sends Walker::walk() into
			 * infinite recursion. Nothing links to these items, so they need no
			 * identifier of their own.
			 */
			$language_menu_item->db_id            = 0;
			$language_menu_item->menu_item_parent = $dropdown ? (int) $item->db_id : $item->menu_item_parent;
			$language_menu_item->ID               = $item->ID . '-' . (string) $language_item['id'];
			$language_menu_item->title            = $this->switcher->get_item_content( $language_item, $settings );
			$language_menu_item->url              = isset( $language_item['url'] ) ? (string) $language_item['url'] : '';
			$language_menu_item->attr_title       = '';
			$language_menu_item->target           = '';
			$language_menu_item->xfn              = '';
			$language_menu_item->current          = false;
			$language_menu_item->menu_order       = $order + $index;
			$language_menu_item->classes          = $this->item_classes( $item, $classes );

			// Read back by menu_link_attributes() once the walker builds the anchor.
			$language_menu_item->shapetranslate_language  = $language;
			$language_menu_item->shapetranslate_available = ! empty( $language_item['available'] )
				|| ! empty( $language_item['fallback'] );

			$built[] = $language_menu_item;
			++$index;
		}

		/**
		 * Filters the menu items generated for one language switcher item.
		 *
		 * @param array<int, WP_Post>  $built    Generated menu items.
		 * @param WP_Post              $item     Virtual switcher menu item.
		 * @param array<string, mixed> $settings Normalized switcher settings.
		 * @param \stdClass             $args     Menu arguments.
		 */
		$result = apply_filters( 'shapetranslate_switcher_menu_items', $built, $item, $settings, $args );

		return is_array( $result ) ? array_values( $result ) : $built;
	}

	/**
	 * Merges generated classes onto a copy of the source item's classes.
	 *
	 * The virtual item is a custom link, so WordPress may have marked it as the
	 * current page before this ran. A language entry is never the current page.
	 *
	 * @param WP_Post            $item    Virtual switcher menu item.
	 * @param array<int, string> $classes Generated classes.
	 * @return array<int, string>
	 */
	private function item_classes( WP_Post $item, array $classes ) {
		$inherited = is_array( $item->classes ) ? $item->classes : array();
		$inherited = array_diff(
			$inherited,
			array(
				'current-menu-item',
				'current_page_item',
				'current-menu-ancestor',
				'current-menu-parent',
				'current_page_parent',
				'current_page_ancestor',
			)
		);

		return array_values( array_unique( array_merge( $inherited, array( 'menu-item-shapetranslate-switcher' ), $classes ) ) );
	}

	/**
	 * Reports whether a menu item is the ShapeTranslate virtual item.
	 *
	 * @param mixed $menu_item Menu item object.
	 * @return bool
	 */
	private function is_switcher_item( $menu_item ) {
		return $menu_item instanceof WP_Post
			&& isset( $menu_item->url )
			&& self::MENU_ITEM_URL === $menu_item->url;
	}

	/**
	 * Loads and normalizes settings for one switcher menu item.
	 *
	 * @param int $item_id Menu item identifier.
	 * @return array<string, mixed>
	 */
	private function get_item_settings( $item_id ) {
		$settings = get_post_meta( absint( $item_id ), self::SETTINGS_META_KEY, true );

		return $this->switcher->normalize_args( is_array( $settings ) ? $settings : array() );
	}

	/**
	 * Renders one menu item checkbox field.
	 *
	 * @param string $field     Input name prefix.
	 * @param int    $item_id   Menu item identifier.
	 * @param string $key       Setting key.
	 * @param bool   $checked   Checked state.
	 * @param string $label     Field label.
	 * @return void
	 */
	private function render_checkbox( $field, $item_id, $key, $checked, $label ) {
		?>
		<p class="description description-wide">
			<label for="edit-menu-item-shapetranslate-<?php echo esc_attr( $key ); ?>-<?php echo esc_attr( $item_id ); ?>">
				<input type="checkbox" id="edit-menu-item-shapetranslate-<?php echo esc_attr( $key ); ?>-<?php echo esc_attr( $item_id ); ?>" name="<?php echo esc_attr( $field ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
				<?php echo esc_html( $label ); ?>
			</label>
		</p>
		<?php
	}
}
