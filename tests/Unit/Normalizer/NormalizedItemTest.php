<?php
/**
 * NormalizedItem class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use Brain\Monkey\Functions;

/**
 * Tests for the NormalizedItem class.
 */
class NormalizedItemTest extends TestCase {

	/**
	 * Test constructor sets all properties.
	 *
	 * @return void
	 */
	public function test_constructor_sets_properties(): void {
		$publish_date = new DateTimeImmutable( '2024-01-15 10:30:00' );
		$media        = array(
			new MediaReference( id: 'media-1', source_url: 'https://example.com/image.jpg' ),
		);

		$item = new NormalizedItem(
			source_id: 'tweet-123',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello World!</p>',
			publish_date: $publish_date,
			title: 'Test Title',
			source_url: 'https://twitter.com/user/status/123',
			media: $media,
			metadata: array( 'platform' => 'twitter' ),
			engagement: array( 'likes' => 100 ),
			author_name: 'Test Author',
			author_url: 'https://twitter.com/testauthor',
			parent_id: 'parent-456',
			tags: array( 'test', 'hello' )
		);

		$this->assertSame( 'tweet-123', $item->source_id );
		$this->assertSame( 'twitter', $item->source_adapter );
		$this->assertSame( ContentType::POST, $item->content_type );
		$this->assertSame( '<p>Hello World!</p>', $item->content );
		$this->assertSame( $publish_date, $item->publish_date );
		$this->assertSame( 'Test Title', $item->title );
		$this->assertSame( 'https://twitter.com/user/status/123', $item->source_url );
		$this->assertCount( 1, $item->media );
		$this->assertSame( array( 'platform' => 'twitter' ), $item->metadata );
		$this->assertSame( array( 'likes' => 100 ), $item->engagement );
		$this->assertSame( 'Test Author', $item->author_name );
		$this->assertSame( 'https://twitter.com/testauthor', $item->author_url );
		$this->assertSame( 'parent-456', $item->parent_id );
		$this->assertSame( array( 'test', 'hello' ), $item->tags );
	}

	/**
	 * Test has_media returns true when media exists.
	 *
	 * @return void
	 */
	public function test_has_media_returns_true_when_media_exists(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			media: array(
				new MediaReference( id: 'media-1', source_url: 'https://example.com/image.jpg' ),
			)
		);

		$this->assertTrue( $item->has_media() );
	}

	/**
	 * Test has_media returns false when no media.
	 *
	 * @return void
	 */
	public function test_has_media_returns_false_when_no_media(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable()
		);

		$this->assertFalse( $item->has_media() );
	}

	/**
	 * Test get_media_count returns correct count.
	 *
	 * @return void
	 */
	public function test_get_media_count(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			media: array(
				new MediaReference( id: 'm1', source_url: 'https://example.com/1.jpg' ),
				new MediaReference( id: 'm2', source_url: 'https://example.com/2.jpg' ),
				new MediaReference( id: 'm3', source_url: 'https://example.com/3.jpg' ),
			)
		);

		$this->assertSame( 3, $item->get_media_count() );
	}

	/**
	 * Test get_media_by_type filters correctly.
	 *
	 * @return void
	 */
	public function test_get_media_by_type(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			media: array(
				new MediaReference( id: 'm1', source_url: 'https://example.com/1.jpg', type: MediaReference::TYPE_IMAGE ),
				new MediaReference( id: 'm2', source_url: 'https://example.com/video.mp4', type: MediaReference::TYPE_VIDEO ),
				new MediaReference( id: 'm3', source_url: 'https://example.com/2.jpg', type: MediaReference::TYPE_IMAGE ),
			)
		);

		$images = $item->get_images();
		$videos = $item->get_videos();

		$this->assertCount( 2, $images );
		$this->assertCount( 1, $videos );
	}

	/**
	 * Test add_media adds a media reference.
	 *
	 * @return void
	 */
	public function test_add_media(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable()
		);

		$item->add_media(
			new MediaReference( id: 'new', source_url: 'https://example.com/new.jpg' )
		);

		$this->assertTrue( $item->has_media() );
		$this->assertSame( 1, $item->get_media_count() );
	}

	/**
	 * Test get_word_count.
	 *
	 * @return void
	 */
	public function test_get_word_count(): void {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( 'Hello this is a test with seven words' );

		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello this is a test with seven words</p>',
			publish_date: new DateTimeImmutable()
		);

		$this->assertSame( 8, $item->get_word_count() );
	}

	/**
	 * Test get_character_count.
	 *
	 * @return void
	 */
	public function test_get_character_count(): void {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( 'Hello World' );

		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello World</p>',
			publish_date: new DateTimeImmutable()
		);

		$this->assertSame( 11, $item->get_character_count() );
	}

	/**
	 * Test get_excerpt truncates content.
	 *
	 * @return void
	 */
	public function test_get_excerpt_truncates(): void {
		$long_text = 'This is a very long text that should be truncated by the get_excerpt method because it exceeds the maximum length.';

		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( $long_text );

		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: "<p>{$long_text}</p>",
			publish_date: new DateTimeImmutable()
		);

		$excerpt = $item->get_excerpt( 50 );

		$this->assertStringEndsWith( '...', $excerpt );
		$this->assertLessThanOrEqual( 53, mb_strlen( $excerpt ) );
	}

	/**
	 * Test get_excerpt returns full text when short.
	 *
	 * @return void
	 */
	public function test_get_excerpt_returns_full_when_short(): void {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( 'Short text' );

		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Short text</p>',
			publish_date: new DateTimeImmutable()
		);

		$excerpt = $item->get_excerpt( 150 );

		$this->assertSame( 'Short text', $excerpt );
	}

	/**
	 * Test is_reply returns true when parent_id set.
	 *
	 * @return void
	 */
	public function test_is_reply_returns_true_when_parent_set(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::REPLY,
			content: 'Reply content',
			publish_date: new DateTimeImmutable(),
			parent_id: 'parent-123'
		);

		$this->assertTrue( $item->is_reply() );
	}

	/**
	 * Test is_reply returns false when no parent.
	 *
	 * @return void
	 */
	public function test_is_reply_returns_false_when_no_parent(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Post content',
			publish_date: new DateTimeImmutable()
		);

		$this->assertFalse( $item->is_reply() );
	}

	/**
	 * Test get_engagement returns metric value.
	 *
	 * @return void
	 */
	public function test_get_engagement_returns_value(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			engagement: array( 'likes' => 100, 'shares' => 25 )
		);

		$this->assertSame( 100, $item->get_engagement( 'likes' ) );
		$this->assertSame( 25, $item->get_engagement( 'shares' ) );
	}

	/**
	 * Test get_engagement returns default for missing key.
	 *
	 * @return void
	 */
	public function test_get_engagement_returns_default(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable()
		);

		$this->assertSame( 0, $item->get_engagement( 'likes' ) );
		$this->assertSame( 5, $item->get_engagement( 'likes', 5 ) );
	}

	/**
	 * Test get_total_engagement sums all metrics.
	 *
	 * @return void
	 */
	public function test_get_total_engagement(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			engagement: array( 'likes' => 100, 'shares' => 25, 'comments' => 10 )
		);

		$this->assertSame( 135, $item->get_total_engagement() );
	}

	/**
	 * Test get_meta returns metadata value.
	 *
	 * @return void
	 */
	public function test_get_meta_returns_value(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			metadata: array( 'language' => 'en', 'geo' => 'US' )
		);

		$this->assertSame( 'en', $item->get_meta( 'language' ) );
		$this->assertSame( 'US', $item->get_meta( 'geo' ) );
	}

	/**
	 * Test set_meta sets metadata value.
	 *
	 * @return void
	 */
	public function test_set_meta_sets_value(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable()
		);

		$item->set_meta( 'processed', true );

		$this->assertTrue( $item->get_meta( 'processed' ) );
	}

	/**
	 * Test generate_title returns title if set.
	 *
	 * @return void
	 */
	public function test_generate_title_returns_existing_title(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello World',
			publish_date: new DateTimeImmutable(),
			title: 'My Title'
		);

		$this->assertSame( 'My Title', $item->generate_title() );
	}

	/**
	 * Test generate_title creates from excerpt.
	 *
	 * @return void
	 */
	public function test_generate_title_creates_from_excerpt(): void {
		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( 'Hello World this is a test' );

		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello World this is a test</p>',
			publish_date: new DateTimeImmutable()
		);

		$title = $item->generate_title( 20 );

		$this->assertNotEmpty( $title );
		$this->assertLessThanOrEqual( 23, mb_strlen( $title ) );
	}

	/**
	 * Test has_tags returns true when tags exist.
	 *
	 * @return void
	 */
	public function test_has_tags_returns_true(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			tags: array( 'test', 'hello' )
		);

		$this->assertTrue( $item->has_tags() );
	}

	/**
	 * Test has_tags returns false when no tags.
	 *
	 * @return void
	 */
	public function test_has_tags_returns_false(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable()
		);

		$this->assertFalse( $item->has_tags() );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$publish_date = new DateTimeImmutable( '2024-01-15T10:30:00+00:00' );

		$item = new NormalizedItem(
			source_id: 'tweet-123',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello World</p>',
			publish_date: $publish_date,
			title: 'Test Title',
			source_url: 'https://twitter.com/user/status/123',
			engagement: array( 'likes' => 100 ),
			tags: array( 'test' )
		);

		$array = $item->to_array();

		$this->assertSame( 'tweet-123', $array['source_id'] );
		$this->assertSame( 'twitter', $array['source_adapter'] );
		$this->assertSame( 'post', $array['content_type'] );
		$this->assertSame( '<p>Hello World</p>', $array['content'] );
		$this->assertSame( 'Test Title', $array['title'] );
		$this->assertSame( '2024-01-15T10:30:00+00:00', $array['publish_date'] );
		$this->assertSame( array( 'likes' => 100 ), $array['engagement'] );
		$this->assertSame( array( 'test' ), $array['tags'] );
	}

	/**
	 * Test to_array includes serialized media.
	 *
	 * @return void
	 */
	public function test_to_array_serializes_media(): void {
		$item = new NormalizedItem(
			source_id: 'test',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Hello',
			publish_date: new DateTimeImmutable(),
			media: array(
				new MediaReference( id: 'media-1', source_url: 'https://example.com/image.jpg' ),
			)
		);

		$array = $item->to_array();

		$this->assertCount( 1, $array['media'] );
		$this->assertSame( 'media-1', $array['media'][0]['id'] );
	}

	/**
	 * Test from_array creates NormalizedItem.
	 *
	 * @return void
	 */
	public function test_from_array_creates_item(): void {
		$data = array(
			'source_id'      => 'tweet-123',
			'source_adapter' => 'twitter',
			'content_type'   => 'post',
			'content'        => '<p>Hello World</p>',
			'publish_date'   => '2024-01-15T10:30:00+00:00',
			'title'          => 'Test Title',
			'source_url'     => 'https://twitter.com/user/status/123',
			'engagement'     => array( 'likes' => 100 ),
			'tags'           => array( 'test' ),
		);

		$item = NormalizedItem::from_array( $data );

		$this->assertSame( 'tweet-123', $item->source_id );
		$this->assertSame( 'twitter', $item->source_adapter );
		$this->assertSame( ContentType::POST, $item->content_type );
		$this->assertSame( '<p>Hello World</p>', $item->content );
		$this->assertSame( 'Test Title', $item->title );
		$this->assertSame( array( 'likes' => 100 ), $item->engagement );
	}

	/**
	 * Test from_array accepts ContentType instance.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_content_type_instance(): void {
		$data = array(
			'source_id'      => 'test',
			'source_adapter' => 'twitter',
			'content_type'   => ContentType::THREAD,
			'content'        => 'Thread content',
			'publish_date'   => new DateTimeImmutable(),
		);

		$item = NormalizedItem::from_array( $data );

		$this->assertSame( ContentType::THREAD, $item->content_type );
	}

	/**
	 * Test from_array deserializes media.
	 *
	 * @return void
	 */
	public function test_from_array_deserializes_media(): void {
		$data = array(
			'source_id'      => 'test',
			'source_adapter' => 'twitter',
			'content_type'   => 'post',
			'content'        => 'Hello',
			'publish_date'   => '2024-01-15T10:30:00+00:00',
			'media'          => array(
				array( 'id' => 'media-1', 'source_url' => 'https://example.com/image.jpg' ),
			),
		);

		$item = NormalizedItem::from_array( $data );

		$this->assertTrue( $item->has_media() );
		$this->assertInstanceOf( MediaReference::class, $item->media[0] );
	}

	/**
	 * Test from_array throws exception for missing required fields.
	 *
	 * @return void
	 */
	public function test_from_array_throws_exception_for_missing_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required field' );

		NormalizedItem::from_array(
			array(
				'source_id' => 'test',
				'content'   => 'Hello',
			)
		);
	}
}
