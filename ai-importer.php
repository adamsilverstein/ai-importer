<?php
/**
 * Plugin Name:       AI Importer
 * Plugin URI:        https://github.com/adamsilverstein/ai-importer
 * Description:       Import content from social media platforms into WordPress using AI-powered analysis and mapping.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Adam Silverstein
 * Author URI:        https://developer.wordpress.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-importer
 *
 * @package AI_Importer
 */

namespace AI_Importer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'AI_IMPORTER_VERSION', '0.1.0' );
define( 'AI_IMPORTER_PLUGIN_FILE', __FILE__ );
define( 'AI_IMPORTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_IMPORTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_IMPORTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoloader.
if ( file_exists( AI_IMPORTER_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AI_IMPORTER_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init(): void {
	// Check for required dependencies.
	if ( ! check_dependencies() ) {
		return;
	}

	// Load text domain.
	load_plugin_textdomain(
		'ai-importer',
		false,
		dirname( AI_IMPORTER_PLUGIN_BASENAME ) . '/languages'
	);

	// Initialize plugin components.
	$plugin = Plugin::get_instance();
	$plugin->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Check for required dependencies.
 *
 * @return bool True if all dependencies are met.
 */
function check_dependencies(): bool {
	$missing = array();

	// Check for AI Experiments plugin.
	if ( ! class_exists( 'WP_AI_Client' ) && ! function_exists( 'ai_get_client' ) ) {
		$missing[] = 'AI Experiments';
	}

	if ( ! empty( $missing ) ) {
		add_action(
			'admin_notices',
			function () use ( $missing ) {
				$message = sprintf(
					/* translators: %s: List of missing plugins */
					__( 'AI Importer requires the following plugins: %s', 'ai-importer' ),
					implode( ', ', $missing )
				);
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( $message )
				);
			}
		);
		return false;
	}

	return true;
}

/**
 * Plugin activation hook.
 *
 * @return void
 */
function activate(): void {
	// Check minimum requirements.
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		deactivate_plugins( AI_IMPORTER_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'AI Importer requires PHP 8.1 or higher.', 'ai-importer' ),
			'Plugin Activation Error',
			array( 'back_link' => true )
		);
	}

	global $wp_version;
	if ( version_compare( $wp_version, '6.4', '<' ) ) {
		deactivate_plugins( AI_IMPORTER_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'AI Importer requires WordPress 6.4 or higher.', 'ai-importer' ),
			'Plugin Activation Error',
			array( 'back_link' => true )
		);
	}

	// Set default options.
	add_option( 'ai_importer_version', AI_IMPORTER_VERSION );

	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function deactivate(): void {
	// Clean up scheduled actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'ai_importer_process_batch' );
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
