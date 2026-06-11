<?php
/**
 * YouTubeNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\YouTubeNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the YouTubeNormalizer class.
 */
class YouTubeNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var YouTubeNormalizer
	 */
	private YouTubeNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new YouTubeNormalizer();

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
	 * Build a minimal raw item as produced by YouTubeAdapter::fetch_item().
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'dQw4w9WgXcQ',
				'type'         => 'media',
				'content'      => '<!-- wp:embed --><figure>...</figure><!-- /wp:embed -->',
				'title'        => 'Welcome to my channel',
				'created_at'   => '2023-01-15T10:00:00+00:00',
				'media_urls'   => array( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' ),
				'metadata'     => array(
					'video_id'      => 'dQw4w9WgXcQ',
					'channel_title' => 'My Channel',
					'duration'      => null,
					'tags'          => array( 'news', 'updates' ),
					'visibility'    => 'public',
				),
				'original_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
				'tags'         => array( 'news', 'updates' ),
				'author'       => array( 'name' => 'My Channel' ),
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_youtube(): void {
		$this->assertSame( 'youtube', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'youtube' ) );
		$this->assertFalse( $this->normalizer->supports( 'ghost' ) );
	}

	/**
	 * Test normalize maps the basic fields.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'dQw4w9WgXcQ', $item->source_id );
		$this->assertSame( 'youtube', $item->source_adapter );
		$this->assertSame( 'Welcome to my channel', $item->title );
		$this->assertSame( ContentType::MEDIA, $item->content_type );
		$this->assertSame(
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			$item->source_url
		);
		$this->assertSame( 'My Channel', $item->author_name );
		$this->assertSame( '2023-01-15T10:00:00+00:00', $item->publish_date->format( 'c' ) );
	}

	/**
	 * Test normalize maps the thumbnail to a media reference.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_thumbnail_media(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertCount( 1, $item->media );
		$this->assertSame(
			'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
			$item->media[0]->source_url
		);
		$this->assertSame( 'image', $item->media[0]->type );
	}

	/**
	 * Test normalize uses tags from metadata.tags.
	 *
	 * @return void
	 */
	public function test_normalize_uses_metadata_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'metadata' => array(
						'video_id' => 'dQw4w9WgXcQ',
						'tags'     => array( 'vlog', 'travel' ),
					),
					'tags'     => array( 'ignored' ),
				)
			)
		);

		$this->assertSame( array( 'vlog', 'travel' ), $item->tags );
	}

	/**
	 * Test normalize falls back to top-level tags when metadata has none.
	 *
	 * @return void
	 */
	public function test_normalize_falls_back_to_top_level_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'metadata' => array( 'video_id' => 'dQw4w9WgXcQ' ),
					'tags'     => array( 'fallback' ),
				)
			)
		);

		$this->assertSame( array( 'fallback' ), $item->tags );
	}

	/**
	 * Test normalize unknown type falls back to MEDIA.
	 *
	 * @return void
	 */
	public function test_normalize_unknown_type_falls_back_to_media(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'whatever' ) )
		);

		$this->assertSame( ContentType::MEDIA, $item->content_type );
	}

	/**
	 * Test normalize preserves YouTube metadata for mapping.
	 *
	 * @return void
	 */
	public function test_normalize_preserves_metadata(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'dQw4w9WgXcQ', $item->get_meta( 'video_id' ) );
		$this->assertSame( 'My Channel', $item->get_meta( 'channel_title' ) );
		$this->assertSame( 'public', $item->get_meta( 'visibility' ) );
	}
}
