<?php
/**
 * ContentCreator tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Processor\ContentCreator;
use AI_Importer\Processor\ItemEnhancer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;

/**
 * Tests for the ContentCreator class.
 */
class ContentCreatorTest extends TestCase {

	/**
	 * Creator instance.
	 *
	 * @var ContentCreator
	 */
	private ContentCreator $creator;

	/**
	 * Stored post meta.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $post_meta;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->creator   = new ContentCreator();
		$this->post_meta = array();

		$meta = &$this->post_meta;

		Functions\when( 'wp_insert_post' )->justReturn( 42 );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_set_post_tags' )->justReturn( true );
		Functions\when( 'set_post_thumbnail' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
	}

	/**
	 * Test create returns a post ID.
	 *
	 * @return void
	 */
	public function test_create_returns_post_id(): void {
		$item   = $this->make_item();
		$result = $this->creator->create( $item, 'batch-abc' );

		$this->assertSame( 42, $result );
	}

	/**
	 * Test all tracking meta is set.
	 *
	 * @return void
	 */
	public function test_tracking_meta_set(): void {
		$item = $this->make_item();
		$this->creator->create( $item, 'batch-abc' );

		$meta = $this->post_meta[42] ?? array();

		$this->assertSame( 'twitter', $meta[ ContentCreator::META_SOURCE ] );
		$this->assertSame( 'tweet-123', $meta[ ContentCreator::META_SOURCE_ID ] );
		$this->assertSame( 'batch-abc', $meta[ ContentCreator::META_BATCH_ID ] );
		$this->assertArrayHasKey( ContentCreator::META_IMPORTED_AT, $meta );
	}

	/**
	 * Test original URL meta is set when available.
	 *
	 * @return void
	 */
	public function test_original_url_meta_set(): void {
		$item = $this->make_item( array( 'source_url' => 'https://x.com/i/status/123' ) );
		$this->creator->create( $item, 'batch-abc' );

		$this->assertSame(
			'https://x.com/i/status/123',
			$this->post_meta[42][ ContentCreator::META_ORIGINAL_URL ]
		);
	}

	/**
	 * Test original URL meta is not set when null.
	 *
	 * @return void
	 */
	public function test_original_url_meta_not_set_when_null(): void {
		$item = $this->make_item( array( 'source_url' => null ) );
		$this->creator->create( $item, 'batch-abc' );

		$this->assertArrayNotHasKey(
			ContentCreator::META_ORIGINAL_URL,
			$this->post_meta[42] ?? array()
		);
	}

	/**
	 * Test throws on wp_insert_post failure.
	 *
	 * @return void
	 */
	public function test_throws_on_insert_failure(): void {
		Functions\when( 'wp_insert_post' )->justReturn(
			new \WP_Error( 'insert_error', 'Insert failed' )
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->creator->create( $this->make_item(), 'batch-abc' );
	}

	/**
	 * Test post_created action fires.
	 *
	 * @return void
	 */
	public function test_post_created_action_fires(): void {
		Actions\expectDone( 'ai_importer_post_created' )
			->once()
			->with( 42, \Mockery::type( NormalizedItem::class ), 'batch-abc' );

		$this->creator->create( $this->make_item(), 'batch-abc' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test the SEO description meta is persisted when present on the item.
	 *
	 * @return void
	 */
	public function test_seo_description_meta_persisted(): void {
		$item = $this->make_item(
			array(
				'metadata' => array(
					ItemEnhancer::META_KEY_SEO_DESCRIPTION => 'Concise SEO-friendly summary that describes the post for SERPs.',
				),
			)
		);

		$this->creator->create( $item, 'batch-abc' );

		$this->assertSame(
			'Concise SEO-friendly summary that describes the post for SERPs.',
			$this->post_meta[42][ ContentCreator::META_SEO_DESCRIPTION ]
		);
	}

	/**
	 * Test the SEO description meta is not set when absent.
	 *
	 * @return void
	 */
	public function test_seo_description_meta_not_set_when_absent(): void {
		$item = $this->make_item();

		$this->creator->create( $item, 'batch-abc' );

		$this->assertArrayNotHasKey(
			ContentCreator::META_SEO_DESCRIPTION,
			$this->post_meta[42] ?? array()
		);
	}

	/**
	 * Test the SEO description meta is not set when empty string.
	 *
	 * @return void
	 */
	public function test_seo_description_meta_not_set_when_empty(): void {
		$item = $this->make_item(
			array(
				'metadata' => array(
					ItemEnhancer::META_KEY_SEO_DESCRIPTION => '',
				),
			)
		);

		$this->creator->create( $item, 'batch-abc' );

		$this->assertArrayNotHasKey(
			ContentCreator::META_SEO_DESCRIPTION,
			$this->post_meta[42] ?? array()
		);
	}

	/**
	 * Create a test NormalizedItem.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return NormalizedItem
	 */
	private function make_item( array $overrides = array() ): NormalizedItem {
		$defaults = array(
			'source_id'      => 'tweet-123',
			'source_adapter' => 'twitter',
			'content_type'   => ContentType::POST,
			'content'        => '<p>Hello world!</p>',
			'publish_date'   => new DateTimeImmutable( '2024-01-15T10:00:00+00:00' ),
			'title'          => 'Hello world!',
			'source_url'     => null,
			'media'          => array(),
			'metadata'       => array(),
			'engagement'     => array(),
			'tags'           => array(),
		);

		$data = array_merge( $defaults, $overrides );

		return new NormalizedItem(
			source_id: $data['source_id'],
			source_adapter: $data['source_adapter'],
			content_type: $data['content_type'],
			content: $data['content'],
			publish_date: $data['publish_date'],
			title: $data['title'],
			source_url: $data['source_url'],
			media: $data['media'],
			metadata: $data['metadata'],
			engagement: $data['engagement'],
			tags: $data['tags'],
		);
	}
}
