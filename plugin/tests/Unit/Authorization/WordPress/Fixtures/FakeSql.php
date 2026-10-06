<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

/**
 * Parser and evaluator for the small SQL dialect the authorization storage adapters
 * emit: single-table SELECT, UPDATE, DELETE and INSERT with AND/OR/NOT, comparisons,
 * IS [NOT] NULL and [NOT] IN, plus ORDER BY, LIMIT and transaction statements.
 * Anything outside the dialect throws, so an adapter cannot silently rely on an
 * unsupported construct.
 */
final class FakeSql {

	/** @var list<array{0: string, 1: ?string}> */
	private array $tokens;

	private int $position = 0;

	private function __construct( string $sql ) {
		$this->tokens = self::tokenize( $sql );
	}

	/** @return array<string, mixed> */
	public static function parse( string $sql ): array {
		$parser = new self( $sql );
		$statement = $parser->statement();
		$parser->accept_symbol( ';' );
		if ( $parser->position !== count( $parser->tokens ) ) {
			throw new \LogicException( 'Unsupported SQL tail: ' . $sql );
		}
		return $statement;
	}

	/** @param array<string, ?string> $row */
	public static function matches( ?array $expression, array $row ): bool {
		if ( null === $expression ) {
			return true;
		}
		switch ( $expression['op'] ) {
			case 'and':
				foreach ( $expression['items'] as $item ) {
					if ( ! self::matches( $item, $row ) ) {
						return false;
					}
				}
				return true;
			case 'or':
				foreach ( $expression['items'] as $item ) {
					if ( self::matches( $item, $row ) ) {
						return true;
					}
				}
				return false;
			case 'not':
				return ! self::matches( $expression['item'], $row );
			case 'null':
				$is_null = null === self::value( $expression['operand'], $row );
				return $expression['negated'] ? ! $is_null : $is_null;
			case 'in':
				$value = self::value( $expression['operand'], $row );
				if ( null === $value ) {
					return false;
				}
				$found = false;
				foreach ( $expression['list'] as $candidate ) {
					if ( self::compare( $value, self::value( $candidate, $row ), '=' ) ) {
						$found = true;
						break;
					}
				}
				return $expression['negated'] ? ! $found : $found;
			case 'cmp':
				return self::compare( self::value( $expression['left'], $row ), self::value( $expression['right'], $row ), $expression['cmp'] );
		}
		throw new \LogicException( 'Unknown expression.' );
	}

	/** @param array<string, ?string> $row */
	public static function value( array $operand, array $row ): ?string {
		if ( 'lit' === $operand[0] ) {
			return $operand[1];
		}
		if ( ! array_key_exists( $operand[1], $row ) ) {
			throw new \LogicException( 'Unknown column ' . $operand[1] );
		}
		return $row[ $operand[1] ];
	}

	public static function compare( ?string $left, ?string $right, string $operator ): bool {
		if ( null === $left || null === $right ) {
			return false;
		}
		$order = is_numeric( $left ) && is_numeric( $right ) ? ( (float) $left <=> (float) $right ) : strcmp( $left, $right ) <=> 0;
		return match ( $operator ) {
			'=' => 0 === $order,
			'<>', '!=' => 0 !== $order,
			'<' => $order < 0,
			'<=' => $order <= 0,
			'>' => $order > 0,
			'>=' => $order >= 0,
		};
	}

	/** @return array<string, mixed> */
	private function statement(): array {
		$word = $this->word();
		switch ( $word ) {
			case 'SELECT':
				return $this->select();
			case 'UPDATE':
				return $this->update();
			case 'DELETE':
				$this->expect_word( 'FROM' );
				$table = $this->identifier();
				$where = $this->accept_word( 'WHERE' ) ? $this->expression() : null;
				return [ 'type' => 'delete', 'table' => $table, 'where' => $where, 'limit' => $this->limit()[0] ];
			case 'INSERT':
				return $this->insert();
			case 'START':
				$this->expect_word( 'TRANSACTION' );
				return [ 'type' => 'begin' ];
			case 'BEGIN':
				return [ 'type' => 'begin' ];
			case 'COMMIT':
				return [ 'type' => 'commit' ];
			case 'ROLLBACK':
				return [ 'type' => 'rollback' ];
			case 'SHOW':
				$what = $this->word();
				if ( 'TABLES' === $what ) {
					$this->expect_word( 'LIKE' );
					return [ 'type' => 'show_tables', 'like' => $this->literal() ];
				}
				if ( in_array( $what, [ 'COLUMNS', 'INDEX' ], true ) ) {
					$this->expect_word( 'FROM' );
					return [ 'type' => 'COLUMNS' === $what ? 'show_columns' : 'show_index', 'table' => $this->identifier() ];
				}
		}
		throw new \LogicException( 'Unsupported statement ' . $word );
	}

	/** @return array<string, mixed> */
	private function select(): array {
		$columns = [];
		do {
			if ( $this->accept_symbol( '*' ) ) {
				$columns[] = [ 'kind' => 'all' ];
				continue;
			}
			$name = $this->identifier();
			$upper = strtoupper( $name );
			if ( in_array( $upper, [ 'COUNT', 'MAX', 'MIN' ], true ) && $this->accept_symbol( '(' ) ) {
				$argument = $this->accept_symbol( '*' ) ? '*' : $this->identifier();
				$this->expect_symbol( ')' );
				$alias = $this->accept_word( 'AS' ) ? $this->identifier() : strtoupper( $upper ) . '(' . $argument . ')';
				$columns[] = [ 'kind' => strtolower( $upper ), 'name' => $argument, 'alias' => $alias ];
				continue;
			}
			$columns[] = [ 'kind' => 'column', 'name' => $name, 'alias' => $this->accept_word( 'AS' ) ? $this->identifier() : $name ];
		} while ( $this->accept_symbol( ',' ) );
		$this->expect_word( 'FROM' );
		$table = $this->identifier();
		$where = $this->accept_word( 'WHERE' ) ? $this->expression() : null;
		$order = [];
		if ( $this->accept_word( 'ORDER' ) ) {
			$this->expect_word( 'BY' );
			do {
				$column = $this->identifier();
				$direction = $this->accept_word( 'DESC' ) ? 'DESC' : ( $this->accept_word( 'ASC' ) ? 'ASC' : 'ASC' );
				$order[] = [ $column, $direction ];
			} while ( $this->accept_symbol( ',' ) );
		}
		[ $limit, $offset ] = $this->limit();
		if ( $this->accept_word( 'FOR' ) ) {
			$this->expect_word( 'UPDATE' );
		}
		return [ 'type' => 'select', 'table' => $table, 'columns' => $columns, 'where' => $where, 'order' => $order, 'limit' => $limit, 'offset' => $offset ];
	}

	/** @return array<string, mixed> */
	private function update(): array {
		$table = $this->identifier();
		$this->expect_word( 'SET' );
		$set = [];
		do {
			$column = $this->identifier();
			$this->expect_symbol( '=' );
			$value = $this->operand();
			if ( 'col' === $value[0] && ( $this->accept_symbol( '+' ) || $this->accept_symbol( '-' ) ) ) {
				$operator = $this->tokens[ $this->position - 1 ][1];
				$value = [ 'arith', $value[1], $operator, $this->literal() ];
			}
			$set[] = [ $column, $value ];
		} while ( $this->accept_symbol( ',' ) );
		$where = $this->accept_word( 'WHERE' ) ? $this->expression() : null;
		return [ 'type' => 'update', 'table' => $table, 'set' => $set, 'where' => $where, 'limit' => $this->limit()[0] ];
	}

	/** @return array<string, mixed> */
	private function insert(): array {
		$ignore = $this->accept_word( 'IGNORE' );
		$this->expect_word( 'INTO' );
		$table = $this->identifier();
		$this->expect_symbol( '(' );
		$columns = [];
		do {
			$columns[] = $this->identifier();
		} while ( $this->accept_symbol( ',' ) );
		$this->expect_symbol( ')' );
		$this->expect_word( 'VALUES' );
		$this->expect_symbol( '(' );
		$values = [];
		do {
			$values[] = $this->literal();
		} while ( $this->accept_symbol( ',' ) );
		$this->expect_symbol( ')' );
		return [ 'type' => 'insert', 'table' => $table, 'ignore' => $ignore, 'columns' => $columns, 'values' => $values ];
	}

	/** @return array{0: ?int, 1: int} */
	private function limit(): array {
		if ( ! $this->accept_word( 'LIMIT' ) ) {
			return [ null, 0 ];
		}
		$first = (int) $this->literal();
		if ( $this->accept_symbol( ',' ) ) {
			return [ (int) $this->literal(), $first ];
		}
		return [ $first, 0 ];
	}

	/** @return array<string, mixed> */
	private function expression(): array {
		$items = [ $this->conjunction() ];
		while ( $this->accept_word( 'OR' ) ) {
			$items[] = $this->conjunction();
		}
		return 1 === count( $items ) ? $items[0] : [ 'op' => 'or', 'items' => $items ];
	}

	/** @return array<string, mixed> */
	private function conjunction(): array {
		$items = [ $this->negation() ];
		while ( $this->accept_word( 'AND' ) ) {
			$items[] = $this->negation();
		}
		return 1 === count( $items ) ? $items[0] : [ 'op' => 'and', 'items' => $items ];
	}

	/** @return array<string, mixed> */
	private function negation(): array {
		if ( $this->accept_word( 'NOT' ) ) {
			return [ 'op' => 'not', 'item' => $this->negation() ];
		}
		return $this->predicate();
	}

	/** @return array<string, mixed> */
	private function predicate(): array {
		if ( $this->accept_symbol( '(' ) ) {
			$inner = $this->expression();
			$this->expect_symbol( ')' );
			return $inner;
		}
		$left = $this->operand();
		if ( $this->accept_word( 'IS' ) ) {
			$negated = $this->accept_word( 'NOT' );
			$this->expect_word( 'NULL' );
			return [ 'op' => 'null', 'operand' => $left, 'negated' => $negated ];
		}
		$negated = $this->accept_word( 'NOT' );
		if ( $this->accept_word( 'IN' ) ) {
			$this->expect_symbol( '(' );
			$list = [];
			do {
				$list[] = $this->operand();
			} while ( $this->accept_symbol( ',' ) );
			$this->expect_symbol( ')' );
			return [ 'op' => 'in', 'operand' => $left, 'list' => $list, 'negated' => $negated ];
		}
		if ( $negated ) {
			throw new \LogicException( 'Unsupported NOT predicate.' );
		}
		foreach ( [ '<=', '>=', '<>', '!=', '=', '<', '>' ] as $operator ) {
			if ( $this->accept_symbol( $operator ) ) {
				return [ 'op' => 'cmp', 'cmp' => $operator, 'left' => $left, 'right' => $this->operand() ];
			}
		}
		throw new \LogicException( 'Unsupported predicate.' );
	}

	/** @return array{0: string, 1: ?string} */
	private function operand(): array {
		$token = $this->tokens[ $this->position ] ?? null;
		if ( null === $token ) {
			throw new \LogicException( 'Unexpected end of SQL.' );
		}
		if ( 'string' === $token[0] || 'number' === $token[0] ) {
			++$this->position;
			return [ 'lit', $token[1] ];
		}
		if ( 'word' === $token[0] && 'NULL' === strtoupper( (string) $token[1] ) ) {
			++$this->position;
			return [ 'lit', null ];
		}
		return [ 'col', $this->identifier() ];
	}

	private function literal(): ?string {
		$operand = $this->operand();
		if ( 'lit' !== $operand[0] ) {
			throw new \LogicException( 'Expected a literal.' );
		}
		return $operand[1];
	}

	private function identifier(): string {
		$token = $this->tokens[ $this->position ] ?? null;
		if ( null === $token || 'word' !== $token[0] ) {
			throw new \LogicException( 'Expected an identifier.' );
		}
		++$this->position;
		return (string) $token[1];
	}

	private function word(): string {
		return strtoupper( $this->identifier() );
	}

	private function accept_word( string $word ): bool {
		$token = $this->tokens[ $this->position ] ?? null;
		if ( null !== $token && 'word' === $token[0] && strtoupper( (string) $token[1] ) === $word ) {
			++$this->position;
			return true;
		}
		return false;
	}

	private function expect_word( string $word ): void {
		if ( ! $this->accept_word( $word ) ) {
			throw new \LogicException( 'Expected ' . $word );
		}
	}

	private function accept_symbol( string $symbol ): bool {
		$token = $this->tokens[ $this->position ] ?? null;
		if ( null !== $token && 'symbol' === $token[0] && $token[1] === $symbol ) {
			++$this->position;
			return true;
		}
		return false;
	}

	private function expect_symbol( string $symbol ): void {
		if ( ! $this->accept_symbol( $symbol ) ) {
			throw new \LogicException( 'Expected ' . $symbol );
		}
	}

	/** @return list<array{0: string, 1: ?string}> */
	private static function tokenize( string $sql ): array {
		$tokens = [];
		$length = strlen( $sql );
		$index = 0;
		while ( $index < $length ) {
			$char = $sql[ $index ];
			if ( ctype_space( $char ) ) {
				++$index;
				continue;
			}
			if ( "'" === $char ) {
				$value = '';
				++$index;
				while ( true ) {
					if ( $index >= $length ) {
						throw new \LogicException( 'Unterminated string literal.' );
					}
					$next = $sql[ $index ];
					if ( '\\' === $next && $index + 1 < $length ) {
						$value .= $sql[ $index + 1 ];
						$index += 2;
						continue;
					}
					if ( "'" === $next ) {
						if ( $index + 1 < $length && "'" === $sql[ $index + 1 ] ) {
							$value .= "'";
							$index += 2;
							continue;
						}
						++$index;
						break;
					}
					$value .= $next;
					++$index;
				}
				$tokens[] = [ 'string', $value ];
				continue;
			}
			if ( '`' === $char ) {
				$end = strpos( $sql, '`', $index + 1 );
				if ( false === $end ) {
					throw new \LogicException( 'Unterminated identifier.' );
				}
				$tokens[] = [ 'word', substr( $sql, $index + 1, $end - $index - 1 ) ];
				$index = $end + 1;
				continue;
			}
			if ( preg_match( '/\G[0-9]+/', $sql, $match, 0, $index ) ) {
				$tokens[] = [ 'number', $match[0] ];
				$index += strlen( $match[0] );
				continue;
			}
			if ( preg_match( '/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $match, 0, $index ) ) {
				$tokens[] = [ 'word', $match[0] ];
				$index += strlen( $match[0] );
				continue;
			}
			$pair = substr( $sql, $index, 2 );
			if ( in_array( $pair, [ '<=', '>=', '<>', '!=' ], true ) ) {
				$tokens[] = [ 'symbol', $pair ];
				$index += 2;
				continue;
			}
			if ( str_contains( '=<>(),*+-;', $char ) ) {
				$tokens[] = [ 'symbol', $char ];
				++$index;
				continue;
			}
			throw new \LogicException( 'Unsupported SQL character ' . $char );
		}
		return $tokens;
	}
}
