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

/**
 * Stub WP_Error class for testing.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * WordPress error class stub.
	 */
	class WP_Error {
		/**
		 * Error codes.
		 *
		 * @var array
		 */
		private $errors = array();

		/**
		 * Error data.
		 *
		 * @var array
		 */
		private $error_data = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( ! empty( $code ) ) {
				$this->add( $code, $message, $data );
			}
		}

		/**
		 * Add an error.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Check if there are errors.
		 *
		 * @return bool True if errors exist.
		 */
		public function has_errors() {
			return ! empty( $this->errors );
		}

		/**
		 * Get error codes.
		 *
		 * @return array Error codes.
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		/**
		 * Get error messages.
		 *
		 * @param string $code Error code.
		 * @return array Error messages.
		 */
		public function get_error_messages( $code = '' ) {
			if ( empty( $code ) ) {
				$all_messages = array();
				foreach ( $this->errors as $messages ) {
					$all_messages = array_merge( $all_messages, $messages );
				}
				return $all_messages;
			}
			return $this->errors[ $code ] ?? array();
		}

		/**
		 * Get error data.
		 *
		 * @param string $code Error code.
		 * @return mixed Error data.
		 */
		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_codes()[0] ?? '';
			}
			return $this->error_data[ $code ] ?? null;
		}
	}
}
