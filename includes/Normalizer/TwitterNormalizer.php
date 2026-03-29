<?php
/**
 * Twitter content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Twitter tweet data into NormalizedItem format.
 */
class TwitterNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string Adapter ID.
	 */
	public function get_adapter_id(): string {
		return 'twitter';
	}

	/**
	 * Normalize a raw tweet into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw tweet data from the adapter.
	 * @return NormalizedItem The normalized item.
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$full_text = $raw_item['full_text'] ?? $raw_item['text'] ?? '';
		$tweet_id  = (string) ( $raw_item['id'] ?? $raw_item['id_str'] ?? '' );
		$account   = $raw_item['_account'] ?? array();
		$username  = $account['username'] ?? '';

		// Expand t.co URLs in the text.
		$expanded_text = $this->expand_urls( $full_text, $raw_item );

		// Convert plain text to HTML.
		$content = $this->text_to_html( $expanded_text );

		// Clean the HTML.
		$content = $this->clean_content( $content );

		// Extract media.
		$media = $this->extract_tweet_media( $raw_item );

		// Extract hashtags from original text.
		$tags = $this->extract_hashtags( $full_text );

		// Determine content type.
		$content_type = $this->determine_tweet_type( $raw_item );

		// Build source URL.
		$source_url = null;
		if ( ! empty( $username ) && ! empty( $tweet_id ) ) {
			$source_url = sprintf( 'https://x.com/%s/status/%s', $username, $tweet_id );
		}

		// Extract engagement.
		$engagement = $this->extract_engagement( $raw_item );

		// Parse date.
		$date_string  = $raw_item['created_at'] ?? '';
		$publish_date = ! empty( $date_string ) ? $this->convert_date( $date_string ) : new \DateTimeImmutable();

		// Author info.
		$author_name = $account['accountDisplayName'] ?? $username;
		$author_url  = ! empty( $username ) ? 'https://x.com/' . $username : null;

		// Reply parent.
		$parent_id   = null;
		$in_reply_to = $raw_item['in_reply_to_status_id'] ?? $raw_item['in_reply_to_status_id_str'] ?? null;
		if ( ! empty( $in_reply_to ) ) {
			$parent_id = (string) $in_reply_to;
		}

		return new NormalizedItem(
			source_id: $tweet_id,
			source_adapter: 'twitter',
			content_type: $content_type,
			content: $content,
			publish_date: $publish_date,
			title: null,
			source_url: $source_url,
			media: $media,
			metadata: array(
				'username'         => $username,
				'in_reply_to_user' => $raw_item['in_reply_to_screen_name'] ?? null,
				'lang'             => $raw_item['lang'] ?? null,
				'source'           => $raw_item['source'] ?? null,
			),
			engagement: $engagement,
			author_name: $author_name,
			author_url: $author_url,
			parent_id: $parent_id,
			tags: $tags,
		);
	}

	/**
	 * Expand t.co URLs in tweet text using the entities data.
	 *
	 * @param string               $text     Tweet text.
	 * @param array<string, mixed> $raw_item Raw tweet data.
	 * @return string Text with expanded URLs.
	 */
	private function expand_urls( string $text, array $raw_item ): string {
		$url_entities = $raw_item['entities']['urls'] ?? array();

		foreach ( $url_entities as $url_entity ) {
			$short_url    = $url_entity['url'] ?? '';
			$expanded_url = $url_entity['expanded_url'] ?? $short_url;
			$display_url  = $url_entity['display_url'] ?? $expanded_url;

			if ( ! empty( $short_url ) && ! empty( $expanded_url ) ) {
				$text = str_replace( $short_url, $expanded_url, $text );
			}
		}

		// Remove media URLs from text (they'll be embedded as media).
		$media_entities = $raw_item['extended_entities']['media']
			?? $raw_item['entities']['media']
			?? array();

		foreach ( $media_entities as $media ) {
			$media_url = $media['url'] ?? '';
			if ( ! empty( $media_url ) ) {
				$text = str_replace( $media_url, '', $text );
			}
		}

		return trim( $text );
	}

	/**
	 * Extract media references from a tweet.
	 *
	 * @param array<string, mixed> $raw_item Raw tweet data.
	 * @return array<MediaReference> Media references.
	 */
	private function extract_tweet_media( array $raw_item ): array {
		$media_entries = $raw_item['extended_entities']['media']
			?? $raw_item['entities']['media']
			?? array();

		$references = array();

		foreach ( $media_entries as $media ) {
			$media_url = $media['media_url_https'] ?? $media['media_url'] ?? '';

			if ( empty( $media_url ) ) {
				continue;
			}

			$media_type = $media['type'] ?? 'photo';

			// Determine reference type.
			if ( 'video' === $media_type || 'animated_gif' === $media_type ) {
				// Use the best video variant URL.
				$video_url = $this->get_best_video_url( $media );
				if ( null !== $video_url ) {
					$references[] = new MediaReference(
						id: md5( $video_url ),
						source_url: $video_url,
						type: MediaReference::TYPE_VIDEO,
						alt_text: $media['ext_alt_text'] ?? null,
						width: ( (int) ( $media['sizes']['large']['w'] ?? 0 ) !== 0 ) ? (int) $media['sizes']['large']['w'] : null,
						height: ( (int) ( $media['sizes']['large']['h'] ?? 0 ) !== 0 ) ? (int) $media['sizes']['large']['h'] : null,
					);
				}
			} else {
				// Photo — use the original size.
				$references[] = new MediaReference(
					id: md5( $media_url ),
					source_url: $media_url . ':orig',
					type: MediaReference::TYPE_IMAGE,
					alt_text: $media['ext_alt_text'] ?? null,
					width: ( (int) ( $media['sizes']['large']['w'] ?? 0 ) !== 0 ) ? (int) $media['sizes']['large']['w'] : null,
					height: ( (int) ( $media['sizes']['large']['h'] ?? 0 ) !== 0 ) ? (int) $media['sizes']['large']['h'] : null,
				);
			}
		}

		return $references;
	}

	/**
	 * Get the best video variant URL from a media entry.
	 *
	 * @param array<string, mixed> $media Media entry from tweet entities.
	 * @return string|null Best video URL.
	 */
	private function get_best_video_url( array $media ): ?string {
		$variants = $media['video_info']['variants'] ?? array();

		$best_url     = null;
		$best_bitrate = -1;

		foreach ( $variants as $variant ) {
			if ( 'video/mp4' !== ( $variant['content_type'] ?? '' ) ) {
				continue;
			}

			$bitrate = (int) ( $variant['bitrate'] ?? 0 );
			if ( $bitrate > $best_bitrate ) {
				$best_bitrate = $bitrate;
				$best_url     = $variant['url'] ?? null;
			}
		}

		return $best_url;
	}

	/**
	 * Determine the content type of a tweet.
	 *
	 * @param array<string, mixed> $raw_item Raw tweet data.
	 * @return ContentType The content type.
	 */
	private function determine_tweet_type( array $raw_item ): ContentType {
		$full_text = $raw_item['full_text'] ?? $raw_item['text'] ?? '';

		// Retweet detection.
		if ( isset( $raw_item['retweeted_status'] ) || str_starts_with( $full_text, 'RT @' ) ) {
			return ContentType::REPOST;
		}

		// Reply detection.
		$in_reply_to = $raw_item['in_reply_to_status_id'] ?? $raw_item['in_reply_to_status_id_str'] ?? null;
		if ( ! empty( $in_reply_to ) ) {
			return ContentType::REPLY;
		}

		return ContentType::POST;
	}
}
