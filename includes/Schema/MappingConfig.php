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
	 * JSON schema for a mapping configuration, suitable for REST route args.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'          => array(
					'type'        => 'string',
					'description' => __( 'Default destination post type.', 'ai-importer' ),
				),
				'post_status'        => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED_STATUSES,
					'description' => __( 'Post status for imported content.', 'ai-importer' ),
				),
				'post_type_mappings' => array(
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
				'taxonomy_mappings'  => array(
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
						),
						'required'             => array( 'source_signal', 'destination_taxonomy' ),
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

				$clean['taxonomy_mappings'][] = array(
					'source_signal'        => sanitize_text_field( $entry['source_signal'] ),
					'destination_taxonomy' => sanitize_key( $entry['destination_taxonomy'] ),
					'destination_terms'    => $terms,
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
