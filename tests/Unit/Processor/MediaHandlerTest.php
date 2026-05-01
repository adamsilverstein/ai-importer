<?php
/**
 * MediaHandler tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\AI\AltTextGenerator;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Processor\MediaHandler;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;

/**
 * Tests for the MediaHandler class.
 */
class MediaHandlerTest extends TestCase {

	/**
	 * Handler instance.
	 *
	 * @var MediaHandler
	 */
	private MediaHandler $handler;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->handler = new MediaHandler();

		Functions\when( 'media_handle_sideload' )->justReturn( 100 );
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/wp-content/uploads/example.jpg' );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Test sideload with remote URL.
	 *
	 * @return void
	 */
	public function test_sideload_remote_url(): void {
		Functions\when( 'download_url' )->justReturn( '/tmp/downloaded.jpg' );

		$media = new MediaReference(
			id: 'media-1',
			source_url: 'https://pbs.twimg.com/media/example.jpg',
			type: MediaReference::TYPE_IMAGE,
		);

		$attachment_id = $this->handler->sideload( $media );

		$this->assertSame( 100, $attachment_id );
		$this->assertTrue( $media->is_imported() );
		$this->assertSame( 100, $media->attachment_id );
	}

	/**
	 * Test sideload with local file.
	 *
	 * @return void
	 */
	public function test_sideload_local_file(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_test_' );

		$media = new MediaReference(
			id: 'media-2',
			source_url: 'https://pbs.twimg.com/media/local.jpg',
			type: MediaReference::TYPE_IMAGE,
		);

		$media->local_path = $tmp;

		$attachment_id = $this->handler->sideload( $media );

		$this->assertSame( 100, $attachment_id );
		$this->assertTrue( $media->is_imported() );

		// Clean up.
		if ( file_exists( $tmp ) ) {
			unlink( $tmp );
		}
	}

	/**
	 * Test sideload sets alt text.
	 *
	 * @return void
	 */
	public function test_sideload_sets_alt_text(): void {
		Functions\when( 'download_url' )->justReturn( '/tmp/downloaded.jpg' );

		$alt_set = false;

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$alt_set ) {
				if ( '_wp_attachment_image_alt' === $key ) {
					$alt_set = true;
				}
				return true;
			}
		);

		$media = new MediaReference(
			id: 'media-3',
			source_url: 'https://example.com/image.jpg',
			type: MediaReference::TYPE_IMAGE,
			alt_text: 'A nice photo',
		);

		$this->handler->sideload( $media );

		$this->assertTrue( $alt_set );
	}

	/**
	 * Test sideload throws on download failure.
	 *
	 * @return void
	 */
	public function test_sideload_throws_on_download_failure(): void {
		Functions\when( 'download_url' )->justReturn(
			new \WP_Error( 'download_error', 'Download failed' )
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$media = new MediaReference(
			id: 'media-4',
			source_url: 'https://example.com/broken.jpg',
			type: MediaReference::TYPE_IMAGE,
		);

		$this->expectException( \RuntimeException::class );
		$this->handler->sideload( $media );
	}

	/**
	 * Test sideload generates alt text for images that lack it.
	 *
	 * @return void
	 */
	public function test_sideload_generates_alt_text_for_image_without_alt(): void {
		Functions\when( 'download_url' )->justReturn( '/tmp/downloaded.jpg' );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$alt_set = null;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$alt_set ) {
				if ( '_wp_attachment_image_alt' === $key ) {
					$alt_set = $value;
				}
				return true;
			}
		);

		$generator = $this->createMock( AltTextGenerator::class );
		$generator->expects( $this->once() )
			->method( 'generate' )
			->with( 'https://example.com/sunset.jpg' )
			->willReturn( 'A sunset over the ocean.' );

		$handler = new MediaHandler( $generator );

		$media = new MediaReference(
			id: 'media-alt-1',
			source_url: 'https://example.com/sunset.jpg',
			type: MediaReference::TYPE_IMAGE,
		);

		$handler->sideload( $media );

		$this->assertSame( 'A sunset over the ocean.', $media->alt_text );
		$this->assertSame( 'A sunset over the ocean.', $alt_set );
	}

	/**
	 * Test sideload preserves source-supplied alt text without calling AI.
	 *
	 * @return void
	 */
	public function test_sideload_does_not_overwrite_existing_alt_text(): void {
		Functions\when( 'download_url' )->justReturn( '/tmp/downloaded.jpg' );

		$generator = $this->createMock( AltTextGenerator::class );
		$generator->expects( $this->never() )->method( 'generate' );

		$handler = new MediaHandler( $generator );

		$media = new MediaReference(
			id: 'media-alt-2',
			source_url: 'https://example.com/photo.jpg',
			type: MediaReference::TYPE_IMAGE,
			alt_text: 'Existing alt text from source.',
		);

		$handler->sideload( $media );

		$this->assertSame( 'Existing alt text from source.', $media->alt_text );
	}

	/**
	 * Test sideload skips alt-text generation for non-image media.
	 *
	 * @return void
	 */
	public function test_sideload_skips_alt_generation_for_non_images(): void {
		Functions\when( 'download_url' )->justReturn( '/tmp/downloaded.mp4' );

		$generator = $this->createMock( AltTextGenerator::class );
		$generator->expects( $this->never() )->method( 'generate' );

		$handler = new MediaHandler( $generator );

		$media = new MediaReference(
			id: 'media-alt-3',
			source_url: 'https://example.com/video.mp4',
			type: MediaReference::TYPE_VIDEO,
		);

		$handler->sideload( $media );

		$this->assertNull( $media->alt_text );
	}

	/**
	 * Test alt-text generation failure is non-fatal — sideload still succeeds.
	 *
	 * @return void
	 */
	public function test_sideload_tolerates_alt_text_generation_failure(): void {
		Functions\when( 'download_url' )->justReturn( '/tmp/downloaded.jpg' );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$generator = $this->createMock( AltTextGenerator::class );
		$generator->method( 'generate' )->willReturn( new \WP_Error( 'fail', 'fail' ) );

		$handler = new MediaHandler( $generator );

		$media = new MediaReference(
			id: 'media-alt-4',
			source_url: 'https://example.com/image.jpg',
			type: MediaReference::TYPE_IMAGE,
		);

		$attachment_id = $handler->sideload( $media );

		$this->assertSame( 100, $attachment_id );
		$this->assertNull( $media->alt_text );
	}

	/**
	 * Test process handles multiple media with individual failures.
	 *
	 * @return void
	 */
	public function test_process_continues_on_individual_failure(): void {
		$call_count = 0;

		Functions\when( 'download_url' )->alias(
			function () use ( &$call_count ) {
				++$call_count;

				if ( 1 === $call_count ) {
					return new \WP_Error( 'fail', 'First download failed' );
				}

				return '/tmp/downloaded.jpg';
			}
		);

		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$item = new NormalizedItem(
			source_id: 'tweet-1',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: 'Test',
			publish_date: new DateTimeImmutable(),
			media: array(
				new MediaReference(
					id: 'fail-media',
					source_url: 'https://example.com/fail.jpg',
					type: MediaReference::TYPE_IMAGE,
				),
				new MediaReference(
					id: 'ok-media',
					source_url: 'https://example.com/ok.jpg',
					type: MediaReference::TYPE_IMAGE,
				),
			),
		);

		$errors = $this->handler->process( $item );

		// First media failed, second succeeded.
		$this->assertCount( 1, $errors );
		$this->assertFalse( $item->media[0]->is_imported() );
		$this->assertTrue( $item->media[1]->is_imported() );
	}
}
