<?php
/**
 * ImportsController tests.
 *
 * @package AI_Importer\Tests\Unit\REST
 */

namespace AI_Importer\Tests\Unit\REST;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\REST\ImportsController;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_REST_Request;

/**
 * Tests for the ImportsController class.
 */
class ImportsControllerTest extends TestCase {

	/**
	 * Controller instance.
	 *
	 * @var ImportsController
	 */
	private ImportsController $controller;

	/**
	 * In-memory option store for testing.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		AdapterRegistry::get_instance()->reset();
		$this->controller = new ImportsController();
		$this->options    = array();

		// Mock WordPress functions.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				if ( $data instanceof \WP_REST_Response ) {
					return $data;
				}
				return new \WP_REST_Response( $data );
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-1234' );

		// Use in-memory option store.
		$options = &$this->options;

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Test create_item creates a batch.
	 *
	 * @return void
	 */
	public function test_create_item(): void {
		$this->register_mock_adapter( 'twitter', true );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/imports' );
		$request->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'item_ids'       => array( 'tweet-1', 'tweet-2', 'tweet-3' ),
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();

		$this->assertSame( 'test-uuid-1234', $data['id'] );
		$this->assertSame( 'twitter', $data['source_adapter'] );
		$this->assertSame( 'processing', $data['state'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 0, $data['processed'] );
		$this->assertSame( 0, $data['percentage'] );
	}

	/**
	 * Test create_item stores a sanitized mapping on the batch.
	 *
	 * @return void
	 */
	public function test_create_item_stores_sanitized_mapping(): void {
		$this->register_mock_adapter( 'twitter', true );

		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( (string) $value );
			}
		);

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/imports' );
		$request->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'item_ids'       => array( 'tweet-1' ),
				'mapping'        => array(
					'post_type'     => 'page',
					'post_status'   => 'publish',
					'unknown_field' => 'evil',
				),
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		$batch = $this->options['ai_importer_batch_test-uuid-1234'];

		$this->assertSame(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			),
			$batch['mapping']
		);
	}

	/**
	 * Test create_item defaults to an empty mapping when none is supplied.
	 *
	 * @return void
	 */
	public function test_create_item_defaults_to_empty_mapping(): void {
		$this->register_mock_adapter( 'twitter', true );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/imports' );
		$request->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'item_ids'       => array( 'tweet-1' ),
			)
		);

		$this->controller->create_item( $request );

		$batch = $this->options['ai_importer_batch_test-uuid-1234'];

		$this->assertSame( array(), $batch['mapping'] );
	}

	/**
	 * Test create_item rejects unknown adapter.
	 *
	 * @return void
	 */
	public function test_create_item_invalid_source(): void {
		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/imports' );
		$request->set_body_params(
			array(
				'source_adapter' => 'nonexistent',
				'item_ids'       => array( '1' ),
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test create_item rejects unauthenticated adapter.
	 *
	 * @return void
	 */
	public function test_create_item_not_authenticated(): void {
		$this->register_mock_adapter( 'twitter', false );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/imports' );
		$request->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'item_ids'       => array( '1' ),
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test create_item rejects empty item_ids.
	 *
	 * @return void
	 */
	public function test_create_item_empty_items(): void {
		$this->register_mock_adapter( 'twitter', true );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/imports' );
		$request->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'item_ids'       => array(),
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get_item returns batch progress.
	 *
	 * @return void
	 */
	public function test_get_item(): void {
		$this->create_test_batch();

		$request             = new WP_REST_Request( 'GET', '/ai-importer/v1/imports/test-uuid-1234' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->get_item( $request );
		$result              = $response->get_data();

		$this->assertSame( 'test-uuid-1234', $result['id'] );
		$this->assertSame( 'processing', $result['state'] );
		$this->assertArrayHasKey( 'percentage', $result );
		$this->assertArrayHasKey( 'state_label', $result );
	}

	/**
	 * Test get_item returns error for unknown batch.
	 *
	 * @return void
	 */
	public function test_get_item_not_found(): void {
		$request             = new WP_REST_Request( 'GET', '/ai-importer/v1/imports/nonexistent' );
		$request['batch_id'] = 'nonexistent';
		$result              = $this->controller->get_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get_items returns batches in reverse order.
	 *
	 * @return void
	 */
	public function test_get_items(): void {
		$this->create_test_batch( 'batch-1' );
		$this->create_test_batch( 'batch-2' );

		$request = new WP_REST_Request( 'GET', '/ai-importer/v1/imports' );
		$request->set_param( 'limit', 10 );
		$response = $this->controller->get_items( $request );
		$result   = $response->get_data();

		$this->assertCount( 2, $result );
		// Newest first.
		$this->assertSame( 'batch-2', $result[0]['id'] );
		$this->assertSame( 'batch-1', $result[1]['id'] );
	}

	/**
	 * Test pause_item pauses a processing batch.
	 *
	 * @return void
	 */
	public function test_pause_item(): void {
		$this->create_test_batch();

		$request             = new WP_REST_Request( 'POST', '/ai-importer/v1/imports/test-uuid-1234/pause' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->pause_item( $request );
		$result              = $response->get_data();

		$this->assertSame( 'paused', $result['state'] );
	}

	/**
	 * Test pause_item rejects non-processing batch.
	 *
	 * @return void
	 */
	public function test_pause_item_invalid_state(): void {
		$this->create_test_batch( 'test-uuid-1234', 'completed' );

		$request             = new WP_REST_Request( 'POST', '/ai-importer/v1/imports/test-uuid-1234/pause' );
		$request['batch_id'] = 'test-uuid-1234';
		$result              = $this->controller->pause_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test resume_item resumes a paused batch.
	 *
	 * @return void
	 */
	public function test_resume_item(): void {
		$this->create_test_batch( 'test-uuid-1234', 'paused' );

		$request             = new WP_REST_Request( 'POST', '/ai-importer/v1/imports/test-uuid-1234/resume' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->resume_item( $request );
		$result              = $response->get_data();

		$this->assertSame( 'processing', $result['state'] );
	}

	/**
	 * Test resume_item rejects non-paused batch.
	 *
	 * @return void
	 */
	public function test_resume_item_invalid_state(): void {
		$this->create_test_batch();

		$request             = new WP_REST_Request( 'POST', '/ai-importer/v1/imports/test-uuid-1234/resume' );
		$request['batch_id'] = 'test-uuid-1234';
		$result              = $this->controller->resume_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test delete_item rolls back a batch.
	 *
	 * @return void
	 */
	public function test_delete_item(): void {
		Functions\when( 'wp_delete_post' )->justReturn( true );

		$this->create_test_batch( 'test-uuid-1234', 'completed', array( 10, 11, 12 ) );

		$request             = new WP_REST_Request( 'DELETE', '/ai-importer/v1/imports/test-uuid-1234' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->delete_item( $request );
		$result              = $response->get_data();

		$this->assertSame( 'rolled_back', $result['state'] );
	}

	/**
	 * Test permissions check denies unauthorized users.
	 *
	 * @return void
	 */
	public function test_permissions_check_denies_unauthorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = new WP_REST_Request( 'GET', '/ai-importer/v1/imports' );
		$result  = $this->controller->permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test batch serialization includes percentage.
	 *
	 * @return void
	 */
	public function test_batch_percentage_calculation(): void {
		$this->create_test_batch( 'test-uuid-1234', 'processing', array(), 10, 5 );

		$request             = new WP_REST_Request( 'GET', '/ai-importer/v1/imports/test-uuid-1234' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->get_item( $request );
		$result              = $response->get_data();

		$this->assertSame( 50, $result['percentage'] );
		$this->assertSame( 5, $result['processed'] );
		$this->assertSame( 10, $result['total'] );
	}

	/**
	 * Test calculate_eta computes throughput and remaining time.
	 *
	 * @return void
	 */
	public function test_calculate_eta_computes_rate_and_eta(): void {
		$started_at = '2024-01-15T10:00:00+00:00';
		$now        = strtotime( $started_at ) + 60;

		$batch = $this->make_eta_batch( 'processing', 20, 5, 1, $started_at );

		$eta = ImportsController::calculate_eta( $batch, $now );

		// 5 items in 60 seconds = 5 items/minute.
		$this->assertSame( 5.0, $eta['items_per_minute'] );
		// 14 items remaining at 5/min = 168 seconds.
		$this->assertSame( 168, $eta['eta_seconds'] );
	}

	/**
	 * Test calculate_eta returns null when nothing has been processed yet.
	 *
	 * @return void
	 */
	public function test_calculate_eta_zero_processed(): void {
		$batch = $this->make_eta_batch( 'processing', 20, 0, 0, '2024-01-15T10:00:00+00:00' );

		$eta = ImportsController::calculate_eta( $batch, strtotime( '2024-01-15T10:05:00+00:00' ) );

		$this->assertNull( $eta['items_per_minute'] );
		$this->assertNull( $eta['eta_seconds'] );
	}

	/**
	 * Test calculate_eta returns null for paused batches.
	 *
	 * @return void
	 */
	public function test_calculate_eta_paused_batch(): void {
		$batch = $this->make_eta_batch( 'paused', 20, 5, 0, '2024-01-15T10:00:00+00:00' );

		$eta = ImportsController::calculate_eta( $batch, strtotime( '2024-01-15T10:01:00+00:00' ) );

		$this->assertNull( $eta['items_per_minute'] );
		$this->assertNull( $eta['eta_seconds'] );
	}

	/**
	 * Test calculate_eta returns null for completed batches.
	 *
	 * @return void
	 */
	public function test_calculate_eta_completed_batch(): void {
		$batch = $this->make_eta_batch( 'completed', 20, 20, 0, '2024-01-15T10:00:00+00:00' );

		$eta = ImportsController::calculate_eta( $batch, strtotime( '2024-01-15T10:10:00+00:00' ) );

		$this->assertNull( $eta['items_per_minute'] );
		$this->assertNull( $eta['eta_seconds'] );
	}

	/**
	 * Test calculate_eta returns null when started_at is missing.
	 *
	 * @return void
	 */
	public function test_calculate_eta_missing_started_at(): void {
		$batch = $this->make_eta_batch( 'processing', 20, 5, 0, null );

		$eta = ImportsController::calculate_eta( $batch, strtotime( '2024-01-15T10:01:00+00:00' ) );

		$this->assertNull( $eta['items_per_minute'] );
		$this->assertNull( $eta['eta_seconds'] );
	}

	/**
	 * Test calculate_eta returns null when no time has elapsed.
	 *
	 * @return void
	 */
	public function test_calculate_eta_zero_elapsed(): void {
		$started_at = '2024-01-15T10:00:00+00:00';
		$batch      = $this->make_eta_batch( 'processing', 20, 5, 0, $started_at );

		$eta = ImportsController::calculate_eta( $batch, strtotime( $started_at ) );

		$this->assertNull( $eta['items_per_minute'] );
		$this->assertNull( $eta['eta_seconds'] );
	}

	/**
	 * Test calculate_eta returns zero remaining when all items are handled.
	 *
	 * @return void
	 */
	public function test_calculate_eta_all_items_handled(): void {
		$started_at = '2024-01-15T10:00:00+00:00';
		$batch      = $this->make_eta_batch( 'processing', 10, 8, 2, $started_at );

		$eta = ImportsController::calculate_eta( $batch, strtotime( $started_at ) + 60 );

		$this->assertSame( 8.0, $eta['items_per_minute'] );
		$this->assertSame( 0, $eta['eta_seconds'] );
	}

	/**
	 * Test serialized batch includes ETA fields.
	 *
	 * @return void
	 */
	public function test_get_item_includes_eta_fields(): void {
		$this->create_test_batch( 'test-uuid-1234', 'processing', array(), 10, 5 );

		$request             = new WP_REST_Request( 'GET', '/ai-importer/v1/imports/test-uuid-1234' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->get_item( $request );
		$result              = $response->get_data();

		$this->assertArrayHasKey( 'items_per_minute', $result );
		$this->assertArrayHasKey( 'eta_seconds', $result );
		// Batch is processing with processed items and a past started_at,
		// so server-computed values must be present.
		$this->assertNotNull( $result['items_per_minute'] );
		$this->assertNotNull( $result['eta_seconds'] );
	}

	/**
	 * Test serialized batch returns null ETA fields when not computable.
	 *
	 * @return void
	 */
	public function test_get_item_eta_fields_null_when_unavailable(): void {
		// No processed items yet.
		$this->create_test_batch( 'test-uuid-1234', 'processing', array(), 10, 0 );

		$request             = new WP_REST_Request( 'GET', '/ai-importer/v1/imports/test-uuid-1234' );
		$request['batch_id'] = 'test-uuid-1234';
		$response            = $this->controller->get_item( $request );
		$result              = $response->get_data();

		$this->assertNull( $result['items_per_minute'] );
		$this->assertNull( $result['eta_seconds'] );
	}

	/**
	 * Build a minimal batch array for calculate_eta tests.
	 *
	 * @param string      $state      Batch state.
	 * @param int         $total      Total items.
	 * @param int         $processed  Processed count.
	 * @param int         $failed     Failed count.
	 * @param string|null $started_at Started timestamp (ISO 8601) or null.
	 * @return array<string, mixed>
	 */
	private function make_eta_batch(
		string $state,
		int $total,
		int $processed,
		int $failed,
		?string $started_at
	): array {
		return array(
			'id'         => 'eta-batch',
			'state'      => $state,
			'total'      => $total,
			'processed'  => $processed,
			'failed'     => $failed,
			'started_at' => $started_at,
		);
	}

	/**
	 * Register a mock adapter in the registry.
	 *
	 * @param string $id              Adapter ID.
	 * @param bool   $is_authenticated Whether adapter is authenticated.
	 * @return void
	 */
	private function register_mock_adapter( string $id, bool $is_authenticated = false ): void {
		$adapter = Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'get_id' )->andReturn( $id );
		$adapter->shouldReceive( 'get_name' )->andReturn( ucfirst( $id ) );
		$adapter->shouldReceive( 'get_description' )->andReturn( "The {$id} adapter." );
		$adapter->shouldReceive( 'get_icon' )->andReturn( "dashicons-{$id}" );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( 'file_upload' );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( $is_authenticated );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post' ) );

		AdapterRegistry::get_instance()->register( $adapter );
	}

	/**
	 * Create a test batch in the in-memory store.
	 *
	 * @param string       $id           Batch ID.
	 * @param string       $state        Batch state.
	 * @param array<int>   $imported_ids Imported post IDs.
	 * @param int          $total        Total items.
	 * @param int          $processed    Processed count.
	 * @return void
	 */
	private function create_test_batch(
		string $id = 'test-uuid-1234',
		string $state = 'processing',
		array $imported_ids = array(),
		int $total = 3,
		int $processed = 0
	): void {
		$batch = array(
			'id'             => $id,
			'source_adapter' => 'twitter',
			'state'          => $state,
			'item_ids'       => array( '1', '2', '3' ),
			'total'          => $total,
			'processed'      => $processed,
			'failed'         => 0,
			'errors'         => array(),
			'created_at'     => '2024-01-15T10:00:00+00:00',
			'started_at'     => '2024-01-15T10:00:01+00:00',
			'completed_at'   => null,
			'imported_ids'   => $imported_ids,
		);

		$this->options[ 'ai_importer_batch_' . $id ] = $batch;

		// Update index.
		$index   = $this->options['ai_importer_batch_index'] ?? array();
		$index[] = $id;
		$this->options['ai_importer_batch_index'] = $index;
	}
}
