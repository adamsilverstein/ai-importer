<?php
/**
 * Manifest item class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters\Manifest;

use DateTimeImmutable;

/**
 * Represents a single content item in an import manifest.
 */
class ManifestItem {

	/**
	 * Unique identifier for the item on the source platform.
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Content type.
	 *
	 * @var ContentType
	 */
	public ContentType $type;

	/**
	 * Item title or first line of content.
	 *
	 * @var string
	 */
	public string $title;

	/**
	 * Content excerpt or summary.
	 *
	 * @var string|null
	 */
	public ?string $excerpt;

	/**
	 * Original creation date.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $created_at;

	/**
	 * Last update date.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $updated_at;

	/**
	 * URLs of associated media files.
	 *
	 * @var array<string>
	 */
	public array $media_urls;

	/**
	 * Platform-specific metadata.
	 *
	 * @var array<string, mixed>
	 */
	public array $metadata;

	/**
	 * Parent item ID (for replies, thread items, etc.).
	 *
	 * @var string|null
	 */
	public ?string $parent_id;

	/**
	 * Original URL of the content.
	 *
	 * @var string|null
	 */
	public ?string $original_url;

	/**
	 * Author information.
	 *
	 * @var array<string, string>|null
	 */
	public ?array $author;

	/**
	 * Constructor.
	 *
	 * @param string                     $id         Unique identifier.
	 * @param ContentType                $type       Content type.
	 * @param string                     $title      Title or first line.
	 * @param DateTimeImmutable          $created_at Creation date.
	 * @param string|null                $excerpt    Content excerpt.
	 * @param DateTimeImmutable|null     $updated_at Update date.
	 * @param array<string>              $media_urls Media URLs.
	 * @param array<string, mixed>       $metadata   Platform metadata.
	 * @param string|null                $parent_id  Parent item ID.
	 * @param string|null                $original_url Original URL.
	 * @param array<string, string>|null $author Author info.
	 */
	public function __construct(
		string $id,
		ContentType $type,
		string $title,
		DateTimeImmutable $created_at,
		?string $excerpt = null,
		?DateTimeImmutable $updated_at = null,
		array $media_urls = array(),
		array $metadata = array(),
		?string $parent_id = null,
		?string $original_url = null,
		?array $author = null
	) {
		$this->id           = $id;
		$this->type         = $type;
		$this->title        = $title;
		$this->created_at   = $created_at;
		$this->excerpt      = $excerpt;
		$this->updated_at   = $updated_at;
		$this->media_urls   = $media_urls;
		$this->metadata     = $metadata;
		$this->parent_id    = $parent_id;
		$this->original_url = $original_url;
		$this->author       = $author;
	}

	/**
	 * Check if the item has media attachments.
	 *
	 * @return bool True if media is attached.
	 */
	public function has_media(): bool {
		return ! empty( $this->media_urls );
	}

	/**
	 * Check if the item is a child of another item.
	 *
	 * @return bool True if item has a parent.
	 */
	public function has_parent(): bool {
		return null !== $this->parent_id;
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
	 * Convert the item to an array.
	 *
	 * @return array<string, mixed> Array representation.
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'type'         => $this->type->value,
			'title'        => $this->title,
			'excerpt'      => $this->excerpt,
			'created_at'   => $this->created_at->format( 'c' ),
			'updated_at'   => $this->updated_at?->format( 'c' ),
			'media_urls'   => $this->media_urls,
			'metadata'     => $this->metadata,
			'parent_id'    => $this->parent_id,
			'original_url' => $this->original_url,
			'author'       => $this->author,
		);
	}

	/**
	 * Create a ManifestItem from an array.
	 *
	 * @param array<string, mixed> $data Item data.
	 * @return self New ManifestItem instance.
	 * @throws \InvalidArgumentException If required data is missing.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['id'], $data['type'], $data['title'], $data['created_at'] ) ) {
			throw new \InvalidArgumentException( 'Missing required fields: id, type, title, created_at' );
		}

		$created_at = $data['created_at'] instanceof DateTimeImmutable
			? $data['created_at']
			: new DateTimeImmutable( $data['created_at'] );

		$updated_at = null;
		if ( isset( $data['updated_at'] ) ) {
			$updated_at = $data['updated_at'] instanceof DateTimeImmutable
				? $data['updated_at']
				: new DateTimeImmutable( $data['updated_at'] );
		}

		$type = $data['type'] instanceof ContentType
			? $data['type']
			: ContentType::from( $data['type'] );

		return new self(
			id: $data['id'],
			type: $type,
			title: $data['title'],
			created_at: $created_at,
			excerpt: $data['excerpt'] ?? null,
			updated_at: $updated_at,
			media_urls: $data['media_urls'] ?? array(),
			metadata: $data['metadata'] ?? array(),
			parent_id: $data['parent_id'] ?? null,
			original_url: $data['original_url'] ?? null,
			author: $data['author'] ?? null,
		);
	}
}
