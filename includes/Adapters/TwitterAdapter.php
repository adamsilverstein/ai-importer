<?php
/**
 * Twitter/X adapter for importing from archive files.
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

/**
 * Adapter for importing Twitter/X archive data.
 *
 * Supports the official Twitter data export format (ZIP file containing
 * JavaScript data files).
 */
class TwitterAdapter extends AbstractAdapter {

	/**
	 * Parsed tweets data cache.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $tweets_data = null;

	/**
	 * Account data from the archive.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $account_data = null;

	/**
	 * Get the unique identifier for this adapter.
	 *
	 * @return string Adapter ID.
	 */
	public function get_id(): string {
		return 'twitter';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string Adapter name.
	 */
	public function get_name(): string {
		return __( 'Twitter/X', 'ai-importer' );
	}

	/**
	 * Get a description.
	 *
	 * @return string Description.
	 */
	public function get_description(): string {
		return __( 'Import tweets, threads, and media from your Twitter/X data archive.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string Dashicon class.
	 */
	public function get_icon(): string {
		return 'dashicons-twitter';
	}

	/**
	 * Get the authentication type.
	 *
	 * @return string Auth type.
	 */
	public function get_auth_type(): string {
		return self::AUTH_TYPE_FILE_UPLOAD;
	}

	/**
	 * Authenticate by processing an uploaded archive file.
	 *
	 * @param array<string, mixed> $credentials Must contain 'archive_path' pointing to the uploaded ZIP.
	 * @return bool True on success.
	 */
	public function authenticate( array $credentials ): bool {
		if ( empty( $credentials['archive_path'] ) ) {
			return false;
		}

		$archive_path = $credentials['archive_path'];

		if ( ! file_exists( $archive_path ) ) {
			return false;
		}

		$extract_dir = $this->extract_archive( $archive_path );

		if ( null === $extract_dir ) {
			return false;
		}

		$this->store_credentials(
			array(
				'archive_path' => $archive_path,
				'extract_dir'  => $extract_dir,
			)
		);

		return true;
	}

	/**
	 * Disconnect and clean up extracted files.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$credentials = $this->get_stored_credentials();

		if ( ! empty( $credentials['extract_dir'] ) ) {
			$this->delete_directory( $credentials['extract_dir'] );
		}

		$this->tweets_data  = null;
		$this->account_data = null;
		$this->clear_credentials();
		$this->delete_cache( 'manifest' );
	}

	/**
	 * Fetch the content manifest from the Twitter archive.
	 *
	 * @return ContentManifest The content manifest.
	 * @throws RuntimeException If not authenticated or archive is invalid.
	 */
	public function fetch_manifest(): ContentManifest {
		$this->ensure_authenticated();

		$cached = $this->get_cache( 'manifest' );
		if ( false !== $cached && is_array( $cached ) ) {
			return ContentManifest::from_array( $cached );
		}

		$tweets   = $this->get_tweets_data();
		$account  = $this->get_account_data();
		$manifest = new ContentManifest( $this->get_id() );

		$username = $account['username'] ?? '';

		// Index tweets by ID for thread detection.
		$tweet_ids = array();
		foreach ( $tweets as $tweet_data ) {
			$tweet = $tweet_data['tweet'] ?? $tweet_data;
			$tweet_ids[ $tweet['id'] ?? $tweet['id_str'] ?? '' ] = true;
		}

		foreach ( $tweets as $tweet_data ) {
			$tweet    = $tweet_data['tweet'] ?? $tweet_data;
			$tweet_id = $tweet['id'] ?? $tweet['id_str'] ?? '';

			if ( empty( $tweet_id ) ) {
				continue;
			}

			$full_text  = $tweet['full_text'] ?? $tweet['text'] ?? '';
			$created_at = $this->parse_twitter_date( $tweet['created_at'] ?? '' );

			// Determine content type.
			$in_reply_to = $tweet['in_reply_to_status_id'] ?? $tweet['in_reply_to_status_id_str'] ?? null;
			$is_retweet  = isset( $tweet['retweeted_status'] ) || str_starts_with( $full_text, 'RT @' );

			if ( $is_retweet ) {
				$type = ContentType::REPOST;
			} elseif ( ! empty( $in_reply_to ) && ! isset( $tweet_ids[ $in_reply_to ] ) ) {
				// Reply to someone else (not a self-thread).
				$type = ContentType::REPLY;
			} elseif ( ! empty( $in_reply_to ) && isset( $tweet_ids[ $in_reply_to ] ) ) {
				// Self-reply — part of a thread.
				$type = ContentType::THREAD;
			} else {
				$type = ContentType::POST;
			}

			// Extract media URLs.
			$media_urls = $this->extract_tweet_media_urls( $tweet );

			// Build source URL.
			$source_url = '';
			if ( ! empty( $username ) ) {
				$source_url = sprintf( 'https://x.com/%s/status/%s', $username, $tweet_id );
			}

			// Extract engagement metrics.
			$metadata = array(
				'favorite_count' => (int) ( $tweet['favorite_count'] ?? 0 ),
				'retweet_count'  => (int) ( $tweet['retweet_count'] ?? 0 ),
			);

			$title = mb_substr( $full_text, 0, 80 );
			if ( mb_strlen( $full_text ) > 80 ) {
				$title .= '...';
			}

			$parent_id = null;
			if ( ! empty( $in_reply_to ) ) {
				$parent_id = (string) $in_reply_to;
			}

			$manifest->add_item(
				new ManifestItem(
					id: $tweet_id,
					type: $type,
					title: $title,
					created_at: $created_at,
					excerpt: mb_substr( $full_text, 0, 200 ),
					media_urls: $media_urls,
					metadata: $metadata,
					parent_id: $parent_id,
					original_url: $source_url,
					author: array( 'name' => $username ),
				)
			);
		}

		$this->set_cache( 'manifest', $manifest->to_array(), 3600 );

		return $manifest;
	}

	/**
	 * Fetch a single tweet item by ID.
	 *
	 * @param string $item_id The tweet ID.
	 * @return array<string, mixed> Raw tweet data.
	 * @throws RuntimeException If not authenticated or item not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$tweets = $this->get_tweets_data();

		foreach ( $tweets as $tweet_data ) {
			$tweet    = $tweet_data['tweet'] ?? $tweet_data;
			$tweet_id = $tweet['id'] ?? $tweet['id_str'] ?? '';

			if ( (string) $tweet_id === $item_id ) {
				$account           = $this->get_account_data();
				$tweet['_account'] = $account;
				return $tweet;
			}
		}

		throw new RuntimeException(
			sprintf( 'Tweet not found: %s', esc_html( $item_id ) )
		);
	}

	/**
	 * Get supported content types.
	 *
	 * @return array<string> Supported content type values.
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
	 * @return SettingsSchema The settings schema.
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();

		$schema->add_field(
			'archive_file',
			array(
				'type'        => 'file',
				'label'       => __( 'Twitter Archive File', 'ai-importer' ),
				'description' => __( 'Upload your Twitter/X data archive (.zip file). You can request this from Settings > Your Account > Download an archive of your data on Twitter/X.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		return $schema;
	}

	/**
	 * Extract a ZIP archive to a temporary directory.
	 *
	 * @param string $archive_path Path to the ZIP file.
	 * @return string|null Path to the extracted directory, or null on failure.
	 */
	private function extract_archive( string $archive_path ): ?string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->log_error( 'ZipArchive class not available.' );
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			$this->log_error( 'Failed to open ZIP archive.', array( 'path' => $archive_path ) );
			return null;
		}

		$upload_dir  = wp_upload_dir();
		$extract_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai-importer/twitter-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $extract_dir ) ) {
			$zip->close();
			$this->log_error( 'Failed to create extraction directory.', array( 'dir' => $extract_dir ) );
			return null;
		}

		$zip->extractTo( $extract_dir );
		$zip->close();

		// Verify the archive contains expected Twitter data files.
		if ( ! $this->validate_archive_structure( $extract_dir ) ) {
			$this->delete_directory( $extract_dir );
			$this->log_error( 'Invalid Twitter archive structure.' );
			return null;
		}

		return $extract_dir;
	}

	/**
	 * Validate that the extracted archive has the expected structure.
	 *
	 * @param string $extract_dir Path to the extracted directory.
	 * @return bool True if valid.
	 */
	private function validate_archive_structure( string $extract_dir ): bool {
		// Twitter archives contain data/tweets.js (or data/tweet.js).
		$tweets_file = $this->find_tweets_file( $extract_dir );
		return null !== $tweets_file;
	}

	/**
	 * Find the tweets data file in the archive.
	 *
	 * @param string $extract_dir Path to the extracted directory.
	 * @return string|null Path to the tweets file, or null if not found.
	 */
	private function find_tweets_file( string $extract_dir ): ?string {
		$possible_paths = array(
			$extract_dir . '/data/tweets.js',
			$extract_dir . '/data/tweet.js',
			$extract_dir . '/tweets.js',
			$extract_dir . '/tweet.js',
		);

		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Find the account data file in the archive.
	 *
	 * @param string $extract_dir Path to the extracted directory.
	 * @return string|null Path to the account file, or null if not found.
	 */
	private function find_account_file( string $extract_dir ): ?string {
		$possible_paths = array(
			$extract_dir . '/data/account.js',
			$extract_dir . '/account.js',
		);

		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Parse a Twitter JavaScript data file.
	 *
	 * Twitter archive files are JavaScript (e.g., "window.YTD.tweets.part0 = [...]").
	 * This strips the variable assignment to get the JSON array.
	 *
	 * @param string $file_path Path to the JS data file.
	 * @return array<int, mixed> Parsed data.
	 * @throws RuntimeException If parsing fails.
	 */
	private function parse_twitter_js_file( string $file_path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file from extracted archive.
		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			throw new RuntimeException(
				sprintf( 'Failed to read file: %s', esc_html( basename( $file_path ) ) )
			);
		}

		// Strip the JavaScript variable assignment (e.g., "window.YTD.tweets.part0 = ").
		$json = preg_replace( '/^[^=]+=\s*/', '', $content );

		if ( null === $json ) {
			throw new RuntimeException( 'Failed to parse Twitter data file.' );
		}

		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException(
				sprintf( 'Invalid JSON in Twitter data file: %s', esc_html( json_last_error_msg() ) )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get parsed tweets data.
	 *
	 * @return array<int, array<string, mixed>> Tweets data.
	 * @throws RuntimeException If tweets file cannot be found or parsed.
	 */
	private function get_tweets_data(): array {
		if ( null !== $this->tweets_data ) {
			return $this->tweets_data;
		}

		$credentials = $this->get_stored_credentials();
		$extract_dir = $credentials['extract_dir'] ?? '';
		$tweets_file = $this->find_tweets_file( $extract_dir );

		if ( null === $tweets_file ) {
			throw new RuntimeException( 'Tweets data file not found in archive.' );
		}

		$this->tweets_data = $this->parse_twitter_js_file( $tweets_file );

		return $this->tweets_data;
	}

	/**
	 * Get account data from the archive.
	 *
	 * @return array<string, mixed> Account data.
	 */
	private function get_account_data(): array {
		if ( null !== $this->account_data ) {
			return $this->account_data;
		}

		$credentials  = $this->get_stored_credentials();
		$extract_dir  = $credentials['extract_dir'] ?? '';
		$account_file = $this->find_account_file( $extract_dir );

		if ( null === $account_file ) {
			$this->account_data = array();
			return $this->account_data;
		}

		try {
			$data               = $this->parse_twitter_js_file( $account_file );
			$this->account_data = $data[0]['account'] ?? $data[0] ?? array();
		} catch ( RuntimeException $e ) {
			$this->account_data = array();
		}

		return $this->account_data;
	}

	/**
	 * Extract media URLs from a tweet.
	 *
	 * @param array<string, mixed> $tweet Raw tweet data.
	 * @return array<string> Media URLs.
	 */
	private function extract_tweet_media_urls( array $tweet ): array {
		$urls = array();

		// Extended entities (preferred — contains full media info).
		$media_entries = $tweet['extended_entities']['media']
			?? $tweet['entities']['media']
			?? array();

		foreach ( $media_entries as $media ) {
			$url = $media['media_url_https'] ?? $media['media_url'] ?? '';
			if ( ! empty( $url ) ) {
				$urls[] = $url;
			}

			// Video variants.
			if ( ! empty( $media['video_info']['variants'] ) ) {
				$best_video = $this->get_best_video_variant( $media['video_info']['variants'] );
				if ( null !== $best_video ) {
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
	 * @return string|null Best video URL, or null if none found.
	 */
	private function get_best_video_variant( array $variants ): ?string {
		$best_url     = null;
		$best_bitrate = -1;

		foreach ( $variants as $variant ) {
			$content_type = $variant['content_type'] ?? '';
			if ( 'video/mp4' !== $content_type ) {
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
	 * Parse a Twitter date string.
	 *
	 * @param string $date_string Twitter date format.
	 * @return DateTimeImmutable Parsed date.
	 */
	private function parse_twitter_date( string $date_string ): DateTimeImmutable {
		if ( empty( $date_string ) ) {
			return new DateTimeImmutable();
		}

		// Twitter format: "Mon Jan 01 00:00:00 +0000 2024".
		$date = DateTimeImmutable::createFromFormat( 'D M d H:i:s O Y', $date_string );

		if ( false === $date ) {
			// Try ISO 8601.
			try {
				return new DateTimeImmutable( $date_string );
			} catch ( \Exception $e ) {
				return new DateTimeImmutable();
			}
		}

		return $date;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
