<?php
/**
 * ThreadStitcher class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\ThreadStitcher;
use AI_Importer\Tests\Unit\TestCase;
use WP_Error;

/**
 * Tests for the ThreadStitcher class.
 */
class ThreadStitcherTest extends TestCase {

	/**
	 * Default happy-path items used across tests.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function sample_items(): array {
		return array(
			array( 'text' => '1/ Starting a thread about WordPress 7 AI.' ),
			array( 'text' => '2/ It ships wp_get_ai_client() out of the box.' ),
			array( 'text' => '3/ Plugins can use it without a dependency.' ),
		);
	}

	/**
	 * Test stitch returns WP_Error when fewer than two items are provided.
	 *
	 * @return void
	 */
	public function test_stitch_returns_error_with_too_few_items(): void {
		$service  = $this->createMock( AIService::class );
		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch( array( array( 'text' => 'Only one.' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_thread_too_few_items', $result->get_error_codes() );
	}

	/**
	 * Test stitch rejects items missing a text key.
	 *
	 * @return void
	 */
	public function test_stitch_rejects_items_missing_text(): void {
		$service  = $this->createMock( AIService::class );
		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch(
			array(
				array( 'text' => 'First item.' ),
				array( 'id' => 123 ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_thread_invalid_item', $result->get_error_codes() );
	}

	/**
	 * Test stitch rejects items with an empty text string.
	 *
	 * @return void
	 */
	public function test_stitch_rejects_empty_item_text(): void {
		$service  = $this->createMock( AIService::class );
		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch(
			array(
				array( 'text' => 'First item.' ),
				array( 'text' => '   ' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_thread_empty_item', $result->get_error_codes() );
	}

	/**
	 * Test stitch propagates AIService errors.
	 *
	 * @return void
	 */
	public function test_stitch_propagates_service_errors(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider configured.' )
		);

		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch( $this->sample_items() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test stitch returns body and summary on success.
	 *
	 * @return void
	 */
	public function test_stitch_returns_body_and_summary(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array(
				'body'    => "Stitched paragraph 1.\n\nStitched paragraph 2.",
				'summary' => 'A short summary of the thread.',
			)
		);

		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch( $this->sample_items() );

		$this->assertIsArray( $result );
		$this->assertSame( "Stitched paragraph 1.\n\nStitched paragraph 2.", $result['body'] );
		$this->assertSame( 'A short summary of the thread.', $result['summary'] );
	}

	/**
	 * Test stitch returns empty summary when AI omits it.
	 *
	 * @return void
	 */
	public function test_stitch_tolerates_missing_summary(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'body' => 'Only body, no summary.' )
		);

		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch( $this->sample_items() );

		$this->assertIsArray( $result );
		$this->assertSame( 'Only body, no summary.', $result['body'] );
		$this->assertSame( '', $result['summary'] );
	}

	/**
	 * Test stitch includes all item texts in the prompt.
	 *
	 * @return void
	 */
	public function test_stitch_includes_item_texts_in_prompt(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'body' => 'Stitched.' );
			}
		);

		$stitcher = new ThreadStitcher( $service );
		$stitcher->stitch( $this->sample_items() );

		$this->assertStringContainsString( 'wp_get_ai_client', $captured_prompt );
		$this->assertStringContainsString( 'without a dependency', $captured_prompt );
	}

	/**
	 * Test stitch includes voice option in the prompt.
	 *
	 * @return void
	 */
	public function test_stitch_includes_voice_option(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'body' => 'Stitched.' );
			}
		);

		$stitcher = new ThreadStitcher( $service );
		$stitcher->stitch(
			$this->sample_items(),
			array( 'voice' => 'first-person casual' )
		);

		$this->assertStringContainsString( 'first-person casual', $captured_prompt );
	}

	/**
	 * Test stitch rejects malformed responses missing body.
	 *
	 * @return void
	 */
	public function test_stitch_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'summary' => 'No body field.' )
		);

		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch( $this->sample_items() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_thread_malformed', $result->get_error_codes() );
	}

	/**
	 * Test stitch rejects an empty body.
	 *
	 * @return void
	 */
	public function test_stitch_rejects_empty_body(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'body' => '   ' )
		);

		$stitcher = new ThreadStitcher( $service );

		$result = $stitcher->stitch( $this->sample_items() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_thread_empty_body', $result->get_error_codes() );
	}
}
