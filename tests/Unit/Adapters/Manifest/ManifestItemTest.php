<?php
/**
 * ManifestItem class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters\Manifest
 */

namespace AI_Importer\Tests\Unit\Adapters\Manifest;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;

/**
 * Tests for the ManifestItem class.
 */
class ManifestItemTest extends TestCase {

	/**
	 * Test ManifestItem constructor.
	 *
	 * @return void
	 */
	public function test_constructor_sets_properties(): void {
		$created_at = new DateTimeImmutable( '2024-01-15 10:30:00' );
		$updated_at = new DateTimeImmutable( '2024-01-16 14:00:00' );

		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test Post',
			created_at: $created_at,
			excerpt: 'This is a test excerpt',
			updated_at: $updated_at,
			media_urls: array( 'https://example.com/image.jpg' ),
			metadata: array( 'likes' => 100 ),
			parent_id: 'parent-456',
			original_url: 'https://twitter.com/user/status/123',
			author: array( 'name' => 'Test Author' )
		);

		$this->assertSame( 'test-123', $item->id );
		$this->assertSame( ContentType::POST, $item->type );
		$this->assertSame( 'Test Post', $item->title );
		$this->assertSame( $created_at, $item->created_at );
		$this->assertSame( 'This is a test excerpt', $item->excerpt );
		$this->assertSame( $updated_at, $item->updated_at );
		$this->assertSame( array( 'https://example.com/image.jpg' ), $item->media_urls );
		$this->assertSame( array( 'likes' => 100 ), $item->metadata );
		$this->assertSame( 'parent-456', $item->parent_id );
		$this->assertSame( 'https://twitter.com/user/status/123', $item->original_url );
		$this->assertSame( array( 'name' => 'Test Author' ), $item->author );
	}

	/**
	 * Test has_media returns true when media exists.
	 *
	 * @return void
	 */
	public function test_has_media_returns_true_when_media_exists(): void {
		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test',
			created_at: new DateTimeImmutable(),
			media_urls: array( 'https://example.com/image.jpg' )
		);

		$this->assertTrue( $item->has_media() );
	}

	/**
	 * Test has_media returns false when no media.
	 *
	 * @return void
	 */
	public function test_has_media_returns_false_when_no_media(): void {
		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test',
			created_at: new DateTimeImmutable()
		);

		$this->assertFalse( $item->has_media() );
	}

	/**
	 * Test has_parent returns true when parent_id is set.
	 *
	 * @return void
	 */
	public function test_has_parent_returns_true_when_parent_exists(): void {
		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::REPLY,
			title: 'Test Reply',
			created_at: new DateTimeImmutable(),
			parent_id: 'parent-456'
		);

		$this->assertTrue( $item->has_parent() );
	}

	/**
	 * Test has_parent returns false when no parent.
	 *
	 * @return void
	 */
	public function test_has_parent_returns_false_when_no_parent(): void {
		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test',
			created_at: new DateTimeImmutable()
		);

		$this->assertFalse( $item->has_parent() );
	}

	/**
	 * Test get_meta returns metadata value.
	 *
	 * @return void
	 */
	public function test_get_meta_returns_metadata_value(): void {
		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test',
			created_at: new DateTimeImmutable(),
			metadata: array( 'likes' => 100, 'shares' => 25 )
		);

		$this->assertSame( 100, $item->get_meta( 'likes' ) );
		$this->assertSame( 25, $item->get_meta( 'shares' ) );
	}

	/**
	 * Test get_meta returns default for missing key.
	 *
	 * @return void
	 */
	public function test_get_meta_returns_default_for_missing_key(): void {
		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test',
			created_at: new DateTimeImmutable()
		);

		$this->assertNull( $item->get_meta( 'nonexistent' ) );
		$this->assertSame( 'default', $item->get_meta( 'nonexistent', 'default' ) );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$created_at = new DateTimeImmutable( '2024-01-15T10:30:00+00:00' );

		$item = new ManifestItem(
			id: 'test-123',
			type: ContentType::POST,
			title: 'Test Post',
			created_at: $created_at,
			excerpt: 'Excerpt',
			media_urls: array( 'https://example.com/image.jpg' ),
			metadata: array( 'likes' => 100 )
		);

		$array = $item->to_array();

		$this->assertSame( 'test-123', $array['id'] );
		$this->assertSame( 'post', $array['type'] );
		$this->assertSame( 'Test Post', $array['title'] );
		$this->assertSame( 'Excerpt', $array['excerpt'] );
		$this->assertSame( '2024-01-15T10:30:00+00:00', $array['created_at'] );
		$this->assertNull( $array['updated_at'] );
		$this->assertSame( array( 'https://example.com/image.jpg' ), $array['media_urls'] );
		$this->assertSame( array( 'likes' => 100 ), $array['metadata'] );
	}

	/**
	 * Test from_array creates item correctly.
	 *
	 * @return void
	 */
	public function test_from_array_creates_item(): void {
		$data = array(
			'id'         => 'test-123',
			'type'       => 'post',
			'title'      => 'Test Post',
			'created_at' => '2024-01-15T10:30:00+00:00',
			'excerpt'    => 'Test excerpt',
			'media_urls' => array( 'https://example.com/image.jpg' ),
			'metadata'   => array( 'likes' => 100 ),
		);

		$item = ManifestItem::from_array( $data );

		$this->assertSame( 'test-123', $item->id );
		$this->assertSame( ContentType::POST, $item->type );
		$this->assertSame( 'Test Post', $item->title );
		$this->assertSame( 'Test excerpt', $item->excerpt );
		$this->assertSame( array( 'https://example.com/image.jpg' ), $item->media_urls );
	}

	/**
	 * Test from_array throws exception for missing required fields.
	 *
	 * @return void
	 */
	public function test_from_array_throws_exception_for_missing_fields(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required fields' );

		ManifestItem::from_array( array( 'id' => 'test' ) );
	}

	/**
	 * Test from_array accepts ContentType instance.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_content_type_instance(): void {
		$data = array(
			'id'         => 'test-123',
			'type'       => ContentType::ARTICLE,
			'title'      => 'Test Article',
			'created_at' => new DateTimeImmutable( '2024-01-15' ),
		);

		$item = ManifestItem::from_array( $data );

		$this->assertSame( ContentType::ARTICLE, $item->type );
	}
}
