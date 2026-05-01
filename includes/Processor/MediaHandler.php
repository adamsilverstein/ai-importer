<?php
/**
 * Media handler for sideloading media into WordPress.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\AI\AltTextGenerator;
use AI_Importer\Normalizer\MediaReference;
use AI_Importer\Normalizer\NormalizedItem;
use RuntimeException;

/**
 * Handles sideloading media from source platforms into the WordPress
 * Media Library. Supports both local files (from ZIP archives) and
 * remote URLs.
 */
class MediaHandler {

	/**
	 * Optional alt-text generator. When provided, images that lack
	 * source-supplied alt text get an AI-generated description.
	 *
	 * @var AltTextGenerator|null
	 */
	private ?AltTextGenerator $alt_text_generator;

	/**
	 * Constructor.
	 *
	 * @param AltTextGenerator|null $alt_text_generator Optional AI alt-text generator.
	 */
	public function __construct( ?AltTextGenerator $alt_text_generator = null ) {
		$this->alt_text_generator = $alt_text_generator;
	}

	/**
	 * Process all media for a normalized item.
	 *
	 * Sideloads each MediaReference into the WordPress Media Library.
	 * Individual failures are caught and logged — they do not prevent
	 * the item from being imported.
	 *
	 * @param NormalizedItem $item    The item whose media to process.
	 * @param int            $post_id Parent post ID (0 for unattached).
	 * @return array<string> Error messages for any failed media.
	 */
	public function process( NormalizedItem $item, int $post_id = 0 ): array {
		$errors = array();

		foreach ( $item->media as $media ) {
			try {
				$this->sideload( $media, $post_id );
			} catch ( RuntimeException $e ) {
				$errors[] = sprintf(
					'Media %s: %s',
					$media->source_url,
					$e->getMessage()
				);
			}
		}

		return $errors;
	}

	/**
	 * Sideload a single media reference into WordPress.
	 *
	 * @param MediaReference $media   The media reference.
	 * @param int            $post_id Parent post ID (0 for unattached).
	 * @return int The WordPress attachment ID.
	 * @throws RuntimeException If sideloading fails.
	 */
	public function sideload( MediaReference $media, int $post_id = 0 ): int {
		$tmp_path = $this->resolve_file( $media );

		$file_array = array(
			'name'     => $media->get_filename() ?? wp_basename( $tmp_path ),
			'tmp_name' => $tmp_path,
		);

		// Ensure media handling functions are available.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			// Clean up temp file on failure.
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}

			$messages = $attachment_id->get_error_messages();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				! empty( $messages ) ? $messages[0] : 'Media sideload failed.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Generate alt text via AI when missing for an image. URL validation
		// (scheme, format) is handled by AltTextGenerator itself.
		$source_url = trim( (string) $media->source_url );
		if (
			$media->is_image() &&
			( null === $media->alt_text || '' === trim( (string) $media->alt_text ) ) &&
			null !== $this->alt_text_generator &&
			'' !== $source_url
		) {
			$generated = $this->alt_text_generator->generate( $source_url );

			if ( ! is_wp_error( $generated ) && '' !== trim( (string) $generated ) ) {
				$media->alt_text = $generated;
			} elseif ( is_wp_error( $generated ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$messages = $generated->get_error_messages();
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic log.
				error_log(
					sprintf(
						'[ai-importer] alt text generation failed for %s: %s',
						$source_url,
						! empty( $messages ) ? $messages[0] : 'unknown error'
					)
				);
			}
		}

		// Set alt text if available.
		if ( $media->alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $media->alt_text );
		}

		// Mark the reference as imported.
		$local_path = get_attached_file( $attachment_id );
		$media->mark_imported( $attachment_id, $local_path ? $local_path : '' );

		return $attachment_id;
	}

	/**
	 * Resolve the file path for a media reference.
	 *
	 * Uses local_path if available (ZIP archive media), otherwise
	 * downloads from the remote source URL.
	 *
	 * @param MediaReference $media The media reference.
	 * @return string Path to the file on disk.
	 * @throws RuntimeException If the file cannot be resolved.
	 */
	private function resolve_file( MediaReference $media ): string {
		// Local file from ZIP archive.
		if ( $media->local_path && file_exists( $media->local_path ) ) {
			return $media->local_path;
		}

		// Download remote URL.
		if ( empty( $media->source_url ) ) {
			throw new RuntimeException( 'No source URL or local path available.' );
		}

		$tmp_path = download_url( $media->source_url );

		if ( is_wp_error( $tmp_path ) ) {
			$messages = $tmp_path->get_error_messages();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				! empty( $messages ) ? $messages[0] : 'Failed to download media.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $tmp_path;
	}
}
