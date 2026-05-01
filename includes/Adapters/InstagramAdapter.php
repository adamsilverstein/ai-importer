<?php
/**
 * Instagram data download adapter.
 *
 * Parses an Instagram "Download your information" ZIP (JSON format)
 * and extracts feed posts, reels, and stories.
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
 * Adapter for importing content from an Instagram data download archive.
 *
 * Instagram exports (Settings → Accounts Center → Your information and
 * permissions → Download your information) ship JSON files inside the
 * `your_instagram_activity/content/` directory:
 *
 *   posts_*.json   — feed posts (single image, video, or carousel)
 *   reels.json     — short videos
 *   stories.json   — stories (24-hour content)
 *
 * Media binaries live alongside under `media/{posts|reels|stories}/...`.
 * The exporter has changed shape over the years; this parser tolerates
 * both list-shaped and object-with-list-key payloads.
 */
class InstagramAdapter extends AbstractAdapter {

	/**
	 * Parsed items keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $items = null;

	/**
	 * Get the unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'instagram';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Instagram';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import posts, reels, and stories from your Instagram data download.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-format-image';
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
	 * Authenticate by validating an uploaded data download ZIP.
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
			$this->log_error( 'Instagram data download file not found.', $credentials );
			return false;
		}

		$validation = $this->validate_archive( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Instagram archive validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->items = null;

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

		$cached = $this->get_cache( 'manifest' );

		if ( $cached instanceof ContentManifest ) {
			return $cached;
		}

		$items    = $this->get_items();
		$manifest = new ContentManifest( $this->get_id() );

		foreach ( $items as $id => $item ) {
			$manifest_item = new ManifestItem(
				id: $id,
				type: $this->classify_item( $item ),
				title: $item['title'],
				created_at: $item['created_at'],
				excerpt: $this->build_excerpt( (string) ( $item['caption'] ?? '' ) ),
				media_urls: array_map( static fn( $m ) => $m['source'], $item['media'] ),
				metadata: array(
					'source_kind' => $item['source_kind'],
					'media_count' => count( $item['media'] ),
				),
				original_url: null,
			);

			$manifest->add_item( $manifest_item );
		}

		$this->set_cache( 'manifest', $manifest, 86400 );

		return $manifest;
	}

	/**
	 * Fetch a single item by ID with full content.
	 *
	 * @param string $item_id Item ID.
	 * @return array<string, mixed>
	 * @throws RuntimeException If not authenticated or item not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$items = $this->get_items();

		if ( ! isset( $items[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: item ID */
					__( 'Instagram item with ID "%s" not found in archive.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$item        = $items[ $item_id ];
		$media_urls  = array();
		$media_paths = array();

		foreach ( $item['media'] as $media ) {
			$media_urls[]  = $media['source'];
			$media_paths[] = $media['archive_path'];
		}

		$caption = (string) ( $item['caption'] ?? '' );

		return array(
			'id'           => $item_id,
			'type'         => $this->classify_item( $item )->value,
			'content'      => $caption,
			'title'        => $item['title'],
			'created_at'   => $item['created_at']->format( 'c' ),
			'media_urls'   => $media_urls,
			'media_paths'  => $media_paths,
			'metadata'     => array(
				'source_kind' => $item['source_kind'],
				'media_count' => count( $item['media'] ),
			),
			'original_url' => null,
			'tags'         => $this->extract_hashtags( $caption ),
			'raw'          => $item,
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
			ContentType::MEDIA->value,
			ContentType::STORY->value,
			ContentType::VIDEO->value,
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
				'label'       => __( 'Instagram Data Download', 'ai-importer' ),
				'description' => __( 'Upload your Instagram data download ZIP. Request the JSON format from Accounts Center > Your information and permissions > Download your information.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		return $schema;
	}

	/**
	 * Validate that a ZIP looks like an Instagram data download.
	 *
	 * Instagram archives contain at least one of: posts*.json, reels.json,
	 * or stories.json under content/. We accept the archive as soon as one
	 * of those is found.
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

		$found = false;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( preg_match( '#(?:^|/)content/(?:posts_\d+|posts|reels|stories)\.json$#i', $name ) ) {
				$found = true;
				break;
			}
		}

		$zip->close();

		if ( ! $found ) {
			return new WP_Error(
				'missing_content',
				__( 'This ZIP does not appear to be an Instagram data download. Could not find any content/posts*.json, reels.json, or stories.json files.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Get all parsed items, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Items keyed by ID.
	 * @throws RuntimeException If archive cannot be read.
	 */
	private function get_items(): array {
		if ( null !== $this->items ) {
			return $this->items;
		}

		$credentials = $this->get_stored_credentials();
		$file_path   = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Instagram archive file not found. Please re-upload your archive.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->items = $this->parse_archive( $file_path );

		return $this->items;
	}

	/**
	 * Parse the Instagram data download ZIP and extract all items.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return array<string, array<string, mixed>> Items keyed by ID.
	 * @throws RuntimeException If parsing fails.
	 */
	private function parse_archive( string $file_path ): array {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to open Instagram archive ZIP file.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$items = array();

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			$kind = $this->classify_content_file( $name );
			if ( null === $kind ) {
				continue;
			}

			$json = $zip->getFromName( $name );
			if ( false === $json ) {
				$this->log_error( 'Failed to read content file from archive.', array( 'file' => $name ) );
				continue;
			}

			foreach ( $this->parse_content_json( $json, $kind ) as $entry ) {
				$items[ $entry['id'] ] = $entry;
			}
		}

		$zip->close();

		if ( empty( $items ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No content found in the Instagram archive. The file may be empty or in an unsupported format.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $items;
	}

	/**
	 * Determine which kind of content a JSON file holds.
	 *
	 * @param string $name Filename inside the ZIP.
	 * @return string|null One of 'posts', 'reels', 'stories', or null when not a content file.
	 */
	private function classify_content_file( string $name ): ?string {
		if ( preg_match( '#(?:^|/)content/posts(?:_\d+)?\.json$#i', $name ) ) {
			return 'posts';
		}

		if ( preg_match( '#(?:^|/)content/reels\.json$#i', $name ) ) {
			return 'reels';
		}

		if ( preg_match( '#(?:^|/)content/stories\.json$#i', $name ) ) {
			return 'stories';
		}

		return null;
	}

	/**
	 * Parse a single content JSON file into normalized entries.
	 *
	 * Tolerates both flat-list payloads (posts_1.json style) and
	 * object-with-list-key payloads (e.g. reels.json's
	 * `ig_reels_media` wrapper).
	 *
	 * @param string $json Raw JSON content.
	 * @param string $kind 'posts' | 'reels' | 'stories'.
	 * @return array<int, array<string, mixed>> Parsed entries.
	 */
	private function parse_content_json( string $json, string $kind ): array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			$this->log_error( 'Failed to parse Instagram JSON.', array( 'json_error' => json_last_error_msg() ) );
			return array();
		}

		$entries = $this->unwrap_payload( $data );
		$out     = array();

		foreach ( $entries as $idx => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$parsed = $this->parse_entry( $raw, $kind, $idx );
			if ( null !== $parsed ) {
				$out[] = $parsed;
			}
		}

		return $out;
	}

	/**
	 * Pull the array of entries from a top-level payload.
	 *
	 * Handles three shapes Instagram has used:
	 *   - direct list:   `[{...}, {...}]`
	 *   - keyed list:    `{ "ig_reels_media": [{...}] }` or `{ "ig_stories": [{...}] }`
	 *   - keyed unknown: `{ "<some_key>": [{...}] }` — first array value wins.
	 *
	 * @param array<string, mixed>|array<int, mixed> $data Decoded JSON.
	 * @return array<int, mixed> Entries.
	 */
	private function unwrap_payload( array $data ): array {
		if ( array_is_list( $data ) ) {
			return $data;
		}

		// Try known keys first.
		foreach ( array( 'ig_reels_media', 'ig_stories', 'ig_posts', 'ig_other_media' ) as $known ) {
			if ( isset( $data[ $known ] ) && is_array( $data[ $known ] ) ) {
				return array_values( $data[ $known ] );
			}
		}

		// Fallback: first array value in the payload.
		foreach ( $data as $value ) {
			if ( is_array( $value ) && array_is_list( $value ) ) {
				return $value;
			}
		}

		return array();
	}

	/**
	 * Parse one Instagram entry into a normalized item shape.
	 *
	 * @param array<string, mixed> $raw  Raw entry.
	 * @param string               $kind Source content kind.
	 * @param int                  $idx  Position in source file (used for ID fallback).
	 * @return array<string, mixed>|null Parsed entry or null when invalid.
	 */
	private function parse_entry( array $raw, string $kind, int $idx ): ?array {
		$caption = '';
		$title   = null;

		// Posts can carry a top-level title (caption); stories store it on each media entry.
		if ( isset( $raw['title'] ) && is_string( $raw['title'] ) ) {
			$caption = $raw['title'];
		}

		// Collect media entries.
		$media_raw = array();
		if ( isset( $raw['media'] ) && is_array( $raw['media'] ) ) {
			$media_raw = $raw['media'];
		} elseif ( isset( $raw['uri'] ) ) {
			// Some shapes (stories.json) put uri at the entry level.
			$media_raw = array( $raw );
		}

		$media     = array();
		$timestamp = 0;

		foreach ( $media_raw as $m ) {
			if ( ! is_array( $m ) || empty( $m['uri'] ) ) {
				continue;
			}

			$archive_path = (string) $m['uri'];
			$media[]      = array(
				'archive_path' => $archive_path,
				'source'       => $archive_path,
				'mime_hint'    => $this->guess_mime_from_path( $archive_path ),
			);

			$ts = (int) ( $m['creation_timestamp'] ?? 0 );
			if ( $ts > 0 && ( 0 === $timestamp || $ts < $timestamp ) ) {
				$timestamp = $ts;
			}

			// First per-media title doubles as the caption when none was set at the entry level.
			if ( '' === $caption && isset( $m['title'] ) && is_string( $m['title'] ) ) {
				$caption = $m['title'];
			}
		}

		// Skip entries that have neither media nor a caption.
		if ( empty( $media ) && '' === trim( $caption ) ) {
			return null;
		}

		if ( 0 === $timestamp ) {
			$timestamp = (int) ( $raw['creation_timestamp'] ?? 0 );
		}

		$created_at = $timestamp > 0
			? new DateTimeImmutable( '@' . $timestamp )
			: new DateTimeImmutable();

		$id = $this->build_id( $kind, $idx, $media, $timestamp );

		// Title: first line of caption, capped at 100 chars.
		if ( '' !== trim( $caption ) ) {
			$first_line = strtok( $caption, "\n" );
			$title      = mb_strlen( $first_line ) > 100
				? mb_substr( $first_line, 0, 97 ) . '...'
				: $first_line;
		} else {
			$title = sprintf(
				/* translators: %s: kind (post, reel, story) */
				__( 'Instagram %s', 'ai-importer' ),
				$kind
			);
		}

		return array(
			'id'          => $id,
			'source_kind' => $kind,
			'caption'     => $caption,
			'title'       => $title,
			'created_at'  => $created_at,
			'media'       => $media,
		);
	}

	/**
	 * Build a stable ID from kind, position, and content.
	 *
	 * Instagram exports do not include a public-facing post ID, so we
	 * compose one from the media URI (or a hash) plus timestamp so the
	 * same entry imports consistently across re-uploads.
	 *
	 * @param string                           $kind      Content kind.
	 * @param int                              $idx       Position in file.
	 * @param array<int, array<string, mixed>> $media     Parsed media list.
	 * @param int                              $timestamp Earliest media timestamp.
	 * @return string
	 */
	private function build_id( string $kind, int $idx, array $media, int $timestamp ): string {
		$first    = $media[0]['archive_path'] ?? "{$kind}-{$idx}";
		$ts_token = $timestamp > 0 ? (string) $timestamp : 'na';

		return sprintf(
			'%s_%s_%s',
			$kind,
			$ts_token,
			substr( md5( $first ), 0, 12 )
		);
	}

	/**
	 * Guess a MIME type from the archive path.
	 *
	 * @param string $path Archive path.
	 * @return string|null MIME type or null.
	 */
	private function guess_mime_from_path( string $path ): ?string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return match ( $ext ) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png'         => 'image/png',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'mp4'         => 'video/mp4',
			'mov'         => 'video/quicktime',
			'webm'        => 'video/webm',
			default       => null,
		};
	}

	/**
	 * Classify an Instagram entry into a ContentType based on source and media.
	 *
	 * @param array<string, mixed> $item Parsed entry.
	 * @return ContentType
	 */
	private function classify_item( array $item ): ContentType {
		switch ( $item['source_kind'] ) {
			case 'reels':
				return ContentType::VIDEO;
			case 'stories':
				return ContentType::STORY;
			default:
				$has_video = false;
				foreach ( $item['media'] as $media ) {
					if ( null !== $media['mime_hint'] && str_starts_with( $media['mime_hint'], 'video/' ) ) {
						$has_video = true;
						break;
					}
				}
				return $has_video ? ContentType::VIDEO : ContentType::POST;
		}
	}

	/**
	 * Build a short excerpt from the caption.
	 *
	 * @param string $caption Caption text.
	 * @return string|null Short excerpt or null when caption is empty/short.
	 */
	private function build_excerpt( string $caption ): ?string {
		$plain = trim( preg_replace( '/\s+/', ' ', $caption ) ?? '' );

		if ( '' === $plain ) {
			return null;
		}

		if ( mb_strlen( $plain ) <= 160 ) {
			return $plain;
		}

		return mb_substr( $plain, 0, 157 ) . '...';
	}

	/**
	 * Extract hashtags from caption text.
	 *
	 * @param string $caption Caption text.
	 * @return array<string>
	 */
	private function extract_hashtags( string $caption ): array {
		$tags = array();

		if ( preg_match_all( '/#(\w+)/u', $caption, $matches ) ) {
			$tags = array_values( array_unique( $matches[1] ) );
		}

		return $tags;
	}
}
