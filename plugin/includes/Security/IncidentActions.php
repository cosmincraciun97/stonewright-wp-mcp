<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/** Formats and ranks compact repair actions for task-start. */
final class IncidentActions {

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	public static function rank( array $rows, string $surface = 'unknown', int $limit = 3 ): array {
		$surface = sanitize_key( strtolower( trim( $surface ) ) );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					$state = (string) ( $row['state'] ?? '' );
					if ( ! in_array( $state, [ 'open', 'observing' ], true ) ) {
						return false;
					}
					return ! IncidentStore::is_input_shape_code( (string) ( $row['root_error_code'] ?? '' ) );
				}
			)
		);
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $surface ): int {
				$left_key  = self::sort_key( $left, $surface );
				$right_key = self::sort_key( $right, $surface );
				return $right_key <=> $left_key;
			}
		);

		$out = [];
		foreach ( array_slice( $rows, 0, max( 1, min( 10, $limit ) ) ) as $row ) {
			$ability = (string) ( $row['ability_name'] ?? '' );
			$code    = (string) ( $row['root_error_code'] ?? '' );
			$next    = (string) ( $row['remediation_code'] ?? '' );
			if ( ! str_starts_with( $next, 'stonewright/' ) ) {
				$next = $ability;
			}
			$out[] = [
				'incident_id'       => (string) ( $row['incident_id'] ?? '' ),
				'state'             => (string) ( $row['state'] ?? 'observing' ),
				'ability'           => $ability,
				'error_code'        => $code,
				'occurrences'       => (int) ( $row['occurrence_count'] ?? 0 ),
				'repair'            => RemediationHints::for_code( $code, $ability ),
				'next_tool'         => $next,
				'required_verifier' => (string) ( $row['expected_verifier'] ?? '' ),
				'retry_policy'      => 'repair_then_retry_once',
				'learning_policy'   => 'promote_only_after_verified_repair',
			];
		}
		return $out;
	}

	/** @param array<string, mixed> $row @return array<int, int|string> */
	private static function sort_key( array $row, string $surface ): array {
		$haystack = strtolower( (string) ( $row['ability_name'] ?? '' ) . ' ' . (string) ( $row['ability_family'] ?? '' ) );
		$relevant = '' !== $surface && 'unknown' !== $surface && str_contains( $haystack, $surface ) ? 1 : 0;
		$state    = 'open' === (string) ( $row['state'] ?? '' ) ? 1 : 0;
		$severity = match ( strtolower( (string) ( $row['severity'] ?? '' ) ) ) {
			'critical', 'p0' => 4,
			'high', 'error'  => 3,
			'warning'        => 2,
			default          => 1,
		};
		return [ $relevant, $state, $severity, (int) ( $row['occurrence_count'] ?? 0 ), (string) ( $row['last_seen'] ?? '' ) ];
	}
}
