<?php
/**
 * SourcesController tests.
 *
 * @package AI_Importer\Tests\Unit\REST
 */

namespace AI_Importer\Tests\Unit\REST;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\AI\ContentAnalyzer;
use AI_Importer\AI\MappingSuggester;
use AI_Importer\REST\SourcesController;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Schema\SiteSchemaAnalyzer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;
use WP_Error;
use WP_REST_Request;

/**
 * Tests for the SourcesController class.
 */
class SourcesControllerTest extends TestCase {

	/**
	 * Controller instance.
	 *
	 * @var SourcesController
	 */
	private SourcesController $controller;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		AdapterRegistry::get_instance()->reset();
		$this->controller = new SourcesController();

		// Mock WordPress functions used by the controller.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				if ( $data instanceof \WP_REST_Response ) {
					return $data;
				}
				return new \WP_REST_Response( $data );
			}
		);
	}

	/**
	 * Test get_items returns all registered adapters.
	 *
	 * @return void
	 */
	public function test_get_items(): void {
		$this->register_mock_adapter( 'twitter' );
		$this->register_mock_adapter( 'medium' );

		$request  = new WP_REST_Request( 'GET', '/ai-importer/v1/sources' );
		$response = $this->controller->get_items( $request );
		$result   = $response->get_data();

		$this->assertCount( 2, $result );
		$this->assertSame( 'twitter', $result[0]['id'] );
		$this->assertSame( 'medium', $result[1]['id'] );
	}

	/**
	 * Test get_item returns single adapter with schema.
	 *
	 * @return void
	 */
	public function test_get_item(): void {
		$this->register_mock_adapter( 'twitter' );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter' );
		$request['id'] = 'twitter';
		$response      = $this->controller->get_item( $request );
		$result        = $response->get_data();

		$this->assertSame( 'twitter', $result['id'] );
		$this->assertArrayHasKey( 'settings_schema', $result );
	}

	/**
	 * Test get_item returns error for unknown adapter.
	 *
	 * @return void
	 */
	public function test_get_item_not_found(): void {
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/unknown' );
		$request['id'] = 'unknown';
		$result        = $this->controller->get_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test disconnect_source disconnects and returns updated adapter.
	 *
	 * @return void
	 */
	public function test_disconnect_source(): void {
		$adapter = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'disconnect' )->once();

		$request       = new WP_REST_Request( 'POST', '/ai-importer/v1/sources/twitter/disconnect' );
		$request['id'] = 'twitter';
		$response      = $this->controller->disconnect_source( $request );
		$result        = $response->get_data();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'twitter', $result['source']['id'] );
	}

	/**
	 * Test get_manifest returns manifest data.
	 *
	 * @return void
	 */
	public function test_get_manifest(): void {
		$manifest = new ContentManifest( 'twitter' );
		$manifest->add_item(
			new ManifestItem(
				id: '1',
				type: ContentType::POST,
				title: 'Test tweet',
				created_at: new DateTimeImmutable( '2024-01-15' ),
			)
		);

		$adapter = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/manifest' );
		$request['id'] = 'twitter';
		$response      = $this->controller->get_manifest( $request );
		$result        = $response->get_data();

		$this->assertSame( 'twitter', $result['source_id'] );
		$this->assertSame( 1, $result['stats']['total'] );
		$this->assertCount( 1, $result['items'] );
	}

	/**
	 * Test get_manifest returns error when not authenticated.
	 *
	 * @return void
	 */
	public function test_get_manifest_not_authenticated(): void {
		$this->register_mock_adapter( 'twitter', false );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/manifest' );
		$request['id'] = 'twitter';
		$result        = $this->controller->get_manifest( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get_mapping_suggestions returns analysis, schema, and suggestions on success.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_returns_payload(): void {
		$manifest = $this->build_manifest_with_items( 'twitter', 3 );
		$adapter  = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$analysis    = $this->sample_analysis();
		$site_schema = $this->sample_site_schema();
		$suggestions = $this->sample_suggestions();

		$content_analyzer = Mockery::mock( ContentAnalyzer::class );
		$content_analyzer->shouldReceive( 'analyze' )
			->once()
			->with(
				Mockery::on(
					static function ( $items ) {
						// Items must be passed as a positional array (array_values).
						return is_array( $items ) && array_keys( $items ) === range( 0, count( $items ) - 1 );
					}
				),
				Mockery::type( 'array' )
			)
			->andReturn( $analysis );

		$schema_analyzer = Mockery::mock( SiteSchemaAnalyzer::class );
		$schema_analyzer->shouldReceive( 'get_schema' )->once()->andReturn( $site_schema );

		$suggester = Mockery::mock( MappingSuggester::class );
		$suggester->shouldReceive( 'suggest' )
			->once()
			->with( $analysis, $site_schema )
			->andReturn( $suggestions );

		$controller    = new SourcesController( $content_analyzer, $schema_analyzer, $suggester );
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$response = $controller->get_mapping_suggestions( $request );
		$result   = $response->get_data();

		$this->assertSame( 'twitter', $result['source_id'] );
		$this->assertSame( $analysis, $result['analysis'] );
		$this->assertSame( $site_schema, $result['site_schema'] );
		$this->assertSame( $suggestions, $result['suggestions'] );
	}

	/**
	 * Test get_mapping_suggestions forwards an explicit sample_size to the analyzer.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_forwards_sample_size(): void {
		$manifest = $this->build_manifest_with_items( 'twitter', 5 );
		$adapter  = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$content_analyzer = Mockery::mock( ContentAnalyzer::class );
		$content_analyzer->shouldReceive( 'analyze' )
			->once()
			->with( Mockery::type( 'array' ), array( 'sample_size' => 25 ) )
			->andReturn( $this->sample_analysis() );

		$schema_analyzer = Mockery::mock( SiteSchemaAnalyzer::class );
		$schema_analyzer->shouldReceive( 'get_schema' )->andReturn( $this->sample_site_schema() );

		$suggester = Mockery::mock( MappingSuggester::class );
		$suggester->shouldReceive( 'suggest' )->andReturn( $this->sample_suggestions() );

		$controller             = new SourcesController( $content_analyzer, $schema_analyzer, $suggester );
		$request                = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id']          = 'twitter';
		$request['sample_size'] = 25;

		$response = $controller->get_mapping_suggestions( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test get_mapping_suggestions returns 400 when the source is not connected.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_not_authenticated(): void {
		$this->register_mock_adapter( 'twitter', false );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$result = $this->controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'not_authenticated', $result->get_error_codes() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test get_mapping_suggestions returns 404 for an unknown source.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_source_not_found(): void {
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/unknown/mapping-suggestions' );
		$request['id'] = 'unknown';

		$result = $this->controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'source_not_found', $result->get_error_codes() );
	}

	/**
	 * Test get_mapping_suggestions surfaces analyzer failures with a 502 status.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_propagates_analyzer_error(): void {
		$manifest = $this->build_manifest_with_items( 'twitter', 1 );
		$adapter  = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$content_analyzer = Mockery::mock( ContentAnalyzer::class );
		$content_analyzer->shouldReceive( 'analyze' )->once()->andReturn(
			new WP_Error( 'ai_unavailable', 'No provider configured.' )
		);

		$schema_analyzer = Mockery::mock( SiteSchemaAnalyzer::class );
		$suggester       = Mockery::mock( MappingSuggester::class );

		$controller    = new SourcesController( $content_analyzer, $schema_analyzer, $suggester );
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$result = $controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * Test get_mapping_suggestions surfaces suggester failures with a 502 status.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_propagates_suggester_error(): void {
		$manifest = $this->build_manifest_with_items( 'twitter', 1 );
		$adapter  = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$content_analyzer = Mockery::mock( ContentAnalyzer::class );
		$content_analyzer->shouldReceive( 'analyze' )->once()->andReturn( $this->sample_analysis() );

		$schema_analyzer = Mockery::mock( SiteSchemaAnalyzer::class );
		$schema_analyzer->shouldReceive( 'get_schema' )->andReturn( $this->sample_site_schema() );

		$suggester = Mockery::mock( MappingSuggester::class );
		$suggester->shouldReceive( 'suggest' )->once()->andReturn(
			new WP_Error( 'ai_mapping_malformed', 'Bad response.' )
		);

		$controller    = new SourcesController( $content_analyzer, $schema_analyzer, $suggester );
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$result = $controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_mapping_malformed', $result->get_error_codes() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * Test malformed analyzer errors carrying response data still receive a 502 status.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_sets_status_on_malformed_analyzer_error_with_data(): void {
		$manifest = $this->build_manifest_with_items( 'twitter', 1 );
		$adapter  = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$raw_response = array( 'unexpected' => 'shape' );

		$content_analyzer = Mockery::mock( ContentAnalyzer::class );
		$content_analyzer->shouldReceive( 'analyze' )->once()->andReturn(
			new WP_Error(
				'ai_analyze_malformed',
				'AI analysis response is missing required field: content_type.',
				array( 'response' => $raw_response )
			)
		);

		$schema_analyzer = Mockery::mock( SiteSchemaAnalyzer::class );
		$suggester       = Mockery::mock( MappingSuggester::class );

		$controller    = new SourcesController( $content_analyzer, $schema_analyzer, $suggester );
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$result = $controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_analyze_malformed', $result->get_error_codes() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 502, $data['status'] );
		$this->assertSame( $raw_response, $data['response'] );
	}

	/**
	 * Test malformed suggester errors carrying response data still receive a 502 status.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_sets_status_on_malformed_suggester_error_with_data(): void {
		$manifest = $this->build_manifest_with_items( 'twitter', 1 );
		$adapter  = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andReturn( $manifest );

		$content_analyzer = Mockery::mock( ContentAnalyzer::class );
		$content_analyzer->shouldReceive( 'analyze' )->once()->andReturn( $this->sample_analysis() );

		$schema_analyzer = Mockery::mock( SiteSchemaAnalyzer::class );
		$schema_analyzer->shouldReceive( 'get_schema' )->andReturn( $this->sample_site_schema() );

		$raw_response = array( 'mappings' => 'not-an-array' );

		$suggester = Mockery::mock( MappingSuggester::class );
		$suggester->shouldReceive( 'suggest' )->once()->andReturn(
			new WP_Error(
				'ai_mapping_malformed',
				'AI mapping response field "mappings" must be of type array.',
				array( 'response' => $raw_response )
			)
		);

		$controller    = new SourcesController( $content_analyzer, $schema_analyzer, $suggester );
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$result = $controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_mapping_malformed', $result->get_error_codes() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 502, $data['status'] );
		$this->assertSame( $raw_response, $data['response'] );
	}

	/**
	 * Test get_mapping_suggestions surfaces manifest fetch failures.
	 *
	 * @return void
	 */
	public function test_get_mapping_suggestions_manifest_failure(): void {
		$adapter = $this->register_mock_adapter( 'twitter', true );
		$adapter->shouldReceive( 'fetch_manifest' )->once()->andThrow(
			new \RuntimeException( 'Archive unreadable.' )
		);

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mapping-suggestions' );
		$request['id'] = 'twitter';

		$result = $this->controller->get_mapping_suggestions( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'manifest_error', $result->get_error_codes() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/**
	 * Build a manifest containing $count items.
	 *
	 * @param string $source_id Adapter source ID.
	 * @param int    $count     Number of items to create.
	 * @return ContentManifest
	 */
	private function build_manifest_with_items( string $source_id, int $count ): ContentManifest {
		$manifest = new ContentManifest( $source_id );

		for ( $i = 0; $i < $count; $i++ ) {
			$manifest->add_item(
				new ManifestItem(
					id: (string) $i,
					type: ContentType::POST,
					title: "Item {$i}",
					created_at: new DateTimeImmutable( '2024-01-15' ),
				)
			);
		}

		return $manifest;
	}

	/**
	 * Sample ContentAnalyzer analysis output.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_analysis(): array {
		return array(
			'content_types'        => array( 'quick_thoughts' => 3 ),
			'top_topics'           => array( 'wordpress' ),
			'writing_style'        => 'casual',
			'suggested_categories' => array( 'Notes' ),
			'high_value_content'   => array(),
		);
	}

	/**
	 * Sample SiteSchemaAnalyzer output.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_site_schema(): array {
		return array(
			'post_types' => array(
				array(
					'slug'   => 'post',
					'name'   => 'Posts',
					'public' => true,
				),
			),
			'taxonomies' => array(
				array(
					'slug'       => 'category',
					'name'       => 'Categories',
					'post_types' => array( 'post' ),
				),
			),
		);
	}

	/**
	 * Sample MappingSuggester output.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_suggestions(): array {
		return array(
			'post_type_mappings'      => array(
				array(
					'source_content_type'   => 'quick_thoughts',
					'destination_post_type' => 'post',
					'reasoning'             => 'Short content fits Posts.',
				),
			),
			'taxonomy_mappings'       => array(),
			'content_transformations' => array(),
			'summary'                 => 'Map quick thoughts to standard posts.',
		);
	}

	/**
	 * Test get_mapping returns null when no mapping is saved.
	 *
	 * @return void
	 */
	public function test_get_mapping_returns_null_when_none_saved(): void {
		$this->register_mock_adapter( 'twitter' );

		Functions\when( 'get_option' )->justReturn( null );

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mappings' );
		$request['id'] = 'twitter';

		$response = $this->controller->get_mapping( $request );
		$result   = $response->get_data();

		$this->assertSame( 'twitter', $result['source_id'] );
		$this->assertNull( $result['mapping'] );
	}

	/**
	 * Test get_mapping returns the saved mapping from the expected option.
	 *
	 * @return void
	 */
	public function test_get_mapping_returns_saved_mapping(): void {
		$this->register_mock_adapter( 'twitter' );

		$mapping = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
		);

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( $mapping ) {
				return 'ai_importer_mappings_twitter' === $key ? $mapping : $default;
			}
		);

		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/twitter/mappings' );
		$request['id'] = 'twitter';

		$response = $this->controller->get_mapping( $request );
		$result   = $response->get_data();

		$this->assertSame( $mapping, $result['mapping'] );
	}

	/**
	 * Test get_mapping returns 404 for an unknown source.
	 *
	 * @return void
	 */
	public function test_get_mapping_source_not_found(): void {
		$request       = new WP_REST_Request( 'GET', '/ai-importer/v1/sources/unknown/mappings' );
		$request['id'] = 'unknown';

		$result = $this->controller->get_mapping( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'source_not_found', $result->get_error_codes() );
	}

	/**
	 * Test save_mapping persists a sanitized mapping in the adapter option.
	 *
	 * @return void
	 */
	public function test_save_mapping_persists_sanitized_mapping(): void {
		$this->register_mock_adapter( 'twitter' );
		$this->stub_sanitizers();

		$saved = array();

		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) use ( &$saved ) {
				$saved[ $key ] = $value;
				return true;
			}
		);

		$request       = new WP_REST_Request( 'POST', '/ai-importer/v1/sources/twitter/mappings' );
		$request['id'] = 'twitter';
		$request->set_body_params(
			array(
				'mapping' => array(
					'post_type'     => 'page',
					'post_status'   => 'publish',
					'unknown_field' => 'evil',
				),
			)
		);

		$response = $this->controller->save_mapping( $request );
		$result   = $response->get_data();

		$expected = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
		);

		$this->assertSame( $expected, $result['mapping'] );
		$this->assertSame( $expected, $saved['ai_importer_mappings_twitter'] );
	}

	/**
	 * Test save_mapping rejects a mapping with no valid fields.
	 *
	 * @return void
	 */
	public function test_save_mapping_rejects_invalid_mapping(): void {
		$this->register_mock_adapter( 'twitter' );
		$this->stub_sanitizers();

		$request       = new WP_REST_Request( 'POST', '/ai-importer/v1/sources/twitter/mappings' );
		$request['id'] = 'twitter';
		$request->set_body_params(
			array(
				'mapping' => array( 'post_status' => 'trash' ),
			)
		);

		$result = $this->controller->save_mapping( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'invalid_mapping', $result->get_error_codes() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test save_mapping rejects a missing mapping parameter.
	 *
	 * @return void
	 */
	public function test_save_mapping_requires_mapping(): void {
		$this->register_mock_adapter( 'twitter' );

		$request       = new WP_REST_Request( 'POST', '/ai-importer/v1/sources/twitter/mappings' );
		$request['id'] = 'twitter';

		$result = $this->controller->save_mapping( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'invalid_mapping', $result->get_error_codes() );
	}

	/**
	 * Stub sanitization functions used by MappingConfig::sanitize().
	 *
	 * @return void
	 */
	private function stub_sanitizers(): void {
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( (string) $value );
			}
		);
	}

	/**
	 * Test permissions check denies unauthorized users.
	 *
	 * @return void
	 */
	public function test_permissions_check_denies_unauthorized(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$request = new WP_REST_Request( 'GET', '/ai-importer/v1/sources' );
		$result  = $this->controller->get_items_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Register a mock adapter in the registry.
	 *
	 * @param string $id              Adapter ID.
	 * @param bool   $is_authenticated Whether authenticated.
	 * @return \Mockery\MockInterface&AdapterInterface Mock adapter.
	 */
	private function register_mock_adapter( string $id, bool $is_authenticated = false ) {
		$schema = new SettingsSchema();

		$adapter = Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'get_id' )->andReturn( $id );
		$adapter->shouldReceive( 'get_name' )->andReturn( ucfirst( $id ) );
		$adapter->shouldReceive( 'get_description' )->andReturn( "The {$id} adapter." );
		$adapter->shouldReceive( 'get_icon' )->andReturn( "dashicons-{$id}" );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( 'file_upload' );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( $is_authenticated );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post' ) );
		$adapter->shouldReceive( 'get_settings_schema' )->andReturn( $schema );

		AdapterRegistry::get_instance()->register( $adapter );

		return $adapter;
	}
}
