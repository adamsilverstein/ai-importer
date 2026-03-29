<?php
/**
 * TwitterNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\HtmlSanitizer;
use AI_Importer\Normalizer\DateConverter;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\TwitterNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;

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

		// Stub WordPress functions used by the sanitizer/normalizer.
		Functions\stubs(
			array(
				'wp_kses_post' => function ( $text ) {
					return $text;
				},
				'wpautop'      => function ( $text ) {
					return '<p>' . $text . '</p>';
				},
			)
		);

		$this->normalizer = new TwitterNormalizer();
	}

	/**
	 * Test get_adapter_id returns twitter.
	 *
	 * @return void
	 */
	public function test_get_adapter_id(): void {
		$this->assertSame( 'twitter', $this->normalizer->get_adapter_id() );
	}

	/**
	 * Test normalize creates NormalizedItem from basic tweet.
	 *
	 * @return void
	 */
	public function test_normalize_basic_tweet(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123456',
			full_text: 'Hello World! #test',
			created_at: 'Mon Jan 15 10:30:00 +0000 2024',
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertSame( '123456', $item->source_id );
		$this->assertSame( 'twitter', $item->source_adapter );
		$this->assertSame( ContentType::POST, $item->content_type );
		$this->assertStringContainsString( 'Hello World!', $item->content );
		$this->assertContains( 'test', $item->tags );
	}

	/**
	 * Test normalize detects retweet.
	 *
	 * @return void
	 */
	public function test_normalize_detects_retweet(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '789',
			full_text: 'RT @someone: Great tweet!',
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertSame( ContentType::REPOST, $item->content_type );
	}

	/**
	 * Test normalize detects reply.
	 *
	 * @return void
	 */
	public function test_normalize_detects_reply(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '789',
			full_text: '@someone Yes I agree!',
		);
		$raw_tweet['in_reply_to_status_id_str'] = '456';
		$raw_tweet['in_reply_to_screen_name']   = 'someone';

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertSame( ContentType::REPLY, $item->content_type );
		$this->assertSame( '456', $item->parent_id );
	}

	/**
	 * Test normalize expands t.co URLs.
	 *
	 * @return void
	 */
	public function test_normalize_expands_urls(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123',
			full_text: 'Check this out https://t.co/abc123',
		);
		$raw_tweet['entities']['urls'] = array(
			array(
				'url'          => 'https://t.co/abc123',
				'expanded_url' => 'https://example.com/article',
				'display_url'  => 'example.com/article',
			),
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertStringContainsString( 'https://example.com/article', $item->content );
		$this->assertStringNotContainsString( 't.co', $item->content );
	}

	/**
	 * Test normalize extracts media.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123',
			full_text: 'Look at this photo https://t.co/media1',
		);
		$raw_tweet['extended_entities'] = array(
			'media' => array(
				array(
					'media_url_https' => 'https://pbs.twimg.com/media/abc.jpg',
					'type'            => 'photo',
					'url'             => 'https://t.co/media1',
					'ext_alt_text'    => 'A photo of a cat',
					'sizes'           => array(
						'large' => array(
							'w' => 1200,
							'h' => 800,
						),
					),
				),
			),
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertTrue( $item->has_media() );
		$this->assertSame( 1, $item->get_media_count() );
		$this->assertSame( MediaReference::TYPE_IMAGE, $item->media[0]->type );
		$this->assertSame( 'A photo of a cat', $item->media[0]->alt_text );
		$this->assertSame( 1200, $item->media[0]->width );
	}

	/**
	 * Test normalize extracts video media.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_video(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '456',
			full_text: 'Video tweet https://t.co/vid1',
		);
		$raw_tweet['extended_entities'] = array(
			'media' => array(
				array(
					'media_url_https' => 'https://pbs.twimg.com/ext_tw_video_thumb/abc.jpg',
					'type'            => 'video',
					'url'             => 'https://t.co/vid1',
					'video_info'      => array(
						'variants' => array(
							array(
								'content_type' => 'application/x-mpegURL',
								'url'          => 'https://video.twimg.com/stream.m3u8',
							),
							array(
								'bitrate'      => 832000,
								'content_type' => 'video/mp4',
								'url'          => 'https://video.twimg.com/low.mp4',
							),
							array(
								'bitrate'      => 2176000,
								'content_type' => 'video/mp4',
								'url'          => 'https://video.twimg.com/high.mp4',
							),
						),
					),
					'sizes'           => array(
						'large' => array(
							'w' => 1280,
							'h' => 720,
						),
					),
				),
			),
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertTrue( $item->has_media() );
		$videos = $item->get_videos();
		$this->assertCount( 1, $videos );
		$this->assertStringContainsString( 'high.mp4', $videos[0]->source_url );
	}

	/**
	 * Test normalize extracts engagement metrics.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_engagement(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123',
			full_text: 'Popular tweet',
		);
		$raw_tweet['favorite_count'] = 42;
		$raw_tweet['retweet_count']  = 10;

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertSame( 42, $item->get_engagement( 'likes' ) );
		$this->assertSame( 10, $item->get_engagement( 'shares' ) );
	}

	/**
	 * Test normalize builds source URL with account info.
	 *
	 * @return void
	 */
	public function test_normalize_builds_source_url(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123',
			full_text: 'Hello',
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertSame( 'https://x.com/testuser/status/123', $item->source_url );
	}

	/**
	 * Test normalize sets author info.
	 *
	 * @return void
	 */
	public function test_normalize_sets_author(): void {
		$raw_tweet              = $this->create_raw_tweet( id: '123', full_text: 'Hello' );
		$raw_tweet['_account'] = array(
			'username'           => 'testuser',
			'accountDisplayName' => 'Test User',
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertSame( 'Test User', $item->author_name );
		$this->assertSame( 'https://x.com/testuser', $item->author_url );
	}

	/**
	 * Test normalize extracts multiple hashtags.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_hashtags(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123',
			full_text: 'Love #WordPress and #OpenSource!',
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertContains( 'WordPress', $item->tags );
		$this->assertContains( 'OpenSource', $item->tags );
	}

	/**
	 * Test normalize handles empty tweet gracefully.
	 *
	 * @return void
	 */
	public function test_normalize_handles_empty_tweet(): void {
		$item = $this->normalizer->normalize( array() );

		$this->assertSame( '', $item->source_id );
		$this->assertSame( 'twitter', $item->source_adapter );
		$this->assertSame( ContentType::POST, $item->content_type );
	}

	/**
	 * Test normalize removes media URLs from text.
	 *
	 * @return void
	 */
	public function test_normalize_removes_media_urls_from_text(): void {
		$raw_tweet = $this->create_raw_tweet(
			id: '123',
			full_text: 'Nice photo https://t.co/media1',
		);
		$raw_tweet['entities']['media'] = array(
			array(
				'url'             => 'https://t.co/media1',
				'media_url_https' => 'https://pbs.twimg.com/media/abc.jpg',
				'type'            => 'photo',
				'sizes'           => array( 'large' => array( 'w' => 100, 'h' => 100 ) ),
			),
		);

		$item = $this->normalizer->normalize( $raw_tweet );

		$this->assertStringNotContainsString( 't.co/media1', $item->content );
		$this->assertStringContainsString( 'Nice photo', $item->content );
	}

	/**
	 * Helper to create a raw tweet data array.
	 *
	 * @param string $id         Tweet ID.
	 * @param string $full_text  Tweet text.
	 * @param string $created_at Created date string.
	 * @return array<string, mixed>
	 */
	private function create_raw_tweet(
		string $id = '123',
		string $full_text = 'Test tweet',
		string $created_at = 'Mon Jan 15 10:30:00 +0000 2024',
	): array {
		return array(
			'id_str'         => $id,
			'full_text'      => $full_text,
			'created_at'     => $created_at,
			'favorite_count' => '0',
			'retweet_count'  => '0',
			'entities'       => array(
				'urls'     => array(),
				'media'    => array(),
				'hashtags' => array(),
			),
			'_account'       => array(
				'username'           => 'testuser',
				'accountDisplayName' => 'Test User',
			),
		);
	}
}
