<?php
/**
 * MappingConfig tests.
 *
 * @package AI_Importer\Tests\Unit\Schema
 */

namespace AI_Importer\Tests\Unit\Schema;

use AI_Importer\Schema\MappingConfig;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the MappingConfig class.
 */
class MappingConfigTest extends TestCase {

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub.
			}
		);
	}

	/**
	 * Test the schema describes the expected top-level properties.
	 *
	 * @return void
	 */
	public function test_schema_shape(): void {
		$schema = MappingConfig::get_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayHasKey( 'post_status', $schema['properties'] );
		$this->assertArrayHasKey( 'post_type_mappings', $schema['properties'] );
		$this->assertArrayHasKey( 'taxonomy_mappings', $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( MappingConfig::ALLOWED_STATUSES, $schema['properties']['post_status']['enum'] );
	}

	/**
	 * Test sanitize keeps a complete valid mapping.
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_valid_mapping(): void {
		$mapping = array(
			'post_type'          => 'page',
			'post_status'        => 'publish',
			'post_type_mappings' => array(
				array(
					'source_content_type'   => 'thread',
					'destination_post_type' => 'page',
				),
			),
			'taxonomy_mappings'  => array(
				array(
					'source_signal'        => 'hashtags',
					'destination_taxonomy' => 'post_tag',
					'destination_terms'    => array(),
				),
				array(
					'source_signal'        => 'suggested_categories',
					'destination_taxonomy' => 'category',
					'destination_terms'    => array( 'Notes', 'Updates' ),
				),
			),
		);

		$this->assertSame( $mapping, MappingConfig::sanitize( $mapping ) );
	}

	/**
	 * Test sanitize strips unknown fields and malformed entries.
	 *
	 * @return void
	 */
	public function test_sanitize_strips_unknown_and_malformed(): void {
		$result = MappingConfig::sanitize(
			array(
				'post_type'          => 'page',
				'unknown_field'      => 'evil',
				'post_type_mappings' => array(
					array( 'source_content_type' => 'thread' ), // Missing destination.
					'not-an-array',
				),
				'taxonomy_mappings'  => array(
					array( 'destination_taxonomy' => 'category' ), // Missing signal.
				),
			)
		);

		$this->assertSame( 'page', $result['post_type'] );
		$this->assertArrayNotHasKey( 'unknown_field', $result );
		$this->assertSame( array(), $result['post_type_mappings'] );
		$this->assertSame( array(), $result['taxonomy_mappings'] );
	}

	/**
	 * Test sanitize rejects post statuses outside the allowed list.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_invalid_status(): void {
		$result = MappingConfig::sanitize( array( 'post_status' => 'trash' ) );

		$this->assertArrayNotHasKey( 'post_status', $result );
	}

	/**
	 * Test sanitize cleans slugs and term names.
	 *
	 * @return void
	 */
	public function test_sanitize_cleans_values(): void {
		$result = MappingConfig::sanitize(
			array(
				'post_type'         => 'My Page!',
				'taxonomy_mappings' => array(
					array(
						'source_signal'        => 'suggested_categories',
						'destination_taxonomy' => 'Category!',
						'destination_terms'    => array( '  Notes  ', '', 123 ),
					),
				),
			)
		);

		$this->assertSame( 'mypage', $result['post_type'] );
		$this->assertSame( 'category', $result['taxonomy_mappings'][0]['destination_taxonomy'] );
		$this->assertSame( array( 'Notes' ), $result['taxonomy_mappings'][0]['destination_terms'] );
	}

	/**
	 * Test the option key uses the documented prefix.
	 *
	 * @return void
	 */
	public function test_option_key(): void {
		$this->assertSame( 'ai_importer_mappings_twitter', MappingConfig::get_option_key( 'twitter' ) );
	}
}
