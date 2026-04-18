<?php
/**
 * TwitterAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\TwitterAdapter;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TwitterAdapter class.
 */
class TwitterAdapterTest extends TestCase {

	/**
	 * Path to the test archive fixture.
	 *
	 * @var string
	 */
	private string $fixture_path;

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

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/twitter-archive.zip';
		$this->adapter      = new TwitterAdapter();

		// Mock WordPress option functions for credential storage.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.7' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
	}

	/**
	 * Test adapter identity methods.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$this->assertSame( 'twitter', $this->adapter->get_id() );
		$this->assertSame( 'Twitter/X', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
		$this->assertSame( 'dashicons-twitter', $this->adapter->get_icon() );
	}

	/**
	 * Test supported content types.
	 *
	 * @return void
	 */
	public function test_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( ContentType::POST->value, $types );
		$this->assertContains( ContentType::THREAD->value, $types );
		$this->assertContains( ContentType::REPLY->value, $types );
		$this->assertContains( ContentType::REPOST->value, $types );
		$this->assertContains( ContentType::MEDIA->value, $types );
	}

	/**
	 * Test settings schema includes file upload field.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $field['type'] );
		$this->assertTrue( $field['required'] );
		$this->assertSame( '.zip', $field['accept'] );
	}

	/**
	 * Test authenticate with valid archive.
	 *
	 * @return void
	 */
	public function test_authenticate_with_valid_archive(): void {
		$this->assertTrue(
			$this->adapter->authenticate( array( 'file' => $this->fixture_path ) )
		);
	}

	/**
	 * Test authenticate with missing file.
	 *
	 * @return void
	 */
	public function test_authenticate_with_missing_file(): void {
		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => '/nonexistent/file.zip' ) )
		);
	}

	/**
	 * Test authenticate with invalid ZIP (not a Twitter archive).
	 *
	 * @return void
	 */
	public function test_authenticate_with_invalid_archive(): void {
		// Create a temporary ZIP without tweets.js.
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_test_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString( 'data/other.js', 'window.YTD.other.part0 = []' );
		$zip->close();

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test fetch_manifest returns correct item count.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_item_count(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();

		$this->assertCount( 5, $manifest->get_items() );
	}

	/**
	 * Test tweet is classified as POST.
	 *
	 * @return void
	 */
	public function test_classify_regular_tweet_as_post(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000001' );

		$this->assertNotNull( $item );
		$this->assertSame( ContentType::POST, $item->type );
	}

	/**
	 * Test reply is classified as REPLY.
	 *
	 * @return void
	 */
	public function test_classify_reply(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000002' );

		$this->assertNotNull( $item );
		$this->assertSame( ContentType::REPLY, $item->type );
		$this->assertSame( '1749999999999999999', $item->parent_id );
	}

	/**
	 * Test retweet is classified as REPOST.
	 *
	 * @return void
	 */
	public function test_classify_retweet_as_repost(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000003' );

		$this->assertNotNull( $item );
		$this->assertSame( ContentType::REPOST, $item->type );
	}

	/**
	 * Test media-only tweet is classified as MEDIA.
	 *
	 * @return void
	 */
	public function test_classify_media_tweet(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000004' );

		$this->assertNotNull( $item );
		$this->assertSame( ContentType::MEDIA, $item->type );
		$this->assertTrue( $item->has_media() );
		$this->assertContains( 'https://pbs.twimg.com/media/example123.jpg', $item->media_urls );
	}

	/**
	 * Test self-reply is classified as THREAD.
	 *
	 * @return void
	 */
	public function test_classify_self_reply_as_thread(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000005' );

		$this->assertNotNull( $item );
		$this->assertSame( ContentType::THREAD, $item->type );
		$this->assertSame( '1750000000000000001', $item->parent_id );
	}

	/**
	 * Test manifest stats are correct.
	 *
	 * @return void
	 */
	public function test_manifest_stats(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$stats    = $manifest->get_stats();

		$this->assertSame( 5, $stats['total'] );
		$this->assertSame( 1, $stats['with_media'] );
		$this->assertArrayHasKey( 'post', $stats['by_type'] );
		$this->assertArrayHasKey( 'reply', $stats['by_type'] );
		$this->assertArrayHasKey( 'repost', $stats['by_type'] );
		$this->assertArrayHasKey( 'media', $stats['by_type'] );
		$this->assertArrayHasKey( 'thread', $stats['by_type'] );
	}

	/**
	 * Test tweet title extraction.
	 *
	 * @return void
	 */
	public function test_tweet_title(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000001' );

		$this->assertStringContainsString( 'Hello world!', $item->title );
		$this->assertStringNotContainsString( 'https://t.co', $item->title );
	}

	/**
	 * Test tweet date parsing.
	 *
	 * @return void
	 */
	public function test_tweet_date_parsing(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000001' );

		$this->assertSame( '2024-01-15', $item->created_at->format( 'Y-m-d' ) );
	}

	/**
	 * Test tweet metadata extraction.
	 *
	 * @return void
	 */
	public function test_tweet_metadata(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000001' );

		$this->assertArrayHasKey( 'hashtags', $item->metadata );
		$this->assertContains( 'WordPress', $item->metadata['hashtags'] );
		$this->assertSame( 12, $item->metadata['favorite_count'] );
		$this->assertSame( 3, $item->metadata['retweet_count'] );
	}

	/**
	 * Test fetch_item returns full content.
	 *
	 * @return void
	 */
	public function test_fetch_item(): void {
		$this->authenticate_adapter();
		$item = $this->adapter->fetch_item( '1750000000000000001' );

		$this->assertSame( '1750000000000000001', $item['id'] );
		$this->assertSame( 'post', $item['type'] );
		$this->assertStringContainsString( 'Hello world!', $item['content'] );
		$this->assertArrayHasKey( 'raw', $item );
	}

	/**
	 * Test fetch_item with media includes paths.
	 *
	 * @return void
	 */
	public function test_fetch_item_media_paths(): void {
		$this->authenticate_adapter();
		$item = $this->adapter->fetch_item( '1750000000000000004' );

		$this->assertNotEmpty( $item['media_urls'] );
		$this->assertNotEmpty( $item['media_paths'] );
		$this->assertStringContainsString( 'tweets_media', $item['media_paths'][0] );
	}

	/**
	 * Test fetch_item throws for unknown ID.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_for_unknown_id(): void {
		$this->authenticate_adapter();

		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_item( 'nonexistent_id' );
	}

	/**
	 * Test fetch_manifest throws when not authenticated.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_throws_when_not_authenticated(): void {
		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_manifest();
	}

	/**
	 * Test original URL generation.
	 *
	 * @return void
	 */
	public function test_original_url(): void {
		$this->authenticate_adapter();
		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( '1750000000000000001' );

		$this->assertNotNull( $item->original_url );
		$this->assertStringContainsString( '1750000000000000001', $item->original_url );
		$this->assertStringContainsString( 'x.com', $item->original_url );
	}

	/**
	 * Test date range in manifest.
	 *
	 * @return void
	 */
	public function test_manifest_date_range(): void {
		$this->authenticate_adapter();
		$manifest   = $this->adapter->fetch_manifest();
		$date_range = $manifest->get_date_range();

		$this->assertNotNull( $date_range['earliest'] );
		$this->assertNotNull( $date_range['latest'] );
		$this->assertSame( '2024-01-15', $date_range['earliest']->format( 'Y-m-d' ) );
		$this->assertSame( '2024-01-19', $date_range['latest']->format( 'Y-m-d' ) );
	}

	/**
	 * Helper: authenticate the adapter with the test fixture.
	 *
	 * @return void
	 */
	private function authenticate_adapter(): void {
		// Override get_option to return stored credentials after authentication.
		$credentials = array(
			'file_path'    => $this->fixture_path,
			'connected_at' => gmdate( 'c' ),
		);

		Functions\when( 'get_option' )->justReturn( $credentials );

		$this->adapter->authenticate( array( 'file' => $this->fixture_path ) );
	}
}
