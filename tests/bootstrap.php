<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package AI_Importer
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define plugin constants for testing.
define( 'AI_IMPORTER_VERSION', '0.1.0' );
define( 'AI_IMPORTER_PLUGIN_FILE', dirname( __DIR__ ) . '/ai-importer.php' );
define( 'AI_IMPORTER_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'AI_IMPORTER_PLUGIN_URL', 'https://example.com/wp-content/plugins/ai-importer/' );
