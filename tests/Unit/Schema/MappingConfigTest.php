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
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
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
		$this->assertArrayHasKey( 'author_mappings', $schema['properties'] );
		$this->assertArrayHasKey( 'default_author_id', $schema['properties'] );
		$this->assertArrayHasKey( 'post_format_mappings', $schema['properties'] );
		$this->assertArrayHasKey( 'default_post_format', $schema['properties'] );
		$this->assertArrayHasKey( 'meta_field_mappings', $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( MappingConfig::ALLOWED_STATUSES, $schema['properties']['post_status']['enum'] );
		$this->assertSame( MappingConfig::ALLOWED_POST_FORMATS, $schema['properties']['default_post_format']['enum'] );
	}

	/**
	 * Test author mappings are sanitized and malformed entries dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_author_mappings(): void {
		$result = MappingConfig::sanitize(
			array(
				'author_mappings' => array(
					array(
						'source_author'       => '@jane',
						'destination_user_id' => '7',
					),
					// Missing destination user.
					array( 'source_author' => '@bob' ),
					// Zero / invalid user ID.
					array(
						'source_author'       => '@zero',
						'destination_user_id' => 0,
					),
					'not-an-array',
				),
				'default_author_id' => '3',
			)
		);

		$this->assertSame(
			array(
				array(
					'source_author'       => '@jane',
					'destination_user_id' => 7,
				),
			),
			$result['author_mappings']
		);
		$this->assertSame( 3, $result['default_author_id'] );
	}

	/**
	 * Test an invalid default author ID is dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_invalid_default_author(): void {
		$result = MappingConfig::sanitize( array( 'default_author_id' => 0 ) );

		$this->assertArrayNotHasKey( 'default_author_id', $result );
	}

	/**
	 * Test post format mappings validate the format against the allowed list.
	 *
	 * @return void
	 */
	public function test_sanitize_post_format_mappings(): void {
		$result = MappingConfig::sanitize(
			array(
				'post_format_mappings' => array(
					array(
						'source_content_type' => 'media',
						'post_format'         => 'gallery',
					),
					// Invalid format dropped.
					array(
						'source_content_type' => 'video',
						'post_format'         => 'bogus',
					),
				),
				'default_post_format'  => 'aside',
			)
		);

		$this->assertSame(
			array(
				array(
					'source_content_type' => 'media',
					'post_format'         => 'gallery',
				),
			),
			$result['post_format_mappings']
		);
		$this->assertSame( 'aside', $result['default_post_format'] );
	}

	/**
	 * Test an invalid default post format is rejected.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_invalid_default_post_format(): void {
		$result = MappingConfig::sanitize( array( 'default_post_format' => 'bogus' ) );

		$this->assertArrayNotHasKey( 'default_post_format', $result );
	}

	/**
	 * Test meta field mappings sanitize keys and drop malformed entries.
	 *
	 * @return void
	 */
	public function test_sanitize_meta_field_mappings(): void {
		$result = MappingConfig::sanitize(
			array(
				'meta_field_mappings' => array(
					array(
						'source_field'         => 'location',
						'destination_meta_key' => 'My Geo Field!',
					),
					// Missing destination.
					array( 'source_field' => 'mood' ),
					'not-an-array',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'source_field'         => 'location',
					'destination_meta_key' => 'mygeofield',
				),
			),
			$result['meta_field_mappings']
		);
	}

	/**
	 * Test taxonomy mappings keep create_if_missing and the custom label.
	 *
	 * @return void
	 */
	public function test_sanitize_taxonomy_create_if_missing(): void {
		$result = MappingConfig::sanitize(
			array(
				'taxonomy_mappings' => array(
					array(
						'source_signal'        => 'hashtags',
						'destination_taxonomy' => 'mood',
						'create_if_missing'    => true,
						'taxonomy_label'       => 'Mood',
					),
				),
			)
		);

		$this->assertTrue( $result['taxonomy_mappings'][0]['create_if_missing'] );
		$this->assertSame( 'Mood', $result['taxonomy_mappings'][0]['taxonomy_label'] );
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
