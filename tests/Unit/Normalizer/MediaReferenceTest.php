<?php
/**
 * MediaReference class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Tests\Unit\TestCase;
use InvalidArgumentException;
use Brain\Monkey\Functions;

/**
 * Tests for the MediaReference class.
 */
class MediaReferenceTest extends TestCase {

	/**
	 * Test constructor sets all properties.
	 *
	 * @return void
	 */
	public function test_constructor_sets_properties(): void {
		$ref = new MediaReference(
			id: 'media-123',
			source_url: 'https://example.com/image.jpg',
			type: MediaReference::TYPE_IMAGE,
			alt_text: 'Test image',
			caption: 'A beautiful image',
			width: 1920,
			height: 1080,
			file_size: 102400,
			mime_type: 'image/jpeg',
			local_path: '/uploads/image.jpg',
			attachment_id: 456,
			metadata: array( 'exif' => 'data' )
		);

		$this->assertSame( 'media-123', $ref->id );
		$this->assertSame( 'https://example.com/image.jpg', $ref->source_url );
		$this->assertSame( MediaReference::TYPE_IMAGE, $ref->type );
		$this->assertSame( 'Test image', $ref->alt_text );
		$this->assertSame( 'A beautiful image', $ref->caption );
		$this->assertSame( 1920, $ref->width );
		$this->assertSame( 1080, $ref->height );
		$this->assertSame( 102400, $ref->file_size );
		$this->assertSame( 'image/jpeg', $ref->mime_type );
		$this->assertSame( '/uploads/image.jpg', $ref->local_path );
		$this->assertSame( 456, $ref->attachment_id );
		$this->assertSame( array( 'exif' => 'data' ), $ref->metadata );
	}

	/**
	 * Test is_image returns true for image type.
	 *
	 * @return void
	 */
	public function test_is_image_returns_true_for_image(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg',
			type: MediaReference::TYPE_IMAGE
		);

		$this->assertTrue( $ref->is_image() );
		$this->assertFalse( $ref->is_video() );
		$this->assertFalse( $ref->is_audio() );
		$this->assertFalse( $ref->is_document() );
	}

	/**
	 * Test is_video returns true for video type.
	 *
	 * @return void
	 */
	public function test_is_video_returns_true_for_video(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/video.mp4',
			type: MediaReference::TYPE_VIDEO
		);

		$this->assertTrue( $ref->is_video() );
		$this->assertFalse( $ref->is_image() );
	}

	/**
	 * Test is_audio returns true for audio type.
	 *
	 * @return void
	 */
	public function test_is_audio_returns_true_for_audio(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/audio.mp3',
			type: MediaReference::TYPE_AUDIO
		);

		$this->assertTrue( $ref->is_audio() );
		$this->assertFalse( $ref->is_image() );
	}

	/**
	 * Test is_document returns true for document type.
	 *
	 * @return void
	 */
	public function test_is_document_returns_true_for_document(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/doc.pdf',
			type: MediaReference::TYPE_DOCUMENT
		);

		$this->assertTrue( $ref->is_document() );
		$this->assertFalse( $ref->is_image() );
	}

	/**
	 * Test is_imported returns false when not imported.
	 *
	 * @return void
	 */
	public function test_is_imported_returns_false_when_not_imported(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$this->assertFalse( $ref->is_imported() );
	}

	/**
	 * Test is_imported returns true when attachment_id is set.
	 *
	 * @return void
	 */
	public function test_is_imported_returns_true_when_imported(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg',
			attachment_id: 123
		);

		$this->assertTrue( $ref->is_imported() );
	}

	/**
	 * Test has_dimensions returns true when both set.
	 *
	 * @return void
	 */
	public function test_has_dimensions_returns_true_when_set(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg',
			width: 1920,
			height: 1080
		);

		$this->assertTrue( $ref->has_dimensions() );
	}

	/**
	 * Test has_dimensions returns false when not set.
	 *
	 * @return void
	 */
	public function test_has_dimensions_returns_false_when_not_set(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$this->assertFalse( $ref->has_dimensions() );
	}

	/**
	 * Test has_dimensions returns false when only width set.
	 *
	 * @return void
	 */
	public function test_has_dimensions_returns_false_when_partial(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg',
			width: 1920
		);

		$this->assertFalse( $ref->has_dimensions() );
	}

	/**
	 * Test get_aspect_ratio calculation.
	 *
	 * @return void
	 */
	public function test_get_aspect_ratio_calculation(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg',
			width: 1920,
			height: 1080
		);

		$this->assertEqualsWithDelta( 1.7778, $ref->get_aspect_ratio(), 0.001 );
	}

	/**
	 * Test get_aspect_ratio returns null without dimensions.
	 *
	 * @return void
	 */
	public function test_get_aspect_ratio_returns_null_without_dimensions(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$this->assertNull( $ref->get_aspect_ratio() );
	}

	/**
	 * Test mark_imported sets attachment_id and local_path.
	 *
	 * @return void
	 */
	public function test_mark_imported_sets_values(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$ref->mark_imported( 123, '/uploads/2024/01/image.jpg' );

		$this->assertSame( 123, $ref->attachment_id );
		$this->assertSame( '/uploads/2024/01/image.jpg', $ref->local_path );
		$this->assertTrue( $ref->is_imported() );
	}

	/**
	 * Test get_meta returns metadata value.
	 *
	 * @return void
	 */
	public function test_get_meta_returns_value(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg',
			metadata: array( 'camera' => 'Canon', 'iso' => 100 )
		);

		$this->assertSame( 'Canon', $ref->get_meta( 'camera' ) );
		$this->assertSame( 100, $ref->get_meta( 'iso' ) );
	}

	/**
	 * Test get_meta returns default for missing key.
	 *
	 * @return void
	 */
	public function test_get_meta_returns_default(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$this->assertNull( $ref->get_meta( 'nonexistent' ) );
		$this->assertSame( 'default', $ref->get_meta( 'nonexistent', 'default' ) );
	}

	/**
	 * Test set_meta sets metadata value.
	 *
	 * @return void
	 */
	public function test_set_meta_sets_value(): void {
		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$ref->set_meta( 'processed', true );

		$this->assertTrue( $ref->get_meta( 'processed' ) );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$ref = new MediaReference(
			id: 'media-123',
			source_url: 'https://example.com/image.jpg',
			type: MediaReference::TYPE_IMAGE,
			alt_text: 'Test image',
			caption: 'A caption',
			width: 1920,
			height: 1080,
			file_size: 102400,
			mime_type: 'image/jpeg'
		);

		$array = $ref->to_array();

		$this->assertSame( 'media-123', $array['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $array['source_url'] );
		$this->assertSame( 'image', $array['type'] );
		$this->assertSame( 'Test image', $array['alt_text'] );
		$this->assertSame( 'A caption', $array['caption'] );
		$this->assertSame( 1920, $array['width'] );
		$this->assertSame( 1080, $array['height'] );
		$this->assertNull( $array['attachment_id'] );
	}

	/**
	 * Test from_array creates MediaReference.
	 *
	 * @return void
	 */
	public function test_from_array_creates_reference(): void {
		$data = array(
			'id'         => 'media-123',
			'source_url' => 'https://example.com/image.jpg',
			'type'       => 'image',
			'alt_text'   => 'Test image',
			'width'      => 1920,
			'height'     => 1080,
		);

		$ref = MediaReference::from_array( $data );

		$this->assertSame( 'media-123', $ref->id );
		$this->assertSame( 'https://example.com/image.jpg', $ref->source_url );
		$this->assertSame( 'image', $ref->type );
		$this->assertSame( 'Test image', $ref->alt_text );
		$this->assertSame( 1920, $ref->width );
	}

	/**
	 * Test from_array throws exception for missing required fields.
	 *
	 * @return void
	 */
	public function test_from_array_throws_exception_for_missing_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required fields' );

		MediaReference::from_array( array( 'id' => 'test' ) );
	}

	/**
	 * Test from_array uses default type when not provided.
	 *
	 * @return void
	 */
	public function test_from_array_uses_default_type(): void {
		$data = array(
			'id'         => 'test',
			'source_url' => 'https://example.com/file',
		);

		$ref = MediaReference::from_array( $data );

		$this->assertSame( MediaReference::TYPE_IMAGE, $ref->type );
	}

	/**
	 * Test from_url creates reference from URL.
	 *
	 * @return void
	 */
	public function test_from_url_creates_reference(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$ref = MediaReference::from_url( 'https://example.com/image.jpg' );

		$this->assertSame( 'https://example.com/image.jpg', $ref->source_url );
		$this->assertSame( MediaReference::TYPE_IMAGE, $ref->type );
		$this->assertNotEmpty( $ref->id );
	}

	/**
	 * Test detect_type_from_url detects image types.
	 *
	 * @return void
	 */
	public function test_detect_type_from_url_detects_images(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$this->assertSame( MediaReference::TYPE_IMAGE, MediaReference::detect_type_from_url( 'https://example.com/photo.jpg' ) );
		$this->assertSame( MediaReference::TYPE_IMAGE, MediaReference::detect_type_from_url( 'https://example.com/image.png' ) );
		$this->assertSame( MediaReference::TYPE_IMAGE, MediaReference::detect_type_from_url( 'https://example.com/animation.gif' ) );
		$this->assertSame( MediaReference::TYPE_IMAGE, MediaReference::detect_type_from_url( 'https://example.com/image.webp' ) );
	}

	/**
	 * Test detect_type_from_url detects video types.
	 *
	 * @return void
	 */
	public function test_detect_type_from_url_detects_videos(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$this->assertSame( MediaReference::TYPE_VIDEO, MediaReference::detect_type_from_url( 'https://example.com/video.mp4' ) );
		$this->assertSame( MediaReference::TYPE_VIDEO, MediaReference::detect_type_from_url( 'https://example.com/video.webm' ) );
		$this->assertSame( MediaReference::TYPE_VIDEO, MediaReference::detect_type_from_url( 'https://example.com/movie.mov' ) );
	}

	/**
	 * Test detect_type_from_url detects audio types.
	 *
	 * @return void
	 */
	public function test_detect_type_from_url_detects_audio(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$this->assertSame( MediaReference::TYPE_AUDIO, MediaReference::detect_type_from_url( 'https://example.com/song.mp3' ) );
		$this->assertSame( MediaReference::TYPE_AUDIO, MediaReference::detect_type_from_url( 'https://example.com/audio.wav' ) );
	}

	/**
	 * Test detect_type_from_url detects document types.
	 *
	 * @return void
	 */
	public function test_detect_type_from_url_detects_documents(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$this->assertSame( MediaReference::TYPE_DOCUMENT, MediaReference::detect_type_from_url( 'https://example.com/file.pdf' ) );
		$this->assertSame( MediaReference::TYPE_DOCUMENT, MediaReference::detect_type_from_url( 'https://example.com/doc.docx' ) );
	}

	/**
	 * Test get_extension returns file extension.
	 *
	 * @return void
	 */
	public function test_get_extension_returns_extension(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/image.jpg'
		);

		$this->assertSame( 'jpg', $ref->get_extension() );
	}

	/**
	 * Test get_filename returns filename.
	 *
	 * @return void
	 */
	public function test_get_filename_returns_filename(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$ref = new MediaReference(
			id: 'test',
			source_url: 'https://example.com/path/to/image.jpg'
		);

		$this->assertSame( 'image.jpg', $ref->get_filename() );
	}

	/**
	 * Test type constants are defined.
	 *
	 * @return void
	 */
	public function test_type_constants_defined(): void {
		$this->assertSame( 'image', MediaReference::TYPE_IMAGE );
		$this->assertSame( 'video', MediaReference::TYPE_VIDEO );
		$this->assertSame( 'audio', MediaReference::TYPE_AUDIO );
		$this->assertSame( 'document', MediaReference::TYPE_DOCUMENT );
	}
}
