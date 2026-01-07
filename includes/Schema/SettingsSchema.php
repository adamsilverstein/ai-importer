<?php
/**
 * Settings schema class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Schema;

use WP_Error;

/**
 * Defines a schema for adapter settings and configuration options.
 */
class SettingsSchema {

	/**
	 * Valid field types.
	 */
	public const FIELD_TYPES = array(
		'text',
		'password',
		'textarea',
		'file',
		'select',
		'checkbox',
		'date',
		'date_range',
		'number',
		'url',
	);

	/**
	 * Schema fields.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = array();

	/**
	 * Add a field to the schema.
	 *
	 * @param string               $key    Field key.
	 * @param array<string, mixed> $config Field configuration.
	 * @return self For method chaining.
	 * @throws \InvalidArgumentException If field configuration is invalid.
	 */
	public function add_field( string $key, array $config ): self {
		$config = $this->normalize_field_config( $key, $config );
		$this->validate_field_config( $key, $config );
		$this->fields[ $key ] = $config;
		return $this;
	}

	/**
	 * Remove a field from the schema.
	 *
	 * @param string $key Field key.
	 * @return self For method chaining.
	 */
	public function remove_field( string $key ): self {
		unset( $this->fields[ $key ] );
		return $this;
	}

	/**
	 * Get a field configuration.
	 *
	 * @param string $key Field key.
	 * @return array<string, mixed>|null Field config or null.
	 */
	public function get_field( string $key ): ?array {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * Get all fields.
	 *
	 * @return array<string, array<string, mixed>> All fields.
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Check if a field exists.
	 *
	 * @param string $key Field key.
	 * @return bool True if field exists.
	 */
	public function has_field( string $key ): bool {
		return isset( $this->fields[ $key ] );
	}

	/**
	 * Validate values against the schema.
	 *
	 * @param array<string, mixed> $values Values to validate.
	 * @return array<string, mixed>|WP_Error Sanitized values or error.
	 */
	public function validate( array $values ): array|WP_Error {
		$sanitized = array();
		$errors    = new WP_Error();

		foreach ( $this->fields as $key => $config ) {
			$value = $values[ $key ] ?? null;

			// Check required fields.
			if ( ! empty( $config['required'] ) && $this->is_empty_value( $value ) ) {
				$errors->add(
					'required_field',
					sprintf(
						/* translators: %s: field label */
						__( '%s is required.', 'ai-importer' ),
						$config['label']
					),
					array( 'field' => $key )
				);
				continue;
			}

			// Skip empty non-required fields.
			if ( $this->is_empty_value( $value ) ) {
				$sanitized[ $key ] = $config['default'] ?? null;
				continue;
			}

			// Validate and sanitize based on type.
			$result = $this->validate_field_value( $key, $value, $config );

			if ( is_wp_error( $result ) ) {
				foreach ( $result->get_error_messages() as $message ) {
					$errors->add( 'validation_error', $message, array( 'field' => $key ) );
				}
			} else {
				$sanitized[ $key ] = $result;
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $sanitized;
	}

	/**
	 * Convert the schema to an array for JSON serialization.
	 *
	 * @return array<string, mixed> Array representation.
	 */
	public function to_array(): array {
		return array(
			'fields' => $this->fields,
		);
	}

	/**
	 * Create a schema from an array.
	 *
	 * @param array<string, mixed> $data Schema data.
	 * @return self New SettingsSchema instance.
	 */
	public static function from_array( array $data ): self {
		$schema = new self();

		if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
			foreach ( $data['fields'] as $key => $config ) {
				$schema->add_field( $key, $config );
			}
		}

		return $schema;
	}

	/**
	 * Normalize field configuration with defaults.
	 *
	 * @param string               $key    Field key.
	 * @param array<string, mixed> $config Field configuration.
	 * @return array<string, mixed> Normalized configuration.
	 */
	private function normalize_field_config( string $key, array $config ): array {
		return array_merge(
			array(
				'type'        => 'text',
				'label'       => $key,
				'description' => '',
				'required'    => false,
				'default'     => null,
				'placeholder' => '',
				'options'     => array(),
				'accept'      => '',
				'min'         => null,
				'max'         => null,
			),
			$config
		);
	}

	/**
	 * Validate field configuration.
	 *
	 * @param string               $key    Field key.
	 * @param array<string, mixed> $config Field configuration.
	 * @return void
	 * @throws \InvalidArgumentException If configuration is invalid.
	 */
	private function validate_field_config( string $key, array $config ): void {
		if ( ! in_array( $config['type'], self::FIELD_TYPES, true ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid field type "%s" for field "%s". Valid types: %s',
					$config['type'],
					$key,
					implode( ', ', self::FIELD_TYPES )
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( 'select' === $config['type'] && empty( $config['options'] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new \InvalidArgumentException(
				sprintf( 'Select field "%s" must have options.', $key )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Validate and sanitize a field value.
	 *
	 * @param string               $key    Field key.
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $config Field configuration.
	 * @return mixed|WP_Error Sanitized value or error.
	 */
	private function validate_field_value( string $key, mixed $value, array $config ): mixed {
		return match ( $config['type'] ) {
			'text', 'password' => sanitize_text_field( $value ),
			'textarea'         => sanitize_textarea_field( $value ),
			'url'              => $this->validate_url( $value, $config ),
			'number'           => $this->validate_number( $value, $config ),
			'checkbox'         => (bool) $value,
			'select'           => $this->validate_select( $value, $config ),
			'date'             => $this->validate_date( $value ),
			'date_range'       => $this->validate_date_range( $value ),
			'file'             => $value, // File validation handled separately.
			default            => $value,
		};
	}

	/**
	 * Validate URL value.
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $config Field configuration.
	 * @return string|WP_Error Sanitized URL or error.
	 */
	private function validate_url( mixed $value, array $config ): string|WP_Error {
		$url = esc_url_raw( $value );

		if ( empty( $url ) && ! empty( $value ) ) {
			return new WP_Error(
				'invalid_url',
				sprintf(
					/* translators: %s: field label */
					__( '%s must be a valid URL.', 'ai-importer' ),
					$config['label']
				)
			);
		}

		return $url;
	}

	/**
	 * Validate number value.
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $config Field configuration.
	 * @return int|float|WP_Error Validated number or error.
	 */
	private function validate_number( mixed $value, array $config ): int|float|WP_Error {
		if ( ! is_numeric( $value ) ) {
			return new WP_Error(
				'invalid_number',
				sprintf(
					/* translators: %s: field label */
					__( '%s must be a number.', 'ai-importer' ),
					$config['label']
				)
			);
		}

		$value = is_float( $value + 0 ) ? (float) $value : (int) $value;

		if ( null !== $config['min'] && $value < $config['min'] ) {
			return new WP_Error(
				'number_too_low',
				sprintf(
					/* translators: 1: field label, 2: minimum value */
					__( '%1$s must be at least %2$s.', 'ai-importer' ),
					$config['label'],
					$config['min']
				)
			);
		}

		if ( null !== $config['max'] && $value > $config['max'] ) {
			return new WP_Error(
				'number_too_high',
				sprintf(
					/* translators: 1: field label, 2: maximum value */
					__( '%1$s must be at most %2$s.', 'ai-importer' ),
					$config['label'],
					$config['max']
				)
			);
		}

		return $value;
	}

	/**
	 * Validate select value.
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $config Field configuration.
	 * @return string|WP_Error Validated value or error.
	 */
	private function validate_select( mixed $value, array $config ): string|WP_Error {
		$options = array_keys( $config['options'] );

		if ( ! in_array( $value, $options, true ) ) {
			return new WP_Error(
				'invalid_option',
				sprintf(
					/* translators: %s: field label */
					__( 'Invalid option selected for %s.', 'ai-importer' ),
					$config['label']
				)
			);
		}

		return $value;
	}

	/**
	 * Validate date value.
	 *
	 * @param mixed $value Value to validate.
	 * @return string|WP_Error ISO date string or error.
	 */
	private function validate_date( mixed $value ): string|WP_Error {
		try {
			$date = new \DateTimeImmutable( $value );
			return $date->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'invalid_date',
				__( 'Invalid date format.', 'ai-importer' )
			);
		}
	}

	/**
	 * Validate date range value.
	 *
	 * @param mixed $value Value to validate (array with start/end keys).
	 * @return array<string, string>|WP_Error Date range or error.
	 */
	private function validate_date_range( mixed $value ): array|WP_Error {
		if ( ! is_array( $value ) || ! isset( $value['start'], $value['end'] ) ) {
			return new WP_Error(
				'invalid_date_range',
				__( 'Date range must include start and end dates.', 'ai-importer' )
			);
		}

		$start = $this->validate_date( $value['start'] );
		$end   = $this->validate_date( $value['end'] );

		if ( is_wp_error( $start ) || is_wp_error( $end ) ) {
			return new WP_Error(
				'invalid_date_range',
				__( 'Invalid date format in date range.', 'ai-importer' )
			);
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Check if a value is considered empty.
	 *
	 * @param mixed $value Value to check.
	 * @return bool True if empty.
	 */
	private function is_empty_value( mixed $value ): bool {
		return null === $value || '' === $value || ( is_array( $value ) && empty( $value ) );
	}
}
