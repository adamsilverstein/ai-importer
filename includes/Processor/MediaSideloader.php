<?php
/**
 * Media sideloader for downloading and importing media.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Normalizer\MediaReference;
use RuntimeException;
use WP_Error;

/**
 * Downloads media from external URLs and creates WordPress attachments.
 */
class MediaSideloader {

	/**
	 * Maximum file size in bytes (10MB).
	 *
	 * @var int
	 */
	private const MAX_FILE_SIZE = 10 * 1024 * 1024;

	/**
	 * Download timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 15;

	/**
	 * Allowed MIME types for sideloading.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'mp4'          => 'video/mp4',
		'webm'         => 'video/webm',
		'mp3'          => 'audio/mpeg',
		'ogg'          => 'audio/ogg',
	);

	/**
	 * Sideload a media reference and attach it to a post.
	 *
	 * @param MediaReference $media_ref The media reference to sideload.
	 * @param int            $post_id   The parent post ID.
	 * @return int The created attachment ID.
	 * @throws RuntimeException On download or import failure.
	 */
	public function sideload( MediaReference $media_ref, int $post_id ): int {
		// Check for existing attachment to avoid duplicates.
		$existing = $this->find_existing_attachment( $media_ref->source_url );
		if ( null !== $existing ) {
			$local_path = get_attached_file( $existing );
			$media_ref->mark_imported( $existing, $local_path ? $local_path : '' );
			return $existing;
		}

		$tmp_path = $this->download_to_temp( $media_ref->source_url );

		try {
			$filename = $media_ref->get_filename() ?? basename( $tmp_path );
			$this->validate_file( $tmp_path, $filename );

			$attachment_id = $this->create_attachment( $tmp_path, $media_ref, $post_id );
			$this->set_attachment_meta( $attachment_id, $media_ref );

			$local_path = get_attached_file( $attachment_id );
			$media_ref->mark_imported( $attachment_id, $local_path ? $local_path : '' );

			return $attachment_id;
		} catch ( RuntimeException $e ) {
			// Clean up temp file on failure.
			wp_delete_file( $tmp_path );
			throw $e;
		}
	}

	/**
	 * Find an existing attachment by original source URL.
	 *
	 * @param string $source_url The original media URL.
	 * @return int|null The attachment ID, or null if not found.
	 */
	public function find_existing_attachment( string $source_url ): ?int {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for media deduplication.
		$posts = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'meta_key'    => '_ai_importer_original_url',
				'meta_value'  => $source_url,
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}

		return null;
	}

	/**
	 * Download a URL to a temporary file.
	 *
	 * @param string $url The URL to download.
	 * @return string Path to the temporary file.
	 * @throws RuntimeException On download failure.
	 */
	private function download_to_temp( string $url ): string {
		$tmp_file = download_url( $url, self::TIMEOUT );

		if ( is_wp_error( $tmp_file ) ) {
			throw new RuntimeException(
				sprintf(
					'Failed to download media from %s: %s',
					esc_html( $url ),
					esc_html( $tmp_file->get_error_message() )
				)
			);
		}

		return $tmp_file;
	}

	/**
	 * Validate a downloaded file.
	 *
	 * @param string $tmp_path Path to the temporary file.
	 * @param string $filename Original filename for type checking.
	 * @return void
	 * @throws RuntimeException If validation fails.
	 */
	private function validate_file( string $tmp_path, string $filename ): void {
		if ( file_exists( $tmp_path ) ) {
			$file_size = filesize( $tmp_path );

			if ( false !== $file_size && $file_size > self::MAX_FILE_SIZE ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Integer value, no escaping needed.
				throw new RuntimeException( sprintf( 'File exceeds maximum size of %dMB.', (int) ( self::MAX_FILE_SIZE / 1024 / 1024 ) ) );
			}
		}

		$filetype = wp_check_filetype( $filename, self::ALLOWED_MIMES );

		if ( empty( $filetype['type'] ) ) {
			throw new RuntimeException(
				sprintf( 'File type not allowed: %s', esc_html( $filename ) )
			);
		}
	}

	/**
	 * Create a WordPress attachment from a temporary file.
	 *
	 * @param string         $tmp_path  Path to the temporary file.
	 * @param MediaReference $media_ref The media reference.
	 * @param int            $post_id   The parent post ID.
	 * @return int The attachment ID.
	 * @throws RuntimeException On failure.
	 */
	private function create_attachment( string $tmp_path, MediaReference $media_ref, int $post_id ): int {
		$file_array = array(
			'name'     => $media_ref->get_filename() ?? basename( $tmp_path ),
			'tmp_name' => $tmp_path,
		);

		$description = $media_ref->caption ?? '';

		$result = media_handle_sideload( $file_array, $post_id, $description );

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException(
				sprintf(
					'Failed to sideload media: %s',
					esc_html( $result->get_error_message() )
				)
			);
		}

		return (int) $result;
	}

	/**
	 * Set metadata on the attachment.
	 *
	 * @param int            $attachment_id The attachment ID.
	 * @param MediaReference $media_ref     The media reference.
	 * @return void
	 */
	private function set_attachment_meta( int $attachment_id, MediaReference $media_ref ): void {
		update_post_meta( $attachment_id, '_ai_importer_original_url', $media_ref->source_url );

		if ( ! empty( $media_ref->alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $media_ref->alt_text );
		}
	}
}
