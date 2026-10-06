<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

/**
 * Table storage shared by every fake connection of one test. Tables come from
 * dbDelta-style CREATE TABLE statements (applied the way dbDelta extends an existing
 * table: missing columns and keys are added, rows keep their values and get the
 * column default). Values are stored as MySQL returns them to wpdb: strings or null.
 */
final class FakeTables {

	/**
	 * @var array<string, array{columns: array<string, array{type: string, nullable: bool, default: ?string, auto: bool}>, primary: list<string>, unique: array<string, list<string>>, indexes: array<string, list<string>>, rows: array<int, array<string, ?string>>, auto: int}>
	 */
	public array $tables = [];

	/** @var list<string> Statements dbDelta received, in order. */
	public array $ddl = [];

	/** @var array<string, string> Option name => autoload value written through SQL. */
	public array $option_autoload = [];

	private int $next_row = 1;

	/**
	 * Apply one or more CREATE TABLE statements the way dbDelta would.
	 *
	 * @param string|list<string> $sql
	 * @return list<string> Human-readable changes.
	 */
	public function apply_ddl( string|array $sql ): array {
		$statements = is_array( $sql ) ? $sql : array_filter( array_map( 'trim', explode( ';', $sql ) ) );
		$changes = [];
		foreach ( $statements as $statement ) {
			$statement = trim( (string) $statement );
			if ( '' === $statement ) {
				continue;
			}
			$this->ddl[] = $statement;
			if ( ! preg_match( '/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)[^)]*$/s', $statement, $match ) ) {
				throw new \LogicException( 'Unsupported DDL: ' . $statement );
			}
			$changes = array_merge( $changes, $this->create_or_extend( $match[1], $match[2] ) );
		}
		return $changes;
	}

	public function has( string $table ): bool {
		return isset( $this->tables[ $table ] );
	}

	/** @return list<array<string, ?string>> */
	public function rows( string $table ): array {
		return array_values( $this->table( $table )['rows'] );
	}

	/** @return array<int, array<string, ?string>> Row id => row. */
	public function indexed_rows( string $table ): array {
		return $this->table( $table )['rows'];
	}

	/** @return list<string> */
	public function columns( string $table ): array {
		return array_keys( $this->table( $table )['columns'] );
	}

	/** @return array<string, list<string>> Key name => columns, including PRIMARY. */
	public function keys( string $table ): array {
		$definition = $this->table( $table );
		return [ 'PRIMARY' => $definition['primary'] ] + $definition['unique'] + $definition['indexes'];
	}

	/**
	 * Insert one row, filling defaults. Returns [row id, auto-increment value] or an error string.
	 *
	 * @param array<string, mixed> $data
	 * @return array{0: int, 1: int}|string
	 */
	public function insert_row( string $table, array $data ): array|string {
		$definition = $this->table( $table );
		$row = [];
		$auto_value = 0;
		foreach ( $definition['columns'] as $name => $column ) {
			if ( array_key_exists( $name, $data ) ) {
				$row[ $name ] = null === $data[ $name ] ? null : self::scalar( $data[ $name ] );
			} elseif ( $column['auto'] ) {
				$auto_value = ++$this->tables[ $table ]['auto'];
				$row[ $name ] = (string) $auto_value;
			} else {
				$row[ $name ] = $column['default'];
			}
			if ( null === $row[ $name ] && ! $column['nullable'] ) {
				return "Column '{$name}' cannot be null";
			}
		}
		foreach ( array_keys( $data ) as $name ) {
			if ( ! isset( $definition['columns'][ $name ] ) ) {
				return "Unknown column '{$name}'";
			}
		}
		$conflict = $this->conflict( $table, $row, null );
		if ( null !== $conflict ) {
			return $conflict;
		}
		$id = $this->next_row++;
		$this->tables[ $table ]['rows'][ $id ] = $row;
		return [ $id, $auto_value ];
	}

	/**
	 * Replace one stored row after a uniqueness check.
	 *
	 * @param array<string, ?string> $row
	 */
	public function replace_row( string $table, int $id, array $row ): ?string {
		$conflict = $this->conflict( $table, $row, $id );
		if ( null !== $conflict ) {
			return $conflict;
		}
		$this->tables[ $table ]['rows'][ $id ] = $row;
		return null;
	}

	/** @param array<string, ?string>|null $row Null removes the row. */
	public function restore_row( string $table, int $id, ?array $row ): void {
		if ( null === $row ) {
			unset( $this->tables[ $table ]['rows'][ $id ] );
			return;
		}
		$this->tables[ $table ]['rows'][ $id ] = $row;
		ksort( $this->tables[ $table ]['rows'] );
	}

	public function delete_row( string $table, int $id ): void {
		unset( $this->tables[ $table ]['rows'][ $id ] );
	}

	/** @return array{columns: array<string, array{type: string, nullable: bool, default: ?string, auto: bool}>, primary: list<string>, unique: array<string, list<string>>, indexes: array<string, list<string>>, rows: array<int, array<string, ?string>>, auto: int} */
	public function table( string $table ): array {
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new FakeTableMissing( "Table '{$table}' doesn't exist" );
		}
		return $this->tables[ $table ];
	}

	/** @return list<string> */
	private function create_or_extend( string $table, string $body ): array {
		$columns = [];
		$primary = [];
		$unique = [];
		$indexes = [];
		foreach ( preg_split( '/\R/', $body ) ?: [] as $line ) {
			$line = rtrim( trim( $line ), ',' );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^PRIMARY KEY\s+\(([^)]+)\)$/i', $line, $match ) ) {
				$primary = self::column_list( $match[1] );
			} elseif ( preg_match( '/^UNIQUE KEY\s+([A-Za-z0-9_]+)\s*\(([^)]+)\)$/i', $line, $match ) ) {
				$unique[ $match[1] ] = self::column_list( $match[2] );
			} elseif ( preg_match( '/^KEY\s+([A-Za-z0-9_]+)\s*\(([^)]+)\)$/i', $line, $match ) ) {
				$indexes[ $match[1] ] = self::column_list( $match[2] );
			} elseif ( preg_match( '/^([a-z_][a-z0-9_]*)\s+(.+)$/', $line, $match ) ) {
				$definition = $match[2];
				$default = null;
				if ( preg_match( "/DEFAULT '([^']*)'/i", $definition, $value ) ) {
					$default = $value[1];
				} elseif ( preg_match( '/DEFAULT ([^\s,]+)/i', $definition, $value ) && 'NULL' !== strtoupper( $value[1] ) ) {
					$default = $value[1];
				}
				$columns[ $match[1] ] = [
					'type'     => strtolower( (string) preg_replace( '/\s+(NOT NULL|NULL|DEFAULT.*|AUTO_INCREMENT).*$/i', '', $definition ) ),
					'nullable' => ! preg_match( '/NOT NULL/i', $definition ),
					'default'  => $default,
					'auto'     => (bool) preg_match( '/AUTO_INCREMENT/i', $definition ),
				];
			} else {
				throw new \LogicException( 'Unsupported DDL line: ' . $line );
			}
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = [ 'columns' => $columns, 'primary' => $primary, 'unique' => $unique, 'indexes' => $indexes, 'rows' => [], 'auto' => 0 ];
			return [ "Created table {$table}" ];
		}
		$changes = [];
		foreach ( $columns as $name => $column ) {
			if ( ! isset( $this->tables[ $table ]['columns'][ $name ] ) ) {
				$this->tables[ $table ]['columns'][ $name ] = $column;
				foreach ( $this->tables[ $table ]['rows'] as $id => $row ) {
					$this->tables[ $table ]['rows'][ $id ][ $name ] = $column['default'];
				}
				$changes[] = "Added column {$table}.{$name}";
			}
		}
		foreach ( [ 'unique' => $unique, 'indexes' => $indexes ] as $kind => $keys ) {
			foreach ( $keys as $name => $list ) {
				if ( ! isset( $this->tables[ $table ][ $kind ][ $name ] ) ) {
					$this->tables[ $table ][ $kind ][ $name ] = $list;
					$changes[] = "Added index {$table} {$name}";
				}
			}
		}
		return $changes;
	}

	/** @param array<string, ?string> $row */
	private function conflict( string $table, array $row, ?int $ignore ): ?string {
		$definition = $this->tables[ $table ];
		$keys = $definition['unique'];
		if ( [] !== $definition['primary'] ) {
			$keys['PRIMARY'] = $definition['primary'];
		}
		foreach ( $keys as $name => $list ) {
			foreach ( $definition['rows'] as $id => $existing ) {
				if ( $id === $ignore ) {
					continue;
				}
				$same = true;
				foreach ( $list as $column ) {
					if ( null === $row[ $column ] || $row[ $column ] !== $existing[ $column ] ) {
						$same = false;
						break;
					}
				}
				if ( $same ) {
					return "Duplicate entry for key '{$name}'";
				}
			}
		}
		return null;
	}

	/** @return list<string> */
	private static function column_list( string $list ): array {
		return array_map( static fn ( string $column ): string => trim( $column, " `\t" ), explode( ',', $list ) );
	}

	private static function scalar( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (string) (int) $value;
		}
		return (string) $value;
	}
}
