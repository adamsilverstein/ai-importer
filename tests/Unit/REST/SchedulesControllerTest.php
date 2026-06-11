<?php
/**
 * SchedulesController tests.
 *
 * @package AI_Importer\Tests\Unit\REST
 */

namespace AI_Importer\Tests\Unit\REST;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Processor\ImportScheduler;
use AI_Importer\REST\SchedulesController;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use WP_REST_Request;

/**
 * Tests for the SchedulesController class.
 */
class SchedulesControllerTest extends TestCase {

	/**
	 * Controller instance.
	 *
	 * @var SchedulesController
	 */
	private SchedulesController $controller;

	/**
	 * In-memory option store.
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
		$this->options = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				if ( $data instanceof \WP_REST_Response ) {
					return $data;
				}
				return new \WP_REST_Response( $data );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			fn( $value ) => trim( (string) $value )
		);
		Functions\when( 'sanitize_key' )->alias(
			fn( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);

		$counter = 0;
		Functions\when( 'wp_generate_uuid4' )->alias(
			function () use ( &$counter ) {
				++$counter;
				return "schedule-uuid-{$counter}";
			}
		);

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

		// Action Scheduler shims.
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 1 );
		Functions\when( 'as_unschedule_action' )->justReturn( null );
		Functions\when( 'as_next_scheduled_action' )->justReturn( 1893456000 );

		$this->controller = new SchedulesController();
	}

	/**
	 * Test get_items returns an empty list initially.
	 *
	 * @return void
	 */
	public function test_get_items_empty(): void {
		$request  = new WP_REST_Request( 'GET', '/ai-importer/v1/schedules' );
		$response = $this->controller->get_items( $request );

		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * Test create_item creates a schedule.
	 *
	 * @return void
	 */
	public function test_create_item(): void {
		$this->register_mock_adapter( 'twitter' );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$request->set_body_params(
			array(
				'source_adapter'  => 'twitter',
				'interval'        => 'daily',
				'update_existing' => true,
				'enabled'         => true,
			)
		);

		$response = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'twitter', $data['source_adapter'] );
		$this->assertSame( 'daily', $data['interval'] );
		$this->assertTrue( $data['enabled'] );
		$this->assertNotEmpty( $data['id'] );

		$this->assertCount( 1, $this->options[ ImportScheduler::OPTION_KEY ] );
	}

	/**
	 * Test create_item rejects an unknown adapter.
	 *
	 * @return void
	 */
	public function test_create_item_invalid_source(): void {
		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$request->set_body_params(
			array(
				'source_adapter' => 'nonexistent',
				'interval'       => 'daily',
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_source', $result->get_error_codes()[0] );
	}

	/**
	 * Test create_item rejects an invalid interval.
	 *
	 * @return void
	 */
	public function test_create_item_invalid_interval(): void {
		$this->register_mock_adapter( 'twitter' );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$request->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'yearly',
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_interval', $result->get_error_codes()[0] );
	}

	/**
	 * Test create_item updates an existing schedule when an ID is supplied.
	 *
	 * @return void
	 */
	public function test_create_item_updates_existing(): void {
		$this->register_mock_adapter( 'twitter' );

		$create = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$create->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);
		$created = $this->controller->create_item( $create )->get_data();

		$update = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$update->set_body_params(
			array(
				'id'             => $created['id'],
				'source_adapter' => 'twitter',
				'interval'       => 'weekly',
			)
		);
		$updated = $this->controller->create_item( $update )->get_data();

		$this->assertSame( $created['id'], $updated['id'] );
		$this->assertSame( 'weekly', $updated['interval'] );
		$this->assertCount( 1, $this->options[ ImportScheduler::OPTION_KEY ] );
	}

	/**
	 * Test create_item rejects updating a non-existent schedule.
	 *
	 * @return void
	 */
	public function test_create_item_update_unknown_schedule(): void {
		$this->register_mock_adapter( 'twitter' );

		$request = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$request->set_body_params(
			array(
				'id'             => 'does-not-exist',
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$result = $this->controller->create_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'schedule_not_found', $result->get_error_codes()[0] );
	}

	/**
	 * Test delete_item removes a schedule.
	 *
	 * @return void
	 */
	public function test_delete_item(): void {
		$this->register_mock_adapter( 'twitter' );

		$create = new WP_REST_Request( 'POST', '/ai-importer/v1/schedules' );
		$create->set_body_params(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);
		$created = $this->controller->create_item( $create )->get_data();

		$request       = new WP_REST_Request( 'DELETE', '/ai-importer/v1/schedules/' . $created['id'] );
		$request['id'] = $created['id'];
		$response      = $this->controller->delete_item( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertSame( array(), $this->options[ ImportScheduler::OPTION_KEY ] );
	}

	/**
	 * Test delete_item returns an error for an unknown schedule.
	 *
	 * @return void
	 */
	public function test_delete_item_not_found(): void {
		$request       = new WP_REST_Request( 'DELETE', '/ai-importer/v1/schedules/missing' );
		$request['id'] = 'missing';
		$result        = $this->controller->delete_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'schedule_not_found', $result->get_error_codes()[0] );
	}

	/**
	 * Test permissions check denies unauthorized users.
	 *
	 * @return void
	 */
	public function test_permissions_check_denies_unauthorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = new WP_REST_Request( 'GET', '/ai-importer/v1/schedules' );
		$result  = $this->controller->permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Register a mock adapter in the registry.
	 *
	 * @param string $id Adapter ID.
	 * @return void
	 */
	private function register_mock_adapter( string $id ): void {
		$adapter = Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'get_id' )->andReturn( $id );
		$adapter->shouldReceive( 'get_name' )->andReturn( ucfirst( $id ) );
		$adapter->shouldReceive( 'get_description' )->andReturn( "The {$id} adapter." );
		$adapter->shouldReceive( 'get_icon' )->andReturn( "dashicons-{$id}" );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( 'file_upload' );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( true );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post' ) );

		AdapterRegistry::get_instance()->register( $adapter );
	}
}
