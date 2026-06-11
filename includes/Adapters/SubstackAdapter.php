<?php
/**
 * Substack export adapter.
 *
 * Parses a Substack data export ZIP and extracts posts.
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
 * Adapter for importing content from a Substack data export ZIP.
 *
 * Substack exports (Settings → Exports → Create new export) ship a
 * `posts.csv` inventory at the archive root plus a `posts/` directory
 * with one `{post_id}.html` file per post containing the body HTML.
 *
 * Assumed `posts.csv` format (columns are mapped defensively by header
 * name, so extra or reordered columns are tolerated):
 *
 *   - `post_id`       - unique ID, typically `{number}.{slug}`.
 *   - `post_date`     - ISO-8601 publish/creation date.
 *   - `is_published`  - `true`/`false`; drafts are excluded from import.
 *   - `email_sent_at` - ISO-8601 date the email went out (may be empty).
 *   - `type`          - `newsletter`, `podcast`, or `thread`.
 *   - `audience`      - `everyone`, `only_paid`, etc.
 *   - `title`         - post title.
 *   - `subtitle`      - post subtitle (may contain quoted newlines).
 *   - `podcast_url`   - audio URL for podcast posts (may be empty).
 *
 * Substack types map onto the existing ContentType enum: podcast →
 * MEDIA, thread → THREAD, and newsletter → ARTICLE for long content
 * (≥500 words) or POST otherwise. The original Substack type is kept
 * on the manifest item under `metadata.post_type`.
 *
 * Media in post HTML is referenced by absolute https URLs on the
 * Substack CDN and flows through the normal download path. Relative
 * references are handled defensively by extracting the file from the
 * archive to a temp file, exposed via `media_paths` aligned by index
 * with `media_urls`.
 */
class SubstackAdapter extends AbstractAdapter {

	/**
	 * Substack post types we recognize.
	 */
	private const TYPE_NEWSLETTER = 'newsletter';
	private const TYPE_PODCAST    = 'podcast';
	private const TYPE_THREAD     = 'thread';

	/**
	 * CSV columns we map onto parsed posts.
	 *
	 * @var array<string>
	 */
	private const KNOWN_COLUMNS = array(
		'post_id',
		'post_date',
		'is_published',
		'email_sent_at',
		'type',
		'audience',
		'title',
		'subtitle',
		'podcast_url',
	);

	/**
	 * Parsed posts keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $posts = null;

	/**
	 * Get the unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'substack';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Substack';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import newsletters and podcasts from your Substack data export ZIP.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-email-alt';
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
	 * Authenticate by validating an uploaded Substack export ZIP.
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
			$this->log_error( 'Substack export file not found.', $credentials );
			return false;
		}

		$validation = $this->validate_archive( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Substack export validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->posts = null;

		return true;
	}

	/**
	 * Fetch the content manifest from the export.
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

		$posts    = $this->get_posts();
		$manifest = new ContentManifest( $this->get_id() );

		foreach ( $posts as $id => $post ) {
			$item = new ManifestItem(
				id: $id,
				type: $this->classify_post( $post ),
				title: $post['title'],
				created_at: $post['published_at'],
				excerpt: $this->build_excerpt( $post ),
				media_urls: $post['media_urls'],
				metadata: array(
					'post_type'   => $post['post_type'],
					'subtitle'    => $post['subtitle'],
					'audience'    => $post['audience'],
					'podcast_url' => $post['podcast_url'],
				),
				original_url: null,
				author: null,
			);

			$manifest->add_item( $item );
		}

		$this->set_cache( 'manifest', $manifest, 86400 );

		return $manifest;
	}

	/**
	 * Fetch a single post by ID with full content.
	 *
	 * @param string $item_id Post ID.
	 * @return array<string, mixed>
	 * @throws RuntimeException If not authenticated or post not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$posts = $this->get_posts();

		if ( ! isset( $posts[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: post ID */
					__( 'Substack post with ID "%s" not found in export.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$post = $posts[ $item_id ];

		if ( ! array_key_exists( 'media_paths', $post ) ) {
			$this->posts[ $item_id ]['media_paths'] = $this->resolve_local_media( $post );

			$post = $this->posts[ $item_id ];
		}

		return array(
			'id'           => $item_id,
			'type'         => $this->classify_post( $post )->value,
			'content'      => $post['content'],
			'title'        => $post['title'],
			'created_at'   => $post['published_at']->format( 'c' ),
			'media_urls'   => $post['media_urls'],
			'media_paths'  => $post['media_paths'],
			'metadata'     => array(
				'post_type'     => $post['post_type'],
				'subtitle'      => $post['subtitle'],
				'audience'      => $post['audience'],
				'podcast_url'   => $post['podcast_url'],
				'email_sent_at' => $post['email_sent_at'],
			),
			'original_url' => null,
			'author'       => null,
			'raw'          => $post,
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
			ContentType::ARTICLE->value,
			ContentType::MEDIA->value,
			ContentType::THREAD->value,
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
				'label'       => __( 'Substack Export', 'ai-importer' ),
				'description' => __( 'Upload your Substack data export ZIP. Get it from Settings > Exports > Create new export.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		return $schema;
	}

	/**
	 * Validate that a ZIP file is a Substack data export.
	 *
	 * Substack exports always contain a posts.csv inventory. That's the
	 * structural marker we rely on.
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

		$found = null !== $this->find_csv_entry( $zip );

		$zip->close();

		if ( ! $found ) {
			return new WP_Error(
				'missing_posts_csv',
				__( 'This ZIP does not appear to be a Substack export. Could not find a posts.csv file.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Locate the posts.csv entry within the archive.
	 *
	 * Prefers a root-level posts.csv but tolerates exports nested in a
	 * subdirectory (e.g. when the user re-zips the extracted folder).
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return string|null Entry name, or null when not found.
	 */
	private function find_csv_entry( \ZipArchive $zip ): ?string {
		$best = null;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( 'posts.csv' === $name ) {
				return $name;
			}

			if ( null === $best && preg_match( '#(?:^|/)posts\.csv$#', $name ) ) {
				$best = $name;
			}
		}

		return $best;
	}

	/**
	 * Get all parsed posts, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If archive cannot be read.
	 */
	private function get_posts(): array {
		if ( null !== $this->posts ) {
			return $this->posts;
		}

		$credentials = $this->get_stored_credentials();
		$file_path   = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Substack export file not found. Please re-upload your archive.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->posts = $this->parse_archive( $file_path );

		return $this->posts;
	}

	/**
	 * Parse the Substack export ZIP and extract posts.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If parsing fails.
	 */
	private function parse_archive( string $file_path ): array {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to open Substack export ZIP file.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$csv_entry = $this->find_csv_entry( $zip );

		if ( null === $csv_entry ) {
			$zip->close();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No posts.csv found in the Substack export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$rows  = $this->parse_csv( $zip, $csv_entry );
		$root  = dirname( $csv_entry );
		$root  = '.' === $root ? '' : $root . '/';
		$posts = array();

		foreach ( $rows as $row ) {
			$post = $this->build_post( $zip, $row, $root );

			if ( null === $post ) {
				continue;
			}

			$posts[ $post['id'] ] = $post;
		}

		$zip->close();

		if ( empty( $posts ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No published posts found in the Substack export. The archive may be empty or in an unsupported format.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $posts;
	}

	/**
	 * Parse posts.csv rows from the archive into associative arrays.
	 *
	 * Streams the entry through fgetcsv() so quoted fields with embedded
	 * newlines and commas are handled correctly. Columns are mapped by
	 * header name; unknown columns are ignored and missing ones default
	 * to empty strings.
	 *
	 * @param \ZipArchive $zip       Open archive.
	 * @param string      $csv_entry Entry name of posts.csv.
	 * @return array<int, array<string, string>> Rows keyed by column name.
	 * @throws RuntimeException If the CSV cannot be read.
	 */
	private function parse_csv( \ZipArchive $zip, string $csv_entry ): array {
		$stream = $zip->getStream( $csv_entry );

		if ( false === $stream ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to read posts.csv from the Substack export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$header = fgetcsv( $stream, 0, ',', '"', '\\' );

		if ( ! is_array( $header ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a ZipArchive entry stream.
			fclose( $stream );
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'posts.csv in the Substack export is empty or unreadable.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Strip a potential UTF-8 BOM from the first header cell.
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		}

		$columns = array();
		foreach ( $header as $index => $name ) {
			$columns[ $index ] = strtolower( trim( (string) $name ) );
		}

		$rows = array();

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard fgetcsv loop.
		while ( false !== ( $fields = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) {
			if ( array( null ) === $fields ) {
				continue;
			}

			$row = array_fill_keys( self::KNOWN_COLUMNS, '' );

			foreach ( $fields as $index => $value ) {
				$column = $columns[ $index ] ?? '';

				if ( in_array( $column, self::KNOWN_COLUMNS, true ) ) {
					$row[ $column ] = trim( (string) $value );
				}
			}

			if ( '' !== $row['post_id'] ) {
				$rows[] = $row;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a ZipArchive entry stream.
		fclose( $stream );

		return $rows;
	}

	/**
	 * Build a parsed post from a CSV row plus its body HTML.
	 *
	 * Drafts (is_published false) are excluded. Posts whose HTML body is
	 * missing from the archive are kept when they still carry a title or
	 * a podcast URL, so podcast episodes without show notes import.
	 *
	 * @param \ZipArchive           $zip  Open archive.
	 * @param array<string, string> $row  CSV row keyed by column name.
	 * @param string                $root Archive path prefix ('' or 'dir/').
	 * @return array<string, mixed>|null Parsed post or null to skip.
	 */
	private function build_post( \ZipArchive $zip, array $row, string $root ): ?array {
		if ( ! filter_var( $row['is_published'], FILTER_VALIDATE_BOOLEAN ) ) {
			return null;
		}

		$post_id = $row['post_id'];
		$entry   = $root . 'posts/' . $post_id . '.html';
		$content = $zip->getFromName( $entry );

		if ( false === $content ) {
			$this->log_error( 'Post HTML file not found in Substack export.', array( 'entry' => $entry ) );
			$content = '';
		}

		$content = trim( $content );
		$title   = $row['title'];

		// Skip rows with no usable content at all.
		if ( '' === $title && '' === trim( wp_strip_all_tags( $content ) ) && '' === $row['podcast_url'] ) {
			return null;
		}

		if ( '' === $title ) {
			$title = $this->title_from_post_id( $post_id );
		}

		return array(
			'id'            => $post_id,
			'archive_entry' => $entry,
			'post_type'     => '' !== $row['type'] ? strtolower( $row['type'] ) : self::TYPE_NEWSLETTER,
			'title'         => $title,
			'subtitle'      => '' !== $row['subtitle'] ? $row['subtitle'] : null,
			'content'       => $content,
			'published_at'  => $this->parse_date( $row['post_date'] ),
			'audience'      => '' !== $row['audience'] ? $row['audience'] : null,
			'podcast_url'   => '' !== $row['podcast_url'] ? $row['podcast_url'] : null,
			'email_sent_at' => '' !== $row['email_sent_at'] ? $row['email_sent_at'] : null,
			'media_urls'    => $this->extract_media_urls( $content ),
		);
	}

	/**
	 * Derive a fallback title from a Substack post ID.
	 *
	 * Post IDs follow the `{number}.{slug}` convention, so the slug part
	 * makes a readable last-resort title.
	 *
	 * @param string $post_id Post ID.
	 * @return string
	 */
	private function title_from_post_id( string $post_id ): string {
		$dot  = strpos( $post_id, '.' );
		$slug = false !== $dot ? substr( $post_id, $dot + 1 ) : $post_id;

		return ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Pull media URLs from img/video/audio/source tags in the body HTML.
	 *
	 * @param string $content_html Post body HTML.
	 * @return array<string>
	 */
	private function extract_media_urls( string $content_html ): array {
		if ( '' === $content_html ) {
			return array();
		}

		$urls = array();

		if ( preg_match_all( '/<(?:img|video|audio|source)[^>]+src=["\']([^"\']+)["\']/i', $content_html, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$src = trim( $src );
				if ( '' !== $src ) {
					$urls[] = $src;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Resolve archive-relative media references to extracted local files.
	 *
	 * Substack post HTML normally references media by absolute https CDN
	 * URLs, which flow through the download path. Relative references are
	 * handled defensively: each is extracted from the ZIP to a temp file
	 * so the import processor can sideload it via `local_path`. Absolute
	 * http(s) URLs resolve to null entries.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return array<int, string|null> Local file paths aligned by index
	 *                                 with the post's media_urls.
	 */
	private function resolve_local_media( array $post ): array {
		$media_urls = is_array( $post['media_urls'] ?? null ) ? $post['media_urls'] : array();
		$paths      = array_fill( 0, count( $media_urls ), null );

		$has_relative = false;
		foreach ( $media_urls as $url ) {
			if ( $this->is_relative_media_path( (string) $url ) ) {
				$has_relative = true;
				break;
			}
		}

		if ( ! $has_relative ) {
			return $paths;
		}

		$credentials = $this->get_stored_credentials();
		$file_path   = (string) ( $credentials['file_path'] ?? '' );

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return $paths;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			$this->log_error( 'Failed to open Substack export ZIP for media extraction.' );
			return $paths;
		}

		$entry_dir = dirname( (string) ( $post['archive_entry'] ?? '' ) );

		foreach ( $media_urls as $index => $url ) {
			$url = (string) $url;

			if ( ! $this->is_relative_media_path( $url ) ) {
				continue;
			}

			$paths[ $index ] = $this->extract_media_entry( $zip, $url, $entry_dir );
		}

		$zip->close();

		return $paths;
	}

	/**
	 * Check whether a media reference is an archive-relative path.
	 *
	 * Anything with a scheme (http:, https:, data:), a protocol-relative
	 * `//` prefix, or a leading slash is treated as external.
	 *
	 * @param string $url Media reference from the post HTML.
	 * @return bool True when the reference points into the archive.
	 */
	private function is_relative_media_path( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		if ( str_starts_with( $url, '//' ) || str_starts_with( $url, '/' ) ) {
			return false;
		}

		return 1 !== preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url );
	}

	/**
	 * Extract a single media entry from the ZIP to a temp file.
	 *
	 * Tries the reference relative to the post HTML file (handles
	 * `../media/...`), relative to the archive root (handles `media/...`
	 * even when the export lives in a subdirectory), then verbatim.
	 *
	 * @param \ZipArchive $zip           Open archive.
	 * @param string      $relative_path Archive-relative media reference.
	 * @param string      $entry_dir     Directory of the post HTML entry within the ZIP.
	 * @return string|null Absolute path to the extracted temp file, or null.
	 */
	private function extract_media_entry( \ZipArchive $zip, string $relative_path, string $entry_dir ): ?string {
		$parent_dir = '.' === $entry_dir || '' === $entry_dir ? '' : dirname( $entry_dir );
		$parent_dir = '.' === $parent_dir ? '' : $parent_dir;

		$candidates = array_unique(
			array_filter(
				array(
					$this->normalize_zip_path( $entry_dir . '/' . $relative_path ),
					$this->normalize_zip_path( ( '' !== $parent_dir ? $parent_dir . '/' : '' ) . $relative_path ),
					$this->normalize_zip_path( $relative_path ),
				)
			)
		);

		foreach ( $candidates as $candidate ) {
			$contents = $zip->getFromName( $candidate );

			if ( false === $contents ) {
				continue;
			}

			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$tmp_path = wp_tempnam( wp_basename( $candidate ) );

			if ( ! $tmp_path ) {
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing extracted archive media to a temp file for sideloading.
			if ( false === file_put_contents( $tmp_path, $contents ) ) {
				wp_delete_file( $tmp_path );
				return null;
			}

			return $tmp_path;
		}

		$this->log_error( 'Media file not found in Substack export.', array( 'path' => $relative_path ) );

		return null;
	}

	/**
	 * Normalize a ZIP entry path, resolving `.` and `..` segments.
	 *
	 * @param string $path Candidate entry path.
	 * @return string Normalized path with no leading slash.
	 */
	private function normalize_zip_path( string $path ): string {
		$parts = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}

			$parts[] = $segment;
		}

		return implode( '/', $parts );
	}

	/**
	 * Map a parsed Substack post onto a ContentType.
	 *
	 * Podcasts classify as MEDIA (audio content), threads as THREAD, and
	 * newsletters as ARTICLE for long content (≥500 words, consistent
	 * with the Medium, Blogger, and Tumblr adapters) or POST otherwise.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return ContentType
	 */
	private function classify_post( array $post ): ContentType {
		switch ( $post['post_type'] ) {
			case self::TYPE_PODCAST:
				return ContentType::MEDIA;
			case self::TYPE_THREAD:
				return ContentType::THREAD;
			case self::TYPE_NEWSLETTER:
			default:
				$word_count = str_word_count( wp_strip_all_tags( (string) $post['content'] ) );
				return $word_count >= 500 ? ContentType::ARTICLE : ContentType::POST;
		}
	}

	/**
	 * Build a manifest excerpt, preferring the subtitle.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return string|null Plain-text excerpt or null when nothing usable.
	 */
	private function build_excerpt( array $post ): ?string {
		$subtitle = (string) ( $post['subtitle'] ?? '' );

		if ( '' !== trim( $subtitle ) ) {
			return $this->truncate_plain_text( $subtitle );
		}

		return $this->truncate_plain_text( wp_strip_all_tags( (string) $post['content'] ) );
	}

	/**
	 * Collapse whitespace and truncate plain text to excerpt length.
	 *
	 * @param string $text Input text.
	 * @return string|null Truncated text or null when empty.
	 */
	private function truncate_plain_text( string $text ): ?string {
		$plain = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );

		if ( '' === $plain ) {
			return null;
		}

		if ( mb_strlen( $plain ) <= 160 ) {
			return $plain;
		}

		return mb_substr( $plain, 0, 157 ) . '...';
	}

	/**
	 * Parse an ISO-8601 (or other) date string into DateTimeImmutable.
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
