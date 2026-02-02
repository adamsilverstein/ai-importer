<?php
/**
 * TwitterAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters\Twitter
 */

namespace AI_Importer\Tests\Unit\Adapters\Twitter;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Twitter\TwitterAdapter;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TwitterAdapter class.
 */
class TwitterAdapterTest extends TestCase {

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Mock WordPress functions.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'http://example.com/wp-content/uploads',
			)
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $path ) {
				return is_dir( $path ) || mkdir( $path, 0755, true );
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-1234' );
		Functions\when( 'trailingslashit' )->alias(
			function ( $string ) {
				return rtrim( $string, '/\\' ) . '/';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $string ) {
				return strip_tags( $string );
			}
		);
	}

	/**
	 * Test adapter returns correct ID.
	 *
	 * @return void
	 */
	public function test_get_id_returns_twitter(): void {
		$adapter = new TwitterAdapter();

		$this->assertSame( 'twitter', $adapter->get_id() );
	}

	/**
	 * Test adapter returns correct name.
	 *
	 * @return void
	 */
	public function test_get_name_returns_twitter_x(): void {
		$adapter = new TwitterAdapter();

		$this->assertSame( 'Twitter/X', $adapter->get_name() );
	}

	/**
	 * Test adapter returns description.
	 *
	 * @return void
	 */
	public function test_get_description_returns_string(): void {
		$adapter = new TwitterAdapter();

		$this->assertIsString( $adapter->get_description() );
		$this->assertNotEmpty( $adapter->get_description() );
	}

	/**
	 * Test adapter returns icon.
	 *
	 * @return void
	 */
	public function test_get_icon_returns_dashicon(): void {
		$adapter = new TwitterAdapter();

		$this->assertSame( 'dashicons-twitter', $adapter->get_icon() );
	}

	/**
	 * Test adapter uses file upload authentication type.
	 *
	 * @return void
	 */
	public function test_get_auth_type_returns_file_upload(): void {
		$adapter = new TwitterAdapter();

		$this->assertSame( AdapterInterface::AUTH_TYPE_FILE_UPLOAD, $adapter->get_auth_type() );
	}

	/**
	 * Test adapter returns supported content types.
	 *
	 * @return void
	 */
	public function test_get_supported_content_types(): void {
		$adapter = new TwitterAdapter();
		$types   = $adapter->get_supported_content_types();

		$this->assertIsArray( $types );
		$this->assertContains( ContentType::POST->value, $types );
		$this->assertContains( ContentType::THREAD->value, $types );
		$this->assertContains( ContentType::REPLY->value, $types );
		$this->assertContains( ContentType::REPOST->value, $types );
	}

	/**
	 * Test settings schema has required fields.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_has_archive_file_field(): void {
		$adapter = new TwitterAdapter();
		$schema  = $adapter->get_settings_schema();

		$this->assertInstanceOf( SettingsSchema::class, $schema );
		$this->assertTrue( $schema->has_field( 'archive_file' ) );

		$field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $field['type'] );
		$this->assertTrue( $field['required'] );
	}

	/**
	 * Test settings schema has include_replies field.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_has_include_replies_field(): void {
		$adapter = new TwitterAdapter();
		$schema  = $adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'include_replies' ) );

		$field = $schema->get_field( 'include_replies' );
		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertFalse( $field['default'] );
	}

	/**
	 * Test settings schema has include_retweets field.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_has_include_retweets_field(): void {
		$adapter = new TwitterAdapter();
		$schema  = $adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'include_retweets' ) );

		$field = $schema->get_field( 'include_retweets' );
		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertFalse( $field['default'] );
	}

	/**
	 * Test settings schema has date_range field.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_has_date_range_field(): void {
		$adapter = new TwitterAdapter();
		$schema  = $adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'date_range' ) );

		$field = $schema->get_field( 'date_range' );
		$this->assertSame( 'date_range', $field['type'] );
		$this->assertFalse( $field['required'] );
	}

	/**
	 * Test authenticate returns false when no file provided.
	 *
	 * @return void
	 */
	public function test_authenticate_returns_false_without_file(): void {
		$adapter = new TwitterAdapter();
		$result  = $adapter->authenticate( array() );

		$this->assertFalse( $result );
	}

	/**
	 * Test authenticate returns false when file does not exist.
	 *
	 * @return void
	 */
	public function test_authenticate_returns_false_for_nonexistent_file(): void {
		$adapter = new TwitterAdapter();
		$result  = $adapter->authenticate( array( 'archive_file' => '/nonexistent/file.zip' ) );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_authenticated returns false when no credentials.
	 *
	 * @return void
	 */
	public function test_is_authenticated_returns_false_without_credentials(): void {
		$adapter = new TwitterAdapter();

		$this->assertFalse( $adapter->is_authenticated() );
	}

	/**
	 * Test fetch_manifest throws exception when not authenticated.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_throws_when_not_authenticated(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not authenticated' );

		$adapter = new TwitterAdapter();
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

		$adapter = new TwitterAdapter();
		$adapter->fetch_item( 'tweet-id-123' );
	}

	/**
	 * Test disconnect can be called without errors.
	 *
	 * @return void
	 */
	public function test_disconnect_can_be_called(): void {
		Functions\when( 'delete_option' )->justReturn( true );

		$adapter = new TwitterAdapter();

		// Calling disconnect should not throw any errors.
		$adapter->disconnect();

		// After disconnect, adapter should not be authenticated.
		$this->assertFalse( $adapter->is_authenticated() );
	}

	/**
	 * Test get_username returns null when not authenticated.
	 *
	 * @return void
	 */
	public function test_get_username_returns_null_when_not_authenticated(): void {
		$adapter = new TwitterAdapter();

		$this->assertNull( $adapter->get_username() );
	}
}
