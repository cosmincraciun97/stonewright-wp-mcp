<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

/**
 * In-memory stand-in for the memory table.
 *
 * It answers the statements the Memory class issues for the admin page: lookups by id and by scope and key,
 * the newest-first list, insert, update and delete. A scope and key pair is unique, as it is in the real table.
 */
final class MemoryTableDouble {

	public string $prefix = 'wp_';

	public int $insert_id = 0;

	public string $last_error = '';

	/** @var array<int, array<string, mixed>> */
	public array $rows = [];

	private int $next_id = 1;

	/**
	 * @param array<string, mixed> $row Column values; defaults fill the rest.
	 */
	public function seed( array $row ): int {
		$id   = isset( $row['id'] ) ? (int) $row['id'] : $this->next_id;
		$data = array_merge(
			[
				'id'         => $id,
				'type'       => 'generic',
				'scope'      => 'default',
				'memory_key' => 'key-' . $id,
				'name'       => 'Entry ' . $id,
				'value_json' => '"value"',
				'confidence' => '1.0000',
				'topic'      => '',
				'status'     => 'active',
				'precedence' => 0,
				'created_at' => '2026-10-07 06:00:00',
				'updated_at' => '2026-10-07 06:00:00',
			],
			$row,
			[ 'id' => $id ]
		);
		$this->rows[ $id ] = $data;
		$this->next_id     = max( $this->next_id, $id + 1 );

		return $id;
	}

	public function prepare( string $query, mixed ...$args ): string {
		return (string) wp_json_encode( [ 'q' => $query, 'a' => $args ] );
	}

	public function get_var( string $prepared ): mixed {
		$call = json_decode( $prepared, true );
		if ( is_array( $call ) && str_contains( (string) $call['q'], 'scope = %s AND memory_key = %s' ) ) {
			foreach ( $this->rows as $row ) {
				if ( (string) $row['scope'] === (string) $call['a'][0] && (string) $row['memory_key'] === (string) $call['a'][1] ) {
					return (string) $row['id'];
				}
			}
		}

		return null;
	}

	/** @return array<string, mixed>|null */
	public function get_row( string $prepared, string $output = 'OBJECT' ): ?array {
		$call = json_decode( $prepared, true );
		$id   = is_array( $call ) ? (int) ( $call['a'][0] ?? 0 ) : 0;

		return $this->rows[ $id ] ?? null;
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $prepared, string $output = 'OBJECT' ): array {
		$rows = array_values( $this->rows );
		usort( $rows, static fn ( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );

		return $rows;
	}

	/** @return list<string> */
	public function get_col( string $query, int $x = 0 ): array {
		return [
			'id', 'scope', 'type', 'name', 'memory_key', 'value_json', 'confidence', 'topic', 'version_fingerprint',
			'expires_at', 'status', 'precedence', 'created_by', 'created_at', 'updated_at', 'last_retrieved_at',
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string>   $format
	 */
	public function insert( string $table, array $data, array $format = [] ): int|false {
		foreach ( $this->rows as $row ) {
			if ( (string) $row['scope'] === (string) $data['scope'] && (string) $row['memory_key'] === (string) $data['memory_key'] ) {
				$this->last_error = 'Duplicate entry';

				return false;
			}
		}
		$this->insert_id = $this->seed( $data );

		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @param array<int, string>   $format
	 * @param array<int, string>   $where_format
	 */
	public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );

		return 1;
	}

	/**
	 * @param array<string, mixed> $where
	 * @param array<int, string>   $where_format
	 */
	public function delete( string $table, array $where, array $where_format = [] ): int|false {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		unset( $this->rows[ $id ] );

		return 1;
	}
}
