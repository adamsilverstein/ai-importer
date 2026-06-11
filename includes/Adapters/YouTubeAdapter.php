<?php
/**
 * YouTube adapter.
 *
 * Imports videos from a Google Takeout YouTube export or the YouTube
 * Data API v3.
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
 * Adapter for importing content from YouTube (F7.2).
 *
 * Supports two connection modes:
 *
 * 1. File upload — a Google Takeout YouTube export ZIP.
 *
 *    Takeout's structure varies between exports and locales, so the
 *    parser is intentionally defensive. It looks for any CSV inside the
 *    archive whose header row identifies it as video metadata (a column
 *    matching a "video id" variant, optionally alongside title,
 *    description, publish/created date, and visibility/privacy columns).
 *    Column names are matched by normalized header text, tolerant of
 *    casing, spacing, and common variants (e.g. "Video ID", "video_id",
 *    "Video Title", "Video Description", "Video Create Timestamp", and a
 *    "Privacy"/"Visibility" column). A canonical watch URL is built from
 *    the video ID as `https://www.youtube.com/watch?v={id}`.
 *
 *    Private and unlisted videos are flagged in metadata and excluded
 *    from the manifest by default; only public videos are surfaced.
 *
 * 2. YouTube Data API v3 — an `api_key` plus a `channel_id`. On
 *    authenticate the API is probed with channels.list. fetch_manifest
 *    resolves the channel's uploads playlist
 *    (channels.list?part=contentDetails) and pages through
 *    playlistItems.list (part=snippet,contentDetails) up to a sane cap.
 *    fetch_item returns a Gutenberg-friendly body containing the YouTube
 *    embed plus the description.
 */
class YouTubeAdapter extends AbstractAdapter {

	/**
	 * Connection modes.
	 */
	private const MODE_FILE = 'file';
	private const MODE_API  = 'api';

	/**
	 * Maximum number of videos fetched from the Data API.
	 *
	 * Pagination stops once this many items have been collected; the cap
	 * is logged so large channels are not silently truncated.
	 */
	private const MAX_API_ITEMS = 500;

	/**
	 * YouTube Data API v3 base URL.
	 */
	private const API_BASE = 'https://www.googleapis.com/youtube/v3';

	/**
	 * Parsed videos keyed by video ID.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $videos = null;

	/**
	 * Get the unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'youtube';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'YouTube';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import videos from a Google Takeout YouTube export or the YouTube Data API.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-video-alt3';
	}

	/**
	 * Get the authentication type.
	 *
	 * File upload is the primary mode; an API key connection is also
	 * accepted by authenticate() when api_key + channel_id are provided
	 * instead of a file.
	 *
	 * @return string
	 */
	public function get_auth_type(): string {
		return self::AUTH_TYPE_FILE_UPLOAD;
	}

	/**
	 * Authenticate with either a Takeout export or Data API credentials.
	 *
	 * @param array<string, mixed> $credentials Either 'file'/'attachment_id'
	 *                                          for a Takeout upload, or
	 *                                          'api_key' + 'channel_id'.
	 * @return bool True on success.
	 */
	public function authenticate( array $credentials ): bool {
		$file_path = $credentials['file'] ?? '';

		if ( ! empty( $credentials['attachment_id'] ) ) {
			$file_path = get_attached_file( (int) $credentials['attachment_id'] );
		}

		if ( ! empty( $file_path ) ) {
			return $this->authenticate_file( (string) $file_path );
		}

		$api_key    = trim( (string) ( $credentials['api_key'] ?? '' ) );
		$channel_id = trim( (string) ( $credentials['channel_id'] ?? '' ) );

		if ( '' !== $api_key && '' !== $channel_id ) {
			return $this->authenticate_api( $api_key, $channel_id );
		}

		$this->log_error( 'YouTube authentication requires a Takeout export file or api_key + channel_id.' );

		return false;
	}

	/**
	 * Fetch the content manifest.
	 *
	 * @return ContentManifest
	 * @throws RuntimeException If not authenticated or fetching fails.
	 */
	public function fetch_manifest(): ContentManifest {
		$this->ensure_authenticated();

		$cached = $this->get_cache( 'manifest' );

		if ( $cached instanceof ContentManifest ) {
			return $cached;
		}

		$videos   = $this->get_videos();
		$manifest = new ContentManifest( $this->get_id() );

		foreach ( $videos as $id => $video ) {
			$item = new ManifestItem(
				id: $id,
				type: ContentType::MEDIA,
				title: $video['title'],
				created_at: $video['published_at'],
				excerpt: $this->build_excerpt( $video['description'] ),
				updated_at: null,
				media_urls: $video['media_urls'],
				metadata: array(
					'video_id'      => $video['video_id'],
					'channel_title' => $video['channel_title'],
					'duration'      => $video['duration'],
					'tags'          => $video['tags'],
					'visibility'    => $video['visibility'],
				),
				original_url: $video['original_url'],
				author: $this->build_author( $video ),
			);

			$manifest->add_item( $item );
		}

		$this->set_cache( 'manifest', $manifest, 86400 );

		return $manifest;
	}

	/**
	 * Fetch a single video by ID with full content.
	 *
	 * @param string $item_id Video ID.
	 * @return array<string, mixed>
	 * @throws RuntimeException If not authenticated or not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$videos = $this->get_videos();

		if ( ! isset( $videos[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: video ID */
					__( 'YouTube video with ID "%s" not found.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$video = $videos[ $item_id ];

		return array(
			'id'           => $item_id,
			'type'         => ContentType::MEDIA->value,
			'content'      => $this->build_content( $video ),
			'title'        => $video['title'],
			'created_at'   => $video['published_at']->format( 'c' ),
			'media_urls'   => $video['media_urls'],
			'metadata'     => array(
				'video_id'      => $video['video_id'],
				'channel_title' => $video['channel_title'],
				'duration'      => $video['duration'],
				'tags'          => $video['tags'],
				'visibility'    => $video['visibility'],
			),
			'original_url' => $video['original_url'],
			'tags'         => $video['tags'],
			'author'       => $this->build_author( $video ),
			'raw'          => $video,
		);
	}

	/**
	 * Get supported content types.
	 *
	 * @return array<string>
	 */
	public function get_supported_content_types(): array {
		return array(
			ContentType::MEDIA->value,
			ContentType::VIDEO->value,
		);
	}

	/**
	 * Build the settings schema.
	 *
	 * Fields are optional individually because either the Takeout export
	 * file or the api_key + channel_id pair satisfies authentication.
	 *
	 * @return SettingsSchema
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();

		$schema->add_field(
			'archive_file',
			array(
				'type'        => 'file',
				'label'       => __( 'YouTube Takeout Export', 'ai-importer' ),
				'description' => __( 'Upload your Google Takeout YouTube export ZIP. Get it from takeout.google.com (select YouTube and YouTube Music).', 'ai-importer' ),
				'required'    => false,
				'accept'      => '.zip',
			)
		);

		$schema->add_field(
			'api_key',
			array(
				'type'        => 'password',
				'label'       => __( 'YouTube Data API Key', 'ai-importer' ),
				'description' => __( 'A YouTube Data API v3 key. Create one in the Google Cloud Console. Used with a channel ID instead of a file upload.', 'ai-importer' ),
				'required'    => false,
			)
		);

		$schema->add_field(
			'channel_id',
			array(
				'type'        => 'text',
				'label'       => __( 'Channel ID', 'ai-importer' ),
				'description' => __( 'The YouTube channel ID to import, e.g. UC_x5XG1OV2P6uZZ5FSM9Ttw.', 'ai-importer' ),
				'required'    => false,
			)
		);

		return $schema;
	}

	/**
	 * Authenticate by validating an uploaded Takeout export ZIP.
	 *
	 * @param string $file_path Path to the ZIP export.
	 * @return bool True on success.
	 */
	private function authenticate_file( string $file_path ): bool {
		if ( ! file_exists( $file_path ) ) {
			$this->log_error( 'YouTube Takeout file not found.', array( 'file' => $file_path ) );
			return false;
		}

		$validation = $this->validate_archive( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'YouTube Takeout validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'mode'         => self::MODE_FILE,
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->videos = null;

		return true;
	}

	/**
	 * Authenticate against the YouTube Data API v3.
	 *
	 * Probes channels.list to confirm the key and channel are valid
	 * before storing the credentials.
	 *
	 * @param string $api_key    Data API key.
	 * @param string $channel_id Channel ID.
	 * @return bool True on success.
	 */
	private function authenticate_api( string $api_key, string $channel_id ): bool {
		$probe = $this->api_request(
			'channels',
			array(
				'part' => 'contentDetails',
				'id'   => $channel_id,
			),
			$api_key
		);

		if ( is_wp_error( $probe ) ) {
			$messages = $probe->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'YouTube Data API probe failed.' );
			return false;
		}

		if ( empty( $probe['items'] ) || ! is_array( $probe['items'] ) ) {
			$this->log_error( 'YouTube Data API probe returned no channel for the given ID.' );
			return false;
		}

		$this->store_credentials(
			array(
				'mode'         => self::MODE_API,
				'api_key'      => $api_key,
				'channel_id'   => $channel_id,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->videos = null;

		return true;
	}

	/**
	 * Validate that a file is a Takeout export containing video metadata.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_archive( string $file_path ): bool|WP_Error {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error(
				'invalid_zip',
				__( 'The uploaded file is not a valid ZIP archive.', 'ai-importer' )
			);
		}

		$found = false;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( ! $this->is_csv_name( $name ) ) {
				continue;
			}

			$contents = $zip->getFromIndex( $i );

			if ( is_string( $contents ) && null !== $this->detect_video_columns( $contents ) ) {
				$found = true;
				break;
			}
		}

		$zip->close();

		if ( ! $found ) {
			return new WP_Error(
				'missing_videos',
				__( 'This ZIP does not appear to be a YouTube Takeout export. Could not find a CSV with video metadata.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Get all parsed videos, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Videos keyed by ID.
	 * @throws RuntimeException If loading fails.
	 */
	private function get_videos(): array {
		if ( null !== $this->videos ) {
			return $this->videos;
		}

		$credentials = $this->get_stored_credentials();
		$mode        = $credentials['mode'] ?? self::MODE_FILE;

		if ( self::MODE_API === $mode ) {
			$this->videos = $this->fetch_videos_from_api( $credentials );
		} else {
			$this->videos = $this->parse_export_file( $credentials );
		}

		return $this->videos;
	}

	/**
	 * Parse the Takeout export ZIP and extract public videos.
	 *
	 * @param array<string, mixed> $credentials Stored credentials.
	 * @return array<string, array<string, mixed>> Videos keyed by ID.
	 * @throws RuntimeException If the file cannot be read or parsed.
	 */
	private function parse_export_file( array $credentials ): array {
		$file_path = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'YouTube Takeout file not found. Please re-upload your export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to open YouTube Takeout ZIP file.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$videos = array();

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( ! $this->is_csv_name( $name ) ) {
				continue;
			}

			$contents = $zip->getFromIndex( $i );

			if ( ! is_string( $contents ) ) {
				continue;
			}

			$parsed = $this->parse_csv( $contents );

			foreach ( $parsed as $video ) {
				$videos[ $video['id'] ] = $video;
			}
		}

		$zip->close();

		if ( empty( $videos ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No public videos found in the YouTube Takeout export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $videos;
	}

	/**
	 * Parse a Takeout video metadata CSV into video records.
	 *
	 * Only public videos are returned; private and unlisted videos are
	 * skipped. Rows without a resolvable video ID are skipped.
	 *
	 * @param string $contents Raw CSV contents.
	 * @return array<array<string, mixed>> Parsed public video records.
	 */
	private function parse_csv( string $contents ): array {
		$rows = $this->read_csv_rows( $contents );

		if ( count( $rows ) < 2 ) {
			return array();
		}

		$map = $this->detect_video_columns( $contents );

		if ( null === $map ) {
			return array();
		}

		// Drop the header row before iterating data rows.
		array_shift( $rows );

		$videos = array();

		foreach ( $rows as $row ) {
			$video = $this->parse_csv_row( $row, $map );

			if ( null !== $video ) {
				$videos[] = $video;
			}
		}

		return $videos;
	}

	/**
	 * Parse a single CSV data row into a video record.
	 *
	 * @param array<int, string> $row Row cells.
	 * @param array<string, int> $map Column name to index map.
	 * @return array<string, mixed>|null Video record or null to skip.
	 */
	private function parse_csv_row( array $row, array $map ): ?array {
		$video_id = $this->cell( $row, $map, 'id' );

		if ( '' === $video_id ) {
			return null;
		}

		$visibility = strtolower( $this->cell( $row, $map, 'visibility' ) );

		// Default unknown visibility to public; flag and skip non-public.
		if ( '' === $visibility ) {
			$visibility = 'public';
		}

		if ( 'public' !== $visibility ) {
			$this->log_error(
				'Skipping non-public YouTube video.',
				array(
					'video_id'   => $video_id,
					'visibility' => $visibility,
				)
			);
			return null;
		}

		$description = $this->cell( $row, $map, 'description' );

		return array(
			'id'            => $video_id,
			'video_id'      => $video_id,
			'title'         => $this->cell( $row, $map, 'title' ),
			'description'   => $description,
			'published_at'  => $this->parse_date( $this->cell( $row, $map, 'published' ) ),
			'channel_title' => '',
			'duration'      => null,
			'tags'          => array(),
			'visibility'    => $visibility,
			'media_urls'    => array(),
			'original_url'  => $this->watch_url( $video_id ),
		);
	}

	/**
	 * Read CSV contents into rows of cells.
	 *
	 * @param string $contents Raw CSV contents.
	 * @return array<int, array<int, string>> Rows of cells.
	 */
	private function read_csv_rows( string $contents ): array {
		// Strip a UTF-8 BOM if present so the first header matches.
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents ) ?? $contents;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream, not a filesystem path.
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to an in-memory stream.
		fwrite( $handle, $contents );
		rewind( $handle );

		$rows = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv -- Parsing an in-memory stream, not the filesystem.
		$row = fgetcsv( $handle, 0, ',', '"', '\\' );

		while ( false !== $row ) {
			if ( array( null ) !== $row ) {
				$rows[] = array_map( static fn( $cell ) => (string) $cell, $row );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv -- Parsing an in-memory stream, not the filesystem.
			$row = fgetcsv( $handle, 0, ',', '"', '\\' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing an in-memory stream.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Detect a video-metadata header in CSV contents.
	 *
	 * Returns a map of internal field names (id, title, description,
	 * published, visibility) to column indices, or null when the header
	 * does not look like video metadata (no video ID column).
	 *
	 * @param string $contents Raw CSV contents.
	 * @return array<string, int>|null Column map or null.
	 */
	private function detect_video_columns( string $contents ): ?array {
		$rows = $this->read_csv_rows( $contents );

		if ( empty( $rows ) ) {
			return null;
		}

		$header = $rows[0];
		$map    = array();

		foreach ( $header as $index => $label ) {
			$key = $this->normalize_header( $label );

			if ( '' === $key ) {
				continue;
			}

			$field = $this->match_header_field( $key );

			if ( null !== $field && ! isset( $map[ $field ] ) ) {
				$map[ $field ] = $index;
			}
		}

		// A video ID column is the minimum marker of video metadata.
		return isset( $map['id'] ) ? $map : null;
	}

	/**
	 * Match a normalized header label to an internal field name.
	 *
	 * @param string $key Normalized header key.
	 * @return string|null Field name or null.
	 */
	private function match_header_field( string $key ): ?string {
		$fields = array(
			'id'          => array( 'video id', 'videoid', 'id' ),
			'title'       => array( 'video title', 'title' ),
			'description' => array( 'video description', 'description' ),
			'published'   => array(
				'video create timestamp',
				'create timestamp',
				'published',
				'published at',
				'publish date',
				'date',
			),
			'visibility'  => array( 'privacy', 'visibility', 'video visibility' ),
		);

		foreach ( $fields as $field => $variants ) {
			if ( in_array( $key, $variants, true ) ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Normalize a CSV header label for matching.
	 *
	 * Lowercases, replaces underscores/dashes with spaces, and collapses
	 * whitespace.
	 *
	 * @param string $label Raw header label.
	 * @return string Normalized key.
	 */
	private function normalize_header( string $label ): string {
		$label = strtolower( trim( $label ) );
		$label = str_replace( array( '_', '-' ), ' ', $label );
		$label = preg_replace( '/\s+/', ' ', $label ) ?? $label;

		return trim( $label );
	}

	/**
	 * Get a cell value by internal field name.
	 *
	 * @param array<int, string> $row   Row cells.
	 * @param array<string, int> $map   Column map.
	 * @param string             $field Internal field name.
	 * @return string Trimmed cell value or empty string.
	 */
	private function cell( array $row, array $map, string $field ): string {
		if ( ! isset( $map[ $field ] ) ) {
			return '';
		}

		return trim( $row[ $map[ $field ] ] ?? '' );
	}

	/**
	 * Check whether a ZIP entry name is a CSV file.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	private function is_csv_name( string $name ): bool {
		return 1 === preg_match( '/\.csv$/i', $name );
	}

	/**
	 * Fetch videos from the YouTube Data API v3.
	 *
	 * Resolves the channel's uploads playlist, then pages through
	 * playlistItems.list until the cap is reached or items are exhausted.
	 *
	 * @param array<string, mixed> $credentials Stored credentials.
	 * @return array<string, array<string, mixed>> Videos keyed by ID.
	 * @throws RuntimeException If a request fails.
	 */
	private function fetch_videos_from_api( array $credentials ): array {
		$api_key    = (string) ( $credentials['api_key'] ?? '' );
		$channel_id = (string) ( $credentials['channel_id'] ?? '' );
		$uploads_id = $this->resolve_uploads_playlist( $api_key, $channel_id );

		$videos     = array();
		$page_token = '';

		do {
			$args = array(
				'part'       => 'snippet,contentDetails',
				'playlistId' => $uploads_id,
				'maxResults' => '50',
			);

			if ( '' !== $page_token ) {
				$args['pageToken'] = $page_token;
			}

			$response = $this->api_request( 'playlistItems', $args, $api_key );

			if ( is_wp_error( $response ) ) {
				$messages = $response->get_error_messages();
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException(
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to fetch playlist items from the YouTube Data API: %s', 'ai-importer' ),
						! empty( $messages ) ? $messages[0] : __( 'Unknown error', 'ai-importer' )
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			foreach ( (array) ( $response['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$video = $this->parse_api_item( $item );

				if ( null !== $video ) {
					$videos[ $video['id'] ] = $video;
				}

				if ( count( $videos ) >= self::MAX_API_ITEMS ) {
					$this->log_error(
						'YouTube Data API import capped.',
						array(
							'cap'        => self::MAX_API_ITEMS,
							'channel_id' => $channel_id,
						)
					);
					break 2;
				}
			}

			$page_token = (string) ( $response['nextPageToken'] ?? '' );
		} while ( '' !== $page_token );

		if ( empty( $videos ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No videos found for the YouTube channel.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $videos;
	}

	/**
	 * Resolve a channel's uploads playlist ID.
	 *
	 * @param string $api_key    Data API key.
	 * @param string $channel_id Channel ID.
	 * @return string Uploads playlist ID.
	 * @throws RuntimeException If the channel cannot be resolved.
	 */
	private function resolve_uploads_playlist( string $api_key, string $channel_id ): string {
		$response = $this->api_request(
			'channels',
			array(
				'part' => 'contentDetails',
				'id'   => $channel_id,
			),
			$api_key
		);

		if ( is_wp_error( $response ) ) {
			$messages = $response->get_error_messages();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to resolve the YouTube channel: %s', 'ai-importer' ),
					! empty( $messages ) ? $messages[0] : __( 'Unknown error', 'ai-importer' )
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$uploads_id = $response['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '';

		if ( ! is_string( $uploads_id ) || '' === $uploads_id ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Could not find an uploads playlist for the YouTube channel.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $uploads_id;
	}

	/**
	 * Parse a Data API playlistItems entry into a video record.
	 *
	 * @param array<string, mixed> $item playlistItems.list item.
	 * @return array<string, mixed>|null Video record or null to skip.
	 */
	private function parse_api_item( array $item ): ?array {
		$snippet  = is_array( $item['snippet'] ?? null ) ? $item['snippet'] : array();
		$details  = is_array( $item['contentDetails'] ?? null ) ? $item['contentDetails'] : array();
		$video_id = (string) ( $details['videoId'] ?? $snippet['resourceId']['videoId'] ?? '' );

		if ( '' === $video_id ) {
			return null;
		}

		$published = (string) ( $details['videoPublishedAt'] ?? $snippet['publishedAt'] ?? '' );
		$thumbnail = $this->best_thumbnail( $snippet['thumbnails'] ?? array() );

		return array(
			'id'            => $video_id,
			'video_id'      => $video_id,
			'title'         => (string) ( $snippet['title'] ?? '' ),
			'description'   => (string) ( $snippet['description'] ?? '' ),
			'published_at'  => $this->parse_date( $published ),
			'channel_title' => (string) ( $snippet['channelTitle'] ?? '' ),
			'duration'      => null,
			'tags'          => $this->normalize_tags( $snippet['tags'] ?? array() ),
			'visibility'    => 'public',
			'media_urls'    => '' !== $thumbnail ? array( $thumbnail ) : array(),
			'original_url'  => $this->watch_url( $video_id ),
		);
	}

	/**
	 * Pick the highest-resolution thumbnail URL available.
	 *
	 * @param mixed $thumbnails Thumbnails map from a snippet.
	 * @return string Thumbnail URL or empty string.
	 */
	private function best_thumbnail( mixed $thumbnails ): string {
		if ( ! is_array( $thumbnails ) ) {
			return '';
		}

		foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
			$url = $thumbnails[ $size ]['url'] ?? '';

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Normalize a tags value to a list of non-empty strings.
	 *
	 * @param mixed $tags Raw tags value.
	 * @return array<string>
	 */
	private function normalize_tags( mixed $tags ): array {
		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();

		foreach ( $tags as $tag ) {
			$tag = trim( (string) $tag );

			if ( '' !== $tag ) {
				$clean[] = $tag;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Make a YouTube Data API request and parse the JSON response.
	 *
	 * @param string                $endpoint Resource name (channels, playlistItems).
	 * @param array<string, string> $args     Query args (excluding key).
	 * @param string                $api_key  Data API key.
	 * @return array<string, mixed>|WP_Error Parsed body or error.
	 */
	private function api_request( string $endpoint, array $args, string $api_key ): array|WP_Error {
		$query = array_merge( $args, array( 'key' => $api_key ) );
		$url   = self::API_BASE . '/' . $endpoint . '?' . http_build_query( $query );

		$response = $this->http_get( $url );

		return $this->parse_json_response( $response );
	}

	/**
	 * Build the post content for a video.
	 *
	 * Emits a core/embed block for the watch URL (so WordPress renders the
	 * player) followed by the description as paragraphs.
	 *
	 * @param array<string, mixed> $video Parsed video.
	 * @return string
	 */
	private function build_content( array $video ): string {
		$watch_url = (string) $video['original_url'];

		$embed  = '<!-- wp:embed {"url":"' . esc_url( $watch_url ) . '","type":"video","providerNameSlug":"youtube","responsive":true} -->' . "\n";
		$embed .= '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio">';
		$embed .= '<div class="wp-block-embed__wrapper">' . "\n" . esc_url( $watch_url ) . "\n" . '</div>';
		$embed .= '</figure>' . "\n" . '<!-- /wp:embed -->';

		$description = trim( (string) $video['description'] );

		if ( '' === $description ) {
			return $embed;
		}

		$paragraphs = array();
		$blocks     = preg_split( '/\n\s*\n/', $description );
		$blocks     = is_array( $blocks ) ? $blocks : array( $description );

		foreach ( $blocks as $block ) {
			$block = trim( (string) $block );

			if ( '' === $block ) {
				continue;
			}

			$paragraphs[] = '<!-- wp:paragraph --><p>' . nl2br( esc_html( $block ) ) . '</p><!-- /wp:paragraph -->';
		}

		if ( empty( $paragraphs ) ) {
			return $embed;
		}

		return $embed . "\n\n" . implode( "\n\n", $paragraphs );
	}

	/**
	 * Build the canonical watch URL for a video ID.
	 *
	 * @param string $video_id Video ID.
	 * @return string
	 */
	private function watch_url( string $video_id ): string {
		return 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
	}

	/**
	 * Build the manifest author array for a video.
	 *
	 * @param array<string, mixed> $video Parsed video.
	 * @return array<string, string>|null
	 */
	private function build_author( array $video ): ?array {
		$channel = trim( (string) ( $video['channel_title'] ?? '' ) );

		return '' !== $channel ? array( 'name' => $channel ) : null;
	}

	/**
	 * Build a short excerpt from a video description.
	 *
	 * @param string $description Video description.
	 * @return string|null Plain-text excerpt or null when empty.
	 */
	private function build_excerpt( string $description ): ?string {
		$plain = trim( preg_replace( '/\s+/', ' ', $description ) ?? '' );

		if ( '' === $plain ) {
			return null;
		}

		if ( mb_strlen( $plain ) <= 160 ) {
			return $plain;
		}

		return mb_substr( $plain, 0, 157 ) . '...';
	}

	/**
	 * Parse a date string into a DateTimeImmutable.
	 *
	 * @param string $date_string Date string.
	 * @return DateTimeImmutable
	 */
	private function parse_date( string $date_string ): DateTimeImmutable {
		if ( '' === $date_string ) {
			return new DateTimeImmutable();
		}

		try {
			return new DateTimeImmutable( $date_string );
		} catch ( \Exception $e ) {
			return new DateTimeImmutable();
		}
	}
}
