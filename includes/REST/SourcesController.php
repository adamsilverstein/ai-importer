<?php
/**
 * REST controller for source adapters.
 *
 * @package AI_Importer
 */

namespace AI_Importer\REST;

use AI_Importer\Adapters\AdapterRegistry;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles REST API endpoints for managing source adapters.
 *
 * Endpoints:
 *   GET    /sources                     - List all adapters
 *   GET    /sources/{id}                - Get adapter details with schema
 *   POST   /sources/{id}/connect        - Authenticate an adapter
 *   POST   /sources/{id}/disconnect     - Disconnect an adapter
 *   GET    /sources/{id}/manifest       - Fetch content manifest
 */
class SourcesController extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-importer/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'sources';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)/connect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'connect_source' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)/disconnect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disconnect_source' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)/manifest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_manifest' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Check if the current user can read sources.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access sources.', 'ai-importer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if the current user can manage sources.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function manage_permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage sources.', 'ai-importer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * List all available source adapters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$registry = AdapterRegistry::get_instance();
		$adapters = $registry->to_array();

		return rest_ensure_response( array_values( $adapters ) );
	}

	/**
	 * Get a single source adapter with settings schema.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$adapter = $this->get_adapter( $request['id'] );

		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$data                    = $this->serialize_adapter( $adapter );
		$data['settings_schema'] = $adapter->get_settings_schema()->to_array();

		return rest_ensure_response( $data );
	}

	/**
	 * Connect (authenticate) a source adapter.
	 *
	 * Handles both JSON credentials and multipart file uploads.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect_source( $request ): WP_REST_Response|WP_Error {
		$adapter = $this->get_adapter( $request['id'] );

		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$credentials   = array();
		$uploaded_file = null;

		// Handle file uploads.
		$files = $request->get_file_params();

		if ( ! empty( $files['file'] ) ) {
			$upload = $this->handle_file_upload( $files['file'] );

			if ( is_wp_error( $upload ) ) {
				return $upload;
			}

			$uploaded_file       = $upload;
			$credentials['file'] = $upload;
		}

		// Merge any additional body params as credentials.
		$body_params = $request->get_body_params();

		if ( ! empty( $body_params ) ) {
			$credentials = array_merge( $credentials, $body_params );
		}

		// Also check JSON body.
		$json_params = $request->get_json_params();

		if ( ! empty( $json_params ) ) {
			$credentials = array_merge( $credentials, $json_params );
		}

		$success = $adapter->authenticate( $credentials );

		if ( ! $success ) {
			// Clean up the uploaded file to avoid orphaned archives.
			if ( $uploaded_file && file_exists( $uploaded_file ) ) {
				wp_delete_file( $uploaded_file );
			}

			return new WP_Error(
				'authentication_failed',
				__( 'Failed to connect to the source. Please check your credentials and try again.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'source'  => $this->serialize_adapter( $adapter ),
			)
		);
	}

	/**
	 * Disconnect a source adapter.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect_source( $request ): WP_REST_Response|WP_Error {
		$adapter = $this->get_adapter( $request['id'] );

		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$adapter->disconnect();

		return rest_ensure_response(
			array(
				'success' => true,
				'source'  => $this->serialize_adapter( $adapter ),
			)
		);
	}

	/**
	 * Fetch the content manifest for a connected source.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_manifest( $request ): WP_REST_Response|WP_Error {
		$adapter = $this->get_adapter( $request['id'] );

		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		if ( ! $adapter->is_authenticated() ) {
			return new WP_Error(
				'not_authenticated',
				__( 'This source is not connected. Please connect it first.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		try {
			$manifest = $adapter->fetch_manifest();
		} catch ( \RuntimeException $e ) {
			return new WP_Error(
				'manifest_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $manifest->to_array() );
	}

	/**
	 * Get an adapter by ID, returning WP_Error if not found.
	 *
	 * @param string $adapter_id Adapter ID.
	 * @return \AI_Importer\Adapters\AdapterInterface|WP_Error
	 */
	private function get_adapter( string $adapter_id ) {
		$registry = AdapterRegistry::get_instance();
		$adapter  = $registry->get( $adapter_id );

		if ( ! $adapter ) {
			return new WP_Error(
				'source_not_found',
				sprintf(
					/* translators: %s: adapter ID */
					__( 'Source adapter "%s" not found.', 'ai-importer' ),
					$adapter_id
				),
				array( 'status' => 404 )
			);
		}

		return $adapter;
	}

	/**
	 * Serialize an adapter to an array for API responses.
	 *
	 * @param \AI_Importer\Adapters\AdapterInterface $adapter The adapter.
	 * @return array<string, mixed>
	 */
	private function serialize_adapter( $adapter ): array {
		return array(
			'id'               => $adapter->get_id(),
			'name'             => $adapter->get_name(),
			'description'      => $adapter->get_description(),
			'icon'             => $adapter->get_icon(),
			'auth_type'        => $adapter->get_auth_type(),
			'is_authenticated' => $adapter->is_authenticated(),
			'content_types'    => $adapter->get_supported_content_types(),
		);
	}

	/**
	 * Handle a file upload and return the temporary file path.
	 *
	 * @param array<string, mixed> $file Upload file data from $_FILES.
	 * @return string|WP_Error File path or error.
	 */
	private function handle_file_upload( array $file ): string|WP_Error {
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error(
				'upload_error',
				__( 'File upload failed.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'upload_error',
				__( 'Invalid file upload.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		// Move to a persistent temp location so it survives the request.
		$upload_dir = wp_upload_dir();
		$dest_dir   = $upload_dir['basedir'] . '/ai-importer-tmp';

		if ( ! file_exists( $dest_dir ) ) {
			if ( ! wp_mkdir_p( $dest_dir ) ) {
				return new WP_Error(
					'upload_error',
					__( 'Failed to create upload directory.', 'ai-importer' ),
					array( 'status' => 500 )
				);
			}

			// Protect the directory from direct web access.
			$this->protect_directory( $dest_dir );
		}

		$filename = wp_unique_filename( $dest_dir, sanitize_file_name( $file['name'] ) );
		$dest     = $dest_dir . '/' . $filename;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- move_uploaded_file may warn on permission issues.
		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error(
				'upload_error',
				__( 'Failed to save uploaded file.', 'ai-importer' ),
				array( 'status' => 500 )
			);
		}

		return $dest;
	}

	/**
	 * Protect a directory from direct web access.
	 *
	 * Creates .htaccess (Apache) and index.php files to prevent
	 * directory listing and direct file access.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function protect_directory( string $dir ): void {
		// Apache: deny all direct access.
		$htaccess = $dir . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing server config, not content.
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		// Fallback: empty index.php to prevent directory listing.
		$index = $dir . '/index.php';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing server config, not content.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
