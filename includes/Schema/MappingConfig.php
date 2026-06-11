<?php
/**
 * Mapping configuration schema and sanitization.
 *
 * @package AI_Importer\Schema
 */

namespace AI_Importer\Schema;

/**
 * Describes the user-editable mapping configuration shape.
 *
 * The shape is derived from MappingSuggester output: a default destination
 * post type and status, optional per-content-type post type overrides, and
 * taxonomy mappings (source signal such as "hashtags" mapped to a
 * destination taxonomy with optional fixed terms).
 *
 * Used by the saved-mappings REST endpoints, by POST /imports when a
 * mapping is attached to a batch, and by ContentCreator when applying the
 * mapping to created posts.
 */
class MappingConfig {

	/**
	 * Option key prefix for saved mappings.
	 */
	public const OPTION_PREFIX = 'ai_importer_mappings_';

	/**
	 * Allowed post statuses for imported content.
	 *
	 * @var array<string>
	 */
	public const ALLOWED_STATUSES = array( 'draft', 'publish', 'pending', 'private' );

	/**
	 * Allowed WordPress post formats.
	 *
	 * Mirrors the core post format slugs ('standard' represents the absence
	 * of a format). See get_post_format_slugs().
	 *
	 * @var array<string>
	 */
	public const ALLOWED_POST_FORMATS = array(
		'standard',
		'aside',
		'gallery',
		'link',
		'image',
		'quote',
		'status',
		'video',
		'audio',
		'chat',
	);

	/**
	 * Option key holding the list of custom taxonomies users have requested
	 * be created during import (F9.3).
	 *
	 * Each entry is an array with 'slug', 'label', and 'post_types' keys.
	 * Registered on init by CustomTaxonomyRegistrar so the taxonomies are
	 * fully functional on subsequent requests.
	 */
	public const OPTION_CUSTOM_TAXONOMIES = 'ai_importer_custom_taxonomies';

	/**
	 * JSON schema for a mapping configuration, suitable for REST route args.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'            => array(
					'type'        => 'string',
					'description' => __( 'Default destination post type.', 'ai-importer' ),
				),
				'post_status'          => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED_STATUSES,
					'description' => __( 'Post status for imported content.', 'ai-importer' ),
				),
				'post_type_mappings'   => array(
					'type'        => 'array',
					'description' => __( 'Per-content-type destination post type overrides.', 'ai-importer' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'source_content_type'   => array( 'type' => 'string' ),
							'destination_post_type' => array( 'type' => 'string' ),
						),
						'required'             => array( 'source_content_type', 'destination_post_type' ),
						'additionalProperties' => false,
					),
				),
				'taxonomy_mappings'    => array(
					'type'        => 'array',
					'description' => __( 'Source signals mapped to destination taxonomies.', 'ai-importer' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'source_signal'        => array( 'type' => 'string' ),
							'destination_taxonomy' => array( 'type' => 'string' ),
							'destination_terms'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'create_if_missing'    => array(
								'type'        => 'boolean',
								'description' => __( 'Register the destination taxonomy during import if it does not exist (F9.3).', 'ai-importer' ),
							),
							'taxonomy_label'       => array(
								'type'        => 'string',
								'description' => __( 'Human-readable label for a custom taxonomy created on demand.', 'ai-importer' ),
							),
						),
						'required'             => array( 'source_signal', 'destination_taxonomy' ),
						'additionalProperties' => false,
					),
				),
				'author_mappings'      => array(
					'type'        => 'array',
					'description' => __( 'Source author names mapped to destination user IDs (F9.2).', 'ai-importer' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'source_author'       => array( 'type' => 'string' ),
							'destination_user_id' => array( 'type' => 'integer' ),
						),
						'required'             => array( 'source_author', 'destination_user_id' ),
						'additionalProperties' => false,
					),
				),
				'default_author_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Fallback author user ID applied to imported posts without a matching author mapping (F9.2).', 'ai-importer' ),
				),
				'post_format_mappings' => array(
					'type'        => 'array',
					'description' => __( 'Per-content-type WordPress post format assignment (F9.4).', 'ai-importer' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'source_content_type' => array( 'type' => 'string' ),
							'post_format'         => array(
								'type' => 'string',
								'enum' => self::ALLOWED_POST_FORMATS,
							),
						),
						'required'             => array( 'source_content_type', 'post_format' ),
						'additionalProperties' => false,
					),
				),
				'default_post_format'  => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED_POST_FORMATS,
					'description' => __( 'Default post format applied to imported posts when no per-content-type mapping matches (F9.4).', 'ai-importer' ),
				),
				'meta_field_mappings'  => array(
					'type'        => 'array',
					'description' => __( 'Item metadata keys copied to destination post meta keys (F9.1, generic post-meta only).', 'ai-importer' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'source_field'         => array( 'type' => 'string' ),
							'destination_meta_key' => array( 'type' => 'string' ),
						),
						'required'             => array( 'source_field', 'destination_meta_key' ),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Sanitize a raw mapping configuration array.
	 *
	 * Whitelists known fields, sanitizes slugs and term names, and drops
	 * malformed entries. Returns a structure safe to persist and apply.
	 *
	 * @param array<string, mixed> $mapping Raw mapping data.
	 * @return array<string, mixed> Sanitized mapping.
	 */
	public static function sanitize( array $mapping ): array {
		$clean = array();

		if ( isset( $mapping['post_type'] ) && is_string( $mapping['post_type'] ) && '' !== $mapping['post_type'] ) {
			$clean['post_type'] = sanitize_key( $mapping['post_type'] );
		}

		if ( isset( $mapping['post_status'] )
			&& is_string( $mapping['post_status'] )
			&& in_array( $mapping['post_status'], self::ALLOWED_STATUSES, true )
		) {
			$clean['post_status'] = $mapping['post_status'];
		}

		if ( isset( $mapping['post_type_mappings'] ) && is_array( $mapping['post_type_mappings'] ) ) {
			$clean['post_type_mappings'] = array();

			foreach ( $mapping['post_type_mappings'] as $entry ) {
				if ( ! is_array( $entry )
					|| empty( $entry['source_content_type'] )
					|| empty( $entry['destination_post_type'] )
					|| ! is_string( $entry['source_content_type'] )
					|| ! is_string( $entry['destination_post_type'] )
				) {
					continue;
				}

				$clean['post_type_mappings'][] = array(
					'source_content_type'   => sanitize_key( $entry['source_content_type'] ),
					'destination_post_type' => sanitize_key( $entry['destination_post_type'] ),
				);
			}
		}

		if ( isset( $mapping['taxonomy_mappings'] ) && is_array( $mapping['taxonomy_mappings'] ) ) {
			$clean['taxonomy_mappings'] = array();

			foreach ( $mapping['taxonomy_mappings'] as $entry ) {
				if ( ! is_array( $entry )
					|| empty( $entry['source_signal'] )
					|| empty( $entry['destination_taxonomy'] )
					|| ! is_string( $entry['source_signal'] )
					|| ! is_string( $entry['destination_taxonomy'] )
				) {
					continue;
				}

				$terms = array();

				if ( isset( $entry['destination_terms'] ) && is_array( $entry['destination_terms'] ) ) {
					foreach ( $entry['destination_terms'] as $term ) {
						if ( is_string( $term ) && '' !== trim( $term ) ) {
							$terms[] = sanitize_text_field( $term );
						}
					}
				}

				$tax_entry = array(
					'source_signal'        => sanitize_text_field( $entry['source_signal'] ),
					'destination_taxonomy' => sanitize_key( $entry['destination_taxonomy'] ),
					'destination_terms'    => $terms,
				);

				if ( ! empty( $entry['create_if_missing'] ) ) {
					$tax_entry['create_if_missing'] = true;

					if ( isset( $entry['taxonomy_label'] ) && is_string( $entry['taxonomy_label'] ) && '' !== trim( $entry['taxonomy_label'] ) ) {
						$tax_entry['taxonomy_label'] = sanitize_text_field( $entry['taxonomy_label'] );
					}
				}

				$clean['taxonomy_mappings'][] = $tax_entry;
			}
		}

		if ( isset( $mapping['author_mappings'] ) && is_array( $mapping['author_mappings'] ) ) {
			$clean['author_mappings'] = array();

			foreach ( $mapping['author_mappings'] as $entry ) {
				if ( ! is_array( $entry )
					|| empty( $entry['source_author'] )
					|| ! is_string( $entry['source_author'] )
					|| ! isset( $entry['destination_user_id'] )
					|| ! is_numeric( $entry['destination_user_id'] )
					|| absint( $entry['destination_user_id'] ) < 1
				) {
					continue;
				}

				$clean['author_mappings'][] = array(
					'source_author'       => sanitize_text_field( $entry['source_author'] ),
					'destination_user_id' => absint( $entry['destination_user_id'] ),
				);
			}
		}

		if ( isset( $mapping['default_author_id'] ) && is_numeric( $mapping['default_author_id'] ) && absint( $mapping['default_author_id'] ) > 0 ) {
			$clean['default_author_id'] = absint( $mapping['default_author_id'] );
		}

		if ( isset( $mapping['post_format_mappings'] ) && is_array( $mapping['post_format_mappings'] ) ) {
			$clean['post_format_mappings'] = array();

			foreach ( $mapping['post_format_mappings'] as $entry ) {
				if ( ! is_array( $entry )
					|| empty( $entry['source_content_type'] )
					|| ! is_string( $entry['source_content_type'] )
					|| empty( $entry['post_format'] )
					|| ! is_string( $entry['post_format'] )
					|| ! in_array( $entry['post_format'], self::ALLOWED_POST_FORMATS, true )
				) {
					continue;
				}

				$clean['post_format_mappings'][] = array(
					'source_content_type' => sanitize_key( $entry['source_content_type'] ),
					'post_format'         => $entry['post_format'],
				);
			}
		}

		if ( isset( $mapping['default_post_format'] )
			&& is_string( $mapping['default_post_format'] )
			&& in_array( $mapping['default_post_format'], self::ALLOWED_POST_FORMATS, true )
		) {
			$clean['default_post_format'] = $mapping['default_post_format'];
		}

		if ( isset( $mapping['meta_field_mappings'] ) && is_array( $mapping['meta_field_mappings'] ) ) {
			$clean['meta_field_mappings'] = array();

			foreach ( $mapping['meta_field_mappings'] as $entry ) {
				if ( ! is_array( $entry )
					|| empty( $entry['source_field'] )
					|| ! is_string( $entry['source_field'] )
					|| empty( $entry['destination_meta_key'] )
					|| ! is_string( $entry['destination_meta_key'] )
				) {
					continue;
				}

				$destination_meta_key = sanitize_key( $entry['destination_meta_key'] );

				if ( '' === $destination_meta_key ) {
					continue;
				}

				$clean['meta_field_mappings'][] = array(
					'source_field'         => sanitize_text_field( $entry['source_field'] ),
					'destination_meta_key' => $destination_meta_key,
				);
			}
		}

		return $clean;
	}

	/**
	 * Get the option key for an adapter's saved mapping.
	 *
	 * @param string $adapter_id Adapter ID.
	 * @return string
	 */
	public static function get_option_key( string $adapter_id ): string {
		return self::OPTION_PREFIX . $adapter_id;
	}
}
