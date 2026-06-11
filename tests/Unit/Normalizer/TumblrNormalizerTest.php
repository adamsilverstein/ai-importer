<?php
/**
 * TumblrNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\TumblrNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TumblrNormalizer class.
 */
class TumblrNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var TumblrNormalizer
	 */
	private TumblrNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new TumblrNormalizer();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
	}

	/**
	 * Build a minimal raw item.
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => '100',
				'type'         => 'post',
				'content'      => '<p>Hello</p>',
				'title'        => 'Hello Tumblr',
				'created_at'   => '2024-01-15T10:30:00Z',
				'media_urls'   => array(),
				'metadata'     => array(
					'post_type'    => 'text',
					'tags'         => array( 'wordpress' ),
					'is_reblog'    => false,
					'reblog_from'  => null,
					'source_title' => null,
				),
				'original_url' => 'https://example.tumblr.com/post/100/hello',
				'tags'         => array( 'wordpress' ),
				'author'       => array(
					'name' => 'Example',
					'url'  => 'https://example.tumblr.com',
				),
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_tumblr(): void {
		$this->assertSame( 'tumblr', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'tumblr' ) );
		$this->assertFalse( $this->normalizer->supports( 'medium' ) );
	}

	/**
	 * Test normalize maps the basic fields.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( '100', $item->source_id );
		$this->assertSame( 'tumblr', $item->source_adapter );
		$this->assertSame( 'Hello Tumblr', $item->title );
		$this->assertSame( 'https://example.tumblr.com/post/100/hello', $item->source_url );
		$this->assertSame( 'Example', $item->author_name );
		$this->assertSame( 'https://example.tumblr.com', $item->author_url );
	}

	/**
	 * Test normalize resolves the article content type.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_article_type(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'article' ) )
		);

		$this->assertSame( ContentType::ARTICLE, $item->content_type );
	}

	/**
	 * Test normalize resolves the media content type for photo/audio.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_media_type(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'media' ) )
		);

		$this->assertSame( ContentType::MEDIA, $item->content_type );
	}

	/**
	 * Test normalize resolves the video content type.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_video_type(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'video' ) )
		);

		$this->assertSame( ContentType::VIDEO, $item->content_type );
	}

	/**
	 * Test normalize falls back to POST when type is unknown.
	 *
	 * @return void
	 */
	public function test_normalize_unknown_type_falls_back_to_post(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'whatever' ) )
		);

		$this->assertSame( ContentType::POST, $item->content_type );
	}

	/**
	 * Test reblogs override the content type to REPOST regardless of
	 * the underlying adapter-supplied type.
	 *
	 * @return void
	 */
	public function test_reblog_overrides_content_type(): void {
		$raw                          = $this->make_raw( array( 'type' => 'video' ) );
		$raw['metadata']['is_reblog'] = true;
		$raw['metadata']['reblog_from'] = 'https://other.tumblr.com/post/9999';

		$item = $this->normalizer->normalize( $raw );

		$this->assertSame( ContentType::REPOST, $item->content_type );
		$this->assertSame(
			'https://other.tumblr.com/post/9999',
			$item->metadata['reblog_from']
		);
	}

	/**
	 * Test normalize forwards tags onto the NormalizedItem.
	 *
	 * @return void
	 */
	public function test_normalize_forwards_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'tags' => array( 'photography', 'sunset' ) ) )
		);

		$this->assertSame( array( 'photography', 'sunset' ), $item->tags );
	}

	/**
	 * Test normalize creates media references from URL list.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'media_urls' => array( 'https://example.com/img/a.jpg' ),
				)
			)
		);

		$this->assertCount( 1, $item->media );
		$this->assertSame(
			'https://example.com/img/a.jpg',
			$item->media[0]->source_url
		);
		$this->assertNull( $item->media[0]->local_path );
	}

	/**
	 * Test normalize sets local_path from media_paths for archive media
	 * while leaving absolute http(s) URLs to the download path.
	 *
	 * @return void
	 */
	public function test_normalize_sets_local_path_for_archive_media(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_test_' );

		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'media_urls'  => array( 'media/sunset.jpg', 'https://example.com/img/a.jpg' ),
					'media_paths' => array( $tmp, null ),
				)
			)
		);

		$this->assertCount( 2, $item->media );

		$this->assertSame( 'media/sunset.jpg', $item->media[0]->source_url );
		$this->assertSame( $tmp, $item->media[0]->local_path );
		$this->assertFileExists( $item->media[0]->local_path );

		$this->assertSame( 'https://example.com/img/a.jpg', $item->media[1]->source_url );
		$this->assertNull( $item->media[1]->local_path );

		unlink( $tmp );
	}

	/**
	 * Test the full adapter-to-normalizer flow: relative media paths in
	 * the fixture ZIP end up as existing absolute local_path values on
	 * MediaReference objects.
	 *
	 * @return void
	 */
	public function test_normalize_fixture_archive_media_resolves_to_local_files(): void {
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
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.7' );
		Functions\when( 'wp_tempnam' )->alias(
			static function () {
				return tempnam( sys_get_temp_dir(), 'ai_importer_test_' );
			}
		);

		$adapter = new \AI_Importer\Adapters\TumblrAdapter();
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/tumblr-export.zip';

		$this->assertTrue( $adapter->authenticate( array( 'file' => $fixture ) ) );

		// Post 300 is the photo post referencing media/sunset.jpg.
		$item = $this->normalizer->normalize( $adapter->fetch_item( '300' ) );

		$this->assertNotEmpty( $item->media );

		$sunset = null;
		foreach ( $item->media as $reference ) {
			if ( 'media/sunset.jpg' === $reference->source_url ) {
				$sunset = $reference;
			}
		}

		$this->assertNotNull( $sunset );
		$this->assertIsString( $sunset->local_path );
		$this->assertMatchesRegularExpression(
			'#^(?:/|[A-Za-z]:[/\\\\])#',
			$sunset->local_path,
			'local_path should be an absolute filesystem path.'
		);
		$this->assertFileExists( $sunset->local_path );
	}
}
