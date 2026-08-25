<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Diagnostics;

use InvalidArgumentException;
use Throwable;

/**
 * Deterministic DAG for connection diagnostics.
 */
final class DiagnosticGraph {

	/**
	 * @var array<string, array{id: string, depends_on: list<string>, callback: callable, index: int}>
	 */
	private array $nodes = [];

	private int $index = 0;

	/**
	 * @param list<string> $depends_on Dependency check ids.
	 * @throws InvalidArgumentException When the id is empty or duplicated.
	 */
	public function add( string $id, array $depends_on, callable $callback ): void {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Diagnostic check id is required.' );
		}
		if ( isset( $this->nodes[ $id ] ) ) {
			throw new InvalidArgumentException( 'Duplicate diagnostic check id.' );
		}

		$deps = [];
		foreach ( $depends_on as $dep ) {
			$token = sanitize_key( (string) $dep );
			if ( '' === $token ) {
				throw new InvalidArgumentException( 'Unknown diagnostic dependency.' );
			}
			$deps[] = $token;
		}

		$this->nodes[ $id ] = [
			'id'         => $id,
			'depends_on' => array_values( array_unique( $deps ) ),
			'callback'   => $callback,
			'index'      => $this->index,
		];
		++$this->index;
	}

	/**
	 * @return array{
	 *   checks: list<array<string, mixed>>,
	 *   counts: array{problem: int, warning: int, info: int, ok: int, skipped: int}
	 * }
	 * @throws InvalidArgumentException When the graph is cyclic or a dependency is unknown.
	 */
	public function run(): array {
		$order   = $this->topo_order();
		$results = [];

		foreach ( $order as $id ) {
			$node    = $this->nodes[ $id ];
			$blocked = [];
			foreach ( $node['depends_on'] as $dep ) {
				$status = (string) ( $results[ $dep ]['status'] ?? 'problem' );
				if ( in_array( $status, [ 'problem', 'skipped' ], true ) ) {
					$blocked[] = $dep;
				}
			}
			if ( [] !== $blocked ) {
				$results[ $id ] = DiagnosticCheck::skipped(
					$id,
					$id,
					'Skipped: ' . implode( ', ', $blocked ),
					$node['depends_on']
				)->to_array();
				continue;
			}

			$started = hrtime( true );
			try {
				$check = ( $node['callback'] )();
				if ( ! $check instanceof DiagnosticCheck ) {
					throw new InvalidArgumentException( 'Diagnostic callback must return DiagnosticCheck.' );
				}
				$payload = $check->to_array();
			} catch ( Throwable $e ) {
				$payload = DiagnosticCheck::problem(
					$id,
					$id,
					'Check failed.',
					'Run diagnostics again.'
				)->to_array();
				$payload['evidence'] = [
					'error_class' => $e::class,
				];
			}
			$payload['id']          = $id;
			$payload['depends_on']  = $node['depends_on'];
			$payload['duration_ms'] = max( 0, (int) round( ( hrtime( true ) - $started ) / 1e6 ) );
			$results[ $id ]         = $payload;
		}

		$checks = [];
		foreach ( $order as $id ) {
			$checks[] = $results[ $id ];
		}

		return [
			'checks' => $checks,
			'counts' => self::count_statuses( $checks ),
		];
	}

	/**
	 * @return list<string>
	 * @throws InvalidArgumentException When the graph is cyclic or a dependency is unknown.
	 */
	private function topo_order(): array {
		$incoming = [];
		$ready    = [];
		$adj      = [];

		foreach ( $this->nodes as $id => $node ) {
			$incoming[ $id ] = 0;
			$adj[ $id ]      = [];
		}
		foreach ( $this->nodes as $id => $node ) {
			foreach ( $node['depends_on'] as $dep ) {
				if ( ! isset( $this->nodes[ $dep ] ) ) {
					throw new InvalidArgumentException( 'Unknown diagnostic dependency.' );
				}
				$adj[ $dep ][] = $id;
				++$incoming[ $id ];
			}
		}

		foreach ( $incoming as $id => $count ) {
			if ( 0 === $count ) {
				$ready[] = $id;
			}
		}
		usort( $ready, fn( string $a, string $b ): int => $this->nodes[ $a ]['index'] <=> $this->nodes[ $b ]['index'] );

		$order = [];
		while ( [] !== $ready ) {
			$id     = array_shift( $ready );
			$order[] = $id;
			$next    = [];
			foreach ( $adj[ $id ] as $child ) {
				--$incoming[ $child ];
				if ( 0 === $incoming[ $child ] ) {
					$next[] = $child;
				}
			}
			usort( $next, fn( string $a, string $b ): int => $this->nodes[ $a ]['index'] <=> $this->nodes[ $b ]['index'] );
			$ready = array_merge( $ready, $next );
		}

		if ( count( $order ) !== count( $this->nodes ) ) {
			throw new InvalidArgumentException( 'Diagnostic graph contains a cycle.' );
		}

		return $order;
	}

	/**
	 * @param list<array<string, mixed>> $checks Normalized checks.
	 * @return array{problem: int, warning: int, info: int, ok: int, skipped: int}
	 */
	private static function count_statuses( array $checks ): array {
		$counts = [
			'problem' => 0,
			'warning' => 0,
			'info'    => 0,
			'ok'      => 0,
			'skipped' => 0,
		];
		foreach ( $checks as $check ) {
			$status = (string) ( $check['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}
}
