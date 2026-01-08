<?php
/**
 * Normalized item class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Represents normalized content in a universal intermediate format.
 *
 * This is the standard format that all platform-specific content is
 * converted to before being imported into WordPress.
 */
class NormalizedItem {

	/**
	 * Unique identifier from the source platform.
	 *
	 * @var string
	 */
	public string $source_id;

	/**
	 * ID of the adapter that produced this item.
	 *
	 * @var string
	 */
	public string $source_adapter;

	/**
	 * Content type.
	 *
	 * @var ContentType
	 */
	public ContentType $content_type;

	/**
	 * Sanitized HTML content.
	 *
	 * @var string
	 */
	public string $content;

	/**
	 * Content title (optional for social media posts).
	 *
	 * @var string|null
	 */
	public ?string $title;

	/**
	 * Original publish date.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $publish_date;

	/**
	 * URL to original content on source platform.
	 *
	 * @var string|null
	 */
	public ?string $source_url;

	/**
	 * Media references.
	 *
	 * @var array<MediaReference>
	 */
	public array $media;

	/**
	 * Platform-specific metadata (preserved for reference).
	 *
	 * @var array<string, mixed>
	 */
	public array $metadata;

	/**
	 * Engagement metrics (likes, shares, comments, etc.).
	 *
	 * @var array<string, int>
	 */
	public array $engagement;

	/**
	 * Author display name.
	 *
	 * @var string|null
	 */
	public ?string $author_name;

	/**
	 * Author profile URL.
	 *
	 * @var string|null
	 */
	public ?string $author_url;

	/**
	 * Parent item ID (for replies, thread items).
	 *
	 * @var string|null
	 */
	public ?string $parent_id;

	/**
	 * Tags/hashtags extracted from content.
	 *
	 * @var array<string>
	 */
	public array $tags;

	/**
	 * Constructor.
	 *
	 * @param string                $source_id      Source platform ID.
	 * @param string                $source_adapter Adapter ID.
	 * @param ContentType           $content_type   Content type.
	 * @param string                $content        HTML content.
	 * @param DateTimeImmutable     $publish_date   Publish date.
	 * @param string|null           $title          Title.
	 * @param string|null           $source_url     Source URL.
	 * @param array<MediaReference> $media          Media references.
	 * @param array<string, mixed>  $metadata       Metadata.
	 * @param array<string, int>    $engagement     Engagement metrics.
	 * @param string|null           $author_name    Author name.
	 * @param string|null           $author_url     Author URL.
	 * @param string|null           $parent_id      Parent item ID.
	 * @param array<string>         $tags           Tags.
	 */
	public function __construct(
		string $source_id,
		string $source_adapter,
		ContentType $content_type,
		string $content,
		DateTimeImmutable $publish_date,
		?string $title = null,
		?string $source_url = null,
		array $media = array(),
		array $metadata = array(),
		array $engagement = array(),
		?string $author_name = null,
		?string $author_url = null,
		?string $parent_id = null,
		array $tags = array()
	) {
		$this->source_id      = $source_id;
		$this->source_adapter = $source_adapter;
		$this->content_type   = $content_type;
		$this->content        = $content;
		$this->publish_date   = $publish_date;
		$this->title          = $title;
		$this->source_url     = $source_url;
		$this->media          = $media;
		$this->metadata       = $metadata;
		$this->engagement     = $engagement;
		$this->author_name    = $author_name;
		$this->author_url     = $author_url;
		$this->parent_id      = $parent_id;
		$this->tags           = $tags;
	}

	/**
	 * Check if the item has media.
	 *
	 * @return bool True if media exists.
	 */
	public function has_media(): bool {
		return ! empty( $this->media );
	}

	/**
	 * Get the count of media items.
	 *
	 * @return int Number of media items.
	 */
	public function get_media_count(): int {
		return count( $this->media );
	}

	/**
	 * Get media items of a specific type.
	 *
	 * @param string $type Media type (image, video, etc.).
	 * @return array<MediaReference> Filtered media items.
	 */
	public function get_media_by_type( string $type ): array {
		return array_filter(
			$this->media,
			fn( MediaReference $ref ) => $ref->type === $type
		);
	}

	/**
	 * Get image media references.
	 *
	 * @return array<MediaReference> Image references.
	 */
	public function get_images(): array {
		return $this->get_media_by_type( MediaReference::TYPE_IMAGE );
	}

	/**
	 * Get video media references.
	 *
	 * @return array<MediaReference> Video references.
	 */
	public function get_videos(): array {
		return $this->get_media_by_type( MediaReference::TYPE_VIDEO );
	}

	/**
	 * Add a media reference.
	 *
	 * @param MediaReference $media_ref The media reference to add.
	 * @return void
	 */
	public function add_media( MediaReference $media_ref ): void {
		$this->media[] = $media_ref;
	}

	/**
	 * Get word count of the content.
	 *
	 * @return int Number of words.
	 */
	public function get_word_count(): int {
		$text = wp_strip_all_tags( $this->content );
		return str_word_count( $text );
	}

	/**
	 * Get character count of the content.
	 *
	 * @return int Number of characters.
	 */
	public function get_character_count(): int {
		$text = wp_strip_all_tags( $this->content );
		return mb_strlen( $text );
	}

	/**
	 * Get an excerpt of the content.
	 *
	 * @param int $length Maximum character length.
	 * @return string The excerpt.
	 */
	public function get_excerpt( int $length = 150 ): string {
		$text = wp_strip_all_tags( $this->content );
		$text = trim( $text );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		// Find a word boundary to cut at.
		$text       = mb_substr( $text, 0, $length );
		$last_space = mb_strrpos( $text, ' ' );

		if ( false !== $last_space && $last_space > $length * 0.8 ) {
			$text = mb_substr( $text, 0, $last_space );
		}

		return $text . '...';
	}

	/**
	 * Check if this is a reply to another item.
	 *
	 * @return bool True if this is a reply.
	 */
	public function is_reply(): bool {
		return null !== $this->parent_id;
	}

	/**
	 * Get engagement metric by key.
	 *
	 * @param string $key           Metric key (likes, shares, etc.).
	 * @param int    $default_value Default value.
	 * @return int The metric value.
	 */
	public function get_engagement( string $key, int $default_value = 0 ): int {
		return $this->engagement[ $key ] ?? $default_value;
	}

	/**
	 * Get total engagement (sum of all metrics).
	 *
	 * @return int Total engagement count.
	 */
	public function get_total_engagement(): int {
		return array_sum( $this->engagement );
	}

	/**
	 * Get metadata value by key.
	 *
	 * @param string $key           Metadata key.
	 * @param mixed  $default_value Default value.
	 * @return mixed The metadata value.
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
	 * Generate a title from content if none exists.
	 *
	 * @param int $max_length Maximum title length.
	 * @return string The generated title.
	 */
	public function generate_title( int $max_length = 60 ): string {
		if ( ! empty( $this->title ) ) {
			return $this->title;
		}

		return $this->get_excerpt( $max_length );
	}

	/**
	 * Check if the item has tags.
	 *
	 * @return bool True if tags exist.
	 */
	public function has_tags(): bool {
		return ! empty( $this->tags );
	}

	/**
	 * Convert the normalized item to an array.
	 *
	 * @return array<string, mixed> Array representation.
	 */
	public function to_array(): array {
		return array(
			'source_id'      => $this->source_id,
			'source_adapter' => $this->source_adapter,
			'content_type'   => $this->content_type->value,
			'content'        => $this->content,
			'title'          => $this->title,
			'publish_date'   => $this->publish_date->format( 'c' ),
			'source_url'     => $this->source_url,
			'media'          => array_map(
				fn( MediaReference $ref ) => $ref->to_array(),
				$this->media
			),
			'metadata'       => $this->metadata,
			'engagement'     => $this->engagement,
			'author_name'    => $this->author_name,
			'author_url'     => $this->author_url,
			'parent_id'      => $this->parent_id,
			'tags'           => $this->tags,
		);
	}

	/**
	 * Create a NormalizedItem from an array.
	 *
	 * @param array<string, mixed> $data Item data.
	 * @return self New NormalizedItem instance.
	 * @throws InvalidArgumentException If required data is missing.
	 */
	public static function from_array( array $data ): self {
		$required = array( 'source_id', 'source_adapter', 'content_type', 'content', 'publish_date' );
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
				throw new InvalidArgumentException( "Missing required field: {$field}" );
			}
		}

		$publish_date = $data['publish_date'] instanceof DateTimeImmutable
			? $data['publish_date']
			: new DateTimeImmutable( $data['publish_date'] );

		$content_type = $data['content_type'] instanceof ContentType
			? $data['content_type']
			: ContentType::from( $data['content_type'] );

		$media = array();
		if ( isset( $data['media'] ) && is_array( $data['media'] ) ) {
			foreach ( $data['media'] as $media_data ) {
				$media[] = $media_data instanceof MediaReference
					? $media_data
					: MediaReference::from_array( $media_data );
			}
		}

		return new self(
			source_id: $data['source_id'],
			source_adapter: $data['source_adapter'],
			content_type: $content_type,
			content: $data['content'],
			publish_date: $publish_date,
			title: $data['title'] ?? null,
			source_url: $data['source_url'] ?? null,
			media: $media,
			metadata: $data['metadata'] ?? array(),
			engagement: $data['engagement'] ?? array(),
			author_name: $data['author_name'] ?? null,
			author_url: $data['author_url'] ?? null,
			parent_id: $data['parent_id'] ?? null,
			tags: $data['tags'] ?? array(),
		);
	}
}
