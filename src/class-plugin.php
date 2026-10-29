<?php
/**
 * ShapeTranslate application bootstrap.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate;

defined( 'ABSPATH' ) || exit;

use ShapeTranslate\Admin\AdminLanguageFilter;
use ShapeTranslate\Admin\AdminLanguageFilterModule;
use ShapeTranslate\Admin\AdminModule;
use ShapeTranslate\Admin\AdminNoticeGate;
use ShapeTranslate\Admin\AdminTextDirectionModule;
use ShapeTranslate\Admin\ContentTranslationModule;
use ShapeTranslate\Admin\SetupWizardModule;
use ShapeTranslate\Admin\SettingsModule;
use ShapeTranslate\Admin\StringTranslationModule;
use ShapeTranslate\Admin\StringTranslationQuery;
use ShapeTranslate\Admin\MediaTranslationFields;
use ShapeTranslate\Admin\TermTranslationModule;
use ShapeTranslate\Admin\TranslationActions;
use ShapeTranslate\Admin\TranslationDashboardModule;
use ShapeTranslate\Admin\TranslationDashboardQuery;
use ShapeTranslate\Content\CommentLanguageModule;
use ShapeTranslate\Content\PostTranslationManager;
use ShapeTranslate\Content\PostTypeSupport;
use ShapeTranslate\Content\DirectQueryLanguageModule;
use ShapeTranslate\Content\QueryIdTranslationModule;
use ShapeTranslate\Content\TranslationLifecycleModule;
use ShapeTranslate\Content\WidgetLanguageModule;
use ShapeTranslate\Contracts\LanguageRepositoryInterface;
use ShapeTranslate\Contracts\ModuleInterface;
use ShapeTranslate\Contracts\StringRepositoryInterface;
use ShapeTranslate\Contracts\TermTranslationRepositoryInterface;
use ShapeTranslate\Contracts\TranslationDashboardRepositoryInterface;
use ShapeTranslate\Contracts\TranslationRepositoryInterface;
use ShapeTranslate\Infrastructure\DatabaseTermTranslationRepository;
use ShapeTranslate\Infrastructure\DatabaseStringRepository;
use ShapeTranslate\Infrastructure\DatabaseTranslationDashboardRepository;
use ShapeTranslate\Infrastructure\DatabaseTranslationRepository;
use ShapeTranslate\Infrastructure\OptionsLanguageRepository;
use ShapeTranslate\Integrations\Elementor\ElementorCompatibility;
use ShapeTranslate\Integrations\Cache\CacheCompatibilityModule;
use ShapeTranslate\Integrations\Elementor\ElementorModule;
use ShapeTranslate\Integrations\Elementor\ElementorSiteEditor;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilder;
use ShapeTranslate\Integrations\Elementor\ElementorThemeBuilderModule;
use ShapeTranslate\Integrations\Elementor\ElementorWidgetModule;
use ShapeTranslate\Integrations\ElementsKit\ElementsKitModule;
use ShapeTranslate\Integrations\ElementsKit\ElementsKitTemplates;
use ShapeTranslate\Integrations\ElementPack\ElementPackModule;
use ShapeTranslate\Integrations\ElementPack\ElementPackTemplates;
use ShapeTranslate\Integrations\EssentialAddons\EssentialAddonsAdmin;
use ShapeTranslate\Integrations\EssentialAddons\EssentialAddonsModule;
use ShapeTranslate\Integrations\EssentialAddons\EssentialAddonsTemplates;
use ShapeTranslate\Integrations\HappyAddons\HappyAddonsModule;
use ShapeTranslate\Integrations\HappyAddons\HappyAddonsTemplates;
use ShapeTranslate\Integrations\HeaderFooter\HeaderFooterModule;
use ShapeTranslate\Integrations\HeaderFooter\HeaderFooterTemplates;
use ShapeTranslate\Integrations\JegKit\JegKitDashboard;
use ShapeTranslate\Integrations\JegKit\JegKitModule;
use ShapeTranslate\Integrations\JegKit\JegKitTemplates;
use ShapeTranslate\Integrations\RoyalAddons\RoyalAddonsModule;
use ShapeTranslate\Integrations\RoyalAddons\RoyalAddonsTemplates;
use ShapeTranslate\Integrations\Seo\RankMathProvider;
use ShapeTranslate\Integrations\Seo\SeoMetaModule;
use ShapeTranslate\Integrations\Seo\SeoPressProvider;
use ShapeTranslate\Integrations\Seo\YoastSeoProvider;
use ShapeTranslate\Integrations\SiteEditor\SiteEditorModule;
use ShapeTranslate\Integrations\SiteEditor\SiteEditorSidebar;
use ShapeTranslate\Integrations\SiteEditor\BlockTemplateLifecycle;
use ShapeTranslate\Integrations\SiteEditor\SiteEditorPanel;
use ShapeTranslate\Integrations\SiteEditor\SiteEditorRoutes;
use ShapeTranslate\Integrations\SiteEditor\TemplateParts;
use ShapeTranslate\Integrations\SiteEditor\TemplateResolver;
use ShapeTranslate\Integrations\SiteEditor\Templates;
use ShapeTranslate\Integrations\Wpml\WpmlConfigModule;
use ShapeTranslate\Integrations\Wpml\WpmlConfigReader;
use ShapeTranslate\Language\BrowserLanguageDetector;
use ShapeTranslate\Language\CurrentLanguageResolver;
use ShapeTranslate\Language\LanguageCatalog;
use ShapeTranslate\Language\LanguageManager;
use ShapeTranslate\Language\LanguageTag;
use ShapeTranslate\Language\LanguageValidator;
use ShapeTranslate\Language\LocaleModule;
use ShapeTranslate\Lifecycle\Installer;
use ShapeTranslate\Media\MediaModule;
use ShapeTranslate\Media\MediaTranslationManager;
use ShapeTranslate\Navigation\MenuLanguageManager;
use ShapeTranslate\Navigation\MenuLocationsModule;
use ShapeTranslate\Navigation\NavigationBlockModule;
use ShapeTranslate\Navigation\NavigationLinkTranslator;
use ShapeTranslate\Navigation\NavigationMenuRoutes;
use ShapeTranslate\Navigation\NavigationMenus;
use ShapeTranslate\Navigation\NavigationModule;
use ShapeTranslate\Rest\RestLanguageModule;
use ShapeTranslate\Routing\HostOriginModule;
use ShapeTranslate\Routing\LanguageDetectionModule;
use ShapeTranslate\Routing\LanguageUrlManager;
use ShapeTranslate\Routing\RoutingModule;
use ShapeTranslate\Routing\SearchFormModule;
use ShapeTranslate\SEO\SeoMetadata;
use ShapeTranslate\SEO\SeoModule;
use ShapeTranslate\SEO\SitemapModule;
use ShapeTranslate\Settings\PluginSettings;
use ShapeTranslate\Settings\WorkflowSettings;
use ShapeTranslate\StringTranslation\CoreStringModule;
use ShapeTranslate\StringTranslation\GettextModule;
use ShapeTranslate\StringTranslation\GettextScanner;
use ShapeTranslate\StringTranslation\GettextStrings;
use ShapeTranslate\StringTranslation\OptionStringTranslator;
use ShapeTranslate\StringTranslation\RegisteredStringValidator;
use ShapeTranslate\Sync\SyncModule;
use ShapeTranslate\Sync\TranslationSynchronizer;
use ShapeTranslate\StringTranslation\StringManager;
use ShapeTranslate\StringTranslation\StringModule;
use ShapeTranslate\Switcher\LanguageSwitcher;
use ShapeTranslate\Switcher\NavigationMenuIntegration;
use ShapeTranslate\Switcher\SwitcherModule;
use ShapeTranslate\Taxonomy\DefaultTermModule;
use ShapeTranslate\Taxonomy\TaxonomySupport;
use ShapeTranslate\Taxonomy\TermTranslationLifecycleModule;
use ShapeTranslate\Taxonomy\TermTranslationManager;

/**
 * Composes services and modules for the plugin.
 */
final class Plugin {

	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Whether modules have been registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Language manager.
	 *
	 * @var LanguageManager|null
	 */
	private $language_manager;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver|null
	 */
	private $current_language_resolver;

	/**
	 * Post translation relationship manager.
	 *
	 * @var PostTranslationManager|null
	 */
	private $post_translation_manager;

	/**
	 * Term translation relationship manager.
	 *
	 * @var TermTranslationManager|null
	 */
	private $term_translation_manager;

	/**
	 * Language-aware frontend URL service.
	 *
	 * @var LanguageUrlManager|null
	 */
	private $language_url_manager;

	/**
	 * Language switcher API and renderer.
	 *
	 * @var LanguageSwitcher|null
	 */
	private $language_switcher;

	/**
	 * Multilingual SEO metadata API.
	 *
	 * @var SeoMetadata|null
	 */
	private $seo_metadata;

	/**
	 * Navigation menu language service.
	 *
	 * @var MenuLanguageManager|null
	 */
	private $menu_language_manager;

	/**
	 * Translation workflow settings.
	 *
	 * @var WorkflowSettings|null
	 */
	private $workflow_settings;

	/**
	 * Central plugin configuration.
	 *
	 * @var PluginSettings|null
	 */
	private $plugin_settings;

	/**
	 * Optional Elementor document compatibility service.
	 *
	 * @var ElementorCompatibility|null
	 */
	private $elementor_compatibility;

	/**
	 * Registered string translation service.
	 *
	 * @var StringManager|null
	 */
	private $string_manager;

	/**
	 * Translation copy and synchronization engine.
	 *
	 * @var TranslationSynchronizer|null
	 */
	private $translation_synchronizer;

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationManager|null
	 */
	private $media_translations;

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Builds services and registers plugin modules.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		Installer::maybe_upgrade();

		$repository = new OptionsLanguageRepository();

		/**
		 * Filters the language repository implementation.
		 *
		 * Addons may provide a different repository before ShapeTranslate boots.
		 *
		 * @param LanguageRepositoryInterface $repository Default Options API repository.
		 */
		$repository = apply_filters( 'shapetranslate_language_repository', $repository );

		if ( ! $repository instanceof LanguageRepositoryInterface ) {
			$repository = new OptionsLanguageRepository();
		}

		$this->language_manager          = new LanguageManager( $repository, new LanguageValidator() );
		$this->current_language_resolver = new CurrentLanguageResolver( $this->language_manager );

		$string_repository = new DatabaseStringRepository();

		/**
		 * Filters the registered string repository implementation.
		 *
		 * @param StringRepositoryInterface $string_repository Default database repository.
		 */
		$string_repository = apply_filters( 'shapetranslate_string_repository', $string_repository );

		if ( ! $string_repository instanceof StringRepositoryInterface ) {
			$string_repository = new DatabaseStringRepository();
		}

		$this->string_manager          = new StringManager(
			$string_repository,
			$this->language_manager,
			$this->current_language_resolver,
			new RegisteredStringValidator()
		);
		$this->plugin_settings         = new PluginSettings();
		$this->workflow_settings       = new WorkflowSettings();
		$this->menu_language_manager   = new MenuLanguageManager( $this->language_manager );
		$this->elementor_compatibility = new ElementorCompatibility();

		$translation_repository = new DatabaseTranslationRepository();

		/**
		 * Filters the post translation repository implementation.
		 *
		 * @param TranslationRepositoryInterface $translation_repository Default database repository.
		 */
		$translation_repository = apply_filters( 'shapetranslate_translation_repository', $translation_repository );

		if ( ! $translation_repository instanceof TranslationRepositoryInterface ) {
			$translation_repository = new DatabaseTranslationRepository();
		}

		$post_type_support              = new PostTypeSupport( $this->plugin_settings );
		$this->post_translation_manager = new PostTranslationManager(
			$translation_repository,
			$this->language_manager,
			$post_type_support,
			$this->workflow_settings
		);

		$term_translation_repository = new DatabaseTermTranslationRepository();

		/**
		 * Filters the term translation repository implementation.
		 *
		 * @param TermTranslationRepositoryInterface $term_translation_repository Default database repository.
		 */
		$term_translation_repository = apply_filters(
			'shapetranslate_term_translation_repository',
			$term_translation_repository
		);

		if ( ! $term_translation_repository instanceof TermTranslationRepositoryInterface ) {
			$term_translation_repository = new DatabaseTermTranslationRepository();
		}

		$taxonomy_support               = new TaxonomySupport( $this->plugin_settings );
		$this->term_translation_manager = new TermTranslationManager(
			$term_translation_repository,
			$this->language_manager,
			$taxonomy_support
		);
		$this->language_url_manager     = new LanguageUrlManager(
			$this->language_manager,
			$this->post_translation_manager,
			$this->term_translation_manager,
			$this->plugin_settings
		);
		$language_tag                   = new LanguageTag();
		$this->language_switcher        = new LanguageSwitcher(
			$this->language_manager,
			$this->language_url_manager,
			$language_tag,
			$this->plugin_settings
		);
		$this->seo_metadata             = new SeoMetadata(
			$this->language_manager,
			$this->language_url_manager,
			$this->post_translation_manager,
			$this->term_translation_manager,
			$language_tag,
			$this->plugin_settings
		);
		$this->booted                   = true;

		$this->media_translations       = new MediaTranslationManager( $this->post_translation_manager );
		$this->translation_synchronizer = new TranslationSynchronizer(
			$this->post_translation_manager,
			$this->term_translation_manager,
			$this->workflow_settings,
			$taxonomy_support
		);

		$option_strings = new OptionStringTranslator( $this->string_manager );

		// Held in a variable because the editor stores scanned text through it and the frontend reads it back.
		$gettext_strings = new GettextStrings( $string_repository );

		$language_detection = new LanguageDetectionModule(
			$this->language_url_manager,
			$this->language_manager,
			new BrowserLanguageDetector(),
			$this->plugin_settings
		);

		$elementor_documents = new ElementorThemeBuilder(
			$this->post_translation_manager,
			$this->term_translation_manager
		);

		$template_parts = new TemplateParts( $this->language_manager, $this->post_translation_manager );
		$templates      = new Templates( $this->language_manager, $this->post_translation_manager );

		// The third thing the Site Editor edits that belongs to a language.
		$navigation_menus = new NavigationMenus(
			$this->post_translation_manager,
			$this->language_manager,
			new NavigationLinkTranslator( $this->post_translation_manager, $this->term_translation_manager )
		);

		$modules = array(

			new LocaleModule( $this->language_url_manager ),
			new StringModule( $this->string_manager ),
			new CoreStringModule( $option_strings, $this->language_manager ),
			new GettextModule( $gettext_strings, $this->current_language_resolver ),
			new WpmlConfigModule(
				new WpmlConfigReader(),
				$option_strings,
				$this->language_manager,
				$this->workflow_settings
			),
			new SeoMetaModule(
				array(
					new YoastSeoProvider(),
					new RankMathProvider(),
					new SeoPressProvider(),
				),
				$this->term_translation_manager,
				$this->workflow_settings,
				$option_strings,
				$this->language_manager
			),
			new TranslationLifecycleModule( $this->post_translation_manager ),
			new TermTranslationLifecycleModule( $this->term_translation_manager ),
			new DefaultTermModule(
				$this->term_translation_manager,
				$this->post_translation_manager,
				$this->language_manager,
				$taxonomy_support,
				$this->current_language_resolver,
				$this->plugin_settings
			),
			new RoutingModule(
				$this->language_url_manager,
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->plugin_settings
			),
			$language_detection,

			new CacheCompatibilityModule(
				$language_detection,
				$this->language_url_manager,
				$this->post_translation_manager
			),

			new HostOriginModule( $this->language_url_manager ),

			new QueryIdTranslationModule(
				$this->language_url_manager,
				$this->post_translation_manager,
				$this->term_translation_manager
			),
			new CommentLanguageModule(
				$this->language_url_manager,
				$this->post_translation_manager
			),
			new WidgetLanguageModule( $this->language_url_manager ),

			new DirectQueryLanguageModule(
				$this->language_url_manager,
				$this->post_translation_manager
			),
			new SearchFormModule( $this->language_url_manager ),
			new NavigationModule(
				$this->menu_language_manager,
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->language_url_manager
			),
			new NavigationBlockModule(
				$this->post_translation_manager,
				$this->language_url_manager
			),
			new SwitcherModule(
				$this->language_switcher,
				new NavigationMenuIntegration( $this->language_switcher ),
				$this->language_manager,
				$this->plugin_settings,
				$this->language_url_manager
			),
			new RestLanguageModule(
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->language_manager,
				$this->language_url_manager
			),
			new SeoModule( $this->seo_metadata ),
			new SitemapModule(
				$this->language_url_manager,
				$this->language_manager,
				$this->post_translation_manager,
				$this->term_translation_manager,
				$this->plugin_settings
			),
			new ElementorModule( $this->elementor_compatibility ),

			new ElementorThemeBuilderModule(
				$elementor_documents,
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new HeaderFooterModule(
				new HeaderFooterTemplates(
					$elementor_documents,
					$this->post_translation_manager,
					$this->term_translation_manager
				),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new EssentialAddonsModule(
				new EssentialAddonsTemplates( $elementor_documents ),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new ElementsKitModule(
				new ElementsKitTemplates( $elementor_documents, $this->post_translation_manager ),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new ElementPackModule(
				new ElementPackTemplates( $elementor_documents, $this->post_translation_manager ),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new HappyAddonsModule(
				new HappyAddonsTemplates( $elementor_documents, $this->post_translation_manager ),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new RoyalAddonsModule(
				new RoyalAddonsTemplates(
					$elementor_documents,
					$this->post_translation_manager,
					$this->term_translation_manager
				),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			new JegKitModule(
				new JegKitTemplates(
					$elementor_documents,
					$this->post_translation_manager,
					$this->term_translation_manager
				),
				$this->post_translation_manager,
				$this->language_url_manager
			),

			/*
			 * Not among the administration modules below, though it only ever acts
			 * on an administration screen. Half of what it does is answering one of
			 * the builder's own REST routes, and a REST request is not an
			 * administration request: `is_admin()` is false while it is served. Its
			 * two hooks decide for themselves where they belong.
			 */
			new JegKitDashboard(
				new JegKitTemplates(
					$elementor_documents,
					$this->post_translation_manager,
					$this->term_translation_manager
				),
				$this->post_translation_manager,
				$this->language_manager
			),

			new SiteEditorModule( $template_parts, $this->language_url_manager ),
			new TemplateResolver( $templates, $this->language_url_manager ),
			new BlockTemplateLifecycle( $template_parts, $this->post_translation_manager ),
			new BlockTemplateLifecycle( $templates, $this->post_translation_manager ),
			new SiteEditorRoutes( $template_parts, $this->language_manager ),
			new SiteEditorRoutes( $templates, $this->language_manager ),
			new SiteEditorPanel( $template_parts, $templates, $this->language_manager, $navigation_menus ),
			new NavigationMenuRoutes( $navigation_menus, $this->language_manager ),

			new ElementorWidgetModule(),
			new SyncModule(
				$this->translation_synchronizer,
				$this->post_translation_manager,
				$this->workflow_settings
			),
			new MediaModule(
				$this->media_translations,
				$this->post_translation_manager,
				$this->current_language_resolver
			),
		);

		if ( is_admin() ) {
			$modules[] = new AdminNoticeGate();
			$modules[] = new AdminModule( $this->language_manager, new LanguageCatalog() );
			$modules[] = new AdminLanguageFilterModule(
				new AdminLanguageFilter( $this->language_manager ),
				$this->language_manager,
				$this->post_translation_manager,
				$this->term_translation_manager
			);
			$modules[] = new SetupWizardModule(
				$this->language_manager,
				new LanguageCatalog(),
				$this->plugin_settings
			);
			$modules[] = new StringTranslationModule(
				$this->string_manager,
				new StringTranslationQuery( $string_repository, $this->language_manager ),
				new GettextScanner(),
				$gettext_strings
			);

			$dashboard_repository = $translation_repository instanceof TranslationDashboardRepositoryInterface
				? $translation_repository
				: null;

			if ( null === $dashboard_repository && $translation_repository instanceof DatabaseTranslationRepository ) {
				$dashboard_repository = new DatabaseTranslationDashboardRepository();
			}

			/**
			 * Filters the optional translation dashboard reporting repository.
			 *
			 * Custom translation storage can return null to disable the dashboard or
			 * provide its own bounded reporting implementation.
			 *
			 * @param TranslationDashboardRepositoryInterface|null $dashboard_repository   Reporting repository.
			 * @param TranslationRepositoryInterface                $translation_repository Active relationship repository.
			 */
			$dashboard_repository = apply_filters(
				'shapetranslate_translation_dashboard_repository',
				$dashboard_repository,
				$translation_repository
			);

			if ( ! $dashboard_repository instanceof TranslationDashboardRepositoryInterface ) {
				$dashboard_repository = null;
			}

			$modules[] = new TranslationDashboardModule(
				new TranslationDashboardQuery(
					$dashboard_repository,
					$this->post_translation_manager,
					$this->language_manager
				),
				$this->post_translation_manager
			);
			$modules[] = new MenuLocationsModule(
				$this->menu_language_manager,
				$this->language_manager
			);
			$modules[] = new SettingsModule(
				$this->language_manager,
				$this->menu_language_manager,
				$this->workflow_settings,
				$this->plugin_settings,
				$post_type_support,
				$taxonomy_support,
				$this->term_translation_manager
			);
			$modules[] = new ContentTranslationModule( $this->post_translation_manager, $this->language_manager );
			$modules[] = new TermTranslationModule( $this->term_translation_manager, $this->language_manager );
			$modules[] = new MediaTranslationFields( $this->post_translation_manager, $this->language_manager );
			$modules[] = new AdminTextDirectionModule( $this->post_translation_manager, $this->language_manager );
			$modules[] = new SiteEditorSidebar( $template_parts, $templates, $this->language_manager, $navigation_menus );
			$modules[] = new EssentialAddonsAdmin(
				new EssentialAddonsTemplates( $elementor_documents ),
				$this->post_translation_manager,
				$this->language_manager
			);

			/*
			 * Administration only, though it hangs on none of the hooks the
			 * modules above do. Elementor's Theme Builder answers `admin_init`
			 * with a document of its own and ends the request there, so the one
			 * hook this has is the one Elementor fires while preparing it.
			 */
			$modules[] = new ElementorSiteEditor(
				$elementor_documents,
				$this->post_translation_manager,
				$this->language_manager,
				new TranslationActions()
			);
		}

		/**
		 * Filters the modules registered by ShapeTranslate.
		 *
		 * Future builder and addon modules can register
		 * through this composition boundary.
		 *
		 * @param array<int, ModuleInterface> $modules Plugin modules.
		 * @param Plugin                      $plugin  Plugin application.
		 */
		$filtered_modules = apply_filters( 'shapetranslate_modules', $modules, $this );

		if ( ! is_array( $filtered_modules ) ) {
			$filtered_modules = $modules;
		}

		if ( is_array( $filtered_modules ) ) {
			foreach ( $filtered_modules as $module ) {
				if ( $module instanceof ModuleInterface ) {
					$module->register();
				}
			}
		}

		/**
		 * Fires after ShapeTranslate services and modules have been registered.
		 *
		 * @param Plugin $plugin Plugin application.
		 */
		do_action( 'shapetranslate_loaded', $this );
	}

	/**
	 * Returns the language manager.
	 *
	 * @return LanguageManager
	 */
	public function languages() {
		return $this->language_manager;
	}

	/**
	 * Resolves the current language.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current_language() {
		return $this->current_language_resolver->resolve();
	}

	/**
	 * Returns the post translation relationship API.
	 *
	 * @return PostTranslationManager
	 */
	public function translations() {
		return $this->post_translation_manager;
	}

	/**
	 * Returns the term translation relationship API.
	 *
	 * @return TermTranslationManager
	 */
	public function term_translations() {
		return $this->term_translation_manager;
	}

	/**
	 * Returns the language-aware frontend URL API.
	 *
	 * @return LanguageUrlManager
	 */
	public function urls() {
		return $this->language_url_manager;
	}

	/**
	 * Returns the language switcher template API.
	 *
	 * @return LanguageSwitcher
	 */
	public function switcher() {
		return $this->language_switcher;
	}

	/**
	 * Returns the multilingual SEO metadata API.
	 *
	 * @return SeoMetadata
	 */
	public function seo() {
		return $this->seo_metadata;
	}

	/**
	 * Returns the navigation menu language API.
	 *
	 * @return MenuLanguageManager
	 */
	public function menus() {
		return $this->menu_language_manager;
	}

	/**
	 * Returns translation workflow settings.
	 *
	 * @return WorkflowSettings
	 */
	public function workflow_settings() {
		return $this->workflow_settings;
	}

	/**
	 * Returns the translation copy and synchronization engine.
	 *
	 * @return TranslationSynchronizer
	 */
	public function synchronizer() {
		return $this->translation_synchronizer;
	}

	/**
	 * Returns the media translation API.
	 *
	 * @return MediaTranslationManager
	 */
	public function media() {
		return $this->media_translations;
	}

	/**
	 * Returns central plugin configuration.
	 *
	 * @return PluginSettings
	 */
	public function settings() {
		return $this->plugin_settings;
	}

	/**
	 * Returns the registered string translation API.
	 *
	 * @return StringManager|null
	 */
	public function strings() {
		return $this->string_manager;
	}

	/**
	 * Returns optional Elementor document compatibility.
	 *
	 * The service remains available when Elementor is inactive and reports that
	 * state through is_available() without loading Elementor classes.
	 *
	 * @return ElementorCompatibility
	 */
	public function elementor() {
		return $this->elementor_compatibility;
	}

	/**
	 * Prevents direct construction.
	 */
	private function __construct() {}

	/**
	 * Prevents cloning the application singleton.
	 *
	 * @return void
	 */
	private function __clone() {}
}
