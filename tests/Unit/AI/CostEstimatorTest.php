<?php
/**
 * CostEstimator class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\CostEstimator;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Filters;
use WP_Error;

/**
 * Tests for the CostEstimator class.
 */
class CostEstimatorTest extends TestCase {

	/**
	 * Test empty plan returns zero everything.
	 *
	 * @return void
	 */
	public function test_empty_plan_returns_zero(): void {
		$estimator = new CostEstimator();

		$result = $estimator->estimate( array() );

		$this->assertSame( 0, $result['total_tokens'] );
		$this->assertSame( array(), $result['operations'] );
		$this->assertArrayHasKey( 'cost_by_provider', $result );
		foreach ( $result['cost_by_provider'] as $cost ) {
			$this->assertSame( 0.0, $cost );
		}
	}

	/**
	 * Test single enhancement returns matching token and cost estimates.
	 *
	 * @return void
	 */
	public function test_single_enhancement_estimates(): void {
		$estimator = new CostEstimator();

		$result = $estimator->estimate( array( 'alt_text' => 100 ) );

		$this->assertArrayHasKey( 'alt_text', $result['operations'] );
		$this->assertSame( 100, $result['operations']['alt_text']['count'] );
		$this->assertSame( 15000, $result['operations']['alt_text']['tokens'] ); // 100 * 150.
		$this->assertSame( 15000, $result['total_tokens'] );
		$this->assertGreaterThan( 0, $result['cost_by_provider']['claude_sonnet'] );
	}

	/**
	 * Test multiple enhancements sum correctly.
	 *
	 * @return void
	 */
	public function test_multiple_enhancements_sum(): void {
		$estimator = new CostEstimator();

		$result = $estimator->estimate(
			array(
				'alt_text'         => 10,    // 10 * 150  = 1500.
				'title_generation' => 20,    // 20 * 100  = 2000.
				'seo_meta'         => 5,     // 5  * 100  = 500.
			)
		);

		$this->assertSame( 4000, $result['total_tokens'] );
		$this->assertCount( 3, $result['operations'] );
	}

	/**
	 * Test unknown enhancement returns WP_Error.
	 *
	 * @return void
	 */
	public function test_unknown_enhancement_returns_error(): void {
		$estimator = new CostEstimator();

		$result = $estimator->estimate( array( 'not_a_real_enhancement' => 5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_cost_unknown_enhancement', $result->get_error_codes() );
	}

	/**
	 * Test negative count returns WP_Error.
	 *
	 * @return void
	 */
	public function test_negative_count_returns_error(): void {
		$estimator = new CostEstimator();

		$result = $estimator->estimate( array( 'alt_text' => -5 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_cost_invalid_count', $result->get_error_codes() );
	}

	/**
	 * Test cost breakdown uses per-million-token rates.
	 *
	 * 1M tokens × $5/M for Claude Sonnet = $5.00.
	 *
	 * @return void
	 */
	public function test_cost_uses_per_million_rates(): void {
		$estimator = new CostEstimator();

		// 1,000,000 / 150 tokens-per-alt-text ≈ 6667 alt texts -> 1,000,050 tokens; close enough to test scale.
		// Use title_generation @ 100 tokens each: 10000 * 100 = 1,000,000 tokens.
		$result = $estimator->estimate( array( 'title_generation' => 10000 ) );

		$this->assertSame( 1_000_000, $result['total_tokens'] );
		$this->assertEqualsWithDelta( 5.0, $result['cost_by_provider']['claude_sonnet'], 0.001 );
	}

	/**
	 * Test ai_importer_cost_rates filter can override provider rates.
	 *
	 * @return void
	 */
	public function test_rates_filter_overrides_defaults(): void {
		Filters\expectApplied( 'ai_importer_cost_rates' )
			->once()
			->andReturn(
				array(
					'custom_provider' => 2.0,
				)
			);

		$estimator = new CostEstimator();
		$result    = $estimator->estimate( array( 'title_generation' => 10000 ) );

		$this->assertArrayHasKey( 'custom_provider', $result['cost_by_provider'] );
		$this->assertArrayNotHasKey( 'claude_sonnet', $result['cost_by_provider'] );
		$this->assertEqualsWithDelta( 2.0, $result['cost_by_provider']['custom_provider'], 0.001 );
	}

	/**
	 * Test invalid rate values from the filter are skipped instead of crashing.
	 *
	 * @return void
	 */
	public function test_invalid_filter_rates_are_skipped(): void {
		Filters\expectApplied( 'ai_importer_cost_rates' )
			->once()
			->andReturn(
				array(
					'good'     => 5.0,
					'string'   => 'free', // Non-numeric — should be skipped, not crash.
					'negative' => -1.0,   // Negative — should be skipped.
				)
			);

		$estimator = new CostEstimator();
		$result    = $estimator->estimate( array( 'title_generation' => 10000 ) );

		$this->assertArrayHasKey( 'good', $result['cost_by_provider'] );
		$this->assertArrayNotHasKey( 'string', $result['cost_by_provider'] );
		$this->assertArrayNotHasKey( 'negative', $result['cost_by_provider'] );
	}
}
