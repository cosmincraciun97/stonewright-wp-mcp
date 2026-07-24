<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge\Lifecycle;

use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Promotes schema knowledge only after a failed shape is followed by verified readback.
 */
final class SchemaRepairLearning {

	/**
	 * Attach the live runtime fingerprint before storing a compiler failure.
	 *
	 * @param array<string, mixed> $attempted_settings
	 * @return list<array<string, string>>
	 */
	public static function observe_compilation_error( string $widget, array $attempted_settings, \WP_Error $error, string $task_hash ): array {
		if ( '' === $task_hash ) {
			return [];
		}
		$schema = WidgetSchemaRepository::get( $widget );
		if ( $schema instanceof \WP_Error ) {
			return [];
		}
		$data = array_merge(
			(array) $error->get_error_data(),
			[
				'schema_hash'         => (string) ( $schema['schema_hash'] ?? '' ),
				'runtime_fingerprint' => (string) ( $schema['runtime_fingerprint'] ?? '' ),
			]
		);
		$enriched = new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
		return self::observe_failure( $widget, $attempted_settings, $enriched, $task_hash );
	}

	/**
	 * @param array<string, mixed> $attempted_settings
	 * @return list<array<string, string>>
	 */
	public static function observe_failure( string $widget, array $attempted_settings, \WP_Error $error, string $task_hash ): array {
		$data        = (array) $error->get_error_data();
		$schema_hash = strtolower( (string) ( $data['schema_hash'] ?? '' ) );
		$runtime     = strtolower( (string) ( $data['runtime_fingerprint'] ?? '' ) );
		$recorded    = [];
		foreach ( (array) ( $data['violations'] ?? [] ) as $violation ) {
			if ( ! is_array( $violation ) ) {
				continue;
			}
			$path    = (string) ( $violation['path'] ?? '' );
			$parts   = array_values( array_filter( preg_split( '/[.\\[\\]]+/', $path ) ?: [] ) );
			$control = sanitize_key( (string) end( $parts ) );
			if ( '' === $control || ! array_key_exists( $control, $attempted_settings ) ) {
				continue;
			}
			$incident = [
				'widget'              => sanitize_key( $widget ),
				'control'             => $control,
				'expected_type'       => self::expected_type( (string) ( $violation['expected'] ?? '' ) ),
				'received_type'       => sanitize_key( (string) ( $violation['received'] ?? self::value_type( $attempted_settings[ $control ] ) ) ),
				'schema_hash'         => $schema_hash,
				'runtime_fingerprint' => $runtime,
				'task_hash'           => $task_hash,
			];
			SchemaRepairIncidentStore::record( $incident );
			$recorded[] = [
				'widget'  => (string) $incident['widget'],
				'control' => $control,
			];
		}
		return $recorded;
	}

	/**
	 * @param array<string, mixed> $verified_settings
	 * @param array<string, mixed> $schema
	 * @return list<array{candidate:array<string,mixed>,control:string}>
	 */
	public static function observe_verified( string $widget, array $verified_settings, array $schema, string $task_hash ): array {
		$schema_hash = strtolower( (string) ( $schema['schema_hash'] ?? '' ) );
		$runtime     = strtolower( (string) ( $schema['runtime_fingerprint'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $task_hash )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $schema_hash )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $runtime ) ) {
			return [];
		}

		$results = [];
		foreach ( $verified_settings as $control => $value ) {
			$control   = sanitize_key( (string) $control );
			$incidents = SchemaRepairIncidentStore::matching( $widget, $control, $schema_hash );
			if ( [] === $incidents ) {
				continue;
			}
			$verified_type = self::value_type( $value );
			$matches       = array_filter(
				$incidents,
				static fn( array $incident ): bool =>
					(string) $incident['expected_type'] === $verified_type
					&& (string) $incident['runtime_fingerprint'] === $runtime
			);
			if ( [] === $matches ) {
				continue;
			}

			$candidate = CandidateRepository::create(
				self::candidate_input( $widget, $control, $verified_type, $schema )
			);
			if ( $candidate instanceof \WP_Error ) {
				continue;
			}
			$verified = CandidateRepository::verify( (int) $candidate['id'], $task_hash, $runtime, true );
			if ( $verified instanceof \WP_Error ) {
				continue;
			}
			if ( (int) $verified['verification_count'] >= 2 ) {
				$promoted = CandidateRepository::promote( (int) $verified['id'], false, '' );
				if ( is_array( $promoted ) && is_array( $promoted['candidate'] ?? null ) ) {
					$verified = $promoted['candidate'];
				}
			}
			$results[] = [
				'candidate' => $verified,
				'control'   => $control,
			];
		}
		return $results;
	}

	/** @param array<string, mixed> $schema @return array<string, mixed> */
	private static function candidate_input( string $widget, string $control, string $type, array $schema ): array {
		$schema_hash = strtolower( (string) $schema['schema_hash'] );
		$core        = trim( (string) ( $schema['elementor_core'] ?? '' ) );
		$pro         = trim( (string) ( $schema['elementor_pro'] ?? '' ) );
		$topic       = 'Elementor schema repair: ' . sanitize_key( $widget ) . '/' . sanitize_key( $control );

		return [
			'topic'               => $topic,
			'widget'              => sanitize_key( $widget ),
			'control'             => sanitize_key( $control ),
			'fact'                => sprintf( 'The %s control expects the %s value type under schema %s.', sanitize_key( $control ), sanitize_key( $type ), $schema_hash ),
			'recipe'              => sprintf( 'Send %s as type %s only after confirming the exact live schema fingerprint.', sanitize_key( $control ), sanitize_key( $type ) ),
			'source_url'          => '',
			'source_hash'         => $schema_hash,
			'fetched_at'          => gmdate( DATE_ATOM ),
			'expires_at'          => gmdate( DATE_ATOM, time() + 30 * DAY_IN_SECONDS ),
			'version_constraints' => [
				'elementor_core' => '' === $core ? '*' : '=' . $core,
				'elementor_pro'  => '' === $pro ? '*' : '=' . $pro,
			],
			'evidence_type'       => 'live_schema',
			'confidence'          => 0.9,
			'create_draft_skill'  => true,
		];
	}

	private static function value_type( mixed $value ): string {
		return match ( true ) {
			is_int( $value ), is_float( $value ) => 'number',
			is_bool( $value )                    => 'boolean',
			is_string( $value )                  => 'string',
			is_array( $value )                   => 'object',
			null === $value                      => 'null',
			default                              => sanitize_key( gettype( $value ) ),
		};
	}

	private static function expected_type( string $expected ): string {
		$expected = strtolower( $expected );
		foreach ( [ 'number', 'boolean', 'string', 'object', 'null' ] as $type ) {
			if ( str_contains( $expected, $type ) ) {
				return $type;
			}
		}
		if ( str_contains( $expected, 'list' ) || str_contains( $expected, 'repeater' ) || str_contains( $expected, 'dimensions' ) ) {
			return 'object';
		}
		return sanitize_key( $expected );
	}
}
