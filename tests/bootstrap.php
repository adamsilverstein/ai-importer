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

// Define WordPress constants used by the plugin.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

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

		/**
		 * Add or replace data for an error code (matches WP 5.1+ behaviour).
		 *
		 * @param mixed  $data Error data.
		 * @param string $code Error code; defaults to the first code.
		 */
		public function add_data( $data, $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_codes()[0] ?? '';
			}
			if ( '' !== $code ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}

/**
 * Stub WP_REST_Server class for testing.
 */
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS  = 'GET, POST, PUT, PATCH, DELETE';
	}
}

/**
 * Stub WP_REST_Request class for testing.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements \ArrayAccess {
		private $method;
		private $route;
		private $params      = array();
		private $body_params  = array();
		private $json_params  = array();
		private $file_params  = array();
		private $query_params = array();

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? $this->body_params[ $key ] ?? $this->query_params[ $key ] ?? null;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_body_params() {
			return $this->body_params;
		}

		public function set_body_params( $params ) {
			$this->body_params = $params;
			// Also set as regular params for get_param access.
			$this->params = array_merge( $this->params, $params );
		}

		public function get_json_params() {
			return $this->json_params;
		}

		public function get_file_params() {
			return $this->file_params;
		}

		public function set_file_params( $params ) {
			$this->file_params = $params;
		}

		public function get_query_params() {
			return $this->query_params;
		}

		public function set_query_params( $params ) {
			$this->query_params = $params;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

/**
 * Stub WP_REST_Response class for testing.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;
		private $headers = array();

		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

/**
 * Stub WP_REST_Controller class for testing.
 */
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	abstract class WP_REST_Controller {
		protected $namespace;
		protected $rest_base;
	}
}
