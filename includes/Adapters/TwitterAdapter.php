<?php
/**
 * Twitter/X archive adapter.
 *
 * Parses a Twitter/X data archive ZIP file and extracts tweets,
 * threads, replies, retweets, and media references.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters;

use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Schema\SettingsSchema;
use DateTimeImmutable;
use RuntimeException;
use WP_Error;

/**
 * Adapter for importing content from Twitter/X archive ZIP files.
 *
 * Archive format: ZIP containing data/tweets.js (and optionally
 * data/tweets-partN.js) with media in data/tweets_media/.
 */
class TwitterAdapter extends AbstractAdapter {

	/**
	 * Parsed tweets keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $tweets = null;

	/**
	 * Get the unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'twitter';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Twitter/X';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import tweets, threads, and media from your Twitter/X data archive.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-twitter';
	}

	/**
	 * Get the authentication type.
	 *
	 * @return string
	 */
	public function get_auth_type(): string {
		return self::AUTH_TYPE_FILE_UPLOAD;
	}

	/**
	 * Authenticate by processing an uploaded archive file.
	 *
	 * Expects $credentials['file'] to be a path to the uploaded ZIP,
	 * or $credentials['attachment_id'] to be a WP attachment ID.
	 *
	 * @param array<string, mixed> $credentials Must contain 'file' path or 'attachment_id'.
	 * @return bool True on success.
	 */
	public function authenticate( array $credentials ): bool {
		$file_path = $credentials['file'] ?? '';

		if ( ! empty( $credentials['attachment_id'] ) ) {
			$file_path = get_attached_file( (int) $credentials['attachment_id'] );
		}

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$this->log_error( 'Archive file not found.', $credentials );
			return false;
		}

		// Validate the ZIP contains tweet data.
		$validation = $this->validate_archive( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Archive validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		// Clear any cached manifest so it rebuilds from the new file.
		$this->delete_cache( 'manifest' );
		$this->tweets = null;

		return true;
	}

	/**
	 * Fetch the content manifest from the archive.
	 *
	 * @return ContentManifest
	 * @throws RuntimeException If not authenticated or parsing fails.
	 */
	public function fetch_manifest(): ContentManifest {
		$this->ensure_authenticated();

		// Return cached manifest if available.
		$cached = $this->get_cache( 'manifest' );

		if ( $cached instanceof ContentManifest ) {
			return $cached;
		}

		$tweets   = $this->get_tweets();
		$manifest = new ContentManifest( $this->get_id() );

		// Build a lookup of tweet IDs for thread/reply detection.
		// Cast to strings because PHP converts large numeric array keys to integers.
		$tweet_ids = array_map( 'strval', array_keys( $tweets ) );

		foreach ( $tweets as $id => $tweet ) {
			$type      = $this->classify_tweet( $tweet, $tweet_ids );
			$title     = $this->get_tweet_title( $tweet );
			$excerpt   = $this->get_tweet_excerpt( $tweet );
			$date      = $this->parse_tweet_date( $tweet['created_at'] ?? '' );
			$media     = $this->extract_media_urls( $tweet );
			$parent_id = $this->get_parent_id( $tweet );
			$metadata  = $this->extract_metadata( $tweet );

			$item = new ManifestItem(
				id: $id,
				type: $type,
				title: $title,
				created_at: $date,
				excerpt: $excerpt,
				media_urls: $media,
				metadata: $metadata,
				parent_id: $parent_id,
				original_url: $this->get_tweet_url( $tweet ),
				author: $this->get_author_info( $tweet ),
			);

			$manifest->add_item( $item );
		}

		$this->set_cache( 'manifest', $manifest, 86400 );

		return $manifest;
	}

	/**
	 * Fetch a single tweet by ID with full content.
	 *
	 * @param string $item_id Tweet ID.
	 * @return array<string, mixed> Tweet data.
	 * @throws RuntimeException If not authenticated or tweet not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$tweets = $this->get_tweets();

		if ( ! isset( $tweets[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: tweet ID */
					__( 'Tweet with ID "%s" not found in archive.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$tweet      = $tweets[ $item_id ];
		$media_urls = $this->extract_media_urls( $tweet );

		// Resolve local media paths from the archive.
		$media_paths = $this->resolve_media_paths( $tweet );

		return array(
			'id'           => $item_id,
			'type'         => $this->classify_tweet( $tweet, array_map( 'strval', array_keys( $tweets ) ) )->value,
			'content'      => $tweet['full_text'] ?? '',
			'title'        => $this->get_tweet_title( $tweet ),
			'created_at'   => $this->parse_tweet_date( $tweet['created_at'] ?? '' )->format( 'c' ),
			'media_urls'   => $media_urls,
			'media_paths'  => $media_paths,
			'metadata'     => $this->extract_metadata( $tweet ),
			'parent_id'    => $this->get_parent_id( $tweet ),
			'original_url' => $this->get_tweet_url( $tweet ),
			'raw'          => $tweet,
		);
	}

	/**
	 * Get supported content types.
	 *
	 * @return array<string>
	 */
	public function get_supported_content_types(): array {
		return array(
			ContentType::POST->value,
			ContentType::THREAD->value,
			ContentType::REPLY->value,
			ContentType::REPOST->value,
			ContentType::MEDIA->value,
		);
	}

	/**
	 * Build the settings schema.
	 *
	 * @return SettingsSchema
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();

		$schema->add_field(
			'archive_file',
			array(
				'type'        => 'file',
				'label'       => __( 'Twitter/X Archive', 'ai-importer' ),
				'description' => __( 'Upload your Twitter/X data archive ZIP file. You can request this from Settings > Your Account > Download an archive of your data on X.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		return $schema;
	}

	/**
	 * Validate that a ZIP file is a valid Twitter archive.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_archive( string $file_path ): bool|WP_Error {
		$zip = new \ZipArchive();
		$res = $zip->open( $file_path );

		if ( true !== $res ) {
			return new WP_Error(
				'invalid_zip',
				__( 'The uploaded file is not a valid ZIP archive.', 'ai-importer' )
			);
		}

		// Look for tweets.js or data/tweets.js in the archive.
		$found = false;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( preg_match( '#(?:^|/)data/tweets\.js$#', $name )
				|| preg_match( '#(?:^|/)data/tweets-part\d+\.js$#', $name )
			) {
				$found = true;
				break;
			}
		}

		$zip->close();

		if ( ! $found ) {
			return new WP_Error(
				'missing_tweets',
				__( 'This ZIP file does not appear to be a Twitter/X archive. Could not find data/tweets.js.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Get all parsed tweets from the archive, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Tweets keyed by ID.
	 * @throws RuntimeException If archive cannot be read.
	 */
	private function get_tweets(): array {
		if ( null !== $this->tweets ) {
			return $this->tweets;
		}

		$credentials = $this->get_stored_credentials();
		$file_path   = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				__( 'Twitter archive file not found. Please re-upload your archive.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->tweets = $this->parse_archive( $file_path );

		return $this->tweets;
	}

	/**
	 * Parse the Twitter archive ZIP and extract all tweets.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return array<string, array<string, mixed>> Tweets keyed by ID.
	 * @throws RuntimeException If parsing fails.
	 */
	private function parse_archive( string $file_path ): array {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				__( 'Failed to open Twitter archive ZIP file.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$tweets      = array();
		$tweet_files = array();

		// Collect all tweet data files.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( preg_match( '#(?:^|/)data/tweets(?:-part\d+)?\.js$#', $name ) ) {
				$tweet_files[] = $name;
			}
		}

		foreach ( $tweet_files as $tweet_file ) {
			$content = $zip->getFromName( $tweet_file );

			if ( false === $content ) {
				$this->log_error( 'Failed to read tweet file from archive.', array( 'file' => $tweet_file ) );
				continue;
			}

			$parsed = $this->parse_js_data( $content );

			foreach ( $parsed as $entry ) {
				$tweet = $entry['tweet'] ?? $entry;
				$id    = $tweet['id_str'] ?? $tweet['id'] ?? null;

				if ( $id ) {
					$tweets[ $id ] = $tweet;
				}
			}
		}

		$zip->close();

		if ( empty( $tweets ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				__( 'No tweets found in the archive. The file may be empty or in an unsupported format.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $tweets;
	}

	/**
	 * Parse a Twitter archive JS data file.
	 *
	 * Twitter archive files use the format:
	 * window.YTD.tweets.part0 = [{...}, {...}]
	 *
	 * @param string $content Raw file content.
	 * @return array<int, array<string, mixed>> Parsed data array.
	 */
	private function parse_js_data( string $content ): array {
		// Strip the JavaScript variable assignment wrapper.
		// Matches: window.YTD.tweets.part0 = [...]
		// Also handles: window.YTD.tweet.part0 = [...].
		$json = preg_replace( '/^window\.YTD\.\w+\.part\d+\s*=\s*/', '', $content, 1 );

		if ( null === $json ) {
			return array();
		}

		$json = trim( $json );

		// Remove trailing semicolon if present.
		$json = rtrim( $json, ';' );

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			$this->log_error( 'Failed to parse tweet JS data.', array( 'json_error' => json_last_error_msg() ) );
			return array();
		}

		return $data;
	}

	/**
	 * Classify a tweet into a ContentType.
	 *
	 * @param array<string, mixed> $tweet     Tweet data.
	 * @param array<string>        $tweet_ids All tweet IDs in the archive for thread detection.
	 * @return ContentType
	 */
	private function classify_tweet( array $tweet, array $tweet_ids ): ContentType {
		$full_text = $tweet['full_text'] ?? '';

		// Retweet: starts with "RT @".
		if ( str_starts_with( $full_text, 'RT @' ) ) {
			return ContentType::REPOST;
		}

		// Reply: has in_reply_to_status_id.
		$reply_to = $tweet['in_reply_to_status_id_str'] ?? $tweet['in_reply_to_status_id'] ?? null;

		if ( $reply_to ) {
			// Self-reply within the archive is a thread.
			$reply_to_user = $tweet['in_reply_to_user_id_str'] ?? $tweet['in_reply_to_user_id'] ?? null;
			$user_id       = $tweet['user_id_str'] ?? $tweet['user_id'] ?? null;

			// If replying to self and the parent is in the archive, it's a thread.
			if ( $reply_to_user && $user_id && $reply_to_user === $user_id && in_array( (string) $reply_to, $tweet_ids, true ) ) {
				return ContentType::THREAD;
			}

			return ContentType::REPLY;
		}

		// Media-only: has media entities but minimal text.
		$has_media = ! empty( $tweet['entities']['media'] ) || ! empty( $tweet['extended_entities']['media'] );
		$text_only = preg_replace( '/https?:\/\/\S+/', '', $full_text );
		$text_only = trim( $text_only ?? '' );

		if ( $has_media && strlen( $text_only ) < 10 ) {
			return ContentType::MEDIA;
		}

		return ContentType::POST;
	}

	/**
	 * Get a title for a tweet (first line or truncated text).
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return string
	 */
	private function get_tweet_title( array $tweet ): string {
		$text = $tweet['full_text'] ?? '';

		// Strip t.co URLs for a cleaner title.
		$text = preg_replace( '/https?:\/\/t\.co\/\S+/', '', $text );
		$text = trim( $text ?? '' );

		if ( empty( $text ) ) {
			return sprintf(
				/* translators: %s: tweet ID */
				__( 'Tweet %s', 'ai-importer' ),
				$tweet['id_str'] ?? $tweet['id'] ?? ''
			);
		}

		// Use first line, truncated to 100 chars.
		$first_line = strtok( $text, "\n" );

		if ( mb_strlen( $first_line ) > 100 ) {
			$first_line = mb_substr( $first_line, 0, 97 ) . '...';
		}

		return $first_line;
	}

	/**
	 * Get an excerpt for a tweet.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return string|null
	 */
	private function get_tweet_excerpt( array $tweet ): ?string {
		$text = $tweet['full_text'] ?? '';

		if ( mb_strlen( $text ) <= 100 ) {
			return null;
		}

		return $text;
	}

	/**
	 * Parse a Twitter date string into a DateTimeImmutable.
	 *
	 * Twitter uses the format: "Mon Jan 15 10:15:00 +0000 2024"
	 *
	 * @param string $date_string Twitter date string.
	 * @return DateTimeImmutable
	 */
	private function parse_tweet_date( string $date_string ): DateTimeImmutable {
		if ( empty( $date_string ) ) {
			return new DateTimeImmutable();
		}

		// Twitter archive format: "Mon Jan 15 10:15:00 +0000 2024".
		$date = DateTimeImmutable::createFromFormat( 'D M d H:i:s O Y', $date_string );

		if ( false === $date ) {
			// Try ISO 8601 as fallback.
			$date = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s.u\Z', $date_string );
		}

		if ( false === $date ) {
			// Last resort: let PHP try to parse it.
			try {
				$date = new DateTimeImmutable( $date_string );
			} catch ( \Exception $e ) {
				$date = new DateTimeImmutable();
			}
		}

		return $date;
	}

	/**
	 * Extract media URLs from a tweet.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<string> Media URLs.
	 */
	private function extract_media_urls( array $tweet ): array {
		$urls = array();

		// Extended entities contain the most complete media info.
		$media_entities = $tweet['extended_entities']['media']
			?? $tweet['entities']['media']
			?? array();

		foreach ( $media_entities as $media ) {
			if ( ! empty( $media['media_url_https'] ) ) {
				$urls[] = $media['media_url_https'];
			} elseif ( ! empty( $media['media_url'] ) ) {
				$urls[] = $media['media_url'];
			}

			// Video variants.
			if ( ! empty( $media['video_info']['variants'] ) ) {
				$best_video = $this->get_best_video_variant( $media['video_info']['variants'] );

				if ( $best_video ) {
					$urls[] = $best_video;
				}
			}
		}

		return array_unique( $urls );
	}

	/**
	 * Get the highest quality video variant URL.
	 *
	 * @param array<int, array<string, mixed>> $variants Video variants.
	 * @return string|null Best video URL or null.
	 */
	private function get_best_video_variant( array $variants ): ?string {
		$best_bitrate = -1;
		$best_url     = null;

		foreach ( $variants as $variant ) {
			// Skip non-MP4 variants (like m3u8 playlists).
			if ( ! empty( $variant['content_type'] ) && 'video/mp4' !== $variant['content_type'] ) {
				continue;
			}

			$bitrate = (int) ( $variant['bitrate'] ?? 0 );

			if ( $bitrate > $best_bitrate && ! empty( $variant['url'] ) ) {
				$best_bitrate = $bitrate;
				$best_url     = $variant['url'];
			}
		}

		return $best_url;
	}

	/**
	 * Resolve local media file paths within the archive.
	 *
	 * Twitter archives store media in data/tweets_media/ with filenames
	 * like {tweet_id}-{media_filename}.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<string> Media file paths within the ZIP.
	 */
	private function resolve_media_paths( array $tweet ): array {
		$paths    = array();
		$tweet_id = $tweet['id_str'] ?? $tweet['id'] ?? '';

		$media_entities = $tweet['extended_entities']['media']
			?? $tweet['entities']['media']
			?? array();

		foreach ( $media_entities as $media ) {
			$media_url = $media['media_url_https'] ?? $media['media_url'] ?? '';

			if ( empty( $media_url ) ) {
				continue;
			}

			// Extract filename from URL.
			$filename = wp_basename( $media_url );
			$paths[]  = "data/tweets_media/{$tweet_id}-{$filename}";
		}

		return $paths;
	}

	/**
	 * Get the parent tweet ID for replies/threads.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return string|null Parent tweet ID or null.
	 */
	private function get_parent_id( array $tweet ): ?string {
		$reply_to = $tweet['in_reply_to_status_id_str'] ?? $tweet['in_reply_to_status_id'] ?? null;

		return $reply_to ? (string) $reply_to : null;
	}

	/**
	 * Extract platform-specific metadata from a tweet.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<string, mixed>
	 */
	private function extract_metadata( array $tweet ): array {
		$metadata = array(
			'source'         => wp_strip_all_tags( $tweet['source'] ?? '' ),
			'lang'           => $tweet['lang'] ?? null,
			'retweet_count'  => (int) ( $tweet['retweet_count'] ?? 0 ),
			'favorite_count' => (int) ( $tweet['favorite_count'] ?? 0 ),
		);

		// Hashtags.
		$hashtags = $tweet['entities']['hashtags'] ?? array();

		if ( ! empty( $hashtags ) ) {
			$metadata['hashtags'] = array_map(
				fn( array $h ) => $h['text'],
				$hashtags
			);
		}

		// User mentions.
		$mentions = $tweet['entities']['user_mentions'] ?? array();

		if ( ! empty( $mentions ) ) {
			$metadata['mentions'] = array_map(
				fn( array $m ) => $m['screen_name'],
				$mentions
			);
		}

		// URLs.
		$urls = $tweet['entities']['urls'] ?? array();

		if ( ! empty( $urls ) ) {
			$metadata['urls'] = array_map(
				fn( array $u ) => $u['expanded_url'] ?? $u['url'],
				$urls
			);
		}

		return $metadata;
	}

	/**
	 * Get the original tweet URL.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return string|null Tweet URL or null.
	 */
	private function get_tweet_url( array $tweet ): ?string {
		$id          = $tweet['id_str'] ?? $tweet['id'] ?? null;
		$screen_name = $tweet['user_screen_name']
			?? $tweet['entities']['user_mentions'][0]['screen_name']
			?? null;

		if ( ! $id ) {
			return null;
		}

		// Archive doesn't always have screen_name; use 'i' path which works for any user.
		if ( ! $screen_name ) {
			return "https://x.com/i/status/{$id}";
		}

		return "https://x.com/{$screen_name}/status/{$id}";
	}

	/**
	 * Get author information from a tweet.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<string, string>|null Author info or null.
	 */
	private function get_author_info( array $tweet ): ?array {
		$screen_name = $tweet['user_screen_name'] ?? null;
		$name        = $tweet['user_name'] ?? null;

		if ( ! $screen_name && ! $name ) {
			return null;
		}

		$result = array_filter(
			array(
				'username' => $screen_name,
				'name'     => $name,
			)
		);

		return ! empty( $result ) ? $result : null;
	}
}
