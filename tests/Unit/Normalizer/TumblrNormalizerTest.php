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
	}
}
