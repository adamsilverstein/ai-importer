<?php
/**
 * Content analyzer.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use AI_Importer\Adapters\Manifest\ManifestItem;
use WP_Error;

/**
 * Uses AIService to classify and summarize a batch of source content.
 *
 * Given a sample of manifest items, asks the AI to identify content types,
 * topics, writing style, suggested categories, and high-value items. The
 * output feeds mapping suggestions and the import preview UI.
 */
class ContentAnalyzer {

	/**
	 * Default number of items to include in the analysis sample.
	 *
	 * Larger samples give the model more signal but cost more tokens.
	 */
	private const DEFAULT_SAMPLE_SIZE = 50;

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
	 * Analyze a collection of manifest items.
	 *
	 * @param array<ManifestItem>  $items   Manifest items to analyze.
	 * @param array<string, mixed> $options Options: 'sample_size' to cap items sent to the model.
	 * @return array<string, mixed>|WP_Error Analysis result or error.
	 */
	public function analyze( array $items, array $options = array() ) {
		if ( empty( $items ) ) {
			return new WP_Error(
				'ai_analyze_empty',
				__( 'Cannot analyze an empty set of items.', 'ai-importer' )
			);
		}

		$sample_size = (int) ( $options['sample_size'] ?? self::DEFAULT_SAMPLE_SIZE );
		$sample      = array_slice( $items, 0, max( 1, $sample_size ) );

		$prompt = $this->build_prompt( $sample );
		$schema = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$required = array(
			'content_types',
			'top_topics',
			'writing_style',
			'suggested_categories',
			'high_value_content',
		);
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $response ) ) {
				return new WP_Error(
					'ai_analyze_malformed',
					sprintf(
						/* translators: %s: missing field name */
						__( 'AI analysis response is missing required field: %s.', 'ai-importer' ),
						$field
					),
					array( 'response' => $response )
				);
			}
		}

		return $response;
	}

	/**
	 * Build the analysis prompt from a sample of items.
	 *
	 * @param array<ManifestItem> $items Items to summarize for the model.
	 * @return string Prompt text.
	 */
	private function build_prompt( array $items ): string {
		$summaries = array();
		foreach ( $items as $item ) {
			$summaries[] = array(
				'id'         => $item->id,
				'type'       => $item->type->value,
				'title'      => $item->title,
				'excerpt'    => $item->excerpt,
				'created_at' => $item->created_at->format( 'c' ),
				'engagement' => $this->extract_engagement( $item ),
			);
		}

		$items_json = wp_json_encode( $summaries );
		if ( false === $items_json ) {
			$items_json = '[]';
		}

		return (
			"Analyze the following content items from a user's social media archive. "
			. 'Identify the mix of content types (e.g. long_form, quick_thoughts, announcements, '
			. "conversations), the top topics, the author's writing style, suggested WordPress "
			. "categories, and the IDs of the highest-value items (those most worth preserving).\n\n"
			. "Items:\n{$items_json}"
		);
	}

	/**
	 * JSON schema for the analysis response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content_types'        => array(
					'type'                 => 'object',
					'description'          => 'Map of content type name to count.',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'top_topics'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'writing_style'        => array(
					'type' => 'string',
				),
				'suggested_categories' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'high_value_content'   => array(
					'type'        => 'array',
					'description' => 'Source item IDs of the highest-value content.',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array(
				'content_types',
				'top_topics',
				'writing_style',
				'suggested_categories',
				'high_value_content',
			),
		);
	}

	/**
	 * Extract engagement hints from an item's metadata, if present.
	 *
	 * @param ManifestItem $item Item to inspect.
	 * @return array<string, int> Engagement signals.
	 */
	private function extract_engagement( ManifestItem $item ): array {
		$signals = array();
		foreach ( array( 'favorite_count', 'retweet_count', 'reply_count', 'like_count' ) as $key ) {
			$value = $item->get_meta( $key );
			if ( is_numeric( $value ) ) {
				$signals[ $key ] = (int) $value;
			}
		}

		return $signals;
	}
}
