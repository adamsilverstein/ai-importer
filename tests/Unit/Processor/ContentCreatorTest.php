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
use AI_Importer\Schema\CustomTaxonomyRegistrar;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;

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

		// A registrar that records on-the-fly taxonomy registration requests.
		$registrar     = Mockery::mock( CustomTaxonomyRegistrar::class );
		$registrar->shouldReceive( 'ensure_registered' )->byDefault();
		$this->creator   = new ContentCreator( $registrar );
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

		// Advanced-mapping helpers default to permissive behavior; individual
		// tests override these as needed.
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'post_type_supports' )->justReturn( false );
		Functions\when( 'set_post_format' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
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
	 * Test posts default to draft status and the post post type.
	 *
	 * @return void
	 */
	public function test_defaults_without_mapping(): void {
		$captured = $this->capture_insert_args();

		$this->creator->create( $this->make_item(), 'batch-abc' );

		$this->assertSame( 'post', $captured['args']['post_type'] );
		$this->assertSame( 'draft', $captured['args']['post_status'] );
	}

	/**
	 * Test the mapping's default post type and status are applied.
	 *
	 * @return void
	 */
	public function test_mapping_post_type_and_status_applied(): void {
		$captured = $this->capture_insert_args();

		$this->creator->create(
			$this->make_item(),
			'batch-abc',
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'page', $captured['args']['post_type'] );
		$this->assertSame( 'publish', $captured['args']['post_status'] );
	}

	/**
	 * Test a per-content-type override wins over the mapping default.
	 *
	 * @return void
	 */
	public function test_mapping_post_type_override_per_content_type(): void {
		$captured = $this->capture_insert_args();

		$this->creator->create(
			$this->make_item(),
			'batch-abc',
			array(
				'post_type'          => 'page',
				'post_type_mappings' => array(
					array(
						'source_content_type'   => 'thread',
						'destination_post_type' => 'story',
					),
					array(
						'source_content_type'   => ContentType::POST->value,
						'destination_post_type' => 'note',
					),
				),
			)
		);

		$this->assertSame( 'note', $captured['args']['post_type'] );
	}

	/**
	 * Test a hashtags taxonomy mapping routes tags to the destination taxonomy.
	 *
	 * @return void
	 */
	public function test_mapping_routes_hashtags_to_taxonomy(): void {
		$set_terms = array();
		$set_tags  = false;

		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $post_id, $terms, $taxonomy ) use ( &$set_terms ) {
				$set_terms[ $taxonomy ] = $terms;
				return array();
			}
		);
		Functions\when( 'wp_set_post_tags' )->alias(
			function () use ( &$set_tags ) {
				$set_tags = true;
				return true;
			}
		);

		$item = $this->make_item( array( 'tags' => array( 'wordpress', 'ai' ) ) );

		$this->creator->create(
			$item,
			'batch-abc',
			array(
				'taxonomy_mappings' => array(
					array(
						'source_signal'        => 'hashtags',
						'destination_taxonomy' => 'topic',
						'destination_terms'    => array(),
					),
				),
			)
		);

		$this->assertSame( array( 'wordpress', 'ai' ), $set_terms['topic'] );
		$this->assertFalse( $set_tags, 'Default hashtag handling should be skipped when mapped.' );
	}

	/**
	 * Test fixed destination terms are assigned for non-hashtag signals.
	 *
	 * @return void
	 */
	public function test_mapping_assigns_fixed_destination_terms(): void {
		$set_terms = array();
		$set_tags  = false;

		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $post_id, $terms, $taxonomy ) use ( &$set_terms ) {
				$set_terms[ $taxonomy ] = $terms;
				return array();
			}
		);
		Functions\when( 'wp_set_post_tags' )->alias(
			function () use ( &$set_tags ) {
				$set_tags = true;
				return true;
			}
		);

		$item = $this->make_item( array( 'tags' => array( 'wordpress' ) ) );

		$this->creator->create(
			$item,
			'batch-abc',
			array(
				'taxonomy_mappings' => array(
					array(
						'source_signal'        => 'suggested_categories',
						'destination_taxonomy' => 'category',
						'destination_terms'    => array( 'Notes', 'Updates' ),
					),
				),
			)
		);

		$this->assertSame( array( 'Notes', 'Updates' ), $set_terms['category'] );
		$this->assertTrue( $set_tags, 'Default hashtag handling should still run when hashtags are not mapped.' );
	}

	/**
	 * Test author mapping sets post_author when the mapped user exists (F9.2).
	 *
	 * @return void
	 */
	public function test_author_mapping_applied_when_user_exists(): void {
		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) {
				return 7 === $user_id ? (object) array( 'ID' => 7 ) : false;
			}
		);

		$captured = $this->capture_insert_args();

		$this->creator->create(
			$this->make_item( array( 'author_name' => '@jane' ) ),
			'batch-abc',
			array(
				'author_mappings' => array(
					array(
						'source_author'       => '@jane',
						'destination_user_id' => 7,
					),
				),
			)
		);

		$this->assertSame( 7, $captured['args']['post_author'] );
	}

	/**
	 * Test author mapping falls back to default behavior for unknown users.
	 *
	 * @return void
	 */
	public function test_author_mapping_falls_back_when_user_missing(): void {
		// get_userdata returns false (default) so the mapped user is invalid.
		$captured = $this->capture_insert_args();

		$this->creator->create(
			$this->make_item( array( 'author_name' => '@jane' ) ),
			'batch-abc',
			array(
				'author_mappings' => array(
					array(
						'source_author'       => '@jane',
						'destination_user_id' => 999,
					),
				),
			)
		);

		$this->assertArrayNotHasKey( 'post_author', $captured['args'] );
	}

	/**
	 * Test the default author ID applies when no source mapping matches (F9.2).
	 *
	 * @return void
	 */
	public function test_default_author_id_applied(): void {
		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) {
				return 3 === $user_id ? (object) array( 'ID' => 3 ) : false;
			}
		);

		$captured = $this->capture_insert_args();

		$this->creator->create(
			$this->make_item( array( 'author_name' => '@nobody' ) ),
			'batch-abc',
			array(
				'author_mappings'   => array(
					array(
						'source_author'       => '@jane',
						'destination_user_id' => 7,
					),
				),
				'default_author_id' => 3,
			)
		);

		$this->assertSame( 3, $captured['args']['post_author'] );
	}

	/**
	 * Test the post format is set when the destination supports formats (F9.4).
	 *
	 * @return void
	 */
	public function test_post_format_set_when_supported(): void {
		Functions\when( 'post_type_supports' )->justReturn( true );

		$set_format = array();
		Functions\when( 'set_post_format' )->alias(
			function ( $post_id, $format ) use ( &$set_format ) {
				$set_format[] = array( $post_id, $format );
				return array();
			}
		);

		$this->creator->create(
			$this->make_item(),
			'batch-abc',
			array(
				'post_format_mappings' => array(
					array(
						'source_content_type' => ContentType::POST->value,
						'post_format'         => 'aside',
					),
				),
			)
		);

		$this->assertSame( array( array( 42, 'aside' ) ), $set_format );
	}

	/**
	 * Test the post format is skipped when the post type lacks support (F9.4).
	 *
	 * @return void
	 */
	public function test_post_format_skipped_when_unsupported(): void {
		// post_type_supports defaults to false.
		$called = false;
		Functions\when( 'set_post_format' )->alias(
			function () use ( &$called ) {
				$called = true;
				return array();
			}
		);

		$this->creator->create(
			$this->make_item(),
			'batch-abc',
			array( 'default_post_format' => 'gallery' )
		);

		$this->assertFalse( $called, 'set_post_format must not run when unsupported.' );
	}

	/**
	 * Test 'standard' clears the post format via a false argument (F9.4).
	 *
	 * @return void
	 */
	public function test_post_format_standard_clears_format(): void {
		Functions\when( 'post_type_supports' )->justReturn( true );

		$captured = null;
		Functions\when( 'set_post_format' )->alias(
			function ( $post_id, $format ) use ( &$captured ) {
				$captured = $format;
				return array();
			}
		);

		$this->creator->create(
			$this->make_item(),
			'batch-abc',
			array( 'default_post_format' => 'standard' )
		);

		$this->assertFalse( $captured );
	}

	/**
	 * Test create_if_missing registers the taxonomy before assigning terms (F9.3).
	 *
	 * @return void
	 */
	public function test_create_if_missing_registers_taxonomy(): void {
		// The taxonomy does not exist until registered.
		$exists = array( 'mood' => false );
		Functions\when( 'taxonomy_exists' )->alias(
			function ( $taxonomy ) use ( &$exists ) {
				return $exists[ $taxonomy ] ?? true;
			}
		);

		$set_terms = array();
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $post_id, $terms, $taxonomy ) use ( &$set_terms ) {
				$set_terms[ $taxonomy ] = $terms;
				return array();
			}
		);

		$registrar = Mockery::mock( CustomTaxonomyRegistrar::class );
		$registrar->shouldReceive( 'ensure_registered' )
			->once()
			->with( 'mood', 'Mood', array( 'post' ) )
			->andReturnUsing(
				function () use ( &$exists ) {
					$exists['mood'] = true;
				}
			);

		$creator = new ContentCreator( $registrar );

		$item = $this->make_item( array( 'tags' => array( 'happy' ) ) );

		$creator->create(
			$item,
			'batch-abc',
			array(
				'taxonomy_mappings' => array(
					array(
						'source_signal'        => 'hashtags',
						'destination_taxonomy' => 'mood',
						'create_if_missing'    => true,
						'taxonomy_label'       => 'Mood',
					),
				),
			)
		);

		$this->assertSame( array( 'happy' ), $set_terms['mood'] );
		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test meta field mapping copies item metadata to post meta (F9.1).
	 *
	 * @return void
	 */
	public function test_meta_field_mapping_copies_metadata(): void {
		$item = $this->make_item(
			array(
				'metadata' => array(
					'location' => 'Brooklyn, NY',
					'mood'     => 'happy',
				),
			)
		);

		$this->creator->create(
			$item,
			'batch-abc',
			array(
				'meta_field_mappings' => array(
					array(
						'source_field'         => 'location',
						'destination_meta_key' => 'geo_location',
					),
					// Source field absent from metadata: should be skipped.
					array(
						'source_field'         => 'missing',
						'destination_meta_key' => 'ignored',
					),
				),
			)
		);

		$this->assertSame( 'Brooklyn, NY', $this->post_meta[42]['geo_location'] );
		$this->assertArrayNotHasKey( 'ignored', $this->post_meta[42] );
	}

	/**
	 * Capture the args passed to wp_insert_post.
	 *
	 * @return \ArrayObject<string, mixed> Container populated with 'args' on insert.
	 */
	private function capture_insert_args(): \ArrayObject {
		$captured = new \ArrayObject( array( 'args' => array() ) );

		Functions\when( 'wp_insert_post' )->alias(
			function ( $args ) use ( $captured ) {
				$captured['args'] = $args;
				return 42;
			}
		);

		return $captured;
	}

	/**
	 * Test find_existing returns the matching post ID.
	 *
	 * @return void
	 */
	public function test_find_existing_returns_post_id(): void {
		$captured_args = null;

		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array( 99 );
			}
		);

		$result = $this->creator->find_existing( 'twitter', 'tweet-123' );

		$this->assertSame( 99, $result );
		$this->assertSame( 'ids', $captured_args['fields'] );
		$this->assertSame( 1, $captured_args['posts_per_page'] );
		$this->assertSame( 'any', $captured_args['post_status'] );
		$this->assertSame(
			array(
				array(
					'key'   => ContentCreator::META_SOURCE,
					'value' => 'twitter',
				),
				array(
					'key'   => ContentCreator::META_SOURCE_ID,
					'value' => 'tweet-123',
				),
			),
			$captured_args['meta_query']
		);
	}

	/**
	 * Test find_existing returns null when no match exists.
	 *
	 * @return void
	 */
	public function test_find_existing_returns_null_when_no_match(): void {
		Functions\when( 'get_posts' )->justReturn( array() );

		$this->assertNull( $this->creator->find_existing( 'twitter', 'tweet-123' ) );
	}

	/**
	 * Test find_existing short-circuits on empty identifiers.
	 *
	 * @return void
	 */
	public function test_find_existing_returns_null_for_empty_identifiers(): void {
		Functions\expect( 'get_posts' )->never();

		$this->assertNull( $this->creator->find_existing( '', 'tweet-123' ) );
		$this->assertNull( $this->creator->find_existing( 'twitter', '' ) );
	}

	/**
	 * Test update refreshes the post and tracking meta.
	 *
	 * @return void
	 */
	public function test_update_refreshes_post_and_meta(): void {
		$captured_args = null;

		Functions\when( 'wp_update_post' )->alias(
			function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return $args['ID'];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		$item   = $this->make_item( array( 'content' => '<p>Updated content.</p>' ) );
		$result = $this->creator->update( 55, $item, 'batch-def' );

		$this->assertSame( 55, $result );
		$this->assertSame( 55, $captured_args['ID'] );
		$this->assertSame( 'Hello world!', $captured_args['post_title'] );
		$this->assertSame( '<p>Updated content.</p>', $captured_args['post_content'] );
		$this->assertSame( '2024-01-15 10:00:00', $captured_args['post_date'] );

		$meta = $this->post_meta[55] ?? array();
		$this->assertSame( 'batch-def', $meta[ ContentCreator::META_BATCH_ID ] );
		$this->assertArrayHasKey( ContentCreator::META_IMPORTED_AT, $meta );
	}

	/**
	 * Test update throws on wp_update_post failure.
	 *
	 * @return void
	 */
	public function test_update_throws_on_failure(): void {
		Functions\when( 'wp_update_post' )->justReturn(
			new \WP_Error( 'update_error', 'Update failed' )
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->creator->update( 55, $this->make_item(), 'batch-def' );
	}

	/**
	 * Test post_updated action fires.
	 *
	 * @return void
	 */
	public function test_post_updated_action_fires(): void {
		Functions\when( 'wp_update_post' )->justReturn( 55 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		Actions\expectDone( 'ai_importer_post_updated' )
			->once()
			->with( 55, \Mockery::type( NormalizedItem::class ), 'batch-def' );

		$this->creator->update( 55, $this->make_item(), 'batch-def' );

		$this->assertBrainMonkeyExpectations();
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
			'author_name'    => null,
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
			author_name: $data['author_name'],
			tags: $data['tags'],
		);
	}
}
