<?php
/**
 * Ghost content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Ghost data from GhostAdapter::fetch_item()
 * into the universal NormalizedItem format.
 *
 * Expected raw item shape from GhostAdapter:
 *   id, type, content, title, created_at, media_urls, metadata
 *   (slug, custom_excerpt, type, tags, authors), original_url,
 *   tags, author, raw
 *
 * All Ghost media URLs are absolute, so media references are built
 * straight from the URL list with no local_path handling.
 */
class GhostNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string
	 */
	public function get_adapter_id(): string {
		return 'ghost';
	}

	/**
	 * Normalize a raw Ghost item into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw item from GhostAdapter::fetch_item().
	 * @return NormalizedItem
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$content = $this->clean_content( (string) ( $raw_item['content'] ?? '' ) );

		$media_urls = $raw_item['media_urls'] ?? array();
		$media      = $this->extract_media_from_urls( is_array( $media_urls ) ? $media_urls : array() );

		$metadata = is_array( $raw_item['metadata'] ?? null ) ? $raw_item['metadata'] : array();

		$tags = $metadata['tags'] ?? $raw_item['tags'] ?? array();
		$tags = is_array( $tags ) ? array_values( $tags ) : array();

		$content_type = $this->resolve_content_type( $raw_item['type'] ?? 'post' );

		$author       = $this->extract_author( $raw_item );
		$created_at   = (string) ( $raw_item['created_at'] ?? '' );
		$publish_date = '' !== $created_at
			? $this->convert_date( $created_at )
			: new \DateTimeImmutable();

		return new NormalizedItem(
			source_id: (string) ( $raw_item['id'] ?? '' ),
			source_adapter: 'ghost',
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
