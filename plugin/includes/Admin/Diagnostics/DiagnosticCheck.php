<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Diagnostics;

use InvalidArgumentException;

/**
 * Bounded diagnostic check value object.
 *
 * @phpstan-type DiagnosticAction array{type: string, label: string, target: string}
 * @phpstan-type DiagnosticEvidence array<string, bool|float|int|string|null>
 * @phpstan-type DiagnosticCheckArray array{
 *   id: string,
 *   scope: string,
 *   status: 'ok'|'info'|'warning'|'problem'|'skipped',
 *   severity: 'none'|'info'|'warning'|'error',
 *   label: string,
 *   summary: string,
 *   evidence: DiagnosticEvidence,
 *   remedy: string,
 *   action: DiagnosticAction|null,
 *   copy: string|null,
 *   depends_on: list<string>,
 *   duration_ms: int
 * }
 */
final class DiagnosticCheck {

	public const STATUSES = [ 'ok', 'info', 'warning', 'problem', 'skipped' ];

	public const SEVERITIES = [ 'none', 'info', 'warning', 'error' ];

	public const ACTION_TYPES = [ 'copy', 'link', 'retry' ];

	public const MAX_ID = 64;

	public const MAX_LABEL = 200;

	public const MAX_SUMMARY = 500;

	public const MAX_REMEDY = 500;

	public const MAX_COPY = 4000;

	public const MAX_SCOPE = 64;

	public const MAX_EVIDENCE_STRING = 200;

	public const MAX_EVIDENCE_KEYS = 16;

	/**
	 * @var DiagnosticCheckArray
	 */
	private array $data;

	/**
	 * @param DiagnosticCheckArray $data Normalized check payload.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * @param array<string, mixed> $data Raw check payload.
	 * @throws InvalidArgumentException When the payload is not a bounded check.
	 */
	public static function from_array( array $data ): self {
		$id     = self::bound_token( (string) ( $data['id'] ?? '' ), self::MAX_ID );
		$status = (string) ( $data['status'] ?? '' );
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Diagnostic check id is required.' );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new InvalidArgumentException( 'Unknown diagnostic status.' );
		}
		if ( isset( $data['severity'] ) && ! in_array( (string) $data['severity'], self::SEVERITIES, true ) ) {
			throw new InvalidArgumentException( 'Unknown diagnostic severity.' );
		}

		$scope      = self::bound_token( (string) ( $data['scope'] ?? 'connection' ), self::MAX_SCOPE );
		$evidence   = self::sanitize_evidence( is_array( $data['evidence'] ?? null ) ? $data['evidence'] : [] );
		$duration   = (int) ( $data['duration_ms'] ?? $evidence['duration_ms'] ?? 0 );
		$depends_on = self::sanitize_id_list( $data['depends_on'] ?? [] );
		$copy       = $data['copy'] ?? null;
		$copy       = is_string( $copy ) && '' !== $copy ? self::bound_text( $copy, self::MAX_COPY ) : null;

		return new self(
			[
				'id'          => $id,
				'scope'       => '' === $scope ? 'connection' : $scope,
				'status'      => $status,
				'severity'    => self::severity_for( $status ),
				'label'       => self::bound_text( (string) ( $data['label'] ?? $id ), self::MAX_LABEL ),
				'summary'     => self::bound_text( (string) ( $data['summary'] ?? '' ), self::MAX_SUMMARY ),
				'evidence'    => $evidence,
				'remedy'      => self::bound_text( (string) ( $data['remedy'] ?? '' ), self::MAX_REMEDY ),
				'action'      => self::sanitize_action( $data['action'] ?? null ),
				'copy'        => $copy,
				'depends_on'  => $depends_on,
				'duration_ms' => max( 0, $duration ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $evidence Scalar evidence.
	 */
	public static function ok( string $id, string $label, string $summary, array $evidence = [], string $scope = 'connection' ): self {
		return self::from_array(
			[
				'id'       => $id,
				'scope'    => $scope,
				'status'   => 'ok',
				'label'    => $label,
				'summary'  => $summary,
				'evidence' => $evidence,
			]
		);
	}

	/**
	 * @param array<string, mixed> $evidence Scalar evidence.
	 */
	public static function info( string $id, string $label, string $summary, array $evidence = [], string $scope = 'connection' ): self {
		return self::from_array(
			[
				'id'       => $id,
				'scope'    => $scope,
				'status'   => 'info',
				'label'    => $label,
				'summary'  => $summary,
				'evidence' => $evidence,
			]
		);
	}

	/**
	 * @param array<string, mixed> $evidence Scalar evidence.
	 */
	public static function warning( string $id, string $label, string $summary, string $remedy = '', array $evidence = [], string $scope = 'connection' ): self {
		return self::from_array(
			[
				'id'       => $id,
				'scope'    => $scope,
				'status'   => 'warning',
				'label'    => $label,
				'summary'  => $summary,
				'remedy'   => $remedy,
				'evidence' => $evidence,
			]
		);
	}

	/**
	 * @param array<string, mixed> $evidence Scalar evidence.
	 */
	public static function problem( string $id, string $label, string $summary, string $remedy, string $scope = 'connection', array $evidence = [] ): self {
		return self::from_array(
			[
				'id'       => $id,
				'scope'    => $scope,
				'status'   => 'problem',
				'label'    => $label,
				'summary'  => $summary,
				'remedy'   => $remedy,
				'evidence' => $evidence,
			]
		);
	}

	/**
	 * @param list<string> $depends_on Dependency ids.
	 */
	public static function skipped( string $id, string $label, string $summary, array $depends_on = [], string $scope = 'connection' ): self {
		return self::from_array(
			[
				'id'         => $id,
				'scope'      => $scope,
				'status'     => 'skipped',
				'label'      => $label,
				'summary'    => $summary,
				'depends_on' => $depends_on,
			]
		);
	}

	public function with_copy( ?string $copy ): self {
		$data         = $this->data;
		$data['copy'] = $copy;
		return self::from_array( $data );
	}

	/**
	 * @param DiagnosticAction|null $action Safe UI action.
	 */
	public function with_action( ?array $action ): self {
		$data           = $this->data;
		$data['action'] = $action;
		return self::from_array( $data );
	}

	/**
	 * @return DiagnosticCheckArray
	 */
	public function to_array(): array {
		return $this->data;
	}

	public static function severity_for( string $status ): string {
		return match ( $status ) {
			'problem' => 'error',
			'warning' => 'warning',
			'info'    => 'info',
			default   => 'none',
		};
	}

	/**
	 * @param array<string, mixed> $evidence Raw evidence.
	 * @return array<string, bool|float|int|string|null>
	 * @throws InvalidArgumentException When evidence is nested or non-scalar.
	 */
	private static function sanitize_evidence( array $evidence ): array {
		$clean = [];
		foreach ( $evidence as $key => $value ) {
			if ( count( $clean ) >= self::MAX_EVIDENCE_KEYS ) {
				break;
			}
			$token = self::bound_token( (string) $key, 64 );
			if ( '' === $token ) {
				continue;
			}
			if ( is_array( $value ) || is_object( $value ) || is_resource( $value ) ) {
				throw new InvalidArgumentException( 'Diagnostic evidence must be scalar.' );
			}
			if ( is_float( $value ) && ! is_finite( $value ) ) {
				throw new InvalidArgumentException( 'Diagnostic evidence must be scalar.' );
			}
			if ( is_string( $value ) ) {
				$clean[ $token ] = self::bound_text( $value, self::MAX_EVIDENCE_STRING );
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $token ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * @param mixed $action Raw action.
	 * @return array{type: string, label: string, target: string}|null
	 * @throws InvalidArgumentException When the action type is unknown.
	 */
	private static function sanitize_action( mixed $action ): ?array {
		if ( null === $action ) {
			return null;
		}
		if ( ! is_array( $action ) ) {
			throw new InvalidArgumentException( 'Diagnostic action must be null or an array.' );
		}
		$type   = self::bound_token( (string) ( $action['type'] ?? '' ), 32 );
		$label  = self::bound_text( (string) ( $action['label'] ?? '' ), self::MAX_LABEL );
		$target = self::bound_text( (string) ( $action['target'] ?? '' ), 200 );
		if ( ! in_array( $type, self::ACTION_TYPES, true ) || '' === $label || '' === $target ) {
			throw new InvalidArgumentException( 'Unknown diagnostic action type.' );
		}

		return [
			'type'   => $type,
			'label'  => $label,
			'target' => $target,
		];
	}

	/**
	 * @param mixed $ids Raw dependency ids.
	 * @return list<string>
	 */
	private static function sanitize_id_list( mixed $ids ): array {
		if ( ! is_array( $ids ) ) {
			return [];
		}
		$clean = [];
		foreach ( $ids as $id ) {
			$token = self::bound_token( (string) $id, self::MAX_ID );
			if ( '' !== $token ) {
				$clean[] = $token;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private static function bound_token( string $value, int $max ): string {
		$value = sanitize_key( $value );
		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		return substr( $value, 0, $max );
	}

	private static function bound_text( string $value, int $max ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		return substr( $value, 0, $max );
	}
}
