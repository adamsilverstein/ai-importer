<?php
/**
 * Main plugin class.
 *
 * @package AI_Importer
 */

namespace AI_Importer;

use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\BloggerAdapter;
use AI_Importer\Adapters\GhostAdapter;
use AI_Importer\Adapters\InstagramAdapter;
use AI_Importer\Adapters\MediumAdapter;
use AI_Importer\Adapters\SubstackAdapter;
use AI_Importer\Adapters\TumblrAdapter;
use AI_Importer\Adapters\TwitterAdapter;
use AI_Importer\AI\AIService;
use AI_Importer\AI\AltTextGenerator;
use AI_Importer\AI\ContentExpander;
use AI_Importer\AI\HashtagMapper;
use AI_Importer\AI\InternalLinkSuggester;
use AI_Importer\AI\MetaDescriptionGenerator;
use AI_Importer\AI\TitleGenerator;
use AI_Importer\Processor\ContentCleaner;
use AI_Importer\Processor\ImportProcessor;
use AI_Importer\Processor\ItemEnhancer;
use AI_Importer\Processor\MediaHandler;
use AI_Importer\REST\ImportsController;
use AI_Importer\REST\SourcesController;

/**
 * Plugin class.
 *
 * Singleton class that initializes all plugin components.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Admin instance.
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

	/**
	 * Adapter registry instance.
	 *
	 * @var AdapterRegistry|null
	 */
	private ?AdapterRegistry $adapter_registry = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 */
	private function __construct() {}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize adapter registry.
		$this->adapter_registry = AdapterRegistry::get_instance();

		// Register built-in adapters.
		$this->adapter_registry->register( new TwitterAdapter() );
		$this->adapter_registry->register( new MediumAdapter() );
		$this->adapter_registry->register( new InstagramAdapter() );
		$this->adapter_registry->register( new BloggerAdapter() );
		$this->adapter_registry->register( new TumblrAdapter() );
		$this->adapter_registry->register( new SubstackAdapter() );
		$this->adapter_registry->register( new GhostAdapter() );

		/**
		 * Fires when adapters should be registered.
		 *
		 * Plugins and themes can hook into this action to register
		 * their own source adapters.
		 *
		 * @param AdapterRegistry $registry The adapter registry instance.
		 */
		do_action( 'ai_importer_register_adapters', $this->adapter_registry );

		// Initialize import processor (must run on all requests for Action Scheduler).
		$ai_service    = new AIService();
		$media_handler = $this->build_media_handler( $ai_service );
		$processor     = new ImportProcessor( null, $media_handler, $this->build_item_enhancer( $ai_service ) );
		$processor->init();

		// Initialize admin.
		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->init();
		}

		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$sources_controller = new SourcesController();
		$sources_controller->register_routes();

		$imports_controller = new ImportsController();
		$imports_controller->register_routes();
	}

	/**
	 * Get admin instance.
	 *
	 * @return Admin|null
	 */
	public function get_admin(): ?Admin {
		return $this->admin;
	}

	/**
	 * Get adapter registry instance.
	 *
	 * @return AdapterRegistry|null
	 */
	public function get_adapter_registry(): ?AdapterRegistry {
		return $this->adapter_registry;
	}

	/**
	 * Construct the item enhancer.
	 *
	 * AI-backed enhancements (title, meta description, hashtag mapping,
	 * content expansion, internal linking) are only attached when a WP AI
	 * client is configured. The local ContentCleaner runs regardless of AI
	 * availability.
	 *
	 * Content expansion (F8.3) and internal linking (F8.4) are opt-in: their
	 * services are wired up when AI is available, but their flags default to
	 * off. Site owners enable them via the 'ai_importer_enhancement_flags'
	 * filter (or by replacing the enhancer through 'ai_importer_item_enhancer').
	 *
	 * Returns null when no WP AI client is configured AND no local cleanup
	 * would happen — currently the cleaner always runs, so this returns a
	 * cleanup-only enhancer in that case.
	 *
	 * @param AIService $service AI service wrapper.
	 * @return ItemEnhancer|null
	 */
	private function build_item_enhancer( AIService $service ): ?ItemEnhancer {
		$content_cleaner = new ContentCleaner();

		if ( ! $service->is_available() ) {
			// Without AI we still want local cleanup. The Title and Meta
			// generators are required dependencies of ItemEnhancer, but
			// flags disable both so they are never called.
			$enhancer = new ItemEnhancer(
				new TitleGenerator( $service ),
				new MetaDescriptionGenerator( $service ),
				$content_cleaner,
				null,
				array(
					'title'             => false,
					'meta_description'  => false,
					'hashtag_mapping'   => false,
					'content_expansion' => false,
					'internal_linking'  => false,
				)
			);
		} else {
			/**
			 * Filters which AI enhancements are enabled by default.
			 *
			 * Content expansion (F8.3) and internal linking (F8.4) are opt-in
			 * and default to off because they alter post bodies and incur
			 * additional AI cost (expansion: 1 call/item; internal links:
			 * 1 call/item, batchable). Title, meta description, and hashtag
			 * mapping default to on.
			 *
			 * @param array<string, bool> $flags Enhancement flags keyed by enhancement name.
			 */
			$flags = apply_filters(
				'ai_importer_enhancement_flags',
				array(
					'title'             => true,
					'meta_description'  => true,
					'hashtag_mapping'   => true,
					'content_expansion' => false,
					'internal_linking'  => false,
				)
			);

			$enhancer = new ItemEnhancer(
				new TitleGenerator( $service ),
				new MetaDescriptionGenerator( $service ),
				$content_cleaner,
				new HashtagMapper( $service ),
				(array) $flags,
				new ContentExpander( $service ),
				new InternalLinkSuggester( $service )
			);
		}

		/**
		 * Filters the item enhancer used by the import processor.
		 *
		 * Return null to disable enhancements entirely. Return a custom
		 * ItemEnhancer instance to override flags or inject alternatives.
		 *
		 * @param ItemEnhancer|null $enhancer Default enhancer.
		 */
		$filtered = apply_filters( 'ai_importer_item_enhancer', $enhancer );

		return $filtered instanceof ItemEnhancer ? $filtered : null;
	}

	/**
	 * Construct the media handler with AI alt-text generation when available.
	 *
	 * @param AIService $service AI service wrapper.
	 * @return MediaHandler
	 */
	private function build_media_handler( AIService $service ): MediaHandler {
		$alt_generator = $service->is_available() ? new AltTextGenerator( $service ) : null;

		/**
		 * Filters the alt-text generator passed to the media handler.
		 *
		 * Return null to disable AI alt-text generation. Source-supplied
		 * alt text from the adapter is preserved either way.
		 *
		 * @param AltTextGenerator|null $alt_generator Default generator (null when AI is unavailable).
		 */
		$alt_generator = apply_filters( 'ai_importer_alt_text_generator', $alt_generator );

		return new MediaHandler( $alt_generator instanceof AltTextGenerator ? $alt_generator : null );
	}
}
