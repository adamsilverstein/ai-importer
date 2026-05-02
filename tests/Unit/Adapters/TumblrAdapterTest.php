<?php
/**
 * TumblrAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\TumblrAdapter;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TumblrAdapter class.
 */
class TumblrAdapterTest extends TestCase {

	/**
	 * Path to the fixture archive.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var TumblrAdapter
	 */
	private TumblrAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/tumblr-export.zip';
		$this->adapter      = new TumblrAdapter();

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
	 * Find a manifest item by title.
	 *
	 * @param array<string, \AI_Importer\Adapters\Manifest\ManifestItem> $items Items.
	 * @param string                                                     $title Title to find.
	 * @return array{0: string, 1: \AI_Importer\Adapters\Manifest\ManifestItem}|null Tuple of (id, item).
	 */
	private function find_by_title( array $items, string $title ): ?array {
		foreach ( $items as $id => $item ) {
			if ( $title === $item->title ) {
				return array( (string) $id, $item );
			}
		}
		return null;
	}

	/**
	 * Test adapter identity methods.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$this->assertSame( 'tumblr', $this->adapter->get_id() );
		$this->assertSame( 'Tumblr', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
		$this->assertSame( 'dashicons-format-status', $this->adapter->get_icon() );
	}

	/**
	 * Test supported content types include the post types Tumblr maps onto.
	 *
	 * @return void
	 */
	public function test_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( ContentType::POST->value, $types );
		$this->assertContains( ContentType::ARTICLE->value, $types );
		$this->assertContains( ContentType::MEDIA->value, $types );
		$this->assertContains( ContentType::VIDEO->value, $types );
		$this->assertContains( ContentType::REPOST->value, $types );
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
	 * Test authenticate succeeds with a valid Tumblr backup.
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
			$this->adapter->authenticate( array( 'file' => '/nonexistent/tumblr.zip' ) )
		);
	}

	/**
	 * Test authenticate fails with a ZIP that has no posts/.
	 *
	 * @return void
	 */
	public function test_authenticate_with_invalid_archive(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_tumblr_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString( 'meta.json', '{}' );
		$zip->close();

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test fetch_manifest returns the expected non-empty post count
	 * (the empty fixture post is filtered out).
	 *
	 * @return void
	 */
	public function test_fetch_manifest_skips_empty_posts(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		// 6 posts in fixture: 1 short text + 1 long text + 1 photo + 1 video
		// + 1 quote + 1 reblog. The 7th (empty) post is filtered out.
		$this->assertCount( 6, $manifest->get_items() );
	}

	/**
	 * Test long text posts classify as ARTICLE.
	 *
	 * @return void
	 */
	public function test_long_text_classified_as_article(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title(
			$manifest->get_items(),
			'A long-form essay on WordPress'
		);

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::ARTICLE, $found[1]->type );
	}

	/**
	 * Test short text posts classify as POST.
	 *
	 * @return void
	 */
	public function test_short_text_classified_as_post(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Hello Tumblr' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::POST, $found[1]->type );
	}

	/**
	 * Test photo posts classify as MEDIA.
	 *
	 * @return void
	 */
	public function test_photo_classified_as_media(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Sunset' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::MEDIA, $found[1]->type );
		$this->assertSame( 'photo', $found[1]->metadata['post_type'] );
	}

	/**
	 * Test video posts classify as VIDEO.
	 *
	 * @return void
	 */
	public function test_video_classified_as_video(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Demo Reel' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::VIDEO, $found[1]->type );
	}

	/**
	 * Test reblogs classify as REPOST regardless of underlying type and
	 * preserve the upstream URL in metadata.
	 *
	 * @return void
	 */
	public function test_reblog_classified_as_repost_with_attribution(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Reblog: Cool article' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::REPOST, $found[1]->type );
		$this->assertTrue( $found[1]->metadata['is_reblog'] );
		$this->assertSame(
			'https://other.tumblr.com/post/9999',
			$found[1]->metadata['reblog_from']
		);
	}

	/**
	 * Test tags from a.tag elements are surfaced into metadata.
	 *
	 * @return void
	 */
	public function test_tags_extracted_from_anchors(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Hello Tumblr' );

		$this->assertNotNull( $found );
		$this->assertContains( 'wordpress', $found[1]->metadata['tags'] );
		$this->assertContains( 'tumblr', $found[1]->metadata['tags'] );
	}

	/**
	 * Test fetch_item returns full content with HTML preserved and
	 * canonical URL surfaced.
	 *
	 * @return void
	 */
	public function test_fetch_item_returns_full_data(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Hello Tumblr' );
		$this->assertNotNull( $found );

		$item = $this->adapter->fetch_item( $found[0] );

		$this->assertSame( 'Hello Tumblr', $item['title'] );
		$this->assertStringContainsString( 'first Tumblr post', $item['content'] );
		$this->assertSame(
			'https://example.tumblr.com/post/100/hello-tumblr',
			$item['original_url']
		);
		$this->assertContains( 'wordpress', $item['tags'] );
		$this->assertSame( 'text', $item['metadata']['post_type'] );
	}

	/**
	 * Test fetch_item extracts media URLs from photo posts.
	 *
	 * @return void
	 */
	public function test_fetch_item_extracts_photo_media(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Sunset' );
		$this->assertNotNull( $found );

		$item = $this->adapter->fetch_item( $found[0] );

		$this->assertContains( 'media/sunset.jpg', $item['media_urls'] );
	}

	/**
	 * Test fetch_item extracts video src and poster URLs.
	 *
	 * @return void
	 */
	public function test_fetch_item_extracts_video_media(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Demo Reel' );
		$this->assertNotNull( $found );

		$item = $this->adapter->fetch_item( $found[0] );

		$this->assertContains( 'media/reel.mp4', $item['media_urls'] );
		$this->assertContains( 'media/reel-poster.jpg', $item['media_urls'] );
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
