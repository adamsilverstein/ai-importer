<?php
/**
 * ItemProcessor class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Processor\ItemProcessor;
use AI_Importer\Processor\MediaSideloader;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use RuntimeException;

/**
 * Tests for the ItemProcessor class.
 */
class ItemProcessorTest extends TestCase {

	/**
	 * Mock media sideloader.
	 *
	 * @var MediaSideloader|\Mockery\MockInterface
	 */
	private $mock_sideloader;

	/**
	 * Processor instance.
	 *
	 * @var ItemProcessor
	 */
	private ItemProcessor $processor;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->mock_sideloader = \Mockery::mock( MediaSideloader::class );
		$this->processor       = new ItemProcessor( $this->mock_sideloader );
	}

	/**
	 * Test process creates post and sets meta.
	 *
	 * @return void
	 */
	public function test_process_creates_post_with_meta(): void {
		$item = $this->create_item();

		Functions\expect( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on(
					function ( $data ) {
						return 'Test Title' === $data['post_title']
							&& '<p>Hello World</p>' === $data['post_content']
							&& 'draft' === $data['post_status']
							&& 'post' === $data['post_type'];
					}
				),
				true
			)
			->andReturn( 42 );

		Functions\expect( 'is_wp_error' )
			->once()
			->with( 42 )
			->andReturn( false );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_ai_importer_source', 'twitter' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_ai_importer_source_id', 'tweet-123' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_ai_importer_batch_id', 'batch-uuid' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_ai_importer_imported_at', \Mockery::type( 'string' ) )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_ai_importer_original_url', 'https://twitter.com/user/status/123' )
			->andReturn( true );

		Functions\expect( 'current_time' )
			->once()
			->with( 'mysql' )
			->andReturn( '2024-06-01 12:00:00' );

		$post_id = $this->processor->process( $item, 'batch-uuid' );

		$this->assertSame( 42, $post_id );
	}

	/**
	 * Test process sets tags.
	 *
	 * @return void
	 */
	public function test_process_sets_tags(): void {
		$item = $this->create_item( tags: array( 'wordpress', 'import' ) );

		$this->stub_post_creation( 42 );

		Functions\expect( 'wp_set_post_tags' )
			->once()
			->with( 42, array( 'wordpress', 'import' ) );

		$this->processor->process( $item, 'batch-uuid' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test process sideloads media and sets featured image.
	 *
	 * @return void
	 */
	public function test_process_sideloads_media(): void {
		$media = array(
			new MediaReference(
				id: 'img-1',
				source_url: 'https://example.com/photo.jpg',
				type: MediaReference::TYPE_IMAGE,
			),
		);

		$item = $this->create_item( media: $media );

		$this->stub_post_creation( 42 );

		$this->mock_sideloader
			->shouldReceive( 'sideload' )
			->once()
			->andReturn( 100 );

		Functions\expect( 'set_post_thumbnail' )
			->once()
			->with( 42, 100 );

		Functions\expect( 'wp_get_attachment_url' )
			->once()
			->with( 100 )
			->andReturn( 'https://mysite.com/wp-content/uploads/photo.jpg' );

		Functions\expect( 'get_post_field' )
			->once()
			->with( 'post_content', 42 )
			->andReturn( '<p>Hello World</p>' );

		$this->processor->process( $item, 'batch-uuid' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test process continues when media sideload fails.
	 *
	 * @return void
	 */
	public function test_process_continues_on_media_failure(): void {
		$media = array(
			new MediaReference(
				id: 'img-1',
				source_url: 'https://example.com/broken.jpg',
				type: MediaReference::TYPE_IMAGE,
			),
		);

		$item = $this->create_item( media: $media );

		$this->stub_post_creation( 42 );

		$this->mock_sideloader
			->shouldReceive( 'sideload' )
			->once()
			->andThrow( new RuntimeException( 'Download failed' ) );

		$post_id = $this->processor->process( $item, 'batch-uuid' );

		$this->assertSame( 42, $post_id );
	}

	/**
	 * Test process throws on wp_insert_post failure.
	 *
	 * @return void
	 */
	public function test_process_throws_on_post_creation_failure(): void {
		$item = $this->create_item();

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )
			->andReturn( 'DB error' );

		Functions\expect( 'wp_insert_post' )
			->once()
			->andReturn( $wp_error );

		Functions\expect( 'is_wp_error' )
			->once()
			->with( $wp_error )
			->andReturn( true );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to create post' );

		$this->processor->process( $item, 'batch-uuid' );
	}

	/**
	 * Create a test NormalizedItem.
	 *
	 * @param array<int, MediaReference> $media Media references.
	 * @param array<int, string>         $tags  Tags.
	 * @return NormalizedItem
	 */
	private function create_item(
		array $media = array(),
		array $tags = array(),
	): NormalizedItem {
		return new NormalizedItem(
			source_id: 'tweet-123',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello World</p>',
			publish_date: new DateTimeImmutable( '2024-01-15 10:30:00' ),
			title: 'Test Title',
			source_url: 'https://twitter.com/user/status/123',
			media: $media,
			tags: $tags,
		);
	}

	/**
	 * Stub post creation and meta calls for tests focused on other behavior.
	 *
	 * @param int $post_id The post ID to return.
	 * @return void
	 */
	private function stub_post_creation( int $post_id ): void {
		Functions\expect( 'wp_insert_post' )
			->once()
			->andReturn( $post_id );

		Functions\expect( 'is_wp_error' )
			->once()
			->with( $post_id )
			->andReturn( false );

		Functions\expect( 'update_post_meta' )
			->andReturn( true );

		Functions\expect( 'current_time' )
			->andReturn( '2024-06-01 12:00:00' );
	}
}
