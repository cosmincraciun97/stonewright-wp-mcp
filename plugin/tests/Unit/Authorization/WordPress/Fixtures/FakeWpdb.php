<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

/**
 * One database connection over shared FakeTables. Several connections over the same
 * tables model independent PHP processes: each has its own transaction (an undo log
 * replayed on ROLLBACK), and a statement hook lets a test run another connection's
 * work at an exact point, for example just before a compare-and-swap. There is no
 * isolation between connections, so a test interleaves complete units of work.
 *
 * The options table is mirrored from the option stubs of the test bootstrap, so raw
 * SQL against it and get_option()/add_option() observe the same values.
 */
#[\AllowDynamicProperties]
final class FakeWpdb extends \wpdb {

	/** Called with (sql, connection) before every statement; re-entrant calls are skipped. */
	public ?\Closure $before = null;

	/** Returns true to make a statement fail as if the database refused it. */
	public ?\Closure $fail_when = null;

	/** @var list<string> */
	public array $statements = [];

	public int $commits = 0;

	public int $rollbacks = 0;

	/** @var list<array{0: string, 1: int, 2: ?array<string, ?string>}>|null Table, row id, previous row. */
	private ?array $undo = null;

	private bool $in_hook = false;

	public function __construct( public FakeTables $space, string $prefix = 'wptests_' ) {
		$this->prefix = $prefix;
		$this->options = $prefix . 'options';
		$this->users = $prefix . 'users';
		$this->usermeta = $prefix . 'usermeta';
		$this->posts = $prefix . 'posts';
		$this->postmeta = $prefix . 'postmeta';
		$this->ready = true;
	}

	public function in_transaction(): bool {
		return null !== $this->undo;
	}

	/** @param mixed ...$args */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$output = '';
		$index = 0;
		$length = strlen( (string) $query );
		for ( $position = 0; $position < $length; $position++ ) {
			$char = $query[ $position ];
			$next = $position + 1 < $length ? $query[ $position + 1 ] : '';
			if ( '%' === $char && '%' === $next ) {
				$output .= '%';
				++$position;
				continue;
			}
			if ( '%' !== $char || ! in_array( $next, [ 'd', 's', 'f' ], true ) ) {
				$output .= $char;
				continue;
			}
			if ( ! array_key_exists( $index, $args ) ) {
				throw new \LogicException( 'Missing prepare argument.' );
			}
			$value = $args[ $index++ ];
			$output .= match ( $next ) {
				'd' => (string) (int) $value,
				'f' => (string) (float) $value,
				default => self::quote( (string) $value ),
			};
			++$position;
		}
		if ( $index !== count( $args ) ) {
			throw new \LogicException( 'Unused prepare arguments.' );
		}
		return $output;
	}

	public function query( $query ) {
		$result = $this->run( (string) $query );
		if ( false === $result ) {
			return false;
		}
		return 'select' === $result['kind'] ? count( $result['rows'] ) : $result['affected'];
	}

	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		$result = $this->run( (string) $query );
		if ( false === $result || ! isset( $result['rows'][ $y ] ) ) {
			return null;
		}
		return ARRAY_A === $output ? $result['rows'][ $y ] : (object) $result['rows'][ $y ];
	}

	public function get_results( $query = null, $output = 'OBJECT' ) {
		$result = $this->run( (string) $query );
		if ( false === $result ) {
			return [];
		}
		return ARRAY_A === $output ? $result['rows'] : array_map( static fn ( array $row ): object => (object) $row, $result['rows'] );
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$result = $this->run( (string) $query );
		if ( false === $result || ! isset( $result['rows'][ $y ] ) ) {
			return null;
		}
		$values = array_values( $result['rows'][ $y ] );
		return $values[ $x ] ?? null;
	}

	public function get_col( $query = null, $x = 0 ) {
		$result = $this->run( (string) $query );
		if ( false === $result ) {
			return [];
		}
		return array_map( static fn ( array $row ) => array_values( $row )[ $x ] ?? null, $result['rows'] );
	}

	public function insert( $table, $data, $format = null ) {
		$columns = implode( ', ', array_keys( $data ) );
		$values = implode( ', ', array_map( static fn ( $value ): string => null === $value ? 'NULL' : self::quote( (string) ( is_bool( $value ) ? (int) $value : $value ) ), array_values( $data ) ) );
		$result = $this->run( "INSERT INTO {$table} ({$columns}) VALUES ({$values})" );
		return false === $result ? false : $result['affected'];
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set = [];
		foreach ( $data as $column => $value ) {
			$set[] = $column . ' = ' . ( null === $value ? 'NULL' : self::quote( (string) $value ) );
		}
		$result = $this->run( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . self::equality( $where ) );
		return false === $result ? false : $result['affected'];
	}

	public function delete( $table, $where, $where_format = null ) {
		$result = $this->run( "DELETE FROM {$table} WHERE " . self::equality( $where ) );
		return false === $result ? false : $result['affected'];
	}

	public function get_charset_collate() {
		return '';
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/** @return array{kind: string, rows: list<array<string, ?string>>, affected: int}|false */
	private function run( string $sql ): array|false {
		$this->statements[] = $sql;
		$this->last_query = $sql;
		$this->last_error = '';
		if ( null !== $this->before && ! $this->in_hook ) {
			$this->in_hook = true;
			try {
				( $this->before )( $sql, $this );
			} finally {
				$this->in_hook = false;
			}
		}
		if ( null !== $this->fail_when && ( $this->fail_when )( $sql ) ) {
			$this->last_error = 'Injected failure';
			return false;
		}
		$statement = FakeSql::parse( $sql );
		$options = isset( $statement['table'] ) && $statement['table'] === $this->options;
		if ( $options ) {
			if ( $this->in_transaction() ) {
				throw new \LogicException( 'Options statements are not expected inside a transaction.' );
			}
			$this->load_options();
		}
		try {
			$result = $this->execute( $statement );
		} catch ( FakeTableMissing $missing ) {
			$this->last_error = $missing->getMessage();
			return false;
		}
		if ( false === $result ) {
			return false;
		}
		if ( $options && 'select' !== $result['kind'] ) {
			$this->store_options();
		}
		$this->rows_affected = $result['affected'];
		$this->num_rows = count( $result['rows'] );
		return $result;
	}

	/**
	 * @param array<string, mixed> $statement
	 * @return array{kind: string, rows: list<array<string, ?string>>, affected: int}|false
	 */
	private function execute( array $statement ): array|false {
		$control = [ 'kind' => 'control', 'rows' => [], 'affected' => 0 ];
		switch ( $statement['type'] ) {
			case 'begin':
				if ( $this->in_transaction() ) {
					throw new \LogicException( 'A transaction is already open on this connection.' );
				}
				$this->undo = [];
				return $control;
			case 'commit':
				$this->undo = null;
				++$this->commits;
				return $control;
			case 'rollback':
				foreach ( array_reverse( $this->undo ?? [] ) as [ $table, $id, $previous ] ) {
					$this->space->restore_row( $table, $id, $previous );
				}
				$this->undo = null;
				++$this->rollbacks;
				return $control;
			case 'select':
				return [ 'kind' => 'select', 'rows' => $this->select( $statement ), 'affected' => 0 ];
			case 'update':
				return $this->update_rows( $statement );
			case 'delete':
				$affected = 0;
				foreach ( $this->space->indexed_rows( $statement['table'] ) as $id => $row ) {
					if ( null !== $statement['limit'] && $affected >= $statement['limit'] ) {
						break;
					}
					if ( FakeSql::matches( $statement['where'], $row ) ) {
						$this->remember( $statement['table'], $id, $row );
						$this->space->delete_row( $statement['table'], $id );
						++$affected;
					}
				}
				return [ 'kind' => 'write', 'rows' => [], 'affected' => $affected ];
			case 'insert':
				$inserted = $this->space->insert_row( $statement['table'], array_combine( $statement['columns'], $statement['values'] ) );
				if ( is_string( $inserted ) ) {
					if ( $statement['ignore'] && str_starts_with( $inserted, 'Duplicate' ) ) {
						return [ 'kind' => 'write', 'rows' => [], 'affected' => 0 ];
					}
					$this->last_error = $inserted;
					return false;
				}
				$this->remember( $statement['table'], $inserted[0], null );
				$this->insert_id = $inserted[1];
				return [ 'kind' => 'write', 'rows' => [], 'affected' => 1 ];
			case 'show_columns':
				$rows = [];
				$definition = $this->space->table( $statement['table'] );
				foreach ( $definition['columns'] as $name => $column ) {
					$rows[] = [ 'Field' => $name, 'Type' => $column['type'], 'Null' => $column['nullable'] ? 'YES' : 'NO', 'Key' => in_array( $name, $definition['primary'], true ) ? 'PRI' : '', 'Default' => $column['default'], 'Extra' => $column['auto'] ? 'auto_increment' : '' ];
				}
				return [ 'kind' => 'select', 'rows' => $rows, 'affected' => 0 ];
			case 'show_index':
				$rows = [];
				foreach ( $this->space->keys( $statement['table'] ) as $name => $columns ) {
					foreach ( $columns as $sequence => $column ) {
						$rows[] = [ 'Table' => $statement['table'], 'Non_unique' => 'PRIMARY' === $name || isset( $this->space->table( $statement['table'] )['unique'][ $name ] ) ? '0' : '1', 'Key_name' => $name, 'Seq_in_index' => (string) ( $sequence + 1 ), 'Column_name' => $column ];
					}
				}
				return [ 'kind' => 'select', 'rows' => $rows, 'affected' => 0 ];
			case 'show_tables':
				$pattern = '/^' . str_replace( '%', '.*', preg_quote( stripslashes( (string) $statement['like'] ), '/' ) ) . '$/';
				$rows = [];
				foreach ( array_keys( $this->space->tables ) as $name ) {
					if ( preg_match( $pattern, $name ) ) {
						$rows[] = [ 'Tables_in_test' => $name ];
					}
				}
				return [ 'kind' => 'select', 'rows' => $rows, 'affected' => 0 ];
		}
		throw new \LogicException( 'Unsupported statement type.' );
	}

	/**
	 * @param array<string, mixed> $statement
	 * @return list<array<string, ?string>>
	 */
	private function select( array $statement ): array {
		$rows = array_values( array_filter( $this->space->indexed_rows( $statement['table'] ), static fn ( array $row ): bool => FakeSql::matches( $statement['where'], $row ) ) );
		if ( [] !== $statement['order'] ) {
			usort(
				$rows,
				static function ( array $left, array $right ) use ( $statement ): int {
					foreach ( $statement['order'] as [ $column, $direction ] ) {
						$a = $left[ $column ];
						$b = $right[ $column ];
						$order = null === $a || null === $b ? ( null === $a ) <=> ( null === $b ) : ( is_numeric( $a ) && is_numeric( $b ) ? (float) $a <=> (float) $b : strcmp( $a, $b ) <=> 0 );
						if ( 0 !== $order ) {
							return 'DESC' === $direction ? -$order : $order;
						}
					}
					return 0;
				}
			);
		}
		$aggregate = null;
		foreach ( $statement['columns'] as $column ) {
			if ( in_array( $column['kind'], [ 'count', 'max', 'min' ], true ) ) {
				$aggregate = $column;
			}
		}
		if ( null !== $aggregate ) {
			if ( 'count' === $aggregate['kind'] ) {
				$value = (string) count( $rows );
			} else {
				$values = array_values( array_filter( array_column( $rows, $aggregate['name'] ), static fn ( $item ): bool => null !== $item ) );
				sort( $values );
				$value = [] === $values ? null : ( 'max' === $aggregate['kind'] ? end( $values ) : $values[0] );
			}
			return [ [ $aggregate['alias'] => $value ] ];
		}
		$rows = array_slice( $rows, $statement['offset'], $statement['limit'] );
		$projected = [];
		foreach ( $rows as $row ) {
			$out = [];
			foreach ( $statement['columns'] as $column ) {
				if ( 'all' === $column['kind'] ) {
					$out += $row;
					continue;
				}
				if ( ! array_key_exists( $column['name'], $row ) ) {
					throw new \LogicException( 'Unknown column ' . $column['name'] );
				}
				$out[ $column['alias'] ] = $row[ $column['name'] ];
			}
			$projected[] = $out;
		}
		return $projected;
	}

	/**
	 * @param array<string, mixed> $statement
	 * @return array{kind: string, rows: list<array<string, ?string>>, affected: int}|false
	 */
	private function update_rows( array $statement ): array|false {
		$affected = 0;
		foreach ( $this->space->indexed_rows( $statement['table'] ) as $id => $row ) {
			if ( null !== $statement['limit'] && $affected >= $statement['limit'] ) {
				break;
			}
			if ( ! FakeSql::matches( $statement['where'], $row ) ) {
				continue;
			}
			$next = $row;
			foreach ( $statement['set'] as [ $column, $value ] ) {
				if ( ! array_key_exists( $column, $row ) ) {
					throw new \LogicException( 'Unknown column ' . $column );
				}
				if ( 'arith' === $value[0] ) {
					$base = (int) $row[ $value[1] ];
					$next[ $column ] = (string) ( '+' === $value[2] ? $base + (int) $value[3] : $base - (int) $value[3] );
				} else {
					$next[ $column ] = FakeSql::value( $value, $row );
				}
			}
			if ( $next === $row ) {
				continue;
			}
			$conflict = $this->space->replace_row( $statement['table'], $id, $next );
			if ( null !== $conflict ) {
				$this->last_error = $conflict;
				return false;
			}
			$this->remember( $statement['table'], $id, $row );
			++$affected;
		}
		return [ 'kind' => 'write', 'rows' => [], 'affected' => $affected ];
	}

	/** @param array<string, ?string>|null $previous */
	private function remember( string $table, int $id, ?array $previous ): void {
		if ( null !== $this->undo ) {
			$this->undo[] = [ $table, $id, $previous ];
		}
	}

	private function load_options(): void {
		$table = $this->options;
		if ( ! $this->space->has( $table ) ) {
			$this->space->apply_ddl( "CREATE TABLE {$table} (\noption_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\noption_name varchar(191) NOT NULL DEFAULT '',\noption_value longtext NOT NULL,\nautoload varchar(20) NOT NULL DEFAULT 'yes',\nPRIMARY KEY  (option_id),\nUNIQUE KEY option_name (option_name)\n)" );
			array_pop( $this->space->ddl );
		}
		foreach ( array_keys( $this->space->indexed_rows( $table ) ) as $id ) {
			$this->space->delete_row( $table, $id );
		}
		foreach ( $GLOBALS['stonewright_test_options'] ?? [] as $name => $value ) {
			$this->space->insert_row(
				$table,
				[
					'option_name'  => (string) $name,
					'option_value' => (string) maybe_serialize( $value ),
					'autoload'     => $this->space->option_autoload[ (string) $name ] ?? 'auto',
				]
			);
		}
	}

	private function store_options(): void {
		$values = [];
		foreach ( $this->space->rows( $this->options ) as $row ) {
			$values[ (string) $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
			$this->space->option_autoload[ (string) $row['option_name'] ] = (string) $row['autoload'];
		}
		$GLOBALS['stonewright_test_options'] = $values;
	}

	/** @param array<string, mixed> $where */
	private static function equality( array $where ): string {
		$parts = [];
		foreach ( $where as $column => $value ) {
			$parts[] = null === $value ? "{$column} IS NULL" : $column . ' = ' . self::quote( (string) $value );
		}
		return implode( ' AND ', $parts );
	}

	private static function quote( string $value ): string {
		return "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value ) . "'";
	}
}
