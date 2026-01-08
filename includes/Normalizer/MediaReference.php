<?php
/**
 * Media reference class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use InvalidArgumentException;

/**
 * Represents a media reference extracted from content.
 *
 * Tracks media files through the import process, from source URL
 * to WordPress attachment.
 */
class MediaReference {

	/**
	 * Media types.
	 */
	public const TYPE_IMAGE    = 'image';
	public const TYPE_VIDEO    = 'video';
	public const TYPE_AUDIO    = 'audio';
	public const TYPE_DOCUMENT = 'document';

	/**
	 * Unique reference ID.
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Original source URL.
	 *
	 * @var string
	 */
	public string $source_url;

	/**
	 * Media type (image, video, audio, document).
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Alt text for accessibility.
	 *
	 * @var string|null
	 */
	public ?string $alt_text;

	/**
	 * Media caption.
	 *
	 * @var string|null
	 */
	public ?string $caption;

	/**
	 * Width in pixels.
	 *
	 * @var int|null
	 */
	public ?int $width;

	/**
	 * Height in pixels.
	 *
	 * @var int|null
	 */
	public ?int $height;

	/**
	 * File size in bytes.
	 *
	 * @var int|null
	 */
	public ?int $file_size;

	/**
	 * MIME type.
	 *
	 * @var string|null
	 */
	public ?string $mime_type;

	/**
	 * Local file path after sideloading.
	 *
	 * @var string|null
	 */
	public ?string $local_path;

	/**
	 * WordPress attachment ID after import.
	 *
	 * @var int|null
	 */
	public ?int $attachment_id;

	/**
	 * Platform-specific metadata.
	 *
	 * @var array<string, mixed>
	 */
	public array $metadata;

	/**
	 * Constructor.
	 *
	 * @param string               $id            Unique reference ID.
	 * @param string               $source_url    Original media URL.
	 * @param string               $type          Media type.
	 * @param string|null          $alt_text      Alt text.
	 * @param string|null          $caption       Caption.
	 * @param int|null             $width         Width in pixels.
	 * @param int|null             $height        Height in pixels.
	 * @param int|null             $file_size     File size in bytes.
	 * @param string|null          $mime_type     MIME type.
	 * @param string|null          $local_path    Local path after sideloading.
	 * @param int|null             $attachment_id WordPress attachment ID.
	 * @param array<string, mixed> $metadata      Platform metadata.
	 */
	public function __construct(
		string $id,
		string $source_url,
		string $type = self::TYPE_IMAGE,
		?string $alt_text = null,
		?string $caption = null,
		?int $width = null,
		?int $height = null,
		?int $file_size = null,
		?string $mime_type = null,
		?string $local_path = null,
		?int $attachment_id = null,
		array $metadata = array()
	) {
		$this->id            = $id;
		$this->source_url    = $source_url;
		$this->type          = $type;
		$this->alt_text      = $alt_text;
		$this->caption       = $caption;
		$this->width         = $width;
		$this->height        = $height;
		$this->file_size     = $file_size;
		$this->mime_type     = $mime_type;
		$this->local_path    = $local_path;
		$this->attachment_id = $attachment_id;
		$this->metadata      = $metadata;
	}

	/**
	 * Check if this is an image.
	 *
	 * @return bool True if image type.
	 */
	public function is_image(): bool {
		return self::TYPE_IMAGE === $this->type;
	}

	/**
	 * Check if this is a video.
	 *
	 * @return bool True if video type.
	 */
	public function is_video(): bool {
		return self::TYPE_VIDEO === $this->type;
	}

	/**
	 * Check if this is audio.
	 *
	 * @return bool True if audio type.
	 */
	public function is_audio(): bool {
		return self::TYPE_AUDIO === $this->type;
	}

	/**
	 * Check if this is a document.
	 *
	 * @return bool True if document type.
	 */
	public function is_document(): bool {
		return self::TYPE_DOCUMENT === $this->type;
	}

	/**
	 * Check if the media has been imported to WordPress.
	 *
	 * @return bool True if imported.
	 */
	public function is_imported(): bool {
		return null !== $this->attachment_id;
	}

	/**
	 * Check if dimensions are available.
	 *
	 * @return bool True if dimensions are set.
	 */
	public function has_dimensions(): bool {
		return null !== $this->width && null !== $this->height;
	}

	/**
	 * Get the aspect ratio.
	 *
	 * @return float|null The aspect ratio (width/height) or null if dimensions unavailable.
	 */
	public function get_aspect_ratio(): ?float {
		// Check for null dimensions and prevent division by zero.
		if ( ! $this->has_dimensions() || 0 === $this->height ) {
			return null;
		}

		return $this->width / $this->height;
	}

	/**
	 * Mark the media as imported.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $local_path    Local file path.
	 * @return void
	 */
	public function mark_imported( int $attachment_id, string $local_path ): void {
		$this->attachment_id = $attachment_id;
		$this->local_path    = $local_path;
	}

	/**
	 * Get metadata value by key.
	 *
	 * @param string $key           Metadata key.
	 * @param mixed  $default_value Default value if key not found.
	 * @return mixed Metadata value or default.
	 */
	public function get_meta( string $key, mixed $default_value = null ): mixed {
		return $this->metadata[ $key ] ?? $default_value;
	}

	/**
	 * Set metadata value.
	 *
	 * @param string $key   Metadata key.
	 * @param mixed  $value Metadata value.
	 * @return void
	 */
	public function set_meta( string $key, mixed $value ): void {
		$this->metadata[ $key ] = $value;
	}

	/**
	 * Convert the media reference to an array.
	 *
	 * @return array<string, mixed> Array representation.
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'source_url'    => $this->source_url,
			'type'          => $this->type,
			'alt_text'      => $this->alt_text,
			'caption'       => $this->caption,
			'width'         => $this->width,
			'height'        => $this->height,
			'file_size'     => $this->file_size,
			'mime_type'     => $this->mime_type,
			'local_path'    => $this->local_path,
			'attachment_id' => $this->attachment_id,
			'metadata'      => $this->metadata,
		);
	}

	/**
	 * Create a MediaReference from an array.
	 *
	 * @param array<string, mixed> $data Reference data.
	 * @return self New MediaReference instance.
	 * @throws InvalidArgumentException If required data is missing.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['id'], $data['source_url'] ) ) {
			throw new InvalidArgumentException( 'Missing required fields: id, source_url' );
		}

		return new self(
			id: $data['id'],
			source_url: $data['source_url'],
			type: $data['type'] ?? self::TYPE_IMAGE,
			alt_text: $data['alt_text'] ?? null,
			caption: $data['caption'] ?? null,
			width: isset( $data['width'] ) ? (int) $data['width'] : null,
			height: isset( $data['height'] ) ? (int) $data['height'] : null,
			file_size: isset( $data['file_size'] ) ? (int) $data['file_size'] : null,
			mime_type: $data['mime_type'] ?? null,
			local_path: $data['local_path'] ?? null,
			attachment_id: isset( $data['attachment_id'] ) ? (int) $data['attachment_id'] : null,
			metadata: $data['metadata'] ?? array(),
		);
	}

	/**
	 * Create a MediaReference from a URL.
	 *
	 * Auto-detects type from URL extension.
	 *
	 * @param string $url The media URL.
	 * @return self New MediaReference instance.
	 */
	public static function from_url( string $url ): self {
		$id   = md5( $url );
		$type = self::detect_type_from_url( $url );

		return new self(
			id: $id,
			source_url: $url,
			type: $type
		);
	}

	/**
	 * Detect media type from URL.
	 *
	 * @param string $url The URL to analyze.
	 * @return string The detected media type.
	 */
	public static function detect_type_from_url( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( false === $path || null === $path ) {
			return self::TYPE_IMAGE;
		}

		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		if ( ! is_string( $extension ) || empty( $extension ) ) {
			return self::TYPE_IMAGE;
		}

		$extension = strtolower( $extension );

		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp' );
		$video_extensions = array( 'mp4', 'webm', 'mov', 'avi', 'wmv', 'flv', 'mkv' );
		$audio_extensions = array( 'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac' );
		$doc_extensions   = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx' );

		if ( in_array( $extension, $image_extensions, true ) ) {
			return self::TYPE_IMAGE;
		}

		if ( in_array( $extension, $video_extensions, true ) ) {
			return self::TYPE_VIDEO;
		}

		if ( in_array( $extension, $audio_extensions, true ) ) {
			return self::TYPE_AUDIO;
		}

		if ( in_array( $extension, $doc_extensions, true ) ) {
			return self::TYPE_DOCUMENT;
		}

		// Default to image for unknown types.
		return self::TYPE_IMAGE;
	}

	/**
	 * Get the file extension from the source URL.
	 *
	 * @return string|null The file extension or null.
	 */
	public function get_extension(): ?string {
		$path = wp_parse_url( $this->source_url, PHP_URL_PATH );
		if ( false === $path || null === $path || empty( $path ) ) {
			return null;
		}

		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		if ( ! is_string( $extension ) || empty( $extension ) ) {
			return null;
		}

		return strtolower( $extension );
	}

	/**
	 * Get the filename from the source URL.
	 *
	 * @return string|null The filename or null.
	 */
	public function get_filename(): ?string {
		$path = wp_parse_url( $this->source_url, PHP_URL_PATH );
		if ( false === $path || null === $path || empty( $path ) ) {
			return null;
		}

		return basename( $path );
	}
}
