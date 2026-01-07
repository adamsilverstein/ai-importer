<?php
/**
 * AdapterRegistry class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Actions;
use Mockery;

/**
 * Tests for the AdapterRegistry class.
 */
class AdapterRegistryTest extends TestCase {

	/**
	 * Reset registry between tests.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		AdapterRegistry::get_instance()->reset();
	}

	/**
	 * Test get_instance returns singleton.
	 *
	 * @return void
	 */
	public function test_get_instance_returns_singleton(): void {
		$instance1 = AdapterRegistry::get_instance();
		$instance2 = AdapterRegistry::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test register adds an adapter.
	 *
	 * @return void
	 */
	public function test_register_adds_adapter(): void {
		$registry = AdapterRegistry::get_instance();
		$adapter  = $this->create_mock_adapter( 'twitter' );

		Actions\expectDone( 'ai_importer_adapter_registered' )
			->once()
			->with( $adapter, 'twitter' );

		$registry->register( $adapter );

		$this->assertTrue( $registry->has( 'twitter' ) );
		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test register throws exception for duplicate ID.
	 *
	 * @return void
	 */
	public function test_register_throws_exception_for_duplicate(): void {
		$registry = AdapterRegistry::get_instance();
		$adapter1 = $this->create_mock_adapter( 'twitter' );
		$adapter2 = $this->create_mock_adapter( 'twitter' );

		$registry->register( $adapter1 );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'already registered' );

		$registry->register( $adapter2 );
	}

	/**
	 * Test unregister removes an adapter.
	 *
	 * @return void
	 */
	public function test_unregister_removes_adapter(): void {
		$registry = AdapterRegistry::get_instance();
		$adapter  = $this->create_mock_adapter( 'twitter' );

		$registry->register( $adapter );
		$this->assertTrue( $registry->has( 'twitter' ) );

		Actions\expectDone( 'ai_importer_adapter_unregistered' )
			->once()
			->with( $adapter, 'twitter' );

		$result = $registry->unregister( 'twitter' );

		$this->assertTrue( $result );
		$this->assertFalse( $registry->has( 'twitter' ) );
		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test unregister returns false for nonexistent adapter.
	 *
	 * @return void
	 */
	public function test_unregister_returns_false_for_nonexistent(): void {
		$registry = AdapterRegistry::get_instance();

		$result = $registry->unregister( 'nonexistent' );

		$this->assertFalse( $result );
	}

	/**
	 * Test get returns adapter by ID.
	 *
	 * @return void
	 */
	public function test_get_returns_adapter(): void {
		$registry = AdapterRegistry::get_instance();
		$adapter  = $this->create_mock_adapter( 'twitter' );

		$registry->register( $adapter );

		$this->assertSame( $adapter, $registry->get( 'twitter' ) );
	}

	/**
	 * Test get returns null for nonexistent.
	 *
	 * @return void
	 */
	public function test_get_returns_null_for_nonexistent(): void {
		$registry = AdapterRegistry::get_instance();

		$this->assertNull( $registry->get( 'nonexistent' ) );
	}

	/**
	 * Test get_all returns all adapters.
	 *
	 * @return void
	 */
	public function test_get_all_returns_all_adapters(): void {
		$registry = AdapterRegistry::get_instance();
		$adapter1 = $this->create_mock_adapter( 'twitter' );
		$adapter2 = $this->create_mock_adapter( 'medium' );

		$registry->register( $adapter1 );
		$registry->register( $adapter2 );

		$all = $registry->get_all();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'twitter', $all );
		$this->assertArrayHasKey( 'medium', $all );
	}

	/**
	 * Test get_ids returns all adapter IDs.
	 *
	 * @return void
	 */
	public function test_get_ids_returns_all_ids(): void {
		$registry = AdapterRegistry::get_instance();
		$registry->register( $this->create_mock_adapter( 'twitter' ) );
		$registry->register( $this->create_mock_adapter( 'medium' ) );

		$ids = $registry->get_ids();

		$this->assertSame( array( 'twitter', 'medium' ), $ids );
	}

	/**
	 * Test get_authenticated returns only authenticated adapters.
	 *
	 * @return void
	 */
	public function test_get_authenticated_returns_authenticated_only(): void {
		$registry = AdapterRegistry::get_instance();

		$authenticated     = $this->create_mock_adapter( 'twitter', true );
		$not_authenticated = $this->create_mock_adapter( 'medium', false );

		$registry->register( $authenticated );
		$registry->register( $not_authenticated );

		$result = $registry->get_authenticated();

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'twitter', $result );
	}

	/**
	 * Test get_by_auth_type filters by auth type.
	 *
	 * @return void
	 */
	public function test_get_by_auth_type_filters_correctly(): void {
		$registry = AdapterRegistry::get_instance();

		$oauth      = $this->create_mock_adapter( 'twitter', false, AdapterInterface::AUTH_TYPE_OAUTH );
		$file       = $this->create_mock_adapter( 'medium', false, AdapterInterface::AUTH_TYPE_FILE_UPLOAD );
		$file_again = $this->create_mock_adapter( 'blogger', false, AdapterInterface::AUTH_TYPE_FILE_UPLOAD );

		$registry->register( $oauth );
		$registry->register( $file );
		$registry->register( $file_again );

		$oauth_adapters = $registry->get_by_auth_type( AdapterInterface::AUTH_TYPE_OAUTH );
		$file_adapters  = $registry->get_by_auth_type( AdapterInterface::AUTH_TYPE_FILE_UPLOAD );

		$this->assertCount( 1, $oauth_adapters );
		$this->assertCount( 2, $file_adapters );
	}

	/**
	 * Test count returns correct value.
	 *
	 * @return void
	 */
	public function test_count_returns_correct_value(): void {
		$registry = AdapterRegistry::get_instance();

		$this->assertSame( 0, $registry->count() );

		$registry->register( $this->create_mock_adapter( 'twitter' ) );
		$registry->register( $this->create_mock_adapter( 'medium' ) );

		$this->assertSame( 2, $registry->count() );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$registry = AdapterRegistry::get_instance();
		$adapter  = $this->create_mock_adapter( 'twitter', true );

		$registry->register( $adapter );

		$array = $registry->to_array();

		$this->assertArrayHasKey( 'twitter', $array );
		$this->assertSame( 'twitter', $array['twitter']['id'] );
		$this->assertSame( 'Twitter', $array['twitter']['name'] );
		$this->assertTrue( $array['twitter']['is_authenticated'] );
	}

	/**
	 * Test reset clears all adapters.
	 *
	 * @return void
	 */
	public function test_reset_clears_adapters(): void {
		$registry = AdapterRegistry::get_instance();
		$registry->register( $this->create_mock_adapter( 'twitter' ) );
		$registry->register( $this->create_mock_adapter( 'medium' ) );

		$this->assertSame( 2, $registry->count() );

		$registry->reset();

		$this->assertSame( 0, $registry->count() );
	}

	/**
	 * Create a mock adapter for testing.
	 *
	 * @param string $id              Adapter ID.
	 * @param bool   $authenticated   Whether adapter is authenticated.
	 * @param string $auth_type       Authentication type.
	 * @return AdapterInterface Mock adapter.
	 */
	private function create_mock_adapter(
		string $id,
		bool $authenticated = false,
		string $auth_type = AdapterInterface::AUTH_TYPE_FILE_UPLOAD
	): AdapterInterface {
		$adapter = Mockery::mock( AdapterInterface::class );

		$adapter->shouldReceive( 'get_id' )->andReturn( $id );
		$adapter->shouldReceive( 'get_name' )->andReturn( ucfirst( $id ) );
		$adapter->shouldReceive( 'get_description' )->andReturn( "Import from {$id}" );
		$adapter->shouldReceive( 'get_icon' )->andReturn( "dashicons-{$id}" );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( $auth_type );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( $authenticated );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post', 'thread' ) );

		return $adapter;
	}
}
