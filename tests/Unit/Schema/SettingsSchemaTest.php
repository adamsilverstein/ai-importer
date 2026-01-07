<?php
/**
 * SettingsSchema class tests.
 *
 * @package AI_Importer\Tests\Unit\Schema
 */

namespace AI_Importer\Tests\Unit\Schema;

use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the SettingsSchema class.
 */
class SettingsSchemaTest extends TestCase {

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Mock WordPress sanitization functions.
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
	}

	/**
	 * Test add_field adds a field.
	 *
	 * @return void
	 */
	public function test_add_field_adds_field(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'api_key',
			array(
				'type'  => 'text',
				'label' => 'API Key',
			)
		);

		$this->assertTrue( $schema->has_field( 'api_key' ) );
	}

	/**
	 * Test add_field normalizes configuration.
	 *
	 * @return void
	 */
	public function test_add_field_normalizes_config(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'api_key',
			array(
				'label' => 'API Key',
			)
		);

		$field = $schema->get_field( 'api_key' );

		$this->assertSame( 'text', $field['type'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( '', $field['description'] );
	}

	/**
	 * Test add_field returns self for chaining.
	 *
	 * @return void
	 */
	public function test_add_field_returns_self(): void {
		$schema = new SettingsSchema();
		$result = $schema->add_field( 'field1', array( 'label' => 'Field 1' ) );

		$this->assertSame( $schema, $result );
	}

	/**
	 * Test add_field throws exception for invalid type.
	 *
	 * @return void
	 */
	public function test_add_field_throws_exception_for_invalid_type(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid field type' );

		$schema = new SettingsSchema();
		$schema->add_field(
			'field',
			array(
				'type'  => 'invalid_type',
				'label' => 'Field',
			)
		);
	}

	/**
	 * Test add_field throws exception for select without options.
	 *
	 * @return void
	 */
	public function test_add_field_throws_exception_for_select_without_options(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must have options' );

		$schema = new SettingsSchema();
		$schema->add_field(
			'field',
			array(
				'type'  => 'select',
				'label' => 'Field',
			)
		);
	}

	/**
	 * Test remove_field removes a field.
	 *
	 * @return void
	 */
	public function test_remove_field_removes_field(): void {
		$schema = new SettingsSchema();
		$schema->add_field( 'field1', array( 'label' => 'Field 1' ) );

		$this->assertTrue( $schema->has_field( 'field1' ) );

		$schema->remove_field( 'field1' );

		$this->assertFalse( $schema->has_field( 'field1' ) );
	}

	/**
	 * Test get_fields returns all fields.
	 *
	 * @return void
	 */
	public function test_get_fields_returns_all_fields(): void {
		$schema = new SettingsSchema();
		$schema
			->add_field( 'field1', array( 'label' => 'Field 1' ) )
			->add_field( 'field2', array( 'label' => 'Field 2' ) );

		$fields = $schema->get_fields();

		$this->assertCount( 2, $fields );
		$this->assertArrayHasKey( 'field1', $fields );
		$this->assertArrayHasKey( 'field2', $fields );
	}

	/**
	 * Test validate returns sanitized values.
	 *
	 * @return void
	 */
	public function test_validate_returns_sanitized_values(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'api_key',
			array(
				'type'  => 'text',
				'label' => 'API Key',
			)
		);

		$result = $schema->validate( array( 'api_key' => 'test-key' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'test-key', $result['api_key'] );
	}

	/**
	 * Test validate returns error for missing required field.
	 *
	 * @return void
	 */
	public function test_validate_returns_error_for_missing_required(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'api_key',
			array(
				'type'     => 'text',
				'label'    => 'API Key',
				'required' => true,
			)
		);

		$result = $schema->validate( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result->has_errors() );
	}

	/**
	 * Test validate uses default value for empty non-required field.
	 *
	 * @return void
	 */
	public function test_validate_uses_default_for_empty(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'limit',
			array(
				'type'    => 'number',
				'label'   => 'Limit',
				'default' => 100,
			)
		);

		$result = $schema->validate( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['limit'] );
	}

	/**
	 * Test validate checkbox field.
	 *
	 * @return void
	 */
	public function test_validate_checkbox_field(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'enabled',
			array(
				'type'  => 'checkbox',
				'label' => 'Enabled',
			)
		);

		$result = $schema->validate( array( 'enabled' => '1' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['enabled'] );
	}

	/**
	 * Test validate select field with valid option.
	 *
	 * @return void
	 */
	public function test_validate_select_with_valid_option(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'format',
			array(
				'type'    => 'select',
				'label'   => 'Format',
				'options' => array(
					'json' => 'JSON',
					'xml'  => 'XML',
				),
			)
		);

		$result = $schema->validate( array( 'format' => 'json' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'json', $result['format'] );
	}

	/**
	 * Test validate select field with invalid option.
	 *
	 * @return void
	 */
	public function test_validate_select_with_invalid_option(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'format',
			array(
				'type'    => 'select',
				'label'   => 'Format',
				'options' => array(
					'json' => 'JSON',
					'xml'  => 'XML',
				),
			)
		);

		$result = $schema->validate( array( 'format' => 'invalid' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test validate number field with min/max.
	 *
	 * @return void
	 */
	public function test_validate_number_with_min_max(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'limit',
			array(
				'type'  => 'number',
				'label' => 'Limit',
				'min'   => 1,
				'max'   => 100,
			)
		);

		// Valid.
		$result = $schema->validate( array( 'limit' => 50 ) );
		$this->assertIsArray( $result );
		$this->assertSame( 50, $result['limit'] );

		// Too low.
		$result = $schema->validate( array( 'limit' => 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );

		// Too high.
		$result = $schema->validate( array( 'limit' => 200 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test validate date field.
	 *
	 * @return void
	 */
	public function test_validate_date_field(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'start_date',
			array(
				'type'  => 'date',
				'label' => 'Start Date',
			)
		);

		$result = $schema->validate( array( 'start_date' => '2024-01-15' ) );

		$this->assertIsArray( $result );
		$this->assertSame( '2024-01-15', $result['start_date'] );
	}

	/**
	 * Test validate date_range field.
	 *
	 * @return void
	 */
	public function test_validate_date_range_field(): void {
		$schema = new SettingsSchema();
		$schema->add_field(
			'date_range',
			array(
				'type'  => 'date_range',
				'label' => 'Date Range',
			)
		);

		$result = $schema->validate(
			array(
				'date_range' => array(
					'start' => '2024-01-01',
					'end'   => '2024-12-31',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '2024-01-01', $result['date_range']['start'] );
		$this->assertSame( '2024-12-31', $result['date_range']['end'] );
	}

	/**
	 * Test to_array returns correct structure.
	 *
	 * @return void
	 */
	public function test_to_array_returns_correct_structure(): void {
		$schema = new SettingsSchema();
		$schema->add_field( 'field1', array( 'label' => 'Field 1' ) );

		$array = $schema->to_array();

		$this->assertArrayHasKey( 'fields', $array );
		$this->assertArrayHasKey( 'field1', $array['fields'] );
	}

	/**
	 * Test from_array creates schema correctly.
	 *
	 * @return void
	 */
	public function test_from_array_creates_schema(): void {
		$data = array(
			'fields' => array(
				'api_key' => array(
					'type'  => 'text',
					'label' => 'API Key',
				),
				'format' => array(
					'type'    => 'select',
					'label'   => 'Format',
					'options' => array(
						'json' => 'JSON',
						'xml'  => 'XML',
					),
				),
			),
		);

		$schema = SettingsSchema::from_array( $data );

		$this->assertTrue( $schema->has_field( 'api_key' ) );
		$this->assertTrue( $schema->has_field( 'format' ) );
	}
}
