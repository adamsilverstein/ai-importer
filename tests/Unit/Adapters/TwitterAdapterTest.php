<?php
/**
 * TwitterAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\TwitterAdapter;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TwitterAdapter class.
 */
class TwitterAdapterTest extends TestCase {

	/**
	 * Adapter instance.
	 *
	 * @var TwitterAdapter
	 */
	private TwitterAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->adapter = new TwitterAdapter();
	}

	/**
	 * Test adapter implements interface.
	 *
	 * @return void
	 */
	public function test_implements_interface(): void {
		$this->assertInstanceOf( AdapterInterface::class, $this->adapter );
	}

	/**
	 * Test get_id returns twitter.
	 *
	 * @return void
	 */
	public function test_get_id(): void {
		$this->assertSame( 'twitter', $this->adapter->get_id() );
	}

	/**
	 * Test get_name returns human-readable name.
	 *
	 * @return void
	 */
	public function test_get_name(): void {
		$this->assertSame( 'Twitter/X', $this->adapter->get_name() );
	}

	/**
	 * Test get_auth_type returns file_upload.
	 *
	 * @return void
	 */
	public function test_get_auth_type(): void {
		$this->assertSame( AdapterInterface::AUTH_TYPE_FILE_UPLOAD, $this->adapter->get_auth_type() );
	}

	/**
	 * Test get_supported_content_types includes expected types.
	 *
	 * @return void
	 */
	public function test_get_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( 'post', $types );
		$this->assertContains( 'thread', $types );
		$this->assertContains( 'reply', $types );
		$this->assertContains( 'repost', $types );
	}

	/**
	 * Test authenticate fails with empty credentials.
	 *
	 * @return void
	 */
	public function test_authenticate_fails_with_empty_credentials(): void {
		$this->assertFalse( $this->adapter->authenticate( array() ) );
	}

	/**
	 * Test authenticate fails with nonexistent file.
	 *
	 * @return void
	 */
	public function test_authenticate_fails_with_nonexistent_file(): void {
		$this->assertFalse(
			$this->adapter->authenticate(
				array( 'archive_path' => '/nonexistent/path.zip' )
			)
		);
	}

	/**
	 * Test get_settings_schema returns schema with file field.
	 *
	 * @return void
	 */
	public function test_get_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();
		$fields = $schema->get_fields();

		$this->assertArrayHasKey( 'archive_file', $fields );
		$this->assertSame( 'file', $fields['archive_file']['type'] );
		$this->assertTrue( $fields['archive_file']['required'] );
		$this->assertSame( '.zip', $fields['archive_file']['accept'] );
	}

	/**
	 * Test is_authenticated returns false when no credentials.
	 *
	 * @return void
	 */
	public function test_is_authenticated_returns_false_by_default(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_adapter_twitter', array() )
			->andReturn( array() );

		$this->assertFalse( $this->adapter->is_authenticated() );
	}

	/**
	 * Test get_icon returns dashicon class.
	 *
	 * @return void
	 */
	public function test_get_icon(): void {
		$this->assertSame( 'dashicons-twitter', $this->adapter->get_icon() );
	}

	/**
	 * Test get_description returns non-empty string.
	 *
	 * @return void
	 */
	public function test_get_description(): void {
		$this->assertNotEmpty( $this->adapter->get_description() );
	}

	/**
	 * Test fetch_manifest throws when not authenticated.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_throws_when_not_authenticated(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_adapter_twitter', array() )
			->andReturn( array() );

		$this->expectException( \RuntimeException::class );

		$this->adapter->fetch_manifest();
	}

	/**
	 * Test fetch_item throws when not authenticated.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_when_not_authenticated(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_adapter_twitter', array() )
			->andReturn( array() );

		$this->expectException( \RuntimeException::class );

		$this->adapter->fetch_item( '12345' );
	}
}
