<?php
/**
 * BloggerAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\BloggerAdapter;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the BloggerAdapter class.
 */
class BloggerAdapterTest extends TestCase {

	/**
	 * Path to the fixture export.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var BloggerAdapter
	 */
	private BloggerAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/blogger-export.xml';
		$this->adapter      = new BloggerAdapter();

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
		$this->assertSame( 'blogger', $this->adapter->get_id() );
		$this->assertSame( 'Blogger', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
	}

	/**
	 * Test settings schema exposes a file-upload field for XML.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $field['type'] );
		$this->assertSame( '.xml', $field['accept'] );
	}

	/**
	 * Test authenticate with a valid Blogger XML export.
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
			$this->adapter->authenticate( array( 'file' => '/nonexistent/blogger.xml' ) )
		);
	}

	/**
	 * Test authenticate fails for an XML that isn't a Blogger export.
	 *
	 * @return void
	 */
	public function test_authenticate_with_non_blogger_xml(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_blogger_' );
		file_put_contents( $tmp, '<?xml version="1.0"?><rss><channel/></rss>' );

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test fetch_manifest returns posts and pages but skips comments and settings.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_filters_to_posts_and_pages(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		// 2 posts + 1 page = 3 items; comment + settings entries are skipped.
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

		$found = null;
		foreach ( $manifest->get_items() as $item ) {
			if ( 'A long-form post about AI' === $item->title ) {
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

		$found = null;
		foreach ( $manifest->get_items() as $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$found = $item;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::POST, $found->type );
	}

	/**
	 * Test tags from category[scheme=blogger atom ns] are surfaced.
	 *
	 * @return void
	 */
	public function test_tags_extracted_from_categories(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$found = null;
		foreach ( $manifest->get_items() as $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$found = $item;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertContains( 'WordPress', $found->metadata['tags'] );
		$this->assertContains( 'PHP', $found->metadata['tags'] );
	}

	/**
	 * Test pages are surfaced and tagged with kind metadata.
	 *
	 * @return void
	 */
	public function test_page_kind_metadata(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$found = null;
		foreach ( $manifest->get_items() as $item ) {
			if ( 'About me' === $item->title ) {
				$found = $item;
				break;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame(
			'http://schemas.google.com/blogger/2008/kind#page',
			$found->metadata['kind']
		);
	}

	/**
	 * Test fetch_item returns content with the original URL and tags.
	 *
	 * @return void
	 */
	public function test_fetch_item_returns_full_data(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$target_id = null;
		foreach ( $manifest->get_items() as $id => $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$target_id = $id;
				break;
			}
		}

		$this->assertNotNull( $target_id );

		$item = $this->adapter->fetch_item( $target_id );

		$this->assertSame( 'Hello WordPress', $item['title'] );
		$this->assertStringContainsString( 'hello-world', $item['content'] );
		$this->assertSame(
			'https://example.blogspot.com/2024/01/hello-wordpress.html',
			$item['original_url']
		);
		$this->assertContains( 'WordPress', $item['tags'] );
		$this->assertSame( 'Test Author', $item['author']['name'] );
	}

	/**
	 * Test media URLs are extracted from img tags inside content.
	 *
	 * @return void
	 */
	public function test_fetch_item_extracts_media_urls(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$target_id = null;
		foreach ( $manifest->get_items() as $id => $item ) {
			if ( 'Hello WordPress' === $item->title ) {
				$target_id = $id;
				break;
			}
		}

		$this->assertNotNull( $target_id );

		$item = $this->adapter->fetch_item( $target_id );

		$this->assertContains( 'https://example.com/img/welcome.jpg', $item['media_urls'] );
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
