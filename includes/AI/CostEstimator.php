<?php
/**
 * Token estimator for AI-powered enhancements.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Estimates token usage for a planned batch of AI enhancements.
 *
 * No AI calls are made — this is pure math based on per-enhancement token
 * estimates. Used by the import preview UI to show users how many tokens
 * an enhancement selection will consume before they confirm.
 *
 * Provider selection and pricing are owned by WordPress core via the
 * Connectors API (WordPress 7.0+); this class deliberately does not know
 * about specific providers or rates.
 */
class CostEstimator {

	/**
	 * Average tokens consumed by each enhancement type (prompt + completion).
	 *
	 * These are coarse estimates drawn from the PRD. Real usage will vary by
	 * content length; the purpose is to give users a realistic order of
	 * magnitude before they commit to an import.
	 *
	 * @var array<string, int>
	 */
	private const TOKENS_PER_OPERATION = array(
		'alt_text'          => 150,
		'thread_stitching'  => 500,
		'title_generation'  => 100,
		'excerpt'           => 150,
		'seo_meta'          => 100,
		'content_expansion' => 400,
		'hashtag_mapping'   => 50,
		'content_analysis'  => 2000, // Single call with a sample of items.
		'mapping_suggest'   => 1500, // Single call per import.
	);

	/**
	 * Estimate token usage for a plan of enhancements.
	 *
	 * @param array<string, int> $plan Map of enhancement name => count of items to process.
	 * @return array{operations: array<string, array{count: int, tokens: int}>, total_tokens: int}|WP_Error
	 */
	public function estimate( array $plan ) {
		$operations   = array();
		$total_tokens = 0;

		foreach ( $plan as $enhancement => $count ) {
			if ( ! isset( self::TOKENS_PER_OPERATION[ $enhancement ] ) ) {
				return new WP_Error(
					'ai_cost_unknown_enhancement',
					sprintf(
						/* translators: %s: enhancement name */
						__( 'Unknown enhancement type: %s.', 'ai-importer' ),
						$enhancement
					)
				);
			}

			if ( ! is_int( $count ) || $count < 0 ) {
				return new WP_Error(
					'ai_cost_invalid_count',
					sprintf(
						/* translators: %s: enhancement name */
						__( 'Enhancement count must be a non-negative integer: %s.', 'ai-importer' ),
						$enhancement
					)
				);
			}

			$tokens                     = $count * self::TOKENS_PER_OPERATION[ $enhancement ];
			$operations[ $enhancement ] = array(
				'count'  => $count,
				'tokens' => $tokens,
			);
			$total_tokens              += $tokens;
		}

		return array(
			'operations'   => $operations,
			'total_tokens' => $total_tokens,
		);
	}
}
