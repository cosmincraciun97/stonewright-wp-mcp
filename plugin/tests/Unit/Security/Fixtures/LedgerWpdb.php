<?php
/**
 * In-memory stand-in for wpdb that understands the small SQL subset the change ledger uses.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Fixtures;

/**
 * Tables are lists of rows held in $tables. insert(), update(), delete(), prepare() and the
 * read methods work on them; a statement outside the subset fails the test instead of passing
 * silently. Like the real class, every value that a read returns is a string (or null).
 *
 * Supported reads: SELECT <*|COUNT(*)|SUM(col)|[DISTINCT] col, ...> FROM <table>
 * [WHERE cond AND cond ...] [ORDER BY col [ASC|DESC], ...] [LIMIT n [OFFSET m]], with the
 * conditions col = != <> < <= > >= LIKE IN NOT IN and IS [NOT] NULL. Supported writes:
 * DELETE FROM <table> [WHERE ...] through query(). A UNIQUE column set in $unique makes
 * insert() fail on a repeated value, as the database does.
 */
class LedgerWpdb extends \wpdb {

	/** @var array<string, list<array<string, mixed>>> */
	public array $tables = [];

	/** @var array<string, list<string>> Table name => columns that must stay unique. */
	public array $unique = [];

	/** @var list<string> */
	public array $statements = [];

	/** @var array<string, int> */
	private array $auto = [];

	public function __construct( string $prefix = 'wptests_' ) {
		$this->prefix   = $prefix;
		$this->options  = $prefix . 'options';
		$this->posts    = $prefix . 'posts';
		$this->postmeta = $prefix . 'postmeta';
		$this->users    = $prefix . 'users';
		$this->usermeta = $prefix . 'usermeta';
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string>   $format
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $format );
		$table = (string) $table;
		foreach ( $this->unique[ $table ] ?? [] as $column ) {
			foreach ( $this->tables[ $table ] ?? [] as $row ) {
				if ( isset( $data[ $column ] ) && (string) ( $row[ $column ] ?? '' ) === (string) $data[ $column ] ) {
					$this->last_error = "Duplicate entry '" . $data[ $column ] . "' for key '" . $column . "'";
					return false;
				}
			}
		}
		$this->auto[ $table ] = ( $this->auto[ $table ] ?? 0 ) + 1;
		$this->insert_id      = $this->auto[ $table ];
		$this->tables[ $table ][] = array_merge( [ 'id' => $this->insert_id ], $data );
		$this->last_error = '';
		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );
		$changed = 0;
		foreach ( $this->tables[ (string) $table ] ?? [] as $index => $row ) {
			if ( $this->matches_equal( $row, $where ) ) {
				$this->tables[ (string) $table ][ $index ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	/** @param array<string, mixed> $where */
	public function delete( $table, $where, $where_format = null ) {
		unset( $where_format );
		$kept = [];
		$gone = 0;
		foreach ( $this->tables[ (string) $table ] ?? [] as $row ) {
			if ( $this->matches_equal( $row, $where ) ) {
				++$gone;
			} else {
				$kept[] = $row;
			}
		}
		$this->tables[ (string) $table ] = $kept;
		return $gone;
	}

	/** @param mixed ...$args */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index    = 0;
		$prepared = preg_replace_callback(
			'/%([sdf%])/',
			static function ( array $placeholder ) use ( &$args, &$index ): string {
				if ( '%%' === $placeholder[0] ) {
					return '%';
				}
				$value = $args[ $index++ ] ?? null;
				return match ( $placeholder[1] ) {
					'd'     => (string) (int) $value,
					'f'     => (string) (float) $value,
					default => "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $value ) . "'",
				};
			},
			(string) $query
		);
		return (string) $prepared;
	}

	/** @return array<string, mixed>|null */
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		unset( $y );
		$rows = $this->select( (string) $query );
		return [] === $rows ? null : $rows[0];
	}

	/** @return list<array<string, mixed>> */
	public function get_results( $query = null, $output = 'OBJECT' ) {
		return $this->select( (string) $query );
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		unset( $x, $y );
		$rows = $this->select( (string) $query );
		if ( [] === $rows ) {
			return null;
		}
		return array_values( $rows[0] )[0] ?? null;
	}

	/** @return list<mixed> */
	public function get_col( $query = null, $x = 0 ) {
		unset( $x );
		$column = [];
		foreach ( $this->select( (string) $query ) as $row ) {
			$column[] = array_values( $row )[0] ?? null;
		}
		return $column;
	}

	public function query( $query ) {
		$sql                = $this->flatten( (string) $query );
		$this->statements[] = $sql;
		if ( 1 !== preg_match( '/^DELETE FROM (\S+)(?: WHERE (.+))?$/i', $sql, $match ) ) {
			throw new \LogicException( 'LedgerWpdb: unsupported statement: ' . $sql );
		}
		$table = $match[1];
		$kept  = [];
		$gone  = 0;
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( $this->matches( $row, $match[2] ?? '' ) ) {
				++$gone;
			} else {
				$kept[] = $row;
			}
		}
		$this->tables[ $table ] = $kept;
		return $gone;
	}

	/** @return list<array<string, mixed>> */
	private function select( string $query ): array {
		$sql                = $this->flatten( $query );
		$this->statements[] = $sql;
		if ( 1 !== preg_match( '/^SELECT (.+?) FROM (\S+)(?: WHERE (.+?))?(?: ORDER BY (.+?))?(?: LIMIT (\d+)(?: OFFSET (\d+))?)?$/i', $sql, $match ) ) {
			throw new \LogicException( 'LedgerWpdb: unsupported read: ' . $sql );
		}
		$rows = [];
		foreach ( $this->tables[ $match[2] ] ?? [] as $row ) {
			if ( $this->matches( $row, $match[3] ?? '' ) ) {
				$rows[] = $row;
			}
		}
		if ( '' !== ( $match[4] ?? '' ) ) {
			$orders = array_map( 'trim', explode( ',', $match[4] ) );
			usort(
				$rows,
				static function ( array $a, array $b ) use ( $orders ): int {
					foreach ( $orders as $order ) {
						$parts     = preg_split( '/\s+/', $order );
						$column    = (string) $parts[0];
						$direction = isset( $parts[1] ) && 'DESC' === strtoupper( $parts[1] ) ? -1 : 1;
						$compare   = self::compare( $a[ $column ] ?? null, $b[ $column ] ?? null );
						if ( 0 !== $compare ) {
							return $direction * $compare;
						}
					}
					return 0;
				}
			);
		}
		$select = trim( $match[1] );
		if ( 1 === preg_match( '/^COUNT\(\*\)$/i', $select ) ) {
			return [ [ 'COUNT(*)' => (string) count( $rows ) ] ];
		}
		if ( 1 === preg_match( '/^SUM\((\w+)\)$/i', $select, $sum ) ) {
			$total = 0;
			foreach ( $rows as $row ) {
				$total += (int) ( $row[ $sum[1] ] ?? 0 );
			}
			return [ [ $select => [] === $rows ? null : (string) $total ] ];
		}
		$offset = isset( $match[6] ) && '' !== $match[6] ? (int) $match[6] : 0;
		if ( isset( $match[5] ) && '' !== $match[5] ) {
			$rows = array_slice( $rows, $offset, (int) $match[5] );
		}
		$distinct = 1 === preg_match( '/^DISTINCT\s+(.+)$/i', $select, $found );
		$columns  = '*' === $select ? null : array_map( 'trim', explode( ',', $distinct ? $found[1] : $select ) );
		$out      = [];
		foreach ( $rows as $row ) {
			$picked = [];
			foreach ( $columns ?? array_keys( $row ) as $column ) {
				$value            = $row[ $column ] ?? null;
				$picked[ $column ] = null === $value ? null : ( is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value );
			}
			$out[ $distinct ? implode( "\0", $picked ) : count( $out ) ] = $picked;
		}
		return array_values( $out );
	}

	/** @param array<string, mixed> $row */
	private function matches( array $row, string $where ): bool {
		if ( '' === trim( $where ) ) {
			return true;
		}
		foreach ( $this->split_conditions( $where ) as $condition ) {
			if ( ! $this->condition_holds( $row, $condition ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, mixed> $row @param array<string, mixed> $where */
	private function matches_equal( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, mixed> $row */
	private function condition_holds( array $row, string $condition ): bool {
		if ( 1 === preg_match( '/^(\w+) IS (NOT )?NULL$/i', $condition, $match ) ) {
			$null = ! isset( $row[ $match[1] ] );
			return '' === $match[2] ? $null : ! $null;
		}
		if ( 1 !== preg_match( '/^(\w+)\s*(NOT IN|IN|>=|<=|<>|!=|=|<|>|LIKE)\s*(.+)$/is', $condition, $match ) ) {
			throw new \LogicException( 'LedgerWpdb: unsupported condition: ' . $condition );
		}
		$actual = $row[ $match[1] ] ?? null;
		$op     = strtoupper( $match[2] );
		if ( 'IN' === $op || 'NOT IN' === $op ) {
			$list = array_map( [ self::class, 'literal' ], self::split_list( trim( $match[3], '() ' ) ) );
			$in   = false;
			foreach ( $list as $item ) {
				if ( 0 === self::compare( $actual, $item ) ) {
					$in = true;
				}
			}
			return 'IN' === $op ? $in : ! $in;
		}
		$expected = self::literal( trim( $match[3] ) );
		if ( 'LIKE' === $op ) {
			$pattern = '';
			$text    = (string) $expected;
			for ( $i = 0, $n = strlen( $text ); $i < $n; $i++ ) {
				$char = $text[ $i ];
				if ( '\\' === $char && $i + 1 < $n ) {
					$pattern .= preg_quote( $text[ ++$i ], '/' );
				} elseif ( '%' === $char ) {
					$pattern .= '.*';
				} elseif ( '_' === $char ) {
					$pattern .= '.';
				} else {
					$pattern .= preg_quote( $char, '/' );
				}
			}
			return 1 === preg_match( '/^' . $pattern . '$/is', (string) $actual );
		}
		$compare = self::compare( $actual, $expected );
		return match ( $op ) {
			'='        => 0 === $compare,
			'!=', '<>' => 0 !== $compare,
			'<'        => $compare < 0,
			'<='       => $compare <= 0,
			'>'        => $compare > 0,
			default    => $compare >= 0,
		};
	}

	private static function compare( mixed $a, mixed $b ): int {
		if ( null === $a || null === $b ) {
			return null === $a && null === $b ? 0 : ( null === $a ? -1 : 1 );
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a <=> (float) $b;
		}
		return strcmp( (string) $a, (string) $b );
	}

	private static function literal( string $token ): string {
		$token = trim( $token );
		if ( strlen( $token ) >= 2 && "'" === $token[0] && "'" === substr( $token, -1 ) ) {
			return (string) preg_replace( '/\\\\(.)/s', '$1', substr( $token, 1, -1 ) );
		}
		return $token;
	}

	/** @return list<string> */
	private static function split_list( string $list ): array {
		return self::split_outside_quotes( $list, ',' );
	}

	/** @return list<string> */
	private function split_conditions( string $where ): array {
		return array_map( 'trim', self::split_outside_quotes( $where, ' AND ' ) );
	}

	/** @return list<string> */
	private static function split_outside_quotes( string $text, string $separator ): array {
		$parts   = [];
		$current = '';
		$quoted  = false;
		$length  = strlen( $text );
		$sep_len = strlen( $separator );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( $quoted && '\\' === $char && $i + 1 < $length ) {
				$current .= $char . $text[ ++$i ];
				continue;
			}
			if ( "'" === $char ) {
				$quoted = ! $quoted;
			}
			if ( ! $quoted && 0 === strcasecmp( substr( $text, $i, $sep_len ), $separator ) ) {
				$parts[] = $current;
				$current = '';
				$i      += $sep_len - 1;
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return $parts;
	}

	/** Collapses whitespace outside string literals. */
	private function flatten( string $sql ): string {
		$out    = '';
		$quoted = false;
		$space  = false;
		$length = strlen( $sql );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			if ( $quoted && '\\' === $char && $i + 1 < $length ) {
				$out .= $char . $sql[ ++$i ];
				continue;
			}
			if ( "'" === $char ) {
				$quoted = ! $quoted;
			}
			if ( ! $quoted && ctype_space( $char ) ) {
				$space = true;
				continue;
			}
			if ( $space && '' !== $out ) {
				$out .= ' ';
			}
			$space = false;
			$out  .= $char;
		}
		return trim( $out );
	}
}
