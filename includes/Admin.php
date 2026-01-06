<?php
/**
 * Admin class.
 *
 * @package AI_Importer
 */

namespace AI_Importer;

/**
 * Admin class.
 *
 * Handles admin menu registration and script/style enqueuing.
 */
class Admin {

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Initialize admin.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_menu_page(
			__( 'AI Importer', 'ai-importer' ),
			__( 'AI Importer', 'ai-importer' ),
			'manage_options',
			'ai-importer',
			array( $this, 'render_page' ),
			'dashicons-download',
			30
		);

		// Add submenu pages.
		add_submenu_page(
			'ai-importer',
			__( 'Dashboard', 'ai-importer' ),
			__( 'Dashboard', 'ai-importer' ),
			'manage_options',
			'ai-importer',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'ai-importer',
			__( 'Import', 'ai-importer' ),
			__( 'Import', 'ai-importer' ),
			'manage_options',
			'ai-importer-import',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'ai-importer',
			__( 'Sources', 'ai-importer' ),
			__( 'Sources', 'ai-importer' ),
			'manage_options',
			'ai-importer-sources',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'ai-importer',
			__( 'History', 'ai-importer' ),
			__( 'History', 'ai-importer' ),
			'manage_options',
			'ai-importer-history',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'ai-importer',
			__( 'Settings', 'ai-importer' ),
			__( 'Settings', 'ai-importer' ),
			'manage_options',
			'ai-importer-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		// Only load on our plugin pages.
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		$asset_file = AI_IMPORTER_PLUGIN_DIR . 'build/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$assets = require $asset_file;

			wp_enqueue_script(
				'ai-importer-admin',
				AI_IMPORTER_PLUGIN_URL . 'build/index.js',
				$assets['dependencies'],
				$assets['version'],
				true
			);

			wp_enqueue_style(
				'ai-importer-admin',
				AI_IMPORTER_PLUGIN_URL . 'build/index.css',
				array( 'wp-components' ),
				$assets['version']
			);

			// Localize script with data.
			wp_localize_script(
				'ai-importer-admin',
				'aiImporter',
				array(
					'restUrl'   => rest_url( 'ai-importer/v1/' ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'adminUrl'  => admin_url(),
					'pluginUrl' => AI_IMPORTER_PLUGIN_URL,
					'version'   => AI_IMPORTER_VERSION,
				)
			);
		}
	}

	/**
	 * Check if current page is a plugin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return bool
	 */
	private function is_plugin_page( string $hook_suffix ): bool {
		$plugin_pages = array(
			'toplevel_page_ai-importer',
			'ai-importer_page_ai-importer-import',
			'ai-importer_page_ai-importer-sources',
			'ai-importer_page_ai-importer-history',
			'ai-importer_page_ai-importer-settings',
		);

		return in_array( $hook_suffix, $plugin_pages, true );
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<div id="ai-importer-root"></div>
		</div>
		<?php
	}
}
