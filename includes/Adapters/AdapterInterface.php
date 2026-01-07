<?php
/**
 * Adapter interface.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters;

use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Schema\SettingsSchema;

/**
 * Interface that all source adapters must implement.
 *
 * An adapter is responsible for connecting to a content source (social media
 * platform, blog, etc.) and extracting content for import into WordPress.
 */
interface AdapterInterface {

	/**
	 * Authentication types.
	 */
	public const AUTH_TYPE_OAUTH       = 'oauth';
	public const AUTH_TYPE_API_KEY     = 'api_key';
	public const AUTH_TYPE_FILE_UPLOAD = 'file_upload';
	public const AUTH_TYPE_SCRAPE      = 'scrape';

	/**
	 * Get the unique identifier for this adapter.
	 *
	 * @return string Adapter ID (e.g., 'twitter', 'medium', 'instagram').
	 */
	public function get_id(): string;

	/**
	 * Get the human-readable name of this adapter.
	 *
	 * @return string Adapter name (e.g., 'Twitter/X', 'Medium', 'Instagram').
	 */
	public function get_name(): string;

	/**
	 * Get a description of this adapter.
	 *
	 * @return string Description text.
	 */
	public function get_description(): string;

	/**
	 * Get the icon for this adapter.
	 *
	 * @return string URL to icon image or dashicon class.
	 */
	public function get_icon(): string;

	/**
	 * Get the authentication type required by this adapter.
	 *
	 * @return string One of the AUTH_TYPE_* constants.
	 */
	public function get_auth_type(): string;

	/**
	 * Authenticate with the source platform.
	 *
	 * @param array<string, mixed> $credentials Authentication credentials.
	 * @return bool True on success, false on failure.
	 */
	public function authenticate( array $credentials ): bool;

	/**
	 * Check if the adapter is currently authenticated.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated(): bool;

	/**
	 * Disconnect from the source platform.
	 *
	 * This should clear any stored credentials and reset authentication state.
	 *
	 * @return void
	 */
	public function disconnect(): void;

	/**
	 * Fetch the content manifest from the source.
	 *
	 * The manifest contains metadata about all available content items
	 * without fetching the full content. This allows users to review
	 * what will be imported before starting the import process.
	 *
	 * @return ContentManifest The content manifest.
	 * @throws \RuntimeException If not authenticated or fetch fails.
	 */
	public function fetch_manifest(): ContentManifest;

	/**
	 * Fetch a single content item by ID.
	 *
	 * Returns the full content and metadata for the specified item.
	 *
	 * @param string $item_id The item ID from the manifest.
	 * @return array<string, mixed> Item data including content, media, metadata.
	 * @throws \RuntimeException If not authenticated or item not found.
	 */
	public function fetch_item( string $item_id ): array;

	/**
	 * Get the settings schema for this adapter.
	 *
	 * The schema defines what configuration options are available for
	 * this adapter (API keys, file uploads, OAuth credentials, etc.).
	 *
	 * @return SettingsSchema The settings schema.
	 */
	public function get_settings_schema(): SettingsSchema;

	/**
	 * Get supported content types for this adapter.
	 *
	 * @return array<string> Array of ContentType values this adapter can produce.
	 */
	public function get_supported_content_types(): array;
}
