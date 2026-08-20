<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Runtime interceptor for $wpdb writes issued from php-execute.
 *
 * Source-regex cannot see concatenated meta keys or aliased $wpdb handles.
 * This proxy inspects the resolved table and payload at call time.
 */
final class ProtectedWpdbWriteGuard {

	private const WRITE_METHODS = [ 'update', 'insert', 'replace', 'delete', 'query' ];

	private const CORE_TABLES = [ 'postmeta', 'posts', 'options', 'users', 'usermeta' ];

	/**
	 * Wrap the global $wpdb for the duration of a php-execute snippet.
	 *
	 * @return object The original $wpdb instance to restore in uninstall().
	 */
	public static function install( bool $read_only ): object {
		global $wpdb;
		$original = $wpdb;
		$wpdb     = new ProtectedWpdbProxy( $original, $read_only );
		return $original;
	}

	public static function uninstall( object $original ): void {
		$GLOBALS['wpdb'] = $original;
	}

	/**
	 * @param array<int, mixed> $arguments
	 */
	public static function assert_allowed( string $method, array $arguments, bool $read_only ): void {
		$method = strtolower( $method );
		if ( ! in_array( $method, self::WRITE_METHODS, true ) ) {
			return;
		}

		if ( 'query' === $method ) {
			$sql = isset( $arguments[0] ) && is_string( $arguments[0] ) ? $arguments[0] : '';
			if ( ! self::query_is_write( $sql ) ) {
				return;
			}
			self::assert_sql_allowed( $sql, $read_only );
			return;
		}

		$table = isset( $arguments[0] ) && is_string( $arguments[0] ) ? $arguments[0] : '';
		$data  = isset( $arguments[1] ) && is_array( $arguments[1] ) ? $arguments[1] : [];
		$where = isset( $arguments[2] ) && is_array( $arguments[2] ) ? $arguments[2] : [];

		if ( self::contains_protected_meta( $data, $where ) ) {
			throw ProtectedElementorWriteGuard::blocked_exception();
		}

		if ( $read_only ) {
			throw self::read_only_exception();
		}

		if ( self::is_core_table( $table ) ) {
			throw self::core_table_exception( $table, $method );
		}
	}

	private static function assert_sql_allowed( string $sql, bool $read_only ): void {
		if ( self::sql_mentions_protected_meta( $sql ) ) {
			throw ProtectedElementorWriteGuard::blocked_exception();
		}

		if ( $read_only ) {
			throw self::read_only_exception();
		}

		foreach ( self::query_tables( $sql ) as $table ) {
			if ( self::is_core_table( $table ) ) {
				throw self::core_table_exception( $table, 'query' );
			}
		}
	}

	public static function is_core_table( string $table ): bool {
		global $wpdb;
		$normalized = strtolower( str_replace( [ '`', '"' ], '', $table ) );
		$prefix     = strtolower( (string) ( $wpdb->prefix ?? 'wp_' ) );

		foreach ( self::CORE_TABLES as $core ) {
			$candidates = [
				$core,
				$prefix . $core,
			];
			if ( isset( $wpdb->{$core} ) && is_string( $wpdb->{$core} ) && '' !== $wpdb->{$core} ) {
				$candidates[] = strtolower( $wpdb->{$core} );
			}
			foreach ( $candidates as $candidate ) {
				if ( $normalized === $candidate || str_ends_with( $normalized, '_' . $core ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param array<string, mixed> ...$bags */
	private static function contains_protected_meta( array ...$bags ): bool {
		foreach ( $bags as $bag ) {
			foreach ( $bag as $key => $value ) {
				if ( 'meta_key' === $key && is_string( $value ) && ProtectedElementorWriteGuard::is_protected_meta_key( $value ) ) {
					return true;
				}
				if ( is_string( $value ) && ProtectedElementorWriteGuard::is_protected_meta_key( $value ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function sql_mentions_protected_meta( string $sql ): bool {
		foreach ( ProtectedElementorWriteGuard::protected_meta_keys() as $key ) {
			if ( str_contains( $sql, $key ) ) {
				return true;
			}
		}
		return false;
	}

	private static function query_is_write( string $sql ): bool {
		return 1 === preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|LOAD)\b/i', $sql );
	}

	/** @return list<string> */
	private static function query_tables( string $sql ): array {
		$count = preg_match_all( '/(?:INTO|UPDATE|FROM|TABLE)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches );
		if ( $count < 1 ) {
			return [];
		}
		return array_values( array_filter( $matches[1] ) );
	}

	private static function read_only_exception(): GuardedRuntimeWriteException {
		return new GuardedRuntimeWriteException(
			'stonewright_php_read_only_violation',
			__( 'php-execute was called with read_only:true but the code mutated WordPress state. Remove writes or set read_only:false.', 'stonewright' ),
			[
				'status'     => 400,
				'retryable'  => true,
				'error_code' => 'php_read_only_violation',
				'fix'        => [ 'remove_mutations', 'set_read_only_false' ],
			]
		);
	}

	private static function core_table_exception( string $table, string $method ): GuardedRuntimeWriteException {
		return new GuardedRuntimeWriteException(
			'stonewright_php_core_table_write_blocked',
			__( 'Direct $wpdb writes to WordPress core tables are blocked in php-execute. Use typed abilities or WordPress APIs that run backup, permission, and audit gates.', 'stonewright' ),
			[
				'status'     => 400,
				'retryable'  => false,
				'error_code' => 'php_core_table_write_blocked',
				'table'      => $table,
				'method'     => $method,
			]
		);
	}
}
