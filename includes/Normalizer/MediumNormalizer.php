<?php
/**
 * Medium content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Medium export data from MediumAdapter::fetch_item()
 * into the universal NormalizedItem format.
 *
 * Expected raw item shape from MediumAdapter:
 *   id, type, content, title, created_at, media_urls, metadata,
 *   original_url, tags, author, raw
 */
class MediumNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string
	 */
	public function get_adapter_id(): string {
		return 'medium';
	}

	/**
	 * Normalize a raw Medium item into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw item from MediumAdapter::fetch_item().
	 * @return NormalizedItem
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$content = $this->clean_content( (string) ( $raw_item['content'] ?? '' ) );

		$media_urls = $raw_item['media_urls'] ?? array();
		$media      = $this->extract_media_from_urls( is_array( $media_urls ) ? $media_urls : array() );

		$metadata = is_array( $raw_item['metadata'] ?? null ) ? $raw_item['metadata'] : array();
		$tags     = is_array( $raw_item['tags'] ?? null ) ? $raw_item['tags'] : array();

		$content_type = $this->resolve_content_type( $raw_item['type'] ?? 'post' );

		$author       = $this->extract_author( $raw_item );
		$created_at   = (string) ( $raw_item['created_at'] ?? '' );
		$publish_date = '' !== $created_at
			? $this->convert_date( $created_at )
			: new \DateTimeImmutable();

		return new NormalizedItem(
			source_id: (string) $raw_item['id'],
			source_adapter: 'medium',
			content_type: $content_type,
			content: $content,
			publish_date: $publish_date,
			title: $raw_item['title'] ?? null,
			source_url: $raw_item['original_url'] ?? null,
			media: $media,
			metadata: $metadata,
			engagement: array(),
			author_name: $author['name'],
			author_url: $author['url'],
			parent_id: null,
			tags: $tags,
		);
	}

	/**
	 * Resolve a content type string to the ContentType enum.
	 *
	 * @param string $type Content type string from the adapter.
	 * @return ContentType
	 */
	private function resolve_content_type( string $type ): ContentType {
		return ContentType::tryFrom( $type ) ?? ContentType::POST;
	}
}
