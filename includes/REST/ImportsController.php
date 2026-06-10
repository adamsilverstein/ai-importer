<?php
/**
 * REST controller for import batches.
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
 * Handles REST API endpoints for managing import batches.
 *
 * Batch data is stored in wp_options as ai_importer_batch_{uuid}.
 *
 * Endpoints:
 *   POST   /imports                      - Start a new import batch
 *   GET    /imports                       - List import batches
 *   GET    /imports/{batch_id}            - Get batch progress
 *   POST   /imports/{batch_id}/pause      - Pause a batch
 *   POST   /imports/{batch_id}/resume     - Resume a batch
 *   DELETE /imports/{batch_id}            - Rollback a batch
 */
class ImportsController extends WP_REST_Controller {

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
	protected $rest_base = 'imports';

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
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'limit' => array(
							'type'              => 'integer',
							'default'           => 10,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'source_adapter'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'item_ids'        => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
						'update_existing' => array(
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<batch_id>[a-f0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_batch_id_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_batch_id_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<batch_id>[a-f0-9-]+)/pause',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pause_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_batch_id_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<batch_id>[a-f0-9-]+)/resume',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resume_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_batch_id_args(),
				),
			)
		);
	}

	/**
	 * Check if the current user can manage imports.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage imports.', 'ai-importer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * List import batches.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$limit     = $request->get_param( 'limit' );
		$batch_ids = get_option( 'ai_importer_batch_index', array() );

		// Sort newest first.
		$batch_ids = array_reverse( $batch_ids );
		$batch_ids = array_slice( $batch_ids, 0, $limit );

		$batches = array();

		foreach ( $batch_ids as $batch_id ) {
			$batch = $this->get_batch( $batch_id );

			if ( $batch ) {
				$batches[] = $this->serialize_batch( $batch );
			}
		}

		return rest_ensure_response( $batches );
	}

	/**
	 * Get a single import batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$batch = $this->get_batch( $request['batch_id'] );

		if ( ! $batch ) {
			return $this->batch_not_found_error();
		}

		return rest_ensure_response( $this->serialize_batch( $batch ) );
	}

	/**
	 * Create a new import batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$source_adapter = $request->get_param( 'source_adapter' );
		$item_ids       = $request->get_param( 'item_ids' );

		// Validate the adapter exists and is authenticated.
		$registry = AdapterRegistry::get_instance();
		$adapter  = $registry->get( $source_adapter );

		if ( ! $adapter ) {
			return new WP_Error(
				'invalid_source',
				__( 'Source adapter not found.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $adapter->is_authenticated() ) {
			return new WP_Error(
				'not_authenticated',
				__( 'Source adapter is not connected.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $item_ids ) ) {
			return new WP_Error(
				'empty_items',
				__( 'No items selected for import.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		$batch_id = wp_generate_uuid4();
		$now      = gmdate( 'c' );

		$batch = array(
			'id'              => $batch_id,
			'source_adapter'  => $source_adapter,
			'state'           => 'processing',
			'item_ids'        => $item_ids,
			'total'           => count( $item_ids ),
			'processed'       => 0,
			'failed'          => 0,
			'skipped'         => 0,
			'update_existing' => (bool) $request->get_param( 'update_existing' ),
			'errors'          => array(),
			'created_at'      => $now,
			'started_at'      => $now,
			'completed_at'    => null,
			'imported_ids'    => array(),
		);

		$this->save_batch( $batch );
		$this->add_to_batch_index( $batch_id );

		/**
		 * Fires when a new import batch is created.
		 *
		 * The import processor should hook into this action to begin
		 * processing the batch (e.g., schedule Action Scheduler jobs).
		 *
		 * @param string               $batch_id The batch UUID.
		 * @param array<string, mixed> $batch    The batch data.
		 */
		do_action( 'ai_importer_batch_created', $batch_id, $batch );

		return new WP_REST_Response( $this->serialize_batch( $batch ), 201 );
	}

	/**
	 * Pause a running import batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause_item( $request ): WP_REST_Response|WP_Error {
		$batch = $this->get_batch( $request['batch_id'] );

		if ( ! $batch ) {
			return $this->batch_not_found_error();
		}

		if ( 'processing' !== $batch['state'] ) {
			return new WP_Error(
				'invalid_state',
				__( 'Only processing imports can be paused.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		$batch['state'] = 'paused';
		$this->save_batch( $batch );

		/**
		 * Fires when an import batch is paused.
		 *
		 * @param string               $batch_id The batch UUID.
		 * @param array<string, mixed> $batch    The batch data.
		 */
		do_action( 'ai_importer_batch_paused', $request['batch_id'], $batch );

		return rest_ensure_response( $this->serialize_batch( $batch ) );
	}

	/**
	 * Resume a paused import batch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_item( $request ): WP_REST_Response|WP_Error {
		$batch = $this->get_batch( $request['batch_id'] );

		if ( ! $batch ) {
			return $this->batch_not_found_error();
		}

		if ( 'paused' !== $batch['state'] ) {
			return new WP_Error(
				'invalid_state',
				__( 'Only paused imports can be resumed.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		$batch['state'] = 'processing';
		$this->save_batch( $batch );

		/**
		 * Fires when an import batch is resumed.
		 *
		 * @param string               $batch_id The batch UUID.
		 * @param array<string, mixed> $batch    The batch data.
		 */
		do_action( 'ai_importer_batch_resumed', $request['batch_id'], $batch );

		return rest_ensure_response( $this->serialize_batch( $batch ) );
	}

	/**
	 * Rollback (delete) an import batch and its imported content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$batch = $this->get_batch( $request['batch_id'] );

		if ( ! $batch ) {
			return $this->batch_not_found_error();
		}

		// Delete any imported posts.
		if ( ! empty( $batch['imported_ids'] ) ) {
			foreach ( $batch['imported_ids'] as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		}

		$batch['state']        = 'rolled_back';
		$batch['completed_at'] = gmdate( 'c' );
		$this->save_batch( $batch );

		/**
		 * Fires when an import batch is rolled back.
		 *
		 * @param string               $batch_id The batch UUID.
		 * @param array<string, mixed> $batch    The batch data.
		 */
		do_action( 'ai_importer_batch_rolled_back', $request['batch_id'], $batch );

		return rest_ensure_response( $this->serialize_batch( $batch ) );
	}

	/**
	 * Get batch data from the database.
	 *
	 * @param string $batch_id Batch UUID.
	 * @return array<string, mixed>|false Batch data or false.
	 */
	private function get_batch( string $batch_id ) {
		return get_option( 'ai_importer_batch_' . $batch_id, false );
	}

	/**
	 * Save batch data to the database.
	 *
	 * @param array<string, mixed> $batch Batch data (must include 'id').
	 * @return bool
	 */
	private function save_batch( array $batch ): bool {
		return update_option( 'ai_importer_batch_' . $batch['id'], $batch, false );
	}

	/**
	 * Add a batch ID to the index of all batches.
	 *
	 * @param string $batch_id Batch UUID.
	 * @return void
	 */
	private function add_to_batch_index( string $batch_id ): void {
		$index   = get_option( 'ai_importer_batch_index', array() );
		$index[] = $batch_id;
		update_option( 'ai_importer_batch_index', $index, false );
	}

	/**
	 * Serialize a batch for API response.
	 *
	 * Produces the shape expected by the frontend ImportProgress component.
	 *
	 * @param array<string, mixed> $batch Raw batch data.
	 * @return array<string, mixed>
	 */
	private function serialize_batch( array $batch ): array {
		$total      = max( 1, (int) $batch['total'] );
		$processed  = (int) $batch['processed'];
		$skipped    = (int) ( $batch['skipped'] ?? 0 );
		$percentage = min( 100, (int) round( ( ( $processed + $skipped ) / $total ) * 100 ) );

		$state_labels = array(
			'processing'  => __( 'Processing', 'ai-importer' ),
			'paused'      => __( 'Paused', 'ai-importer' ),
			'completed'   => __( 'Completed', 'ai-importer' ),
			'failed'      => __( 'Failed', 'ai-importer' ),
			'rolled_back' => __( 'Rolled Back', 'ai-importer' ),
		);

		return array(
			'id'              => $batch['id'],
			'source_adapter'  => $batch['source_adapter'],
			'state'           => $batch['state'],
			'state_label'     => $state_labels[ $batch['state'] ] ?? $batch['state'],
			'total'           => $total,
			'processed'       => $processed,
			'failed'          => (int) $batch['failed'],
			'skipped'         => $skipped,
			'update_existing' => ! empty( $batch['update_existing'] ),
			'percentage'      => $percentage,
			'created_at'      => $batch['created_at'],
			'started_at'      => $batch['started_at'] ?? null,
			'completed_at'    => $batch['completed_at'] ?? null,
			'errors'          => array_slice( $batch['errors'] ?? array(), -50 ),
		);
	}

	/**
	 * Get common batch_id route arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_batch_id_args(): array {
		return array(
			'batch_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Return a standard "batch not found" error.
	 *
	 * @return WP_Error
	 */
	private function batch_not_found_error(): WP_Error {
		return new WP_Error(
			'batch_not_found',
			__( 'Import batch not found.', 'ai-importer' ),
			array( 'status' => 404 )
		);
	}
}
