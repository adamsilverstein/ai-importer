<?php
/**
 * TwitterNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Normalizer\TwitterNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the TwitterNormalizer class.
 */
class TwitterNormalizerTest extends TestCase {

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Mock WordPress functions.
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $string ) {
				return strip_tags( $string );
			}
		);
		Functions\when( 'esc_url' )->alias(
			function ( $url ) {
				return filter_var( $url, FILTER_SANITIZE_URL );
			}
		);
		Functions\when( 'esc_html' )->alias(
			function ( $text ) {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'wp_kses_post' )->alias(
			function ( $string ) {
				return $string;
			}
		);
		Functions\when( 'wpautop' )->alias(
			function ( $text ) {
				// Simple implementation: wrap in p tags.
				$text = trim( $text );
				if ( empty( $text ) ) {
					return '';
				}
				return '<p>' . $text . '</p>';
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component = -1 ) {
				$parsed = parse_url( $url );
				if ( -1 === $component ) {
					return $parsed;
				}
				$map = array(
					PHP_URL_SCHEME   => 'scheme',
					PHP_URL_HOST     => 'host',
					PHP_URL_PORT     => 'port',
					PHP_URL_USER     => 'user',
					PHP_URL_PASS     => 'pass',
					PHP_URL_PATH     => 'path',
					PHP_URL_QUERY    => 'query',
					PHP_URL_FRAGMENT => 'fragment',
				);
				$key = $map[ $component ] ?? null;
				return $key && isset( $parsed[ $key ] ) ? $parsed[ $key ] : null;
			}
		);
	}

	/**
	 * Get a basic tweet item for testing.
	 *
	 * @return array<string, mixed>
	 */
	private function get_basic_tweet(): array {
		return array(
			'id'             => '1234567890',
			'full_text'      => 'Hello, this is a test tweet! #testing @someone https://example.com',
			'created_at'     => 'Mon Jan 15 10:15:00 +0000 2024',
			'source_url'     => 'https://twitter.com/testuser/status/1234567890',
			'favorite_count' => 42,
			'retweet_count'  => 10,
			'is_retweet'     => false,
			'is_reply'       => false,
			'is_self_reply'  => false,
			'source'         => '<a href="https://twitter.com">Twitter Web App</a>',
			'lang'           => 'en',
			'hashtags'       => array( 'testing' ),
			'mentions'       => array(
				array( 'screen_name' => 'someone', 'name' => 'Someone' ),
			),
			'urls'           => array(
				array(
					'url'          => 'https://t.co/abc123',
					'expanded_url' => 'https://example.com',
					'display_url'  => 'example.com',
				),
			),
			'media_urls'     => array(),
			'author'         => array(
				'name'     => 'Test User',
				'username' => 'testuser',
				'url'      => 'https://twitter.com/testuser',
			),
		);
	}

	/**
	 * Test normalizer returns correct adapter ID.
	 *
	 * @return void
	 */
	public function test_get_adapter_id_returns_twitter(): void {
		$normalizer = new TwitterNormalizer();

		$this->assertSame( 'twitter', $normalizer->get_adapter_id() );
	}

	/**
	 * Test normalizer supports twitter adapter.
	 *
	 * @return void
	 */
	public function test_supports_twitter_adapter(): void {
		$normalizer = new TwitterNormalizer();

		$this->assertTrue( $normalizer->supports( 'twitter' ) );
		$this->assertFalse( $normalizer->supports( 'instagram' ) );
	}

	/**
	 * Test normalize returns NormalizedItem.
	 *
	 * @return void
	 */
	public function test_normalize_returns_normalized_item(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertInstanceOf( NormalizedItem::class, $result );
	}

	/**
	 * Test normalize sets correct source ID.
	 *
	 * @return void
	 */
	public function test_normalize_sets_source_id(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( '1234567890', $result->source_id );
	}

	/**
	 * Test normalize sets correct source adapter.
	 *
	 * @return void
	 */
	public function test_normalize_sets_source_adapter(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( 'twitter', $result->source_adapter );
	}

	/**
	 * Test normalize sets POST content type for regular tweet.
	 *
	 * @return void
	 */
	public function test_normalize_sets_post_type_for_regular_tweet(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( ContentType::POST, $result->content_type );
	}

	/**
	 * Test normalize sets REPOST content type for retweet.
	 *
	 * @return void
	 */
	public function test_normalize_sets_repost_type_for_retweet(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['is_retweet'] = true;

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( ContentType::REPOST, $result->content_type );
	}

	/**
	 * Test normalize sets REPLY content type for reply.
	 *
	 * @return void
	 */
	public function test_normalize_sets_reply_type_for_reply(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['is_reply'] = true;

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( ContentType::REPLY, $result->content_type );
	}

	/**
	 * Test normalize sets THREAD content type for self-reply.
	 *
	 * @return void
	 */
	public function test_normalize_sets_thread_type_for_self_reply(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['is_self_reply'] = true;

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( ContentType::THREAD, $result->content_type );
	}

	/**
	 * Test normalize generates HTML content.
	 *
	 * @return void
	 */
	public function test_normalize_generates_html_content(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertNotEmpty( $result->content );
		// Should contain the original text.
		$this->assertStringContainsString( 'Hello, this is a test tweet', $result->content );
	}

	/**
	 * Test normalize converts hashtags to links.
	 *
	 * @return void
	 */
	public function test_normalize_converts_hashtags_to_links(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertStringContainsString( 'href="https://twitter.com/hashtag/testing"', $result->content );
	}

	/**
	 * Test normalize converts mentions to links.
	 *
	 * @return void
	 */
	public function test_normalize_converts_mentions_to_links(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertStringContainsString( 'href="https://twitter.com/someone"', $result->content );
	}

	/**
	 * Test normalize extracts engagement metrics.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_engagement_metrics(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertArrayHasKey( 'likes', $result->engagement );
		$this->assertArrayHasKey( 'shares', $result->engagement );
		$this->assertSame( 42, $result->engagement['likes'] );
		$this->assertSame( 10, $result->engagement['shares'] );
	}

	/**
	 * Test normalize extracts tags from hashtags.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_tags(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertContains( 'testing', $result->tags );
	}

	/**
	 * Test normalize extracts author information.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_author_info(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( 'Test User', $result->author_name );
		$this->assertSame( 'https://twitter.com/testuser', $result->author_url );
	}

	/**
	 * Test normalize sets source URL.
	 *
	 * @return void
	 */
	public function test_normalize_sets_source_url(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( 'https://twitter.com/testuser/status/1234567890', $result->source_url );
	}

	/**
	 * Test normalize parses publish date.
	 *
	 * @return void
	 */
	public function test_normalize_parses_publish_date(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( '2024-01-15', $result->publish_date->format( 'Y-m-d' ) );
	}

	/**
	 * Test normalize handles tweet with media.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['media_urls'] = array(
			'https://pbs.twimg.com/media/test-image.jpg',
		);

		$result = $normalizer->normalize( $tweet );

		$this->assertNotEmpty( $result->media );
		$this->assertCount( 1, $result->media );
		$this->assertInstanceOf( MediaReference::class, $result->media[0] );
	}

	/**
	 * Test normalize extracts media from extended_entities.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media_from_extended_entities(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['extended_entities'] = array(
			'media' => array(
				array(
					'id_str'          => 'media-123',
					'media_url_https' => 'https://pbs.twimg.com/media/test.jpg',
					'type'            => 'photo',
					'sizes'           => array(
						'large' => array( 'w' => 1200, 'h' => 800 ),
					),
					'ext_alt_text'    => 'Test image description',
				),
			),
		);

		$result = $normalizer->normalize( $tweet );

		$this->assertNotEmpty( $result->media );
		$media = $result->media[0];
		$this->assertSame( 'https://pbs.twimg.com/media/test.jpg', $media->source_url );
		$this->assertSame( MediaReference::TYPE_IMAGE, $media->type );
		$this->assertSame( 'Test image description', $media->alt_text );
		$this->assertSame( 1200, $media->width );
		$this->assertSame( 800, $media->height );
	}

	/**
	 * Test normalize handles video media.
	 *
	 * @return void
	 */
	public function test_normalize_handles_video_media(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['extended_entities'] = array(
			'media' => array(
				array(
					'id_str'          => 'video-123',
					'media_url_https' => 'https://pbs.twimg.com/ext_tw_video/thumb.jpg',
					'type'            => 'video',
					'video_info'      => array(
						'variants' => array(
							array(
								'content_type' => 'video/mp4',
								'bitrate'      => 832000,
								'url'          => 'https://video.twimg.com/ext_tw_video/low.mp4',
							),
							array(
								'content_type' => 'video/mp4',
								'bitrate'      => 2176000,
								'url'          => 'https://video.twimg.com/ext_tw_video/high.mp4',
							),
						),
					),
				),
			),
		);

		$result = $normalizer->normalize( $tweet );

		// Should have both thumbnail and video.
		$this->assertGreaterThanOrEqual( 2, count( $result->media ) );

		// Find the high bitrate video reference (not the thumbnail).
		$high_bitrate_video_found = false;
		foreach ( $result->media as $media ) {
			if ( str_contains( $media->source_url, 'high.mp4' ) ) {
				$high_bitrate_video_found = true;
				$this->assertSame( MediaReference::TYPE_VIDEO, $media->type );
			}
		}
		$this->assertTrue( $high_bitrate_video_found, 'High bitrate video reference not found' );
	}

	/**
	 * Test normalize sets metadata.
	 *
	 * @return void
	 */
	public function test_normalize_sets_metadata(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertArrayHasKey( 'source', $result->metadata );
		$this->assertArrayHasKey( 'lang', $result->metadata );
		$this->assertSame( 'Twitter Web App', $result->metadata['source'] );
		$this->assertSame( 'en', $result->metadata['lang'] );
	}

	/**
	 * Test normalize handles reply with parent ID.
	 *
	 * @return void
	 */
	public function test_normalize_sets_parent_id_for_reply(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['is_reply']           = true;
		$tweet['in_reply_to_status'] = 'parent-tweet-123';

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( 'parent-tweet-123', $result->parent_id );
	}

	/**
	 * Test normalize handles ISO date format.
	 *
	 * @return void
	 */
	public function test_normalize_handles_iso_date_format(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		$tweet['created_at'] = '2024-01-15T10:15:00+00:00';

		$result = $normalizer->normalize( $tweet );

		$this->assertSame( '2024-01-15', $result->publish_date->format( 'Y-m-d' ) );
	}

	/**
	 * Test normalize title is null for tweets.
	 *
	 * @return void
	 */
	public function test_normalize_title_is_null(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();

		$result = $normalizer->normalize( $tweet );

		$this->assertNull( $result->title );
	}

	/**
	 * Test normalize uses text field as fallback.
	 *
	 * @return void
	 */
	public function test_normalize_uses_text_field_as_fallback(): void {
		$normalizer = new TwitterNormalizer();
		$tweet      = $this->get_basic_tweet();
		unset( $tweet['full_text'] );
		$tweet['text'] = 'Fallback text content';

		$result = $normalizer->normalize( $tweet );

		$this->assertStringContainsString( 'Fallback text content', $result->content );
	}
}
