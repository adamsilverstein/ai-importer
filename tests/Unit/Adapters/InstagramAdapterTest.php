<?php
/**
 * InstagramAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\InstagramAdapter;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the InstagramAdapter class.
 */
class InstagramAdapterTest extends TestCase {

	/**
	 * Path to the fixture archive.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var InstagramAdapter
	 */
	private InstagramAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/instagram-export.zip';
		$this->adapter      = new InstagramAdapter();

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
	 * Authenticate against the fixture.
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
		$this->assertSame( 'instagram', $this->adapter->get_id() );
		$this->assertSame( 'Instagram', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
	}

	/**
	 * Test supported content types include POST, VIDEO, and STORY.
	 *
	 * @return void
	 */
	public function test_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( ContentType::POST->value, $types );
		$this->assertContains( ContentType::VIDEO->value, $types );
		$this->assertContains( ContentType::STORY->value, $types );
	}

	/**
	 * Test settings schema exposes a file-upload field.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$field = $schema->get_field( 'archive_file' );
		$this->assertSame( '.zip', $field['accept'] );
	}

	/**
	 * Test authenticate succeeds with a valid Instagram archive.
	 *
	 * @return void
	 */
	public function test_authenticate_with_valid_archive(): void {
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
			$this->adapter->authenticate( array( 'file' => '/nonexistent/file.zip' ) )
		);
	}

	/**
	 * Test authenticate fails for a ZIP without recognised content files.
	 *
	 * @return void
	 */
	public function test_authenticate_with_invalid_archive(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_ig_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString( 'something/unrelated.json', '{}' );
		$zip->close();

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test the manifest aggregates posts, reels, and stories.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_item_count(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		// 3 posts (single-image, carousel, video) + 1 reel + 1 story = 5.
		$this->assertCount( 5, $manifest->get_items() );
	}

	/**
	 * Test reel content is classified as VIDEO.
	 *
	 * @return void
	 */
	public function test_reel_classified_as_video(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$reels = array_filter(
			$manifest->get_items(),
			static fn( $item ) => 'reels' === ( $item->metadata['source_kind'] ?? null )
		);

		$this->assertCount( 1, $reels );
		$reel = array_values( $reels )[0];
		$this->assertSame( ContentType::VIDEO, $reel->type );
	}

	/**
	 * Test story content is classified as STORY.
	 *
	 * @return void
	 */
	public function test_story_classified_as_story(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$stories = array_filter(
			$manifest->get_items(),
			static fn( $item ) => 'stories' === ( $item->metadata['source_kind'] ?? null )
		);

		$this->assertCount( 1, $stories );
		$story = array_values( $stories )[0];
		$this->assertSame( ContentType::STORY, $story->type );
	}

	/**
	 * Test video posts are classified as VIDEO via mime hint.
	 *
	 * @return void
	 */
	public function test_video_post_classified_as_video(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$video_post = null;
		foreach ( $manifest->get_items() as $item ) {
			if ( 'posts' === ( $item->metadata['source_kind'] ?? null )
				&& ContentType::VIDEO === $item->type
			) {
				$video_post = $item;
				break;
			}
		}

		$this->assertNotNull( $video_post );
	}

	/**
	 * Test single-image post is classified as POST.
	 *
	 * @return void
	 */
	public function test_image_post_classified_as_post(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$image_posts = array_filter(
			$manifest->get_items(),
			static fn( $item ) =>
				'posts' === ( $item->metadata['source_kind'] ?? null )
				&& ContentType::POST === $item->type
		);

		// Both the single image and the carousel are POSTs.
		$this->assertGreaterThanOrEqual( 1, count( $image_posts ) );
	}

	/**
	 * Test carousel posts list every media URI.
	 *
	 * @return void
	 */
	public function test_carousel_includes_every_media_uri(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$carousel = null;
		foreach ( $manifest->get_items() as $item ) {
			if ( ( $item->metadata['media_count'] ?? 0 ) > 1 ) {
				$carousel = $item;
				break;
			}
		}

		$this->assertNotNull( $carousel );
		$this->assertCount( 2, $carousel->media_urls );
	}

	/**
	 * Test fetch_item returns full data including media_paths and tags.
	 *
	 * @return void
	 */
	public function test_fetch_item_returns_full_data(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		// Find the first single-image post (caption "My first post!").
		$target_id = null;
		foreach ( $items as $id => $item ) {
			if ( str_contains( (string) $item->title, 'My first post' ) ) {
				$target_id = $id;
				break;
			}
		}

		$this->assertNotNull( $target_id );

		$item = $this->adapter->fetch_item( $target_id );

		$this->assertSame( 'My first post! #wordpress #ai', $item['content'] );
		$this->assertSame( 'media/posts/202401/photo-a.jpg', $item['media_paths'][0] );
		$this->assertContains( 'wordpress', $item['tags'] );
		$this->assertContains( 'ai', $item['tags'] );
	}

	/**
	 * Test fetch_item throws on an unknown ID.
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
