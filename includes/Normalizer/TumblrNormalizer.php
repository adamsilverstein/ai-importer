<?php
/**
 * Tumblr content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Tumblr backup data from TumblrAdapter::fetch_item()
 * into the universal NormalizedItem format.
 *
 * Expected raw item shape from TumblrAdapter:
 *   id, type, content, title, created_at, media_urls, metadata,
 *   original_url, tags, author, raw
 *
 * The `metadata` array carries Tumblr-specific fields (`post_type`,
 * `is_reblog`, `reblog_from`, `source_title`) that downstream consumers
 * can use without needing to re-parse the raw export.
 */
class TumblrNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string
	 */
	public function get_adapter_id(): string {
		return 'tumblr';
	}

	/**
	 * Normalize a raw Tumblr item into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw item from TumblrAdapter::fetch_item().
	 * @return NormalizedItem
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$content = $this->clean_content( (string) ( $raw_item['content'] ?? '' ) );

		$media_urls = $raw_item['media_urls'] ?? array();
		$media      = $this->extract_media_from_urls( is_array( $media_urls ) ? $media_urls : array() );

		$metadata = is_array( $raw_item['metadata'] ?? null ) ? $raw_item['metadata'] : array();
		$tags     = is_array( $raw_item['tags'] ?? null ) ? $raw_item['tags'] : array();

		$content_type = $this->resolve_content_type( $raw_item, $metadata );

		$author       = $this->extract_author( $raw_item );
		$created_at   = (string) ( $raw_item['created_at'] ?? '' );
		$publish_date = '' !== $created_at
			? $this->convert_date( $created_at )
			: new \DateTimeImmutable();

		return new NormalizedItem(
			source_id: (string) ( $raw_item['id'] ?? '' ),
			source_adapter: 'tumblr',
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
	 * Resolve a content type for a Tumblr item.
	 *
	 * Reblogs always classify as REPOST so the original attribution is
	 * preserved for downstream consumers. Otherwise we trust the adapter's
	 * classification, falling back to POST when the value is unknown.
	 *
	 * @param array<string, mixed> $raw_item Raw item.
	 * @param array<string, mixed> $metadata Metadata array.
	 * @return ContentType
	 */
	private function resolve_content_type( array $raw_item, array $metadata ): ContentType {
		if ( ! empty( $metadata['is_reblog'] ) ) {
			return ContentType::REPOST;
		}

		$type = (string) ( $raw_item['type'] ?? 'post' );
		return ContentType::tryFrom( $type ) ?? ContentType::POST;
	}
}
