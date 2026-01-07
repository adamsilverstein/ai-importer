<?php
/**
 * Main plugin class.
 *
 * @package AI_Importer
 */

namespace AI_Importer;

use AI_Importer\Adapters\AdapterRegistry;

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

		/**
		 * Fires when adapters should be registered.
		 *
		 * Plugins and themes can hook into this action to register
		 * their own source adapters.
		 *
		 * @param AdapterRegistry $registry The adapter registry instance.
		 */
		do_action( 'ai_importer_register_adapters', $this->adapter_registry );

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
		// REST routes will be added in future issues.
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
}
