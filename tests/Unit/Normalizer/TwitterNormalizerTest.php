<?php
/**
 * TwitterNormalizer tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\TwitterNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TwitterNormalizer class.
 */
class TwitterNormalizerTest extends TestCase {

	/**
	 * Normalizer instance.
	 *
	 * @var TwitterNormalizer
	 */
	private TwitterNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new TwitterNormalizer();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wpautop' )->alias(
			function ( $text ) {
				return '<p>' . trim( $text ) . '</p>';
			}
		);
	}

	/**
	 * Test adapter ID.
	 *
	 * @return void
	 */
	public function test_get_adapter_id(): void {
		$this->assertSame( 'twitter', $this->normalizer->get_adapter_id() );
	}

	/**
	 * Test supports method.
	 *
	 * @return void
	 */
	public function test_supports_twitter(): void {
		$this->assertTrue( $this->normalizer->supports( 'twitter' ) );
		$this->assertFalse( $this->normalizer->supports( 'medium' ) );
	}

	/**
	 * Test normalizing a basic tweet.
	 *
	 * @return void
	 */
	public function test_normalize_basic_tweet(): void {
		$raw = $this->make_raw_tweet();

		$item = $this->normalizer->normalize( $raw );

		$this->assertSame( 'tweet-123', $item->source_id );
		$this->assertSame( 'twitter', $item->source_adapter );
		$this->assertSame( ContentType::POST, $item->content_type );
		$this->assertStringContainsString( 'Hello world', $item->content );
		$this->assertSame( 'Hello world!', $item->title );
		$this->assertSame( '2024-01-15', $item->publish_date->format( 'Y-m-d' ) );
	}

	/**
	 * Test content is converted to HTML paragraphs.
	 *
	 * @return void
	 */
	public function test_plain_text_converted_to_html(): void {
		Functions\when( 'wpautop' )->alias(
			function ( $text ) {
				return '<p>' . $text . '</p>';
			}
		);

		$raw  = $this->make_raw_tweet( array( 'content' => 'Just a plain tweet' ) );
		$item = $this->normalizer->normalize( $raw );

		$this->assertStringContainsString( '<p>', $item->content );
	}

	/**
	 * Test media URLs are extracted.
	 *
	 * @return void
	 */
	public function test_media_urls_extracted(): void {
		$raw = $this->make_raw_tweet(
			array(
				'media_urls' => array( 'https://pbs.twimg.com/media/example.jpg' ),
			)
		);

		$item = $this->normalizer->normalize( $raw );

		$this->assertTrue( $item->has_media() );
		$this->assertSame( 1, $item->get_media_count() );
		$this->assertSame( 'https://pbs.twimg.com/media/example.jpg', $item->media[0]->source_url );
	}

	/**
	 * Test local media paths are set from archive.
	 *
	 * @return void
	 */
	public function test_local_media_paths_set(): void {
		$raw = $this->make_raw_tweet(
			array(
				'media_urls'  => array( 'https://pbs.twimg.com/media/example.jpg' ),
				'media_paths' => array( '/tmp/archive/data/tweets_media/123-example.jpg' ),
			)
		);

		$item = $this->normalizer->normalize( $raw );

		$this->assertSame(
			'/tmp/archive/data/tweets_media/123-example.jpg',
			$item->media[0]->local_path
		);
	}

	/**
	 * Test hashtags extracted from metadata.
	 *
	 * @return void
	 */
	public function test_hashtags_from_metadata(): void {
		$raw = $this->make_raw_tweet(
			array(
				'metadata' => array(
					'hashtags'       => array( 'WordPress', 'PHP' ),
					'favorite_count' => 10,
				),
			)
		);

		$item = $this->normalizer->normalize( $raw );

		$this->assertTrue( $item->has_tags() );
		$this->assertContains( 'WordPress', $item->tags );
		$this->assertContains( 'PHP', $item->tags );
	}

	/**
	 * Test engagement metrics extracted.
	 *
	 * @return void
	 */
	public function test_engagement_extracted(): void {
		$raw = $this->make_raw_tweet(
			array(
				'metadata' => array(
					'favorite_count' => 42,
					'retweet_count'  => 7,
				),
			)
		);

		$item = $this->normalizer->normalize( $raw );

		$this->assertSame( 42, $item->get_engagement( 'likes' ) );
		$this->assertSame( 7, $item->get_engagement( 'shares' ) );
	}

	/**
	 * Test content type mapping.
	 *
	 * @return void
	 */
	public function test_content_type_mapping(): void {
		$types = array(
			'post'   => ContentType::POST,
			'reply'  => ContentType::REPLY,
			'repost' => ContentType::REPOST,
			'thread' => ContentType::THREAD,
			'media'  => ContentType::MEDIA,
		);

		foreach ( $types as $raw_type => $expected ) {
			$raw  = $this->make_raw_tweet( array( 'type' => $raw_type ) );
			$item = $this->normalizer->normalize( $raw );
			$this->assertSame( $expected, $item->content_type, "Failed for type: {$raw_type}" );
		}
	}

	/**
	 * Test original URL preserved.
	 *
	 * @return void
	 */
	public function test_original_url_preserved(): void {
		$raw  = $this->make_raw_tweet(
			array( 'original_url' => 'https://x.com/i/status/123' )
		);
		$item = $this->normalizer->normalize( $raw );

		$this->assertSame( 'https://x.com/i/status/123', $item->source_url );
	}

	/**
	 * Test parent_id preserved for replies.
	 *
	 * @return void
	 */
	public function test_parent_id_preserved(): void {
		$raw  = $this->make_raw_tweet(
			array(
				'type'      => 'reply',
				'parent_id' => 'tweet-999',
			)
		);
		$item = $this->normalizer->normalize( $raw );

		$this->assertSame( 'tweet-999', $item->parent_id );
		$this->assertTrue( $item->is_reply() );
	}

	/**
	 * Create a raw tweet array matching TwitterAdapter::fetch_item() shape.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function make_raw_tweet( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'tweet-123',
				'type'         => 'post',
				'content'      => 'Hello world!',
				'title'        => 'Hello world!',
				'created_at'   => '2024-01-15T10:15:00+00:00',
				'media_urls'   => array(),
				'media_paths'  => array(),
				'metadata'     => array(),
				'parent_id'    => null,
				'original_url' => null,
				'raw'          => array(),
			),
			$overrides
		);
	}
}
