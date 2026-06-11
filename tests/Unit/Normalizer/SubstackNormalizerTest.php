<?php
/**
 * SubstackNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\SubstackNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the SubstackNormalizer class.
 */
class SubstackNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var SubstackNormalizer
	 */
	private SubstackNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new SubstackNormalizer();

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
				'id'           => '1001.owning-your-words',
				'type'         => 'article',
				'content'      => '<p>Hello Substack</p>',
				'title'        => 'Owning Your Words',
				'created_at'   => '2023-05-01T12:00:00+00:00',
				'media_urls'   => array(),
				'metadata'     => array(
					'post_type'     => 'newsletter',
					'subtitle'      => 'A long-form essay on durable publishing',
					'audience'      => 'everyone',
					'podcast_url'   => null,
					'email_sent_at' => '2023-05-01T12:05:00.000Z',
				),
				'original_url' => null,
				'author'       => null,
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_substack(): void {
		$this->assertSame( 'substack', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'substack' ) );
		$this->assertFalse( $this->normalizer->supports( 'tumblr' ) );
	}

	/**
	 * Test normalize maps the basic fields.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( '1001.owning-your-words', $item->source_id );
		$this->assertSame( 'substack', $item->source_adapter );
		$this->assertSame( 'Owning Your Words', $item->title );
		$this->assertSame( '<p>Hello Substack</p>', $item->content );
		$this->assertNull( $item->source_url );
		$this->assertNull( $item->author_name );
		$this->assertSame(
			'2023-05-01T12:00:00+00:00',
			$item->publish_date->format( 'c' )
		);
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
	 * Test normalize resolves the media content type for podcasts.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_media_type_for_podcasts(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'media' ) )
		);

		$this->assertSame( ContentType::MEDIA, $item->content_type );
	}

	/**
	 * Test normalize resolves the thread content type.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_thread_type(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'thread' ) )
		);

		$this->assertSame( ContentType::THREAD, $item->content_type );
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
	 * Test Substack-specific metadata is passed through, including the
	 * podcast URL which is intentionally kept as metadata rather than a
	 * media reference.
	 *
	 * @return void
	 */
	public function test_normalize_passes_substack_metadata_through(): void {
		$raw = $this->make_raw(
			array(
				'type'     => 'media',
				'metadata' => array(
					'post_type'     => 'podcast',
					'subtitle'      => 'The very first episode',
					'audience'      => 'everyone',
					'podcast_url'   => 'https://api.substack.com/feed/podcast/1003/episode-1.mp3',
					'email_sent_at' => null,
				),
			)
		);

		$item = $this->normalizer->normalize( $raw );

		$this->assertSame( 'podcast', $item->metadata['post_type'] );
		$this->assertSame( 'The very first episode', $item->metadata['subtitle'] );
		$this->assertSame( 'everyone', $item->metadata['audience'] );
		$this->assertSame(
			'https://api.substack.com/feed/podcast/1003/episode-1.mp3',
			$item->metadata['podcast_url']
		);
		$this->assertEmpty( $item->media );
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
					'media_urls' => array( 'https://substackcdn.com/image/fetch/hero.jpg' ),
				)
			)
		);

		$this->assertCount( 1, $item->media );
		$this->assertSame(
			'https://substackcdn.com/image/fetch/hero.jpg',
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
					'media_urls'  => array( 'media/bundled.jpg', 'https://substackcdn.com/image/fetch/hero.jpg' ),
					'media_paths' => array( $tmp, null ),
				)
			)
		);

		$this->assertCount( 2, $item->media );

		$this->assertSame( 'media/bundled.jpg', $item->media[0]->source_url );
		$this->assertSame( $tmp, $item->media[0]->local_path );
		$this->assertFileExists( $item->media[0]->local_path );

		$this->assertSame( 'https://substackcdn.com/image/fetch/hero.jpg', $item->media[1]->source_url );
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

		$adapter = new \AI_Importer\Adapters\SubstackAdapter();
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/substack-export.zip';

		$this->assertTrue( $adapter->authenticate( array( 'file' => $fixture ) ) );

		// Post 1006 references the archive-bundled media/bundled.jpg.
		$item = $this->normalizer->normalize( $adapter->fetch_item( '1006.local-image' ) );

		$this->assertNotEmpty( $item->media );

		$bundled = null;
		foreach ( $item->media as $reference ) {
			if ( 'media/bundled.jpg' === $reference->source_url ) {
				$bundled = $reference;
			}
		}

		$this->assertNotNull( $bundled );
		$this->assertIsString( $bundled->local_path );
		$this->assertMatchesRegularExpression(
			'#^(?:/|[A-Za-z]:[/\\\\])#',
			$bundled->local_path,
			'local_path should be an absolute filesystem path.'
		);
		$this->assertFileExists( $bundled->local_path );
	}
}
