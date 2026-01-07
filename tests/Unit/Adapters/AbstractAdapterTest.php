<?php
/**
 * AbstractAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\AbstractAdapter;
use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Concrete test adapter for testing AbstractAdapter.
 */
class TestAdapter extends AbstractAdapter {

	/**
	 * Get the adapter ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'test';
	}

	/**
	 * Get the adapter name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Test Adapter';
	}

	/**
	 * Get the adapter description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'A test adapter for unit testing.';
	}

	/**
	 * Get the adapter icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-admin-generic';
	}

	/**
	 * Get the authentication type.
	 *
	 * @return string
	 */
	public function get_auth_type(): string {
		return AdapterInterface::AUTH_TYPE_API_KEY;
	}

	/**
	 * Authenticate with credentials.
	 *
	 * @param array<string, mixed> $credentials Credentials.
	 * @return bool
	 */
	public function authenticate( array $credentials ): bool {
		if ( empty( $credentials['api_key'] ) ) {
			return false;
		}
		$this->store_credentials( $credentials );
		return true;
	}

	/**
	 * Fetch the content manifest.
	 *
	 * @return ContentManifest
	 */
	public function fetch_manifest(): ContentManifest {
		$this->ensure_authenticated();
		return new ContentManifest( 'test' );
	}

	/**
	 * Fetch a single item.
	 *
	 * @param string $item_id Item ID.
	 * @return array<string, mixed>
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();
		return array( 'id' => $item_id );
	}

	/**
	 * Get supported content types.
	 *
	 * @return array<string>
	 */
	public function get_supported_content_types(): array {
		return array( ContentType::POST->value );
	}

	/**
	 * Build the settings schema.
	 *
	 * @return SettingsSchema
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();
		$schema->add_field(
			'api_key',
			array(
				'type'     => 'password',
				'label'    => 'API Key',
				'required' => true,
			)
		);
		return $schema;
	}

	/**
	 * Expose protected method for testing.
	 *
	 * @return array<string, mixed>
	 */
	public function test_get_stored_credentials(): array {
		return $this->get_stored_credentials();
	}

	/**
	 * Expose protected method for testing.
	 *
	 * @param array<string, mixed> $credentials Credentials.
	 * @return bool
	 */
	public function test_store_credentials( array $credentials ): bool {
		return $this->store_credentials( $credentials );
	}

	/**
	 * Expose protected method for testing.
	 *
	 * @return bool
	 */
	public function test_clear_credentials(): bool {
		return $this->clear_credentials();
	}

	/**
	 * Expose protected method for testing.
	 *
	 * @return string
	 */
	public function test_get_option_name(): string {
		return $this->get_option_name();
	}
}

/**
 * Tests for the AbstractAdapter class.
 */
class AbstractAdapterTest extends TestCase {

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Mock WordPress functions that won't be tested with expectations.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.4' );
	}

	/**
	 * Test get_option_name returns correct format.
	 *
	 * @return void
	 */
	public function test_get_option_name_returns_correct_format(): void {
		$adapter = new TestAdapter();

		$this->assertSame( 'ai_importer_adapter_test', $adapter->test_get_option_name() );
	}

	/**
	 * Test is_authenticated returns false when no credentials.
	 *
	 * @return void
	 */
	public function test_is_authenticated_returns_false_when_no_credentials(): void {
		$adapter = new TestAdapter();

		$this->assertFalse( $adapter->is_authenticated() );
	}

	/**
	 * Test is_authenticated returns true when credentials exist.
	 *
	 * @return void
	 */
	public function test_is_authenticated_returns_true_when_credentials_exist(): void {
		Functions\when( 'get_option' )->justReturn( array( 'api_key' => 'test-key' ) );

		$adapter = new TestAdapter();

		$this->assertTrue( $adapter->is_authenticated() );
	}

	/**
	 * Test authenticate stores credentials on success.
	 *
	 * @return void
	 */
	public function test_authenticate_stores_credentials_on_success(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( 'ai_importer_adapter_test', array( 'api_key' => 'test-key' ) )
			->andReturn( true );

		$adapter = new TestAdapter();
		$result  = $adapter->authenticate( array( 'api_key' => 'test-key' ) );

		$this->assertTrue( $result );
		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test authenticate returns false on failure.
	 *
	 * @return void
	 */
	public function test_authenticate_returns_false_on_failure(): void {
		$adapter = new TestAdapter();
		$result  = $adapter->authenticate( array() );

		$this->assertFalse( $result );
	}

	/**
	 * Test disconnect clears credentials.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_credentials(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( 'ai_importer_adapter_test' )
			->andReturn( true );

		$adapter = new TestAdapter();
		$adapter->disconnect();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test get_settings_schema returns schema.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_returns_schema(): void {
		$adapter = new TestAdapter();
		$schema  = $adapter->get_settings_schema();

		$this->assertInstanceOf( SettingsSchema::class, $schema );
		$this->assertTrue( $schema->has_field( 'api_key' ) );
	}

	/**
	 * Test get_settings_schema caches the schema.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_caches_schema(): void {
		$adapter = new TestAdapter();
		$schema1 = $adapter->get_settings_schema();
		$schema2 = $adapter->get_settings_schema();

		$this->assertSame( $schema1, $schema2 );
	}

	/**
	 * Test fetch_manifest throws exception when not authenticated.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_throws_when_not_authenticated(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not authenticated' );

		$adapter = new TestAdapter();
		$adapter->fetch_manifest();
	}

	/**
	 * Test fetch_item throws exception when not authenticated.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_when_not_authenticated(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not authenticated' );

		$adapter = new TestAdapter();
		$adapter->fetch_item( 'item-1' );
	}

	/**
	 * Test get_supported_content_types returns array.
	 *
	 * @return void
	 */
	public function test_get_supported_content_types_returns_array(): void {
		$adapter = new TestAdapter();
		$types   = $adapter->get_supported_content_types();

		$this->assertIsArray( $types );
		$this->assertContains( 'post', $types );
	}

	/**
	 * Test adapter interface constants are available.
	 *
	 * @return void
	 */
	public function test_interface_constants_are_available(): void {
		$this->assertSame( 'oauth', AdapterInterface::AUTH_TYPE_OAUTH );
		$this->assertSame( 'api_key', AdapterInterface::AUTH_TYPE_API_KEY );
		$this->assertSame( 'file_upload', AdapterInterface::AUTH_TYPE_FILE_UPLOAD );
		$this->assertSame( 'scrape', AdapterInterface::AUTH_TYPE_SCRAPE );
	}
}
