<?php
/**
 * REST controller for scheduled imports.
 *
 * @package AI_Importer
 */

namespace AI_Importer\REST;

use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Processor\ImportScheduler;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles REST API endpoints for managing scheduled imports (PRD F10.3).
 *
 * Schedules are stored via ImportScheduler in the ai_importer_schedules option.
 *
 * Endpoints:
 *   GET    /schedules            - List schedules
 *   POST   /schedules            - Create or update a schedule
 *   DELETE /schedules/{id}       - Delete a schedule
 */
class SchedulesController extends WP_REST_Controller {

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
	protected $rest_base = 'schedules';

	/**
	 * Scheduler service.
	 *
	 * @var ImportScheduler
	 */
	private ImportScheduler $scheduler;

	/**
	 * Constructor.
	 *
	 * @param ImportScheduler|null $scheduler Optional scheduler instance.
	 */
	public function __construct( ?ImportScheduler $scheduler = null ) {
		$this->scheduler = $scheduler ?? new ImportScheduler();
	}

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
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id'              => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Existing schedule ID to update.', 'ai-importer' ),
						),
						'source_adapter'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Adapter ID to import from.', 'ai-importer' ),
						),
						'interval'        => array(
							'type'        => 'string',
							'required'    => true,
							'enum'        => array_keys( ImportScheduler::INTERVALS ),
							'description' => __( 'Recurrence interval.', 'ai-importer' ),
						),
						'update_existing' => array(
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'enabled'         => array(
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-f0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
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
	 * Check if the current user can manage schedules.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage scheduled imports.', 'ai-importer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * List all schedules.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$schedules = array_map(
			array( $this, 'serialize_schedule' ),
			$this->scheduler->get_schedules()
		);

		return rest_ensure_response( array_values( $schedules ) );
	}

	/**
	 * Create or update a schedule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$source_adapter = (string) $request->get_param( 'source_adapter' );
		$interval       = (string) $request->get_param( 'interval' );

		// Validate the adapter exists.
		$adapter = AdapterRegistry::get_instance()->get( $source_adapter );

		if ( null === $adapter ) {
			return new WP_Error(
				'invalid_source',
				__( 'Source adapter not found.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( ImportScheduler::INTERVALS[ $interval ] ) ) {
			return new WP_Error(
				'invalid_interval',
				__( 'Invalid recurrence interval.', 'ai-importer' ),
				array( 'status' => 400 )
			);
		}

		$id = $request->get_param( 'id' );

		// When updating, the schedule must already exist.
		if ( is_string( $id ) && '' !== $id && null === $this->scheduler->get_schedule( $id ) ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Scheduled import not found.', 'ai-importer' ),
				array( 'status' => 404 )
			);
		}

		$schedule = $this->scheduler->save_schedule(
			array(
				'id'              => is_string( $id ) ? $id : '',
				'source_adapter'  => $source_adapter,
				'interval'        => $interval,
				'update_existing' => (bool) $request->get_param( 'update_existing' ),
				'enabled'         => (bool) $request->get_param( 'enabled' ),
			)
		);

		return new WP_REST_Response( $this->serialize_schedule( $schedule ), 201 );
	}

	/**
	 * Delete a schedule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id = (string) $request['id'];

		if ( null === $this->scheduler->get_schedule( $id ) ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Scheduled import not found.', 'ai-importer' ),
				array( 'status' => 404 )
			);
		}

		$this->scheduler->delete_schedule( $id );

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Serialize a schedule for API responses.
	 *
	 * @param array<string, mixed> $schedule Raw schedule record.
	 * @return array<string, mixed>
	 */
	private function serialize_schedule( array $schedule ): array {
		$next_run = $schedule['next_run'] ?? null;

		return array(
			'id'              => (string) ( $schedule['id'] ?? '' ),
			'source_adapter'  => (string) ( $schedule['source_adapter'] ?? '' ),
			'interval'        => (string) ( $schedule['interval'] ?? 'daily' ),
			'update_existing' => ! empty( $schedule['update_existing'] ),
			'enabled'         => ! empty( $schedule['enabled'] ),
			'last_run'        => $schedule['last_run'] ?? null,
			'next_run'        => is_int( $next_run ) ? gmdate( 'c', $next_run ) : null,
		);
	}
}
