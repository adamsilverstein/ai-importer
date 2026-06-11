<?php
/**
 * YouTubeAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\YouTubeAdapter;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the YouTubeAdapter class.
 */
class YouTubeAdapterTest extends TestCase {

	/**
	 * Path to the fixture Takeout export.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var YouTubeAdapter
	 */
	private YouTubeAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/youtube-takeout.zip';
		$this->adapter      = new YouTubeAdapter();

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
	}

	/**
	 * Authenticate the adapter against the fixture export.
	 *
	 * @return void
	 */
	private function authenticate_adapter(): void {
		$this->assertTrue(
			$this->adapter->authenticate( array( 'file' => $this->fixture_path ) )
		);
	}

	/**
	 * Stub the WP HTTP API to return queued JSON bodies in order.
	 *
	 * Captures requested URLs into the passed array.
	 *
	 * @param array<array<string, mixed>> $bodies Decoded response bodies, in request order.
	 * @param array<string>               $urls   Captured request URLs (by reference).
	 * @param int                         $code   HTTP status code.
	 * @return void
	 */
	private function stub_http_api( array $bodies, array &$urls, int $code = 200 ): void {
		$index = 0;

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url ) use ( $bodies, &$urls, &$index, $code ) {
				$urls[] = $url;
				$body   = $bodies[ $index ] ?? ( $bodies[ count( $bodies ) - 1 ] ?? array() );
				++$index;

				return array(
					'response' => array( 'code' => $code ),
					'body'     => json_encode( $body ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub.
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
	}

	/**
	 * Build a channels.list response that exposes an uploads playlist.
	 *
	 * @param string $uploads_id Uploads playlist ID.
	 * @return array<string, mixed>
	 */
	private function make_channel_response( string $uploads_id = 'UU_uploads' ): array {
		return array(
			'items' => array(
				array(
					'contentDetails' => array(
						'relatedPlaylists' => array(
							'uploads' => $uploads_id,
						),
					),
				),
			),
		);
	}

	/**
	 * Build a playlistItems.list response with the given items.
	 *
	 * @param array<array<string, mixed>> $items          Playlist items.
	 * @param string|null                 $next_page_token Optional next page token.
	 * @return array<string, mixed>
	 */
	private function make_playlist_response( array $items, ?string $next_page_token = null ): array {
		$response = array( 'items' => $items );

		if ( null !== $next_page_token ) {
			$response['nextPageToken'] = $next_page_token;
		}

		return $response;
	}

	/**
	 * Build a single playlist item.
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_playlist_item( array $overrides = array() ): array {
		$defaults = array(
			'video_id'     => 'abc123',
			'title'        => 'API Video',
			'description'  => 'Fetched over the Data API.',
			'published_at' => '2024-06-01T09:00:00Z',
			'channel'      => 'My Channel',
			'tags'         => array( 'news', 'updates' ),
			'thumbnail'    => 'https://i.ytimg.com/vi/abc123/hqdefault.jpg',
		);

		$data = array_merge( $defaults, $overrides );

		return array(
			'snippet'        => array(
				'title'        => $data['title'],
				'description'  => $data['description'],
				'channelTitle' => $data['channel'],
				'publishedAt'  => $data['published_at'],
				'tags'         => $data['tags'],
				'thumbnails'   => array(
					'high' => array( 'url' => $data['thumbnail'] ),
				),
				'resourceId'   => array( 'videoId' => $data['video_id'] ),
			),
			'contentDetails' => array(
				'videoId'          => $data['video_id'],
				'videoPublishedAt' => $data['published_at'],
			),
		);
	}

	/**
	 * Test adapter identity methods.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$this->assertSame( 'youtube', $this->adapter->get_id() );
		$this->assertSame( 'YouTube', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
	}

	/**
	 * Test settings schema exposes file upload and Data API fields.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$this->assertTrue( $schema->has_field( 'api_key' ) );
		$this->assertTrue( $schema->has_field( 'channel_id' ) );

		$file_field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $file_field['type'] );
		$this->assertSame( '.zip', $file_field['accept'] );

		$this->assertSame( 'password', $schema->get_field( 'api_key' )['type'] );
		$this->assertSame( 'text', $schema->get_field( 'channel_id' )['type'] );
	}

	/**
	 * Test supported content types include media and video.
	 *
	 * @return void
	 */
	public function test_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( ContentType::MEDIA->value, $types );
		$this->assertContains( ContentType::VIDEO->value, $types );
	}

	/**
	 * Test authenticate with a valid Takeout export.
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
			$this->adapter->authenticate( array( 'file' => '/nonexistent/youtube.zip' ) )
		);
	}

	/**
	 * Test authenticate fails for a ZIP without video metadata.
	 *
	 * @return void
	 */
	public function test_authenticate_with_non_takeout_zip(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_yt_' ) . '.zip';

		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString( 'notes.csv', "name,value\nfoo,bar\n" );
		$zip->close();

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test authenticate fails when neither file nor API credentials given.
	 *
	 * @return void
	 */
	public function test_authenticate_with_empty_credentials(): void {
		$this->assertFalse( $this->adapter->authenticate( array() ) );
	}

	/**
	 * Test fetch_manifest surfaces only public videos.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_excludes_non_public(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		// 4 rows: 2 public, 1 private, 1 unlisted -> only 2 surfaced.
		$this->assertCount( 2, $manifest->get_items() );
		$this->assertNull( $manifest->get_item( 'kJQP7kiw5Fk' ) );
		$this->assertNull( $manifest->get_item( 'M7lc1UVf-VE' ) );
	}

	/**
	 * Test manifest items are classified as MEDIA with a canonical URL.
	 *
	 * @return void
	 */
	public function test_manifest_item_shape(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( 'dQw4w9WgXcQ' );

		$this->assertNotNull( $item );
		$this->assertSame( ContentType::MEDIA, $item->type );
		$this->assertSame( 'Welcome to my channel', $item->title );
		$this->assertSame(
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			$item->original_url
		);
		$this->assertSame( 'public', $item->metadata['visibility'] );
	}

	/**
	 * Test fetch_item builds an embed plus a multiline description.
	 *
	 * @return void
	 */
	public function test_fetch_item_builds_embed_and_paragraphs(): void {
		$this->authenticate_adapter();

		$item = $this->adapter->fetch_item( '9bZkp7q19f0' );

		$this->assertSame( ContentType::MEDIA->value, $item['type'] );
		$this->assertSame( 'Multi-line notes', $item['title'] );

		// Embed block referencing the canonical watch URL.
		$this->assertStringContainsString( 'wp:embed', $item['content'] );
		$this->assertStringContainsString(
			'https://www.youtube.com/watch?v=9bZkp7q19f0',
			$item['content']
		);

		// Both description paragraphs are present.
		$this->assertStringContainsString( 'First paragraph of the description.', $item['content'] );
		$this->assertStringContainsString( 'Second paragraph with more detail.', $item['content'] );
		$this->assertStringContainsString( 'wp:paragraph', $item['content'] );

		$this->assertSame(
			'https://www.youtube.com/watch?v=9bZkp7q19f0',
			$item['original_url']
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
	 * Test fetch_item throws for an excluded private video.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_for_private_video(): void {
		$this->authenticate_adapter();

		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_item( 'kJQP7kiw5Fk' );
	}

	/**
	 * Test authenticate with valid Data API credentials probes channels.
	 *
	 * @return void
	 */
	public function test_authenticate_with_api_credentials(): void {
		$urls = array();
		$this->stub_http_api( array( $this->make_channel_response() ), $urls );

		$this->assertTrue(
			$this->adapter->authenticate(
				array(
					'api_key'    => 'key123',
					'channel_id' => 'UC_channel',
				)
			)
		);
		$this->assertTrue( $this->adapter->is_authenticated() );

		$this->assertNotEmpty( $urls );
		$this->assertStringContainsString( 'googleapis.com/youtube/v3/channels', $urls[0] );
		$this->assertStringContainsString( 'id=UC_channel', $urls[0] );
		$this->assertStringContainsString( 'key=key123', $urls[0] );
	}

	/**
	 * Test authenticate fails when the Data API returns an HTTP error.
	 *
	 * @return void
	 */
	public function test_authenticate_with_api_http_error(): void {
		$urls = array();
		$this->stub_http_api( array( array() ), $urls, 403 );

		$this->assertFalse(
			$this->adapter->authenticate(
				array(
					'api_key'    => 'bad-key',
					'channel_id' => 'UC_channel',
				)
			)
		);
	}

	/**
	 * Test authenticate fails when the channel cannot be found.
	 *
	 * @return void
	 */
	public function test_authenticate_with_unknown_channel(): void {
		$urls = array();
		$this->stub_http_api( array( array( 'items' => array() ) ), $urls );

		$this->assertFalse(
			$this->adapter->authenticate(
				array(
					'api_key'    => 'key123',
					'channel_id' => 'UC_missing',
				)
			)
		);
	}

	/**
	 * Test fetch_manifest in API mode lists uploads with pagination.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_from_api_paginates(): void {
		$urls = array();

		// Request order: probe(channel), resolve(channel), page1, page2.
		$this->stub_http_api(
			array(
				$this->make_channel_response(),
				$this->make_channel_response(),
				$this->make_playlist_response(
					array( $this->make_playlist_item( array( 'video_id' => 'vid1' ) ) ),
					'PAGE2'
				),
				$this->make_playlist_response(
					array( $this->make_playlist_item( array( 'video_id' => 'vid2' ) ) )
				),
			),
			$urls
		);

		$this->assertTrue(
			$this->adapter->authenticate(
				array(
					'api_key'    => 'key123',
					'channel_id' => 'UC_channel',
				)
			)
		);

		$manifest = $this->adapter->fetch_manifest();

		$this->assertCount( 2, $manifest->get_items() );
		$this->assertNotNull( $manifest->get_item( 'vid1' ) );
		$this->assertNotNull( $manifest->get_item( 'vid2' ) );

		// Pagination passed the next page token through.
		$paged = array_filter(
			$urls,
			static fn( $url ) => false !== strpos( $url, 'pageToken=PAGE2' )
		);
		$this->assertNotEmpty( $paged );
	}

	/**
	 * Test fetch_item in API mode includes the thumbnail and tags.
	 *
	 * @return void
	 */
	public function test_fetch_item_from_api(): void {
		$urls = array();

		$this->stub_http_api(
			array(
				$this->make_channel_response(),
				$this->make_channel_response(),
				$this->make_playlist_response(
					array( $this->make_playlist_item( array( 'video_id' => 'vid1' ) ) )
				),
			),
			$urls
		);

		$this->assertTrue(
			$this->adapter->authenticate(
				array(
					'api_key'    => 'key123',
					'channel_id' => 'UC_channel',
				)
			)
		);

		$item = $this->adapter->fetch_item( 'vid1' );

		$this->assertSame( 'API Video', $item['title'] );
		$this->assertSame( 'My Channel', $item['metadata']['channel_title'] );
		$this->assertSame( array( 'news', 'updates' ), $item['metadata']['tags'] );
		$this->assertContains(
			'https://i.ytimg.com/vi/abc123/hqdefault.jpg',
			$item['media_urls']
		);
		$this->assertSame( array( 'name' => 'My Channel' ), $item['author'] );
		$this->assertSame( 'https://www.youtube.com/watch?v=vid1', $item['original_url'] );
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
