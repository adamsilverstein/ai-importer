<?php
/**
 * MediumAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\MediumAdapter;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the MediumAdapter class.
 */
class MediumAdapterTest extends TestCase {

	/**
	 * Path to the fixture archive.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var MediumAdapter
	 */
	private MediumAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/medium-export.zip';
		$this->adapter      = new MediumAdapter();

		$stored = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$stored ) {
				return $stored[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.7' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	/**
	 * Authenticate the adapter against the fixture.
	 *
	 * @return void
	 */
	private function authenticate_adapter(): void {
		$this->assertTrue(
			$this->adapter->authenticate( array( 'file' => $this->fixture_path ) )
		);
	}

	/**
	 * Test adapter identity methods.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$this->assertSame( 'medium', $this->adapter->get_id() );
		$this->assertSame( 'Medium', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
		$this->assertSame( 'dashicons-edit', $this->adapter->get_icon() );
	}

	/**
	 * Test supported content types include article and post.
	 *
	 * @return void
	 */
	public function test_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( ContentType::ARTICLE->value, $types );
		$this->assertContains( ContentType::POST->value, $types );
	}

	/**
	 * Test settings schema exposes a file-upload field for the export ZIP.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $field['type'] );
		$this->assertSame( '.zip', $field['accept'] );
	}

	/**
	 * Test authenticate succeeds with a valid Medium export.
	 *
	 * @return void
	 */
	public function test_authenticate_with_valid_export(): void {
		$this->assertTrue(
			$this->adapter->authenticate( array( 'file' => $this->fixture_path ) )
		);
		$this->assertTrue( $this->adapter->is_authenticated() );
	}

	/**
	 * Test authenticate fails with a missing file.
	 *
	 * @return void
	 */
	public function test_authenticate_with_missing_file(): void {
		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => '/nonexistent/medium-export.zip' ) )
		);
	}

	/**
	 * Test authenticate fails with a ZIP that has no posts.
	 *
	 * @return void
	 */
	public function test_authenticate_with_invalid_archive(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_medium_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString( 'profile/profile.html', '<html></html>' );
		$zip->close();

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test fetch_manifest returns the expected number of items.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_item_count(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$this->assertCount( 3, $manifest->get_items() );
	}

	/**
	 * Test long content is classified as ARTICLE.
	 *
	 * @return void
	 */
	public function test_long_post_classified_as_article(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		$found = null;
		foreach ( $items as $item ) {
			if ( 'Long article on AI' === $item->title ) {
				$found = $item;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::ARTICLE, $found->type );
	}

	/**
	 * Test short content is classified as POST.
	 *
	 * @return void
	 */
	public function test_short_post_classified_as_post(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		$found = null;
		foreach ( $items as $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$found = $item;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::POST, $found->type );
	}

	/**
	 * Test draft posts are flagged in metadata.
	 *
	 * @return void
	 */
	public function test_draft_post_metadata_flag(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		$draft_count = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item->metadata['is_draft'] ) ) {
				++$draft_count;
			}
		}

		$this->assertSame( 1, $draft_count );
	}

	/**
	 * Test fetch_item returns full content with HTML preserved.
	 *
	 * @return void
	 */
	public function test_fetch_item_returns_content_html(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		// Find the "Hello WordPress" item ID.
		$target_id = null;
		foreach ( $items as $id => $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$target_id = $id;
				break;
			}
		}

		$this->assertNotNull( $target_id );

		$item = $this->adapter->fetch_item( $target_id );

		$this->assertSame( 'Hello WordPress', $item['title'] );
		$this->assertStringContainsString( 'first paragraph', $item['content'] );
		$this->assertStringContainsString( '<a href="https://example.com">link</a>', $item['content'] );
		$this->assertSame(
			'https://medium.com/@me/hello-wordpress-7a3f9b2c1d4e',
			$item['original_url']
		);
		$this->assertContains( 'WordPress', $item['tags'] );
		$this->assertContains( 'PHP', $item['tags'] );
	}

	/**
	 * Test media URLs are extracted from img tags inside e-content.
	 *
	 * @return void
	 */
	public function test_fetch_item_extracts_media_urls(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		$target_id = null;
		foreach ( $items as $id => $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$target_id = $id;
				break;
			}
		}

		$this->assertNotNull( $target_id );

		$item = $this->adapter->fetch_item( $target_id );

		$this->assertCount( 1, $item['media_urls'] );
		$this->assertSame(
			'https://cdn-images-1.medium.com/max/1024/abc.png',
			$item['media_urls'][0]
		);
	}

	/**
	 * Test fetch_item throws on unknown ID.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_on_missing_id(): void {
		$this->authenticate_adapter();

		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_item( 'no-such-id' );
	}

	/**
	 * Test disconnect clears stored credentials.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_credentials(): void {
		$this->authenticate_adapter();
		$this->assertTrue( $this->adapter->is_authenticated() );

		$this->adapter->disconnect();

		$this->assertFalse( $this->adapter->is_authenticated() );
	}
}
