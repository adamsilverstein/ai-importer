<?php
/**
 * Cost estimator for AI-powered enhancements.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Estimates token usage and USD cost for a planned batch of AI enhancements.
 *
 * No AI calls are made — this is pure math based on per-enhancement token
 * estimates and per-provider pricing. Used by the import preview UI to show
 * users what an enhancement selection will cost before they confirm.
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
	 * Default per-million-token blended rates in USD.
	 *
	 * These are approximations that weight input and output tokens together.
	 * Override via the 'ai_importer_cost_rates' filter for live pricing or
	 * to add/remove providers.
	 *
	 * @var array<string, float>
	 */
	private const DEFAULT_RATES_PER_MILLION = array(
		'claude_sonnet' => 5.0,
		'claude_opus'   => 15.0,
		'gpt_4o'        => 5.0,
		'gpt_4_turbo'   => 10.0,
		'gemini_pro'    => 1.5,
	);

	/**
	 * Estimate cost for a plan of enhancements.
	 *
	 * @param array<string, int> $plan Map of enhancement name => count of items to process.
	 * @return array<string, mixed>|WP_Error {
	 *     operations: map of enhancement name => ['count' => int, 'tokens' => int],
	 *     total_tokens: int,
	 *     cost_by_provider: map of provider name => float USD
	 * }
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

		$rates            = $this->get_rates();
		$cost_by_provider = array();
		foreach ( $rates as $provider => $rate_per_million ) {
			$cost_by_provider[ $provider ] = round( ( $total_tokens / 1_000_000 ) * $rate_per_million, 2 );
		}

		return array(
			'operations'       => $operations,
			'total_tokens'     => $total_tokens,
			'cost_by_provider' => $cost_by_provider,
		);
	}

	/**
	 * Resolve the rates table, honoring the ai_importer_cost_rates filter.
	 *
	 * @return array<string, float>
	 */
	private function get_rates(): array {
		/**
		 * Filter the per-million-token blended USD rates used for cost estimation.
		 *
		 * Return an associative array of provider name => float dollars per million tokens.
		 * Replaces defaults entirely, so include every provider you want displayed.
		 *
		 * @param array<string, float> $rates Default rate table.
		 */
		return (array) apply_filters( 'ai_importer_cost_rates', self::DEFAULT_RATES_PER_MILLION );
	}
}
