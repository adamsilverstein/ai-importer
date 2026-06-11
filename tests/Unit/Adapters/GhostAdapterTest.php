<?php
/**
 * GhostAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\GhostAdapter;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the GhostAdapter class.
 */
class GhostAdapterTest extends TestCase {

	/**
	 * Path to the fixture export.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var GhostAdapter
	 */
	private GhostAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/ghost-export.json';
		$this->adapter      = new GhostAdapter();

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
	 * Stub the WP HTTP API to return the given body for every request.
	 *
	 * Captures requested URLs into the passed array.
	 *
	 * @param array<string, mixed> $body Decoded response body per resource.
	 * @param array<string>        $urls Captured request URLs (by reference).
	 * @param int                  $code HTTP status code.
	 * @return void
	 */
	private function stub_http_api( array $body, array &$urls, int $code = 200 ): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url ) use ( $body, &$urls, $code ) {
				$urls[] = $url;

				$resource = false !== strpos( $url, '/content/pages/' ) ? 'pages' : 'posts';

				return array(
					'response' => array( 'code' => $code ),
					'body'     => json_encode( $body[ $resource ] ?? array() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub.
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
	 * Build a minimal Content API post record.
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_api_post( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'api-post-1',
				'uuid'           => '9c5c0c5e-0000-4000-8000-00000000000a',
				'title'          => 'API Post',
				'slug'           => 'api-post',
				'html'           => '<p>Fetched over the Content API.</p>',
				'feature_image'  => 'https://example.ghost.io/content/images/api-feature.jpg',
				'custom_excerpt' => null,
				'created_at'     => '2024-06-01T08:00:00.000Z',
				'updated_at'     => '2024-06-01T08:00:00.000Z',
				'published_at'   => '2024-06-01T09:00:00.000Z',
				'url'            => 'https://example.ghost.io/api-post/',
				'tags'           => array(
					array( 'id' => 'tag-1', 'name' => 'News', 'slug' => 'news' ),
				),
				'authors'        => array(
					array( 'id' => 'user-1', 'name' => 'Jane Doe', 'slug' => 'jane' ),
				),
			),
			$overrides
		);
	}

	/**
	 * Test adapter identity methods.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$this->assertSame( 'ghost', $this->adapter->get_id() );
		$this->assertSame( 'Ghost', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
	}

	/**
	 * Test settings schema exposes file upload and Content API fields.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$this->assertTrue( $schema->has_field( 'api_url' ) );
		$this->assertTrue( $schema->has_field( 'content_api_key' ) );

		$file_field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $file_field['type'] );
		$this->assertSame( '.json', $file_field['accept'] );

		$this->assertSame( 'url', $schema->get_field( 'api_url' )['type'] );
		$this->assertSame( 'password', $schema->get_field( 'content_api_key' )['type'] );
	}

	/**
	 * Test authenticate with a valid Ghost JSON export.
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
			$this->adapter->authenticate( array( 'file' => '/nonexistent/ghost.json' ) )
		);
	}

	/**
	 * Test authenticate fails for JSON that isn't a Ghost export.
	 *
	 * @return void
	 */
	public function test_authenticate_with_non_ghost_json(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_ghost_' );
		file_put_contents( $tmp, '{"posts": []}' );

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
	 * Test fetch_manifest surfaces published posts and pages but not drafts.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_excludes_drafts(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		// 3 published posts + 1 page = 4 items; the draft is skipped.
		$this->assertCount( 4, $manifest->get_items() );
		$this->assertNull( $manifest->get_item( 'post-3' ) );
	}

	/**
	 * Test tags are joined onto posts via posts_tags.
	 *
	 * @return void
	 */
	public function test_tags_joined_from_posts_tags(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( 'post-1' );

		$this->assertNotNull( $item );
		$this->assertSame( array( 'News', 'Updates' ), $item->metadata['tags'] );
	}

	/**
	 * Test authors are joined via posts_authors + users, including co-authors.
	 *
	 * @return void
	 */
	public function test_authors_joined_from_posts_authors(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( 'post-5' );

		$this->assertNotNull( $item );
		$this->assertCount( 2, $item->metadata['authors'] );
		$this->assertSame( 'Jane Doe', $item->metadata['authors'][0]['name'] );
		$this->assertSame( 'John Smith', $item->metadata['authors'][1]['name'] );
		$this->assertSame( array( 'name' => 'Jane Doe' ), $item->author );
	}

	/**
	 * Test pages are surfaced with their Ghost type in metadata.
	 *
	 * @return void
	 */
	public function test_page_type_metadata(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$item     = $manifest->get_item( 'post-2' );

		$this->assertNotNull( $item );
		$this->assertSame( 'page', $item->metadata['type'] );
	}

	/**
	 * Test long content is classified as ARTICLE and short as POST.
	 *
	 * @return void
	 */
	public function test_content_type_classification(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();

		$this->assertSame( ContentType::POST, $manifest->get_item( 'post-1' )->type );
		$this->assertSame( ContentType::ARTICLE, $manifest->get_item( 'post-5' )->type );
	}

	/**
	 * Test a post with html null falls back to lexical text extraction.
	 *
	 * @return void
	 */
	public function test_lexical_fallback_when_html_missing(): void {
		$this->authenticate_adapter();

		$item = $this->adapter->fetch_item( 'post-4' );

		$this->assertStringContainsString( 'This post only has lexical content.', $item['content'] );
		$this->assertStringContainsString( '<p>', $item['content'] );
		$this->assertStringContainsString( 'It still gets imported.', $item['content'] );
	}

	/**
	 * Test fetch_item returns full data including media URLs and metadata.
	 *
	 * @return void
	 */
	public function test_fetch_item_returns_full_data(): void {
		$this->authenticate_adapter();

		$item = $this->adapter->fetch_item( 'post-1' );

		$this->assertSame( 'Hello Ghost', $item['title'] );
		$this->assertStringContainsString( 'Welcome to my Ghost blog.', $item['content'] );
		$this->assertSame( '2024-01-10T10:00:00+00:00', $item['created_at'] );
		$this->assertSame( 'hello-ghost', $item['metadata']['slug'] );
		$this->assertSame( 'A short welcome post.', $item['metadata']['custom_excerpt'] );
		$this->assertSame( array( 'News', 'Updates' ), $item['tags'] );

		// Feature image first, then inline images, absolute URLs only.
		$this->assertSame(
			array(
				'https://example.ghost.io/content/images/feature.jpg',
				'https://example.ghost.io/content/images/inline-photo.jpg',
			),
			$item['media_urls']
		);

		// No api_url is known in file mode, so no original URL.
		$this->assertNull( $item['original_url'] );
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
	 * Test fetch_item throws for a draft excluded from the manifest.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_for_draft(): void {
		$this->authenticate_adapter();

		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_item( 'post-3' );
	}

	/**
	 * Test authenticate with valid Content API credentials.
	 *
	 * @return void
	 */
	public function test_authenticate_with_api_credentials(): void {
		$urls = array();
		$this->stub_http_api(
			array( 'posts' => array( 'posts' => array( $this->make_api_post() ) ) ),
			$urls
		);

		$this->assertTrue(
			$this->adapter->authenticate(
				array(
					'api_url'         => 'https://example.ghost.io/',
					'content_api_key' => 'abc123',
				)
			)
		);
		$this->assertTrue( $this->adapter->is_authenticated() );

		$this->assertNotEmpty( $urls );
		$this->assertStringContainsString( 'https://example.ghost.io/ghost/api/content/posts/', $urls[0] );
		$this->assertStringContainsString( 'key=abc123', $urls[0] );
	}

	/**
	 * Test authenticate fails when the Content API returns an HTTP error.
	 *
	 * @return void
	 */
	public function test_authenticate_with_api_http_error(): void {
		$urls = array();
		$this->stub_http_api( array(), $urls, 401 );

		$this->assertFalse(
			$this->adapter->authenticate(
				array(
					'api_url'         => 'https://example.ghost.io',
					'content_api_key' => 'bad-key',
				)
			)
		);
	}

	/**
	 * Test fetch_manifest in API mode merges posts and pages.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_from_api(): void {
		$urls = array();
		$this->stub_http_api(
			array(
				'posts' => array(
					'posts' => array(
						$this->make_api_post(),
					),
				),
				'pages' => array(
					'pages' => array(
						$this->make_api_post(
							array(
								'id'    => 'api-page-1',
								'title' => 'API Page',
								'slug'  => 'api-page',
								'url'   => 'https://example.ghost.io/api-page/',
								'type'  => 'page',
							)
						),
					),
				),
			),
			$urls
		);

		$this->assertTrue(
			$this->adapter->authenticate(
				array(
					'api_url'         => 'https://example.ghost.io',
					'content_api_key' => 'abc123',
				)
			)
		);

		$manifest = $this->adapter->fetch_manifest();

		$this->assertCount( 2, $manifest->get_items() );

		$post = $manifest->get_item( 'api-post-1' );
		$this->assertNotNull( $post );
		$this->assertSame( array( 'News' ), $post->metadata['tags'] );
		$this->assertSame( array( 'name' => 'Jane Doe' ), $post->author );
		$this->assertSame( 'https://example.ghost.io/api-post/', $post->original_url );

		$page = $manifest->get_item( 'api-page-1' );
		$this->assertNotNull( $page );
		$this->assertSame( 'page', $page->metadata['type'] );

		// Manifest requests include tags, authors, and html format.
		$manifest_url = $urls[ count( $urls ) - 2 ];
		$this->assertStringContainsString( 'include=tags%2Cauthors', $manifest_url );
		$this->assertStringContainsString( 'limit=all', $manifest_url );
		$this->assertStringContainsString( 'formats=html', $manifest_url );
	}

	/**
	 * Test fetch_item in API mode derives the original URL from api_url + slug.
	 *
	 * @return void
	 */
	public function test_fetch_item_from_api_builds_original_url_from_slug(): void {
		$urls = array();
		$this->stub_http_api(
			array(
				'posts' => array(
					'posts' => array(
						$this->make_api_post( array( 'url' => null ) ),
					),
				),
			),
			$urls
		);

		$this->assertTrue(
			$this->adapter->authenticate(
				array(
					'api_url'         => 'https://example.ghost.io',
					'content_api_key' => 'abc123',
				)
			)
		);

		$item = $this->adapter->fetch_item( 'api-post-1' );

		$this->assertSame( 'https://example.ghost.io/api-post/', $item['original_url'] );
		$this->assertContains( 'https://example.ghost.io/content/images/api-feature.jpg', $item['media_urls'] );
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
