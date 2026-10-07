<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Forwards $wpdb access while intercepting write verbs for php-execute.
 *
 * The class extends wpdb only so strict third-party type checks keep working.
 * It never runs inherited behavior: the constructor does not call the parent,
 * every public wpdb method is declared here and forwarded to the real handle,
 * and every property read or write goes to that handle too. Whatever the real
 * handle is (the stock class or a driver subclass with its own escaping and
 * query code), the snippet sees exactly that implementation. Write verbs are
 * checked by ProtectedWpdbWriteGuard before the call is forwarded.
 */
#[\AllowDynamicProperties]
final class ProtectedWpdbProxy extends \wpdb {

	// phpcs:disable PSR2.Methods.MethodDeclaration.Underscore -- method names are the public wpdb API.

	private const PROXY_INTERNALS = [ 'inner', 'read_only' ];

	/** Helpers that build and run a write statement from a table and a payload. */
	private const ROW_WRITE_METHODS = [ 'insert', 'replace', 'update', 'delete', '_insert_replace_helper' ];

	/** Helpers that run the SQL passed as their first argument. */
	private const SQL_METHODS = [ 'query', 'get_var', 'get_row', 'get_col', 'get_results' ];

	public function __construct(
		private \wpdb $inner,
		private bool $read_only
	) {
		// Declared properties would shadow the magic accessors below; removing
		// them sends every read and write to the real handle.
		foreach ( ( new \ReflectionClass( \wpdb::class ) )->getProperties() as $property ) {
			if ( $property->isStatic() || $property->isPrivate() || $this->is_proxy_internal( $property->getName() ) ) {
				continue;
			}
			unset( $this->{$property->getName()} );
		}
	}

	// -------------------------------------------------------------------------
	// Property access
	// -------------------------------------------------------------------------

	/**
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function &__get( $name ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			$none = null;
			return $none;
		}

		// A reference keeps `$wpdb->queries[] = ...` working on the real handle.
		if ( $this->inner_has_public( $name ) ) {
			return $this->inner->{$name};
		}

		// Everything else goes through the real handle's own accessors, which
		// decide what a caller outside the class may see.
		$value = method_exists( $this->inner, '__get' ) ? $this->inner->__get( $name ) : null;
		return $value;
	}

	public function __set( $name, $value ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			return;
		}
		if ( $this->inner_has_public( $name ) || ! method_exists( $this->inner, '__set' ) ) {
			$this->inner->{$name} = $value;
			return;
		}
		$this->inner->__set( $name, $value );
	}

	public function __isset( $name ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			return false;
		}
		if ( $this->inner_has_public( $name ) || ! method_exists( $this->inner, '__isset' ) ) {
			return isset( $this->inner->{$name} );
		}
		return (bool) $this->inner->__isset( $name );
	}

	public function __unset( $name ) {
		$name = (string) $name;
		if ( $this->is_proxy_internal( $name ) ) {
			return;
		}
		if ( $this->inner_has_public( $name ) || ! method_exists( $this->inner, '__unset' ) ) {
			unset( $this->inner->{$name} );
			return;
		}
		$this->inner->__unset( $name );
	}

	/**
	 * Forward methods that exist on the real handle but are not part of the
	 * stock class (driver additions). Only public methods are reachable.
	 *
	 * @param array<int, mixed> $arguments
	 * @throws \Error When the method is not public on the live handle.
	 */
	public function __call( string $name, array $arguments ): mixed {
		if ( method_exists( $this->inner, $name ) && ! ( new \ReflectionMethod( $this->inner, $name ) )->isPublic() ) {
			throw new \Error( sprintf( 'Call to non-public method %s::%s() from global scope', get_class( $this->inner ), $name ) );
		}
		return $this->forward( $name, $arguments );
	}

	// -------------------------------------------------------------------------
	// Statements: the write policy runs before the real handle sees them.
	// -------------------------------------------------------------------------

	public function query( $query ) {
		return $this->forward( 'query', [ $query ] );
	}

	public function get_var( ...$arguments ) {
		return $this->forward( 'get_var', $arguments );
	}

	/**
	 * @param mixed ...$arguments Query, output type and row offset, as wpdb::get_row().
	 * @return mixed
	 */
	public function get_row( ...$arguments ) {
		return $this->forward( 'get_row', $arguments );
	}

	public function get_col( ...$arguments ) {
		return $this->forward( 'get_col', $arguments );
	}

	/**
	 * @param mixed ...$arguments Query and output type, as wpdb::get_results().
	 * @return mixed
	 */
	public function get_results( ...$arguments ) {
		return $this->forward( 'get_results', $arguments );
	}

	public function insert( ...$arguments ) {
		return $this->forward( 'insert', $arguments );
	}

	public function replace( ...$arguments ) {
		return $this->forward( 'replace', $arguments );
	}

	public function update( ...$arguments ) {
		return $this->forward( 'update', $arguments );
	}

	public function delete( ...$arguments ) {
		return $this->forward( 'delete', $arguments );
	}

	public function _insert_replace_helper( ...$arguments ) {
		return $this->forward( '_insert_replace_helper', $arguments );
	}

	// -------------------------------------------------------------------------
	// Plain forwards
	// -------------------------------------------------------------------------

	public function init_charset( ...$arguments ) {
		return $this->forward( 'init_charset', $arguments );
	}

	public function determine_charset( ...$arguments ) {
		return $this->forward( 'determine_charset', $arguments );
	}

	public function set_charset( ...$arguments ) {
		return $this->forward( 'set_charset', $arguments );
	}

	public function set_sql_mode( ...$arguments ) {
		$this->forward( 'set_sql_mode', $arguments );
	}

	public function set_prefix( ...$arguments ) {
		return $this->forward( 'set_prefix', $arguments );
	}

	public function set_blog_id( ...$arguments ) {
		return $this->forward( 'set_blog_id', $arguments );
	}

	public function get_blog_prefix( ...$arguments ) {
		return $this->forward( 'get_blog_prefix', $arguments );
	}

	public function tables( ...$arguments ) {
		return $this->forward( 'tables', $arguments );
	}

	public function select( ...$arguments ) {
		return $this->forward( 'select', $arguments );
	}

	public function _weak_escape( ...$arguments ) {
		return $this->forward( '_weak_escape', $arguments );
	}

	public function _real_escape( ...$arguments ) {
		return $this->forward( '_real_escape', $arguments );
	}

	public function _escape( ...$arguments ) {
		return $this->forward( '_escape', $arguments );
	}

	public function escape( ...$arguments ) {
		return $this->forward( 'escape', $arguments );
	}

	/**
	 * By reference, so the caller's variable is the one that gets escaped.
	 *
	 * @param mixed $data Value to escape in place.
	 */
	public function escape_by_ref( &$data ) {
		return $this->inner->escape_by_ref( $data );
	}

	public function quote_identifier( ...$arguments ) {
		return $this->forward( 'quote_identifier', $arguments );
	}

	public function prepare( $query, ...$args ) {
		return $this->forward( 'prepare', array_merge( [ $query ], $args ) );
	}

	public function esc_like( ...$arguments ) {
		return $this->forward( 'esc_like', $arguments );
	}

	public function print_error( ...$arguments ) {
		return $this->forward( 'print_error', $arguments );
	}

	public function show_errors( ...$arguments ) {
		return $this->forward( 'show_errors', $arguments );
	}

	public function hide_errors( ...$arguments ) {
		return $this->forward( 'hide_errors', $arguments );
	}

	public function suppress_errors( ...$arguments ) {
		return $this->forward( 'suppress_errors', $arguments );
	}

	public function flush( ...$arguments ) {
		$this->forward( 'flush', $arguments );
	}

	public function db_connect( ...$arguments ) {
		return $this->forward( 'db_connect', $arguments );
	}

	public function parse_db_host( ...$arguments ) {
		return $this->forward( 'parse_db_host', $arguments );
	}

	public function check_connection( ...$arguments ) {
		return $this->forward( 'check_connection', $arguments );
	}

	public function log_query( ...$arguments ) {
		return $this->forward( 'log_query', $arguments );
	}

	public function placeholder_escape( ...$arguments ) {
		return $this->forward( 'placeholder_escape', $arguments );
	}

	public function add_placeholder_escape( ...$arguments ) {
		return $this->forward( 'add_placeholder_escape', $arguments );
	}

	public function remove_placeholder_escape( ...$arguments ) {
		return $this->forward( 'remove_placeholder_escape', $arguments );
	}

	public function get_col_charset( ...$arguments ) {
		return $this->forward( 'get_col_charset', $arguments );
	}

	public function get_col_length( ...$arguments ) {
		return $this->forward( 'get_col_length', $arguments );
	}

	public function strip_invalid_text_for_column( ...$arguments ) {
		return $this->forward( 'strip_invalid_text_for_column', $arguments );
	}

	public function get_col_info( ...$arguments ) {
		return $this->forward( 'get_col_info', $arguments );
	}

	public function timer_start( ...$arguments ) {
		return $this->forward( 'timer_start', $arguments );
	}

	public function timer_stop( ...$arguments ) {
		return $this->forward( 'timer_stop', $arguments );
	}

	public function bail( ...$arguments ) {
		return $this->forward( 'bail', $arguments );
	}

	public function close( ...$arguments ) {
		return $this->forward( 'close', $arguments );
	}

	public function check_database_version( ...$arguments ) {
		return $this->forward( 'check_database_version', $arguments );
	}

	public function supports_collation( ...$arguments ) {
		return $this->forward( 'supports_collation', $arguments );
	}

	public function get_charset_collate( ...$arguments ) {
		return $this->forward( 'get_charset_collate', $arguments );
	}

	public function has_cap( ...$arguments ) {
		return $this->forward( 'has_cap', $arguments );
	}

	public function get_caller( ...$arguments ) {
		return $this->forward( 'get_caller', $arguments );
	}

	public function db_version( ...$arguments ) {
		return $this->forward( 'db_version', $arguments );
	}

	public function db_server_info( ...$arguments ) {
		return $this->forward( 'db_server_info', $arguments );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Runs the write policy for the call, then hands it to the real handle.
	 *
	 * @param array<int, mixed> $arguments
	 */
	private function forward( string $method, array $arguments ): mixed {
		$this->assert_allowed( $method, $arguments );
		return $this->inner->{$method}( ...$arguments );
	}

	/**
	 * @param array<int, mixed> $arguments
	 */
	private function assert_allowed( string $method, array $arguments ): void {
		$lower = strtolower( $method );

		if ( in_array( $lower, self::SQL_METHODS, true ) ) {
			// Any helper that runs SQL is checked as a statement, like a direct query().
			ProtectedWpdbWriteGuard::assert_allowed( 'query', [ $arguments[0] ?? null ], $this->read_only );
			return;
		}

		if ( ! in_array( $lower, self::ROW_WRITE_METHODS, true ) ) {
			// Driver additions named like a write verb keep the row check.
			ProtectedWpdbWriteGuard::assert_allowed( $method, $arguments, $this->read_only );
			return;
		}

		// A row helper is checked as its table and payload, and as the statement
		// the stock class would send, because the real handle runs it without
		// coming back through this proxy.
		if ( '_insert_replace_helper' === $lower ) {
			$type      = isset( $arguments[3] ) && is_string( $arguments[3] ) ? strtolower( $arguments[3] ) : 'insert';
			$lower     = 'replace' === $type ? 'replace' : 'insert';
			$arguments = array_slice( $arguments, 0, 2 );
		}
		ProtectedWpdbWriteGuard::assert_allowed( $lower, $arguments, $this->read_only );
		ProtectedWpdbWriteGuard::assert_allowed( 'query', [ self::statement_for( $lower, $arguments ) ], $this->read_only );
	}

	/**
	 * Statement text that carries the table and every key and value of a row
	 * write, so the SQL checks see what the stock class would send.
	 *
	 * @param array<int, mixed> $arguments
	 */
	private static function statement_for( string $method, array $arguments ): string {
		$table = isset( $arguments[0] ) && is_string( $arguments[0] ) ? $arguments[0] : '';

		$parts = [];
		foreach ( array_slice( $arguments, 1, 2 ) as $bag ) {
			if ( ! is_array( $bag ) ) {
				continue;
			}
			foreach ( $bag as $key => $value ) {
				$parts[] = (string) $key;
				if ( is_scalar( $value ) ) {
					$parts[] = (string) $value;
				}
			}
		}
		$payload = implode( ' ', $parts );

		return match ( $method ) {
			'update'  => 'UPDATE `' . $table . '` SET ' . $payload,
			'delete'  => 'DELETE FROM `' . $table . '` WHERE ' . $payload,
			'replace' => 'REPLACE INTO `' . $table . '` ' . $payload,
			default   => 'INSERT INTO `' . $table . '` ' . $payload,
		};
	}

	/**
	 * Whether the real handle holds this property as a public (or dynamic) one.
	 * Casting to an array lists public names plain and mangles the others.
	 */
	private function inner_has_public( string $name ): bool {
		return array_key_exists( $name, (array) $this->inner );
	}

	private function is_proxy_internal( string $name ): bool {
		return in_array( $name, self::PROXY_INTERNALS, true );
	}

	// phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
