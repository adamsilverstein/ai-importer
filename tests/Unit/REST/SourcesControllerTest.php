<?php
/**
 * SourcesController tests.
 *
 * @package AI_Importer\Tests\Unit\REST
 */

namespace AI_Importer\Tests\Unit\REST;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\REST\SourcesController;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;
use WP_REST_Request;

/**
 * Tests for the SourcesController class.
 */
class SourcesControllerTest extends TestCase {

	/**
	 * Controller instance.
	 *
	 * @var SourcesController
	 */
	private SourcesController $controller;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		AdapterRegistry::get_instance()->reset();
		$this->controller = new SourcesController();

		// Mock WordPress functions used by the controller.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				if ( $data instanceof \WP_REST_Response ) {
					return $data;
				}
				return new \WP_REST_Response( $data );
			}
		);
	}

	/**
	 * Test get_items returns all registered adapters.
	 *
	 * @return void
	 */
	public function test_get_items(): void {
		$this->register_mock_adapter( 'twitter' );
		$this->register_mock_adapter( 'medium' );

		$request  = new WP_REST_Request( 'GET', '/ai-importer/v1/sources' );
		$response = $this->controller->get_items( $request );
		$result   = $response->get_data();

		$this->assertCount( 2, $result );
		$this->assertSame( 'twitter', $result[0]['id'] );
		$this->assertSame( 'medium', $result[1]['id'] );
	}

	/**
	 * Test get_item returns single adapter with schema.
	 *
	 * @return void
	 */
	public function test_get_item(): void {
		$this->register_mock_adapter( 'twitter' );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter' );
		$request['id'] = 'twitter';
		$response      = $this->controller->get_item( $request );
		$result        = $response->get_data();

		$this->assertSame( 'twitter', $result['id'] );
		$this->assertArrayHasKey( 'settings_schema', $result );
	}

	/**
	 * Test get_item returns error for unknown adapter.
	 *
	 * @return void
	 */
	public function test_get_item_not_found(): void {
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/unknown' );
		$request['id'] = 'unknown';
		$result        = $this->controller->get_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test disconnect_source disconnects and returns updated adapter.
	 *
	 * @return void
	 */
	public function test_disconnect_source(): void {
		$adapter = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'disconnect' )->once();

		$request       = new WP_REST_Request( 'POST', '/ai-importer/v1/sources/twitter/disconnect' );
		$request['id'] = 'twitter';
		$response      = $this->controller->disconnect_source( $request );
		$result        = $response->get_data();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'twitter', $result['source']['id'] );
	}

	/**
	 * Test get_manifest returns manifest data.
	 *
	 * @return void
	 */
	public function test_get_manifest(): void {
		$manifest = new ContentManifest( 'twitter' );
		$manifest->add_item(
			new ManifestItem(
				id: '1',
				type: ContentType::POST,
				title: 'Test tweet',
				created_at: new DateTimeImmutable( '2024-01-15' ),
			)
		);

		$adapter = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/manifest' );
		$request['id'] = 'twitter';
		$response      = $this->controller->get_manifest( $request );
		$result        = $response->get_data();

		$this->assertSame( 'twitter', $result['source_id'] );
		$this->assertSame( 1, $result['stats']['total'] );
		$this->assertCount( 1, $result['items'] );
	}

	/**
	 * Test get_manifest returns error when not authenticated.
	 *
	 * @return void
	 */
	public function test_get_manifest_not_authenticated(): void {
		$this->register_mock_adapter( 'twitter', false );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/manifest' );
		$request['id'] = 'twitter';
		$result        = $this->controller->get_manifest( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test permissions check denies unauthorized users.
	 *
	 * @return void
	 */
	public function test_permissions_check_denies_unauthorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = new WP_REST_Request( 'GET', '/ai-importer/v1/sources' );
		$result  = $this->controller->get_items_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Register a mock adapter in the registry.
	 *
	 * @param string $id              Adapter ID.
	 * @param bool   $is_authenticated Whether authenticated.
	 * @return \Mockery\MockInterface&AdapterInterface Mock adapter.
	 */
	private function register_mock_adapter( string $id, bool $is_authenticated = false ) {
		$schema = new SettingsSchema();

		$adapter = Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'get_id' )->andReturn( $id );
		$adapter->shouldReceive( 'get_name' )->andReturn( ucfirst( $id ) );
		$adapter->shouldReceive( 'get_description' )->andReturn( "The {$id} adapter." );
		$adapter->shouldReceive( 'get_icon' )->andReturn( "dashicons-{$id}" );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( 'file_upload' );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( $is_authenticated );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post' ) );
		$adapter->shouldReceive( 'get_settings_schema' )->andReturn( $schema );

		AdapterRegistry::get_instance()->register( $adapter );

		return $adapter;
	}
}
