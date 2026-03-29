<?php
/**
 * MediaSideloader class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Processor\MediaSideloader;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use RuntimeException;

/**
 * Tests for the MediaSideloader class.
 */
class MediaSideloaderTest extends TestCase {

	/**
	 * Sideloader instance.
	 *
	 * @var MediaSideloader
	 */
	private MediaSideloader $sideloader;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->sideloader = new MediaSideloader();
	}

	/**
	 * Test find_existing_attachment returns ID when found.
	 *
	 * @return void
	 */
	public function test_find_existing_attachment_returns_id(): void {
		Functions\expect( 'get_posts' )
			->once()
			->with(
				\Mockery::on(
					function ( $args ) {
						return 'attachment' === $args['post_type']
							&& '_ai_importer_original_url' === $args['meta_key']
							&& 'https://example.com/image.jpg' === $args['meta_value'];
					}
				)
			)
			->andReturn( array( 42 ) );

		$result = $this->sideloader->find_existing_attachment( 'https://example.com/image.jpg' );

		$this->assertSame( 42, $result );
	}

	/**
	 * Test find_existing_attachment returns null when not found.
	 *
	 * @return void
	 */
	public function test_find_existing_attachment_returns_null(): void {
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		$result = $this->sideloader->find_existing_attachment( 'https://example.com/new.jpg' );

		$this->assertNull( $result );
	}

	/**
	 * Test sideload returns existing attachment on dedup.
	 *
	 * @return void
	 */
	public function test_sideload_returns_existing_on_dedup(): void {
		$media_ref = new MediaReference(
			id: 'media-1',
			source_url: 'https://example.com/image.jpg',
		);

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( 99 ) );

		Functions\expect( 'get_attached_file' )
			->once()
			->with( 99 )
			->andReturn( '/var/www/wp-content/uploads/image.jpg' );

		$result = $this->sideloader->sideload( $media_ref, 1 );

		$this->assertSame( 99, $result );
		$this->assertTrue( $media_ref->is_imported() );
		$this->assertSame( 99, $media_ref->attachment_id );
	}

	/**
	 * Test sideload downloads and creates attachment.
	 *
	 * @return void
	 */
	public function test_sideload_creates_attachment(): void {
		$media_ref = new MediaReference(
			id: 'media-1',
			source_url: 'https://example.com/photo.jpg',
			alt_text: 'A nice photo',
			caption: 'My caption',
		);

		// No existing attachment.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		// Download to temp.
		Functions\expect( 'download_url' )
			->once()
			->with( 'https://example.com/photo.jpg', 15 )
			->andReturn( '/tmp/photo.jpg' );

		Functions\expect( 'is_wp_error' )
			->with( '/tmp/photo.jpg' )
			->andReturn( false );

		Functions\expect( 'is_wp_error' )
			->with( 55 )
			->andReturn( false );

		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		// Validate file.
		Functions\expect( 'wp_check_filetype' )
			->once()
			->andReturn( array( 'type' => 'image/jpeg' ) );

		// Create attachment.
		Functions\expect( 'media_handle_sideload' )
			->once()
			->andReturn( 55 );

		// Set meta.
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 55, '_ai_importer_original_url', 'https://example.com/photo.jpg' )
			->andReturn( true );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 55, '_wp_attachment_image_alt', 'A nice photo' )
			->andReturn( true );

		// Get local path.
		Functions\expect( 'get_attached_file' )
			->once()
			->with( 55 )
			->andReturn( '/var/www/wp-content/uploads/photo.jpg' );

		$result = $this->sideloader->sideload( $media_ref, 1 );

		$this->assertSame( 55, $result );
		$this->assertTrue( $media_ref->is_imported() );
	}

	/**
	 * Test sideload throws on download failure.
	 *
	 * @return void
	 */
	public function test_sideload_throws_on_download_failure(): void {
		$media_ref = new MediaReference(
			id: 'media-1',
			source_url: 'https://example.com/broken.jpg',
		);

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )
			->andReturn( 'Connection timed out' );

		Functions\expect( 'download_url' )
			->once()
			->andReturn( $wp_error );

		Functions\expect( 'is_wp_error' )
			->once()
			->with( $wp_error )
			->andReturn( true );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to download media' );

		$this->sideloader->sideload( $media_ref, 1 );
	}

	/**
	 * Test sideload throws on sideload failure.
	 *
	 * @return void
	 */
	public function test_sideload_throws_on_media_handle_failure(): void {
		$media_ref = new MediaReference(
			id: 'media-1',
			source_url: 'https://example.com/photo.jpg',
		);

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		Functions\expect( 'download_url' )
			->once()
			->andReturn( '/tmp/photo.jpg' );

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )
			->andReturn( 'Upload failed' );

		Functions\expect( 'is_wp_error' )
			->andReturnUsing(
				function ( $value ) use ( $wp_error ) {
					return $value === $wp_error;
				}
			);

		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		Functions\expect( 'wp_check_filetype' )
			->once()
			->andReturn( array( 'type' => 'image/jpeg' ) );

		Functions\expect( 'media_handle_sideload' )
			->once()
			->andReturn( $wp_error );

		Functions\expect( 'wp_delete_file' )
			->once()
			->with( '/tmp/photo.jpg' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to sideload media' );

		$this->sideloader->sideload( $media_ref, 1 );
	}
}
