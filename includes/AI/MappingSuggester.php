<?php
/**
 * Mapping suggester.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Uses AIService to propose how source content maps to destination WordPress structures.
 *
 * Given a content analysis (from ContentAnalyzer) and a summary of the destination
 * site's post types and taxonomies, asks the AI to recommend post-type mappings,
 * taxonomy mappings, and content transformations with reasoning for each.
 */
class MappingSuggester {

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private AIService $service;

	/**
	 * Constructor.
	 *
	 * @param AIService $service AI service wrapper.
	 */
	public function __construct( AIService $service ) {
		$this->service = $service;
	}

	/**
	 * Suggest mappings for a content analysis against a site schema.
	 *
	 * @param array<string, mixed> $analysis    Output of ContentAnalyzer::analyze().
	 * @param array<string, mixed> $site_schema Summary of destination site structure (post types, taxonomies).
	 * @return array<string, mixed>|WP_Error Mapping suggestions or error.
	 */
	public function suggest( array $analysis, array $site_schema ) {
		if ( empty( $analysis ) ) {
			return new WP_Error(
				'ai_mapping_empty_analysis',
				__( 'Cannot suggest mappings without a content analysis.', 'ai-importer' )
			);
		}

		if ( empty( $site_schema ) ) {
			return new WP_Error(
				'ai_mapping_empty_schema',
				__( 'Cannot suggest mappings without a destination site schema.', 'ai-importer' )
			);
		}

		$prompt = $this->build_prompt( $analysis, $site_schema );
		$schema = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$required = array( 'post_type_mappings', 'taxonomy_mappings', 'content_transformations', 'summary' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $response ) ) {
				return new WP_Error(
					'ai_mapping_malformed',
					sprintf(
						/* translators: %s: missing field name */
						__( 'AI mapping response is missing required field: %s.', 'ai-importer' ),
						$field
					),
					array( 'response' => $response )
				);
			}
		}

		return $response;
	}

	/**
	 * Build the prompt text.
	 *
	 * @param array<string, mixed> $analysis    Content analysis.
	 * @param array<string, mixed> $site_schema Destination schema summary.
	 * @return string Prompt.
	 */
	private function build_prompt( array $analysis, array $site_schema ): string {
		$analysis_json = wp_json_encode( $analysis );
		$schema_json   = wp_json_encode( $site_schema );
		if ( false === $analysis_json ) {
			$analysis_json = '{}';
		}
		if ( false === $schema_json ) {
			$schema_json = '{}';
		}

		return (
			'You are helping a user import social media content into their WordPress site. '
			. "Given the content analysis and the destination site's available post types and "
			. 'taxonomies, recommend how source content should be mapped. Include concrete '
			. "reasoning for each recommendation.\n\n"
			. "Content analysis:\n{$analysis_json}\n\n"
			. "Destination site schema:\n{$schema_json}"
		);
	}

	/**
	 * JSON schema describing the expected mapping response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type_mappings'      => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'source_content_type'   => array( 'type' => 'string' ),
							'destination_post_type' => array( 'type' => 'string' ),
							'reasoning'             => array( 'type' => 'string' ),
						),
						'required'   => array( 'source_content_type', 'destination_post_type', 'reasoning' ),
					),
				),
				'taxonomy_mappings'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'source_signal'        => array( 'type' => 'string' ),
							'destination_taxonomy' => array( 'type' => 'string' ),
							'destination_terms'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'reasoning'            => array( 'type' => 'string' ),
						),
						'required'   => array( 'source_signal', 'destination_taxonomy', 'destination_terms', 'reasoning' ),
					),
				),
				'content_transformations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'source_pattern' => array( 'type' => 'string' ),
							'transformation' => array( 'type' => 'string' ),
							'reasoning'      => array( 'type' => 'string' ),
						),
						'required'   => array( 'source_pattern', 'transformation', 'reasoning' ),
					),
				),
				'summary'                 => array(
					'type'        => 'string',
					'description' => 'Human-readable summary of the recommended mapping.',
				),
			),
			'required'   => array( 'post_type_mappings', 'taxonomy_mappings', 'content_transformations', 'summary' ),
		);
	}
}
