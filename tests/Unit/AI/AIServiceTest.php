<?php
/**
 * AIService class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * Tests for the AIService class.
 */
class AIServiceTest extends TestCase {

	/**
	 * Test is_available returns true when client can be retrieved.
	 *
	 * @return void
	 */
	public function test_is_available_returns_true_when_client_retrievable(): void {
		$client = $this->create_mock_client( array( 'foo' => 'bar' ) );
		Functions\when( 'wp_get_ai_client' )->justReturn( $client );

		$service = new AIService();

		$this->assertTrue( $service->is_available() );
	}

	/**
	 * Test is_available returns false when wp_get_ai_client returns WP_Error.
	 *
	 * @return void
	 */
	public function test_is_available_returns_false_when_client_errors(): void {
		Functions\when( 'wp_get_ai_client' )->justReturn(
			new WP_Error( 'no_provider', 'No AI provider configured.' )
		);

		$service = new AIService();

		$this->assertFalse( $service->is_available() );
	}

	/**
	 * Test is_available returns false when wp_get_ai_client returns null.
	 *
	 * @return void
	 */
	public function test_is_available_returns_false_when_client_null(): void {
		Functions\when( 'wp_get_ai_client' )->justReturn( null );

		$service = new AIService();

		$this->assertFalse( $service->is_available() );
	}

	/**
	 * Test generate_structured returns WP_Error when service unavailable.
	 *
	 * @return void
	 */
	public function test_generate_structured_returns_error_when_unavailable(): void {
		Functions\when( 'wp_get_ai_client' )->justReturn(
			new WP_Error( 'no_provider', 'No AI provider configured.' )
		);

		$service = new AIService();

		$result = $service->generate_structured(
			'Summarize this post.',
			array( 'type' => 'object' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test generate_structured returns decoded structured output on success.
	 *
	 * @return void
	 */
	public function test_generate_structured_returns_structured_data(): void {
		$expected = array(
			'topics'        => array( 'wordpress', 'javascript' ),
			'writing_style' => 'technical',
		);

		$client = $this->create_mock_client( $expected );
		Functions\when( 'wp_get_ai_client' )->justReturn( $client );

		$service = new AIService();

		$result = $service->generate_structured(
			'Analyze the content.',
			array( 'type' => 'object' )
		);

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test generate_structured propagates client errors.
	 *
	 * @return void
	 */
	public function test_generate_structured_propagates_client_errors(): void {
		$client = new class() {
			public function generate_text( $prompt, $options ) {
				return new WP_Error( 'rate_limited', 'API rate limit hit.' );
			}
		};
		Functions\when( 'wp_get_ai_client' )->justReturn( $client );

		$service = new AIService();

		$result = $service->generate_structured(
			'Analyze.',
			array( 'type' => 'object' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'rate_limited', $result->get_error_codes() );
	}

	/**
	 * Test generate_structured returns error when client returns non-JSON.
	 *
	 * @return void
	 */
	public function test_generate_structured_errors_on_invalid_json(): void {
		$client = new class() {
			public function generate_text( $prompt, $options ) {
				return 'not valid json {{';
			}
		};
		Functions\when( 'wp_get_ai_client' )->justReturn( $client );

		$service = new AIService();

		$result = $service->generate_structured(
			'Analyze.',
			array( 'type' => 'object' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_invalid_response', $result->get_error_codes() );
	}

	/**
	 * Test generate_structured passes prompt and schema to the client.
	 *
	 * @return void
	 */
	public function test_generate_structured_passes_prompt_and_schema(): void {
		$received_prompt  = null;
		$received_options = null;

		$client = new class( $received_prompt, $received_options ) {
			private $prompt_ref;
			private $options_ref;

			public function __construct( &$prompt_ref, &$options_ref ) {
				$this->prompt_ref  = &$prompt_ref;
				$this->options_ref = &$options_ref;
			}

			public function generate_text( $prompt, $options ) {
				$this->prompt_ref  = $prompt;
				$this->options_ref = $options;

				return '{"ok":true}';
			}
		};
		Functions\when( 'wp_get_ai_client' )->justReturn( $client );

		$service = new AIService();

		$schema = array(
			'type'       => 'object',
			'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
		);

		$service->generate_structured( 'Say ok.', $schema );

		$this->assertSame( 'Say ok.', $received_prompt );
		$this->assertArrayHasKey( 'response_format', $received_options );
		$this->assertSame( 'json_schema', $received_options['response_format']['type'] );
		$this->assertSame( $schema, $received_options['response_format']['schema'] );
	}

	/**
	 * Create a mock AI client that returns the given data as a JSON string.
	 *
	 * @param array<string, mixed> $data Structured data to return as JSON.
	 * @return object
	 */
	private function create_mock_client( array $data ): object {
		$json = wp_json_encode_fallback( $data );

		return new class( $json ) {
			private string $json;

			public function __construct( string $json ) {
				$this->json = $json;
			}

			public function generate_text( $prompt, $options ) {
				return $this->json;
			}
		};
	}
}

/**
 * Local fallback for wp_json_encode during tests.
 *
 * @param mixed $data Data to encode.
 * @return string JSON string.
 */
function wp_json_encode_fallback( $data ): string {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
