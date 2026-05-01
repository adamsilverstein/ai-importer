<?php
/**
 * CostEstimator class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\CostEstimator;
use AI_Importer\Tests\Unit\TestCase;
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
	}

	/**
	 * Test single enhancement returns matching token estimate.
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
	 * Test result no longer carries provider-specific cost data.
	 *
	 * Provider/pricing concerns moved to the WordPress Connectors API.
	 *
	 * @return void
	 */
	public function test_result_has_no_provider_cost_breakdown(): void {
		$estimator = new CostEstimator();

		$result = $estimator->estimate( array( 'title_generation' => 100 ) );

		$this->assertArrayNotHasKey( 'cost_by_provider', $result );
	}
}
