<?php
/**
 * Substack content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Substack export data from SubstackAdapter::fetch_item()
 * into the universal NormalizedItem format.
 *
 * Expected raw item shape from SubstackAdapter:
 *   id, type, content, title, created_at, media_urls, media_paths,
 *   metadata, original_url, author, raw
 *
 * `media_paths` is aligned by index with `media_urls` and carries the
 * absolute path of media extracted from the export ZIP (null for media
 * referenced by absolute http(s) URL, which is the common case since
 * Substack post HTML references the Substack CDN).
 *
 * The `metadata` array carries Substack-specific fields (`post_type`,
 * `subtitle`, `audience`, `podcast_url`, `email_sent_at`) that downstream
 * consumers can use without needing to re-parse the raw export. The
 * podcast audio URL is intentionally kept in metadata rather than as a
 * media reference.
 */
class SubstackNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string
	 */
	public function get_adapter_id(): string {
		return 'substack';
	}

	/**
	 * Normalize a raw Substack item into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw item from SubstackAdapter::fetch_item().
	 * @return NormalizedItem
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$content = $this->clean_content( (string) ( $raw_item['content'] ?? '' ) );

		$media_urls  = is_array( $raw_item['media_urls'] ?? null ) ? $raw_item['media_urls'] : array();
		$media_paths = is_array( $raw_item['media_paths'] ?? null ) ? $raw_item['media_paths'] : array();
		$media       = array();

		foreach ( $media_urls as $index => $url ) {
			$url = (string) $url;

			if ( '' === $url ) {
				continue;
			}

			$reference = MediaReference::from_url( $url );

			// Media extracted from the export ZIP sideloads from disk
			// instead of being downloaded from the (relative) source URL.
			if ( ! empty( $media_paths[ $index ] ) ) {
				$reference->local_path = (string) $media_paths[ $index ];
			}

			$media[] = $reference;
		}

		$metadata = is_array( $raw_item['metadata'] ?? null ) ? $raw_item['metadata'] : array();

		$type         = (string) ( $raw_item['type'] ?? 'post' );
		$content_type = ContentType::tryFrom( $type ) ?? ContentType::POST;

		$author       = $this->extract_author( $raw_item );
		$created_at   = (string) ( $raw_item['created_at'] ?? '' );
		$publish_date = '' !== $created_at
			? $this->convert_date( $created_at )
			: new \DateTimeImmutable();

		return new NormalizedItem(
			source_id: (string) ( $raw_item['id'] ?? '' ),
			source_adapter: 'substack',
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
			tags: array(),
		);
	}
}
