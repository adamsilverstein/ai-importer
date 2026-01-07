<?php
/**
 * ContentManifest class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters\Manifest
 */

namespace AI_Importer\Tests\Unit\Adapters\Manifest;

use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;

/**
 * Tests for the ContentManifest class.
 */
class ContentManifestTest extends TestCase {

	/**
	 * Test constructor sets source ID and generated timestamp.
	 *
	 * @return void
	 */
	public function test_constructor_sets_source_id(): void {
		$manifest = new ContentManifest( 'twitter' );

		$this->assertSame( 'twitter', $manifest->get_source_id() );
		$this->assertInstanceOf( DateTimeImmutable::class, $manifest->get_generated_at() );
	}

	/**
	 * Test add_item and get_item.
	 *
	 * @return void
	 */
	public function test_add_item_and_get_item(): void {
		$manifest = new ContentManifest( 'twitter' );
		$item     = $this->create_test_item( 'item-1', ContentType::POST );

		$manifest->add_item( $item );

		$this->assertSame( $item, $manifest->get_item( 'item-1' ) );
	}

	/**
	 * Test get_item returns null for nonexistent ID.
	 *
	 * @return void
	 */
	public function test_get_item_returns_null_for_nonexistent(): void {
		$manifest = new ContentManifest( 'twitter' );

		$this->assertNull( $manifest->get_item( 'nonexistent' ) );
	}

	/**
	 * Test has_item returns correct value.
	 *
	 * @return void
	 */
	public function test_has_item_returns_correct_value(): void {
		$manifest = new ContentManifest( 'twitter' );
		$item     = $this->create_test_item( 'item-1', ContentType::POST );

		$manifest->add_item( $item );

		$this->assertTrue( $manifest->has_item( 'item-1' ) );
		$this->assertFalse( $manifest->has_item( 'nonexistent' ) );
	}

	/**
	 * Test remove_item removes item.
	 *
	 * @return void
	 */
	public function test_remove_item_removes_item(): void {
		$manifest = new ContentManifest( 'twitter' );
		$item     = $this->create_test_item( 'item-1', ContentType::POST );

		$manifest->add_item( $item );
		$this->assertTrue( $manifest->has_item( 'item-1' ) );

		$result = $manifest->remove_item( 'item-1' );

		$this->assertTrue( $result );
		$this->assertFalse( $manifest->has_item( 'item-1' ) );
	}

	/**
	 * Test remove_item returns false for nonexistent.
	 *
	 * @return void
	 */
	public function test_remove_item_returns_false_for_nonexistent(): void {
		$manifest = new ContentManifest( 'twitter' );

		$this->assertFalse( $manifest->remove_item( 'nonexistent' ) );
	}

	/**
	 * Test get_items returns all items.
	 *
	 * @return void
	 */
	public function test_get_items_returns_all_items(): void {
		$manifest = new ContentManifest( 'twitter' );
		$item1    = $this->create_test_item( 'item-1', ContentType::POST );
		$item2    = $this->create_test_item( 'item-2', ContentType::THREAD );

		$manifest->add_item( $item1 );
		$manifest->add_item( $item2 );

		$items = $manifest->get_items();

		$this->assertCount( 2, $items );
		$this->assertArrayHasKey( 'item-1', $items );
		$this->assertArrayHasKey( 'item-2', $items );
	}

	/**
	 * Test get_items_by_type filters correctly.
	 *
	 * @return void
	 */
	public function test_get_items_by_type_filters_correctly(): void {
		$manifest = new ContentManifest( 'twitter' );
		$manifest->add_item( $this->create_test_item( 'post-1', ContentType::POST ) );
		$manifest->add_item( $this->create_test_item( 'post-2', ContentType::POST ) );
		$manifest->add_item( $this->create_test_item( 'thread-1', ContentType::THREAD ) );

		$posts = $manifest->get_items_by_type( ContentType::POST );

		$this->assertCount( 2, $posts );
		$this->assertArrayHasKey( 'post-1', $posts );
		$this->assertArrayHasKey( 'post-2', $posts );
	}

	/**
	 * Test get_items_with_media filters correctly.
	 *
	 * @return void
	 */
	public function test_get_items_with_media_filters_correctly(): void {
		$manifest = new ContentManifest( 'twitter' );

		$with_media = $this->create_test_item( 'with-media', ContentType::POST );
		$with_media->media_urls = array( 'https://example.com/image.jpg' );

		$without_media = $this->create_test_item( 'without-media', ContentType::POST );

		$manifest->add_item( $with_media );
		$manifest->add_item( $without_media );

		$items = $manifest->get_items_with_media();

		$this->assertCount( 1, $items );
		$this->assertArrayHasKey( 'with-media', $items );
	}

	/**
	 * Test count returns correct value.
	 *
	 * @return void
	 */
	public function test_count_returns_correct_value(): void {
		$manifest = new ContentManifest( 'twitter' );

		$this->assertCount( 0, $manifest );

		$manifest->add_item( $this->create_test_item( 'item-1', ContentType::POST ) );
		$manifest->add_item( $this->create_test_item( 'item-2', ContentType::POST ) );

		$this->assertCount( 2, $manifest );
	}

	/**
	 * Test is_empty returns correct value.
	 *
	 * @return void
	 */
	public function test_is_empty_returns_correct_value(): void {
		$manifest = new ContentManifest( 'twitter' );

		$this->assertTrue( $manifest->is_empty() );

		$manifest->add_item( $this->create_test_item( 'item-1', ContentType::POST ) );

		$this->assertFalse( $manifest->is_empty() );
	}

	/**
	 * Test get_date_range returns correct range.
	 *
	 * @return void
	 */
	public function test_get_date_range_returns_correct_range(): void {
		$manifest = new ContentManifest( 'twitter' );

		$item1 = new ManifestItem(
			id: 'item-1',
			type: ContentType::POST,
			title: 'Post 1',
			created_at: new DateTimeImmutable( '2024-01-15' )
		);

		$item2 = new ManifestItem(
			id: 'item-2',
			type: ContentType::POST,
			title: 'Post 2',
			created_at: new DateTimeImmutable( '2024-03-20' )
		);

		$item3 = new ManifestItem(
			id: 'item-3',
			type: ContentType::POST,
			title: 'Post 3',
			created_at: new DateTimeImmutable( '2024-02-10' )
		);

		$manifest->add_item( $item1 );
		$manifest->add_item( $item2 );
		$manifest->add_item( $item3 );

		$range = $manifest->get_date_range();

		$this->assertSame( '2024-01-15', $range['earliest']->format( 'Y-m-d' ) );
		$this->assertSame( '2024-03-20', $range['latest']->format( 'Y-m-d' ) );
	}

	/**
	 * Test get_date_range returns nulls when empty.
	 *
	 * @return void
	 */
	public function test_get_date_range_returns_nulls_when_empty(): void {
		$manifest = new ContentManifest( 'twitter' );

		$range = $manifest->get_date_range();

		$this->assertNull( $range['earliest'] );
		$this->assertNull( $range['latest'] );
	}

	/**
	 * Test get_stats returns correct statistics.
	 *
	 * @return void
	 */
	public function test_get_stats_returns_correct_statistics(): void {
		$manifest = new ContentManifest( 'twitter' );

		$post1 = $this->create_test_item( 'post-1', ContentType::POST );
		$post2 = $this->create_test_item( 'post-2', ContentType::POST );
		$post2->media_urls = array( 'https://example.com/image.jpg' );

		$thread = $this->create_test_item( 'thread-1', ContentType::THREAD );

		$manifest->add_item( $post1 );
		$manifest->add_item( $post2 );
		$manifest->add_item( $thread );

		$stats = $manifest->get_stats();

		$this->assertSame( 3, $stats['total'] );
		$this->assertSame( 1, $stats['with_media'] );
		$this->assertSame( 2, $stats['by_type']['post'] );
		$this->assertSame( 1, $stats['by_type']['thread'] );
	}

	/**
	 * Test manifest is iterable.
	 *
	 * @return void
	 */
	public function test_manifest_is_iterable(): void {
		$manifest = new ContentManifest( 'twitter' );
		$manifest->add_item( $this->create_test_item( 'item-1', ContentType::POST ) );
		$manifest->add_item( $this->create_test_item( 'item-2', ContentType::POST ) );

		$count = 0;
		foreach ( $manifest as $id => $item ) {
			$this->assertIsString( $id );
			$this->assertInstanceOf( ManifestItem::class, $item );
			++$count;
		}

		$this->assertSame( 2, $count );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$manifest = new ContentManifest( 'twitter' );
		$manifest->add_item( $this->create_test_item( 'item-1', ContentType::POST ) );

		$array = $manifest->to_array();

		$this->assertSame( 'twitter', $array['source_id'] );
		$this->assertArrayHasKey( 'generated_at', $array );
		$this->assertArrayHasKey( 'stats', $array );
		$this->assertArrayHasKey( 'items', $array );
		$this->assertCount( 1, $array['items'] );
	}

	/**
	 * Test from_array creates manifest correctly.
	 *
	 * @return void
	 */
	public function test_from_array_creates_manifest(): void {
		$data = array(
			'source_id'    => 'twitter',
			'generated_at' => '2024-01-15T10:30:00+00:00',
			'items'        => array(
				array(
					'id'         => 'item-1',
					'type'       => 'post',
					'title'      => 'Test Post',
					'created_at' => '2024-01-15T10:30:00+00:00',
				),
			),
		);

		$manifest = ContentManifest::from_array( $data );

		$this->assertSame( 'twitter', $manifest->get_source_id() );
		$this->assertCount( 1, $manifest );
		$this->assertTrue( $manifest->has_item( 'item-1' ) );
	}

	/**
	 * Test from_array throws exception for missing source_id.
	 *
	 * @return void
	 */
	public function test_from_array_throws_exception_for_missing_source_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required field: source_id' );

		ContentManifest::from_array( array() );
	}

	/**
	 * Create a test ManifestItem.
	 *
	 * @param string      $id   Item ID.
	 * @param ContentType $type Content type.
	 * @return ManifestItem Test item.
	 */
	private function create_test_item( string $id, ContentType $type ): ManifestItem {
		return new ManifestItem(
			id: $id,
			type: $type,
			title: "Test {$id}",
			created_at: new DateTimeImmutable()
		);
	}
}
