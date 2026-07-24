<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge\Lifecycle;

/**
 * Bounded, non-agentic correlation store for Elementor schema failures.
 */
final class SchemaRepairIncidentStore {
	private const OPTION          = 'stonewright_schema_repair_incidents';
	private const TTL             = 7 * DAY_IN_SECONDS;
	private const MAX_TOTAL       = 200;
	private const MAX_PER_RUNTIME = 20;

	/** @param array<string, mixed> $incident */
	public static function record( array $incident ): void {
		$row = self::normalize( $incident );
		if ( null === $row ) {
			return;
		}

		$rows = self::prune( (array) get_option( self::OPTION, [] ) );
		$key  = hash(
			'sha256',
			implode(
				'|',
				[
					$row['widget'],
					$row['control'],
					$row['schema_hash'],
					$row['received_type'],
				]
			)
		);
		$now        = time();
		$rows[ $key ] = $row + [
			'recorded_at' => $now,
			'expires_at'  => $now + self::TTL,
		];

		update_option( self::OPTION, self::limit( $rows ), false );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function matching( string $widget, string $control, string $schema_hash ): array {
		$rows = self::prune( (array) get_option( self::OPTION, [] ) );
		update_option( self::OPTION, $rows, false );

		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool =>
					(string) $row['widget'] === sanitize_key( $widget )
					&& (string) $row['control'] === sanitize_key( $control )
					&& hash_equals( (string) $row['schema_hash'], strtolower( $schema_hash ) )
			)
		);
	}

	/** @param array<string, mixed> $incident @return array<string, string>|null */
	private static function normalize( array $incident ): ?array {
		$row = [
			'widget'              => sanitize_key( (string) ( $incident['widget'] ?? '' ) ),
			'control'             => sanitize_key( (string) ( $incident['control'] ?? '' ) ),
			'expected_type'       => sanitize_key( (string) ( $incident['expected_type'] ?? '' ) ),
			'received_type'       => sanitize_key( (string) ( $incident['received_type'] ?? '' ) ),
			'schema_hash'         => strtolower( (string) ( $incident['schema_hash'] ?? '' ) ),
			'runtime_fingerprint' => strtolower( (string) ( $incident['runtime_fingerprint'] ?? '' ) ),
			'task_hash'           => strtolower( (string) ( $incident['task_hash'] ?? '' ) ),
		];
		if ( '' === $row['widget'] || '' === $row['control'] || '' === $row['expected_type'] || '' === $row['received_type'] ) {
			return null;
		}
		foreach ( [ 'schema_hash', 'runtime_fingerprint', 'task_hash' ] as $hash_key ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $row[ $hash_key ] ) ) {
				return null;
			}
		}
		return $row;
	}

	/** @param array<string, mixed> $rows @return array<string, array<string, mixed>> */
	private static function prune( array $rows ): array {
		$now = time();
		return array_filter(
			$rows,
			static fn( mixed $row ): bool => is_array( $row ) && (int) ( $row['expires_at'] ?? 0 ) > $now
		);
	}

	/** @param array<string, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
	private static function limit( array $rows ): array {
		uasort(
			$rows,
			static fn( array $left, array $right ): int =>
				(int) ( $right['recorded_at'] ?? 0 ) <=> (int) ( $left['recorded_at'] ?? 0 )
		);
		$limited  = [];
		$runtime_counts = [];
		foreach ( $rows as $key => $row ) {
			$runtime = (string) ( $row['runtime_fingerprint'] ?? '' );
			$runtime_counts[ $runtime ] = (int) ( $runtime_counts[ $runtime ] ?? 0 );
			if ( $runtime_counts[ $runtime ] >= self::MAX_PER_RUNTIME ) {
				continue;
			}
			$limited[ (string) $key ] = $row;
			++$runtime_counts[ $runtime ];
			if ( count( $limited ) >= self::MAX_TOTAL ) {
				break;
			}
		}
		return $limited;
	}
}
