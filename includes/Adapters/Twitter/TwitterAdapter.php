<?php
/**
 * Twitter/X adapter class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters\Twitter;

use AI_Importer\Adapters\AbstractAdapter;
use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Schema\SettingsSchema;
use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Adapter for importing content from Twitter/X archives.
 *
 * Twitter allows users to download their complete archive as a ZIP file.
 * This adapter parses the archive and extracts tweets for import.
 */
class TwitterAdapter extends AbstractAdapter {

	/**
	 * Path to the extracted archive directory.
	 *
	 * @var string|null
	 */
	private ?string $archive_path = null;

	/**
	 * Parsed tweets data.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $tweets = null;

	/**
	 * Account information from the archive.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $account = null;

	/**
	 * Get the unique identifier for this adapter.
	 *
	 * @return string Adapter ID.
	 */
	public function get_id(): string {
		return 'twitter';
	}

	/**
	 * Get the human-readable name of this adapter.
	 *
	 * @return string Adapter name.
	 */
	public function get_name(): string {
		return 'Twitter/X';
	}

	/**
	 * Get a description of this adapter.
	 *
	 * @return string Description text.
	 */
	public function get_description(): string {
		return __( 'Import tweets from your Twitter/X archive. Download your archive from Twitter settings.', 'ai-importer' );
	}

	/**
	 * Get the icon for this adapter.
	 *
	 * @return string Dashicon class.
	 */
	public function get_icon(): string {
		return 'dashicons-twitter';
	}

	/**
	 * Get the authentication type required by this adapter.
	 *
	 * @return string Authentication type.
	 */
	public function get_auth_type(): string {
		return AdapterInterface::AUTH_TYPE_FILE_UPLOAD;
	}

	/**
	 * Get supported content types for this adapter.
	 *
	 * @return array<string> Array of ContentType values.
	 */
	public function get_supported_content_types(): array {
		return array(
			ContentType::POST->value,
			ContentType::THREAD->value,
			ContentType::REPLY->value,
			ContentType::REPOST->value,
		);
	}

	/**
	 * Build the settings schema for this adapter.
	 *
	 * @return SettingsSchema The settings schema.
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();

		$schema->add_field(
			'archive_file',
			array(
				'type'        => 'file',
				'label'       => __( 'Twitter Archive', 'ai-importer' ),
				'description' => __( 'Upload your Twitter archive ZIP file. You can download this from Twitter Settings > Your Account > Download an archive of your data.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		$schema->add_field(
			'include_replies',
			array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Replies', 'ai-importer' ),
				'description' => __( 'Include tweets that are replies to other users.', 'ai-importer' ),
				'default'     => false,
			)
		);

		$schema->add_field(
			'include_retweets',
			array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Retweets', 'ai-importer' ),
				'description' => __( 'Include retweets in the import.', 'ai-importer' ),
				'default'     => false,
			)
		);

		$schema->add_field(
			'date_range',
			array(
				'type'        => 'date_range',
				'label'       => __( 'Date Range', 'ai-importer' ),
				'description' => __( 'Optionally filter tweets to a specific date range.', 'ai-importer' ),
				'required'    => false,
			)
		);

		return $schema;
	}

	/**
	 * Authenticate with the source platform (process uploaded archive).
	 *
	 * @param array<string, mixed> $credentials Authentication credentials including file path.
	 * @return bool True on success, false on failure.
	 */
	public function authenticate( array $credentials ): bool {
		if ( empty( $credentials['archive_file'] ) ) {
			$this->log_error( 'No archive file provided.' );
			return false;
		}

		$file_path = $credentials['archive_file'];

		if ( ! file_exists( $file_path ) ) {
			$this->log_error( 'Archive file does not exist.', array( 'path' => $file_path ) );
			return false;
		}

		// Extract the archive.
		$extract_path = $this->extract_archive( $file_path );
		if ( false === $extract_path ) {
			return false;
		}

		// Validate the archive structure.
		if ( ! $this->validate_archive( $extract_path ) ) {
			$this->cleanup_extracted_archive( $extract_path );
			return false;
		}

		// Load account info.
		$account = $this->load_account_info( $extract_path );

		// Store credentials.
		$this->store_credentials(
			array(
				'archive_path'     => $extract_path,
				'original_file'    => $file_path,
				'account'          => $account,
				'include_replies'  => $credentials['include_replies'] ?? false,
				'include_retweets' => $credentials['include_retweets'] ?? false,
				'date_range'       => $credentials['date_range'] ?? null,
			)
		);

		$this->archive_path = $extract_path;
		$this->account      = $account;

		return true;
	}

	/**
	 * Check if the adapter is currently authenticated.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated(): bool {
		$credentials = $this->get_stored_credentials();

		if ( empty( $credentials['archive_path'] ) ) {
			return false;
		}

		// Verify the extracted archive still exists.
		return is_dir( $credentials['archive_path'] );
	}

	/**
	 * Disconnect from the source platform.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$credentials = $this->get_stored_credentials();

		if ( ! empty( $credentials['archive_path'] ) ) {
			$this->cleanup_extracted_archive( $credentials['archive_path'] );
		}

		$this->archive_path = null;
		$this->tweets       = null;
		$this->account      = null;

		parent::disconnect();
	}

	/**
	 * Fetch the content manifest from the source.
	 *
	 * @return ContentManifest The content manifest.
	 * @throws RuntimeException If not authenticated or fetch fails.
	 */
	public function fetch_manifest(): ContentManifest {
		$this->ensure_authenticated();
		$this->load_archive_data();

		$credentials = $this->get_stored_credentials();
		$manifest    = new ContentManifest( $this->get_id() );

		$include_replies  = $credentials['include_replies'] ?? false;
		$include_retweets = $credentials['include_retweets'] ?? false;
		$date_range       = $credentials['date_range'] ?? null;

		foreach ( $this->tweets as $tweet_id => $tweet ) {
			// Filter retweets if not included.
			if ( ! $include_retweets && $this->is_retweet( $tweet ) ) {
				continue;
			}

			// Filter replies if not included.
			if ( ! $include_replies && $this->is_reply( $tweet ) ) {
				continue;
			}

			// Apply date filter.
			$created_at = $this->parse_twitter_date( $tweet['created_at'] ?? '' );
			if ( null !== $date_range && ! $this->is_in_date_range( $created_at, $date_range ) ) {
				continue;
			}

			$manifest->add_item( $this->create_manifest_item( $tweet_id, $tweet, $created_at ) );
		}

		return $manifest;
	}

	/**
	 * Fetch a single content item by ID.
	 *
	 * @param string $item_id The item ID from the manifest.
	 * @return array<string, mixed> Item data including content, media, metadata.
	 * @throws RuntimeException If not authenticated or item not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();
		$this->load_archive_data();

		if ( ! isset( $this->tweets[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				sprintf(
					/* translators: %s: tweet ID */
					__( 'Tweet with ID "%s" not found in archive.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$tweet = $this->tweets[ $item_id ];

		return $this->build_item_data( $item_id, $tweet );
	}

	/**
	 * Get the account username from the loaded archive.
	 *
	 * @return string|null The username or null if not loaded.
	 */
	public function get_username(): ?string {
		if ( null === $this->account ) {
			$credentials   = $this->get_stored_credentials();
			$this->account = $credentials['account'] ?? null;
		}

		return $this->account['username'] ?? null;
	}

	/**
	 * Extract the Twitter archive ZIP file.
	 *
	 * @param string $zip_path Path to the ZIP file.
	 * @return string|false Path to extracted directory or false on failure.
	 */
	private function extract_archive( string $zip_path ): string|false {
		$zip = new ZipArchive();

		$result = $zip->open( $zip_path );
		if ( true !== $result ) {
			$this->log_error( 'Failed to open ZIP archive.', array( 'error_code' => $result ) );
			return false;
		}

		// Create extraction directory in uploads.
		$upload_dir   = wp_upload_dir();
		$extract_path = trailingslashit( $upload_dir['basedir'] ) . 'ai-importer/twitter-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $extract_path ) ) {
			$this->log_error( 'Failed to create extraction directory.' );
			$zip->close();
			return false;
		}

		if ( ! $zip->extractTo( $extract_path ) ) {
			$this->log_error( 'Failed to extract archive.' );
			$zip->close();
			$this->cleanup_extracted_archive( $extract_path );
			return false;
		}

		$zip->close();

		return $extract_path;
	}

	/**
	 * Validate the extracted archive has expected structure.
	 *
	 * @param string $extract_path Path to extracted archive.
	 * @return bool True if valid.
	 */
	private function validate_archive( string $extract_path ): bool {
		// Twitter archives have data in a 'data' subdirectory.
		$data_path = $this->find_data_directory( $extract_path );

		if ( null === $data_path ) {
			$this->log_error( 'Invalid archive structure: data directory not found.' );
			return false;
		}

		// Check for required files.
		$tweets_file = $data_path . '/tweets.js';
		if ( ! file_exists( $tweets_file ) ) {
			// Try alternate location with partitioned files.
			$tweets_file = $data_path . '/tweet.js';
			if ( ! file_exists( $tweets_file ) ) {
				$this->log_error( 'Invalid archive structure: tweets.js not found.' );
				return false;
			}
		}

		return true;
	}

	/**
	 * Find the data directory within the extracted archive.
	 *
	 * @param string $extract_path Path to extracted archive.
	 * @return string|null Path to data directory or null if not found.
	 */
	private function find_data_directory( string $extract_path ): ?string {
		// Direct data directory.
		if ( is_dir( $extract_path . '/data' ) ) {
			return $extract_path . '/data';
		}

		// Sometimes archives have a single subdirectory.
		$entries = scandir( $extract_path );
		if ( false === $entries ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$subpath = $extract_path . '/' . $entry;
			if ( is_dir( $subpath ) && is_dir( $subpath . '/data' ) ) {
				return $subpath . '/data';
			}
		}

		return null;
	}

	/**
	 * Load account information from the archive.
	 *
	 * @param string $extract_path Path to extracted archive.
	 * @return array<string, mixed> Account information.
	 */
	private function load_account_info( string $extract_path ): array {
		$data_path    = $this->find_data_directory( $extract_path );
		$account_file = $data_path . '/account.js';

		if ( ! file_exists( $account_file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local archive file.
		$content = file_get_contents( $account_file );
		if ( false === $content ) {
			return array();
		}

		$data = $this->parse_twitter_js( $content );
		if ( empty( $data ) || ! isset( $data[0]['account'] ) ) {
			return array();
		}

		return $data[0]['account'];
	}

	/**
	 * Load archive data (tweets) into memory.
	 *
	 * @return void
	 * @throws RuntimeException If loading fails.
	 */
	private function load_archive_data(): void {
		if ( null !== $this->tweets ) {
			return;
		}

		$credentials  = $this->get_stored_credentials();
		$archive_path = $credentials['archive_path'] ?? null;

		if ( null === $archive_path ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException( __( 'Archive path not found in credentials.', 'ai-importer' ) );
		}

		$this->archive_path = $archive_path;
		$this->account      = $credentials['account'] ?? null;

		$data_path = $this->find_data_directory( $archive_path );
		if ( null === $data_path ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException( __( 'Data directory not found in archive.', 'ai-importer' ) );
		}

		$this->tweets = $this->load_tweets( $data_path );
	}

	/**
	 * Load tweets from the archive data directory.
	 *
	 * @param string $data_path Path to data directory.
	 * @return array<string, array<string, mixed>> Tweets keyed by ID.
	 * @throws RuntimeException If loading fails.
	 */
	private function load_tweets( string $data_path ): array {
		$tweets = array();

		// Try tweets.js first (newer format).
		$tweets_file = $data_path . '/tweets.js';
		if ( ! file_exists( $tweets_file ) ) {
			$tweets_file = $data_path . '/tweet.js';
		}

		if ( file_exists( $tweets_file ) ) {
			$tweets = $this->load_tweets_from_file( $tweets_file );
		}

		// Also check for partitioned files (tweets-part1.js, etc.).
		$part_files = glob( $data_path . '/tweets-part*.js' );
		if ( false !== $part_files && ! empty( $part_files ) ) {
			foreach ( $part_files as $part_file ) {
				$part_tweets = $this->load_tweets_from_file( $part_file );
				$tweets      = array_merge( $tweets, $part_tweets );
			}
		}

		if ( empty( $tweets ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException( __( 'No tweets found in archive.', 'ai-importer' ) );
		}

		return $tweets;
	}

	/**
	 * Load tweets from a single JS file.
	 *
	 * @param string $file_path Path to the JS file.
	 * @return array<string, array<string, mixed>> Tweets keyed by ID.
	 */
	private function load_tweets_from_file( string $file_path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local archive file.
		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			$this->log_error( 'Failed to read tweets file.', array( 'path' => $file_path ) );
			return array();
		}

		$data = $this->parse_twitter_js( $content );
		if ( empty( $data ) ) {
			return array();
		}

		$tweets = array();
		foreach ( $data as $item ) {
			// Handle both formats: {tweet: {...}} and direct tweet object.
			$tweet = $item['tweet'] ?? $item;

			if ( isset( $tweet['id_str'] ) ) {
				$tweets[ $tweet['id_str'] ] = $tweet;
			} elseif ( isset( $tweet['id'] ) ) {
				$tweets[ (string) $tweet['id'] ] = $tweet;
			}
		}

		return $tweets;
	}

	/**
	 * Parse Twitter archive JS file content to JSON.
	 *
	 * Twitter archive files are JS with a variable assignment prefix like:
	 * window.YTD.tweets.part0 = [...]
	 *
	 * @param string $content File content.
	 * @return array<int, mixed> Parsed JSON data.
	 */
	private function parse_twitter_js( string $content ): array {
		// Remove the JavaScript variable assignment prefix.
		$content = preg_replace( '/^window\.YTD\.[a-zA-Z0-9_]+\.part\d+\s*=\s*/', '', $content );
		$content = trim( $content );

		// Handle trailing semicolon.
		$content = rtrim( $content, ';' );

		$data = json_decode( $content, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->log_error(
				'Failed to parse Twitter JS file.',
				array( 'json_error' => json_last_error_msg() )
			);
			return array();
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Parse Twitter's date format.
	 *
	 * @param string $date_string Twitter date string (e.g., "Mon Jan 15 10:15:00 +0000 2024").
	 * @return DateTimeImmutable The parsed date.
	 */
	private function parse_twitter_date( string $date_string ): DateTimeImmutable {
		// Twitter format: "Mon Jan 15 10:15:00 +0000 2024".
		$date = DateTimeImmutable::createFromFormat( 'D M d H:i:s O Y', $date_string );

		if ( false === $date ) {
			// Try ISO format as fallback.
			$date = new DateTimeImmutable( $date_string );
		}

		return $date;
	}

	/**
	 * Check if a tweet is a retweet.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return bool True if retweet.
	 */
	private function is_retweet( array $tweet ): bool {
		// Check for retweeted_status field.
		if ( isset( $tweet['retweeted_status'] ) ) {
			return true;
		}

		// Check if full_text starts with "RT @".
		$text = $tweet['full_text'] ?? $tweet['text'] ?? '';
		return str_starts_with( $text, 'RT @' );
	}

	/**
	 * Check if a tweet is a reply.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return bool True if reply.
	 */
	private function is_reply( array $tweet ): bool {
		// Check for in_reply_to fields.
		if ( ! empty( $tweet['in_reply_to_status_id_str'] ) ) {
			return true;
		}

		if ( ! empty( $tweet['in_reply_to_user_id_str'] ) ) {
			// Only count as reply if it's to a different user.
			$username = $this->get_username();
			if ( null !== $username ) {
				$reply_to = $tweet['in_reply_to_screen_name'] ?? '';
				return strtolower( $reply_to ) !== strtolower( $username );
			}
			return true;
		}

		return false;
	}

	/**
	 * Check if a tweet is part of a self-thread.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return bool True if part of a self-thread.
	 */
	private function is_self_reply( array $tweet ): bool {
		if ( empty( $tweet['in_reply_to_status_id_str'] ) ) {
			return false;
		}

		$username = $this->get_username();
		if ( null === $username ) {
			return false;
		}

		$reply_to = $tweet['in_reply_to_screen_name'] ?? '';
		return strtolower( $reply_to ) === strtolower( $username );
	}

	/**
	 * Check if a date is within a date range.
	 *
	 * @param DateTimeImmutable     $date       Date to check.
	 * @param array<string, string> $date_range Date range with 'start' and 'end' keys.
	 * @return bool True if in range.
	 */
	private function is_in_date_range( DateTimeImmutable $date, array $date_range ): bool {
		if ( empty( $date_range['start'] ) && empty( $date_range['end'] ) ) {
			return true;
		}

		if ( ! empty( $date_range['start'] ) ) {
			$start = new DateTimeImmutable( $date_range['start'] );
			if ( $date < $start ) {
				return false;
			}
		}

		if ( ! empty( $date_range['end'] ) ) {
			$end = new DateTimeImmutable( $date_range['end'] . ' 23:59:59' );
			if ( $date > $end ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Create a manifest item from tweet data.
	 *
	 * @param string               $tweet_id   Tweet ID.
	 * @param array<string, mixed> $tweet      Tweet data.
	 * @param DateTimeImmutable    $created_at Creation date.
	 * @return ManifestItem The manifest item.
	 */
	private function create_manifest_item( string $tweet_id, array $tweet, DateTimeImmutable $created_at ): ManifestItem {
		$text = $tweet['full_text'] ?? $tweet['text'] ?? '';

		// Determine content type.
		$type = ContentType::POST;
		if ( $this->is_retweet( $tweet ) ) {
			$type = ContentType::REPOST;
		} elseif ( $this->is_self_reply( $tweet ) ) {
			$type = ContentType::THREAD;
		} elseif ( $this->is_reply( $tweet ) ) {
			$type = ContentType::REPLY;
		}

		// Extract media URLs.
		$media_urls = $this->extract_media_urls( $tweet );

		// Build source URL.
		$username   = $this->get_username() ?? 'user';
		$source_url = sprintf( 'https://twitter.com/%s/status/%s', $username, $tweet_id );

		// Get parent ID for replies/threads.
		$parent_id = $tweet['in_reply_to_status_id_str'] ?? null;

		// Build metadata.
		$metadata = array(
			'favorite_count' => (int) ( $tweet['favorite_count'] ?? 0 ),
			'retweet_count'  => (int) ( $tweet['retweet_count'] ?? 0 ),
			'source'         => $tweet['source'] ?? '',
			'lang'           => $tweet['lang'] ?? '',
		);

		return new ManifestItem(
			id: $tweet_id,
			type: $type,
			title: $this->generate_title( $text ),
			created_at: $created_at,
			excerpt: $this->truncate_text( $text, 280 ),
			media_urls: $media_urls,
			metadata: $metadata,
			parent_id: $parent_id,
			original_url: $source_url,
			author: array(
				'name' => $this->account['accountDisplayName'] ?? $username,
				'url'  => 'https://twitter.com/' . $username,
			)
		);
	}

	/**
	 * Build item data for a single tweet.
	 *
	 * @param string               $tweet_id Tweet ID.
	 * @param array<string, mixed> $tweet    Tweet data.
	 * @return array<string, mixed> Item data.
	 */
	private function build_item_data( string $tweet_id, array $tweet ): array {
		$text       = $tweet['full_text'] ?? $tweet['text'] ?? '';
		$created_at = $this->parse_twitter_date( $tweet['created_at'] ?? '' );
		$username   = $this->get_username() ?? 'user';

		return array(
			'id'                 => $tweet_id,
			'text'               => $text,
			'full_text'          => $text,
			'created_at'         => $created_at->format( 'c' ),
			'source_url'         => sprintf( 'https://twitter.com/%s/status/%s', $username, $tweet_id ),
			'entities'           => $tweet['entities'] ?? array(),
			'extended_entities'  => $tweet['extended_entities'] ?? array(),
			'media_urls'         => $this->extract_media_urls( $tweet ),
			'hashtags'           => $this->extract_hashtags( $tweet ),
			'mentions'           => $this->extract_mentions( $tweet ),
			'urls'               => $this->extract_urls( $tweet ),
			'favorite_count'     => (int) ( $tweet['favorite_count'] ?? 0 ),
			'retweet_count'      => (int) ( $tweet['retweet_count'] ?? 0 ),
			'is_retweet'         => $this->is_retweet( $tweet ),
			'is_reply'           => $this->is_reply( $tweet ),
			'is_self_reply'      => $this->is_self_reply( $tweet ),
			'in_reply_to_status' => $tweet['in_reply_to_status_id_str'] ?? null,
			'in_reply_to_user'   => $tweet['in_reply_to_screen_name'] ?? null,
			'source'             => $tweet['source'] ?? '',
			'lang'               => $tweet['lang'] ?? '',
			'author'             => array(
				'name'     => $this->account['accountDisplayName'] ?? $username,
				'username' => $username,
				'url'      => 'https://twitter.com/' . $username,
			),
		);
	}

	/**
	 * Extract media URLs from tweet data.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<string> Media URLs.
	 */
	private function extract_media_urls( array $tweet ): array {
		$urls = array();

		// Check extended_entities first (has highest quality media).
		$media = $tweet['extended_entities']['media'] ?? $tweet['entities']['media'] ?? array();

		foreach ( $media as $item ) {
			if ( isset( $item['media_url_https'] ) ) {
				$urls[] = $item['media_url_https'];
			} elseif ( isset( $item['media_url'] ) ) {
				$urls[] = $item['media_url'];
			}

			// For videos, get the video URL.
			if ( isset( $item['video_info']['variants'] ) ) {
				$best_url     = null;
				$best_bitrate = 0;

				foreach ( $item['video_info']['variants'] as $variant ) {
					if ( 'video/mp4' === ( $variant['content_type'] ?? '' ) ) {
						$bitrate = $variant['bitrate'] ?? 0;
						if ( $bitrate > $best_bitrate ) {
							$best_bitrate = $bitrate;
							$best_url     = $variant['url'];
						}
					}
				}

				if ( null !== $best_url ) {
					$urls[] = $best_url;
				}
			}
		}

		return array_unique( $urls );
	}

	/**
	 * Extract hashtags from tweet data.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<string> Hashtags (without # prefix).
	 */
	private function extract_hashtags( array $tweet ): array {
		$hashtags = array();
		$entities = $tweet['entities']['hashtags'] ?? array();

		foreach ( $entities as $hashtag ) {
			if ( isset( $hashtag['text'] ) ) {
				$hashtags[] = $hashtag['text'];
			}
		}

		return $hashtags;
	}

	/**
	 * Extract mentions from tweet data.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<array<string, string>> Mentions with screen_name and name.
	 */
	private function extract_mentions( array $tweet ): array {
		$mentions = array();
		$entities = $tweet['entities']['user_mentions'] ?? array();

		foreach ( $entities as $mention ) {
			$mentions[] = array(
				'screen_name' => $mention['screen_name'] ?? '',
				'name'        => $mention['name'] ?? '',
			);
		}

		return $mentions;
	}

	/**
	 * Extract URLs from tweet data.
	 *
	 * @param array<string, mixed> $tweet Tweet data.
	 * @return array<array<string, string>> URLs with url, expanded_url, display_url.
	 */
	private function extract_urls( array $tweet ): array {
		$urls     = array();
		$entities = $tweet['entities']['urls'] ?? array();

		foreach ( $entities as $url ) {
			$urls[] = array(
				'url'          => $url['url'] ?? '',
				'expanded_url' => $url['expanded_url'] ?? '',
				'display_url'  => $url['display_url'] ?? '',
			);
		}

		return $urls;
	}

	/**
	 * Generate a title from tweet text.
	 *
	 * @param string $text Tweet text.
	 * @return string Generated title.
	 */
	private function generate_title( string $text ): string {
		// Remove URLs.
		$title = preg_replace( '/https?:\/\/\S+/', '', $text );

		// Remove extra whitespace.
		$title = preg_replace( '/\s+/', ' ', $title );
		$title = trim( $title );

		// Truncate to reasonable title length.
		return $this->truncate_text( $title, 100 );
	}

	/**
	 * Truncate text to a maximum length at word boundary.
	 *
	 * @param string $text   Text to truncate.
	 * @param int    $length Maximum length.
	 * @return string Truncated text.
	 */
	private function truncate_text( string $text, int $length ): string {
		$text = trim( $text );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$text       = mb_substr( $text, 0, $length );
		$last_space = mb_strrpos( $text, ' ' );

		if ( false !== $last_space && $last_space > $length * 0.7 ) {
			$text = mb_substr( $text, 0, $last_space );
		}

		return $text . '...';
	}

	/**
	 * Clean up extracted archive directory.
	 *
	 * @param string $path Path to the extracted archive.
	 * @return void
	 */
	private function cleanup_extracted_archive( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up temp directory.
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $path );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
