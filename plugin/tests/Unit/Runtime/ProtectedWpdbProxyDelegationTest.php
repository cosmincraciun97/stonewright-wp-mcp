<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Security\GuardedRuntimeWriteException;
use Stonewright\WpMcp\Security\ProtectedWpdbProxy;
use Stonewright\WpMcp\Security\ProtectedWpdbWriteGuard;

/**
 * A wpdb double that behaves like the stock driver: it records every statement
 * it runs, and its escape helper needs a mysqli-style handle.
 */
#[\AllowDynamicProperties]
class DelegationPlainWpdb extends \wpdb {
	/** @var list<string> */
	public array $statements = [];

	public function __construct() {
		$this->prefix   = 'wp_';
		$this->posts    = 'wp_posts';
		$this->postmeta = 'wp_postmeta';
		$this->options  = 'wp_options';
		$this->users    = 'wp_users';
		$this->usermeta = 'wp_usermeta';
		$this->dbh      = new \ArrayObject();
	}

	public function _real_escape( $data ) {
		if ( ! is_string( $this->dbh ) ) {
			throw new \TypeError( 'mysqli_real_escape_string(): Argument #1 ($mysql) must be of type mysqli, ' . get_debug_type( $this->dbh ) . ' given' );
		}
		return parent::_real_escape( $data );
	}

	public function prepare( $query, ...$args ) {
		return (string) vsprintf( (string) $query, array_map( fn( $a ) => $this->_escape( (string) $a ), $args ) );
	}

	public function query( $query ) {
		$this->statements[] = (string) $query;
		$this->last_query   = (string) $query;
		$this->last_error   = '';
		return 1;
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->query( $query );
		return 'var';
	}

	public function get_results( $query = null, $output = 'OBJECT' ) {
		$this->query( $query );
		return [ (object) [ 'id' => 1 ] ];
	}

	public function insert( $table, $data, $format = null ) {
		return $this->query( 'INSERT INTO ' . $table . ' ' . wp_json_encode( $data ) );
	}

	public function _insert_replace_helper( $table, $data, $format = null, $type = 'INSERT' ) {
		return $this->query( $type . ' INTO ' . $table . ' ' . wp_json_encode( $data ) );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		return $this->query( 'UPDATE ' . $table . ' SET ' . wp_json_encode( $data ) . ' WHERE ' . wp_json_encode( $where ) );
	}

	public function delete( $table, $where, $where_format = null ) {
		return $this->query( 'DELETE FROM ' . $table . ' WHERE ' . wp_json_encode( $where ) );
	}
}

/**
 * Plain double on a handle the stock escape accepts.
 */
final class DelegationMysqlLikeWpdb extends DelegationPlainWpdb {
	public function __construct() {
		parent::__construct();
		$this->dbh = 'mysql-handle';
	}
}

/**
 * A driver subclass in the style of the SQLite integration: its own escape,
 * its own handle, an extra property and an extra method.
 */
final class DelegationSqliteLikeWpdb extends DelegationPlainWpdb {
	public string $engine = 'sqlite';

	public function _real_escape( $data ) {
		return '[sqlite]' . addslashes( (string) $data );
	}

	public function sqlite_engine_version(): string {
		return 'sqlite-3.0';
	}

	public function note_error( string $message ): void {
		$this->last_error = $message;
	}
}

/**
 * Strict type consumer used to prove the guarded handle remains a real wpdb.
 */
final class DelegationStrictConsumer {
	public function __construct( public \wpdb $database ) {}
}

/**
 * The php-execute write guard has to work for any wpdb implementation: every
 * method and property reaches the real handle, and only writes are refused.
 *
 * @covers \Stonewright\WpMcp\Security\ProtectedWpdbProxy
 * @covers \Stonewright\WpMcp\Security\ProtectedWpdbWriteGuard
 */
final class ProtectedWpdbProxyDelegationTest extends TestCase {

	private mixed $original_wpdb;

	private ?\wpdb $installed = null;

	protected function setUp(): void {
		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps']        = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_current_user_id']  = 17;
		$GLOBALS['stonewright_test_options']          = [
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
	}

	protected function tearDown(): void {
		if ( $this->installed instanceof \wpdb ) {
			ProtectedWpdbWriteGuard::uninstall( $this->installed );
			$this->installed = null;
		}
		$GLOBALS['wpdb']                       = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	/**
	 * @return array<string, array{0: class-string<DelegationPlainWpdb>, 1: string}>
	 */
	public static function drivers(): array {
		return [
			'plain wpdb double'      => [ DelegationMysqlLikeWpdb::class, 'a\\\'b' ],
			'driver subclass double' => [ DelegationSqliteLikeWpdb::class, '[sqlite]a\\\'b' ],
		];
	}

	/**
	 * @param class-string<DelegationPlainWpdb> $class
	 */
	private function install( string $class, bool $read_only ): ProtectedWpdbProxy {
		$GLOBALS['wpdb'] = new $class();
		$this->installed = ProtectedWpdbWriteGuard::install( $read_only );
		$proxy           = $GLOBALS['wpdb'];
		self::assertInstanceOf( ProtectedWpdbProxy::class, $proxy );
		return $proxy;
	}

	/**
	 * @dataProvider drivers
	 * @param class-string<DelegationPlainWpdb> $class
	 */
	public function test_escaping_runs_the_driver_of_the_real_handle( string $class, string $expected ): void {
		$proxy = $this->install( $class, true );

		self::assertSame( $expected, $proxy->_escape( "a'b" ) );
		self::assertSame( [ $expected ], $proxy->_escape( [ "a'b" ] ) );
		self::assertSame( $expected, $proxy->prepare( '%s', "a'b" ) );
	}

	public function test_php_execute_can_escape_and_read_on_a_driver_subclass(): void {
		$GLOBALS['wpdb'] = new DelegationSqliteLikeWpdb();

		$result = ( new PhpExecute() )->execute(
			[ 'code' => 'global $wpdb; return [ $wpdb->_escape( "a\'b" ), $wpdb->get_var( "SELECT 1" ) ];' ]
		);

		self::assertIsArray( $result );
		self::assertSame( [ '[sqlite]a\\\'b', 'var' ], $result['result'] ?? $result['primary'] ?? null );
		self::assertInstanceOf( DelegationSqliteLikeWpdb::class, $GLOBALS['wpdb'], 'The real handle is restored.' );
	}

	public function test_escape_by_ref_changes_the_caller_variable(): void {
		$proxy  = $this->install( DelegationSqliteLikeWpdb::class, true );
		$string = "a'b";

		$proxy->escape_by_ref( $string );

		self::assertSame( "[sqlite]a\\'b", $string );
	}

	public function test_state_is_read_from_and_written_to_the_real_handle(): void {
		$proxy = $this->install( DelegationSqliteLikeWpdb::class, true );
		/** @var DelegationSqliteLikeWpdb $inner */
		$inner = $this->installed;

		self::assertSame( 'wp_', $proxy->prefix );
		self::assertSame( 'sqlite', $proxy->engine );
		self::assertSame( 'wp_posts', $proxy->posts );

		// A change made on the real handle shows at once, without going through the proxy.
		$inner->note_error( 'driver said no' );
		self::assertSame( 'driver said no', $proxy->last_error );

		// A change made through the proxy lands on the real handle, declared or dynamic.
		$proxy->suppress_errors = true;
		$proxy->custom_flag     = 5;
		self::assertTrue( $inner->suppress_errors );
		self::assertSame( 5, $inner->custom_flag );
		self::assertTrue( isset( $proxy->custom_flag ) );
		unset( $proxy->custom_flag );
		self::assertFalse( isset( $inner->custom_flag ) );
		self::assertFalse( isset( $proxy->never_set ) );
		self::assertNull( $proxy->never_set );

		// Result state of a statement is visible as soon as it ran.
		$proxy->query( 'SELECT 1' );
		self::assertSame( 'SELECT 1', $proxy->last_query );
		self::assertSame( 'SELECT 1', $inner->last_query );
	}

	public function test_array_properties_can_be_extended_through_the_proxy(): void {
		$proxy = $this->install( DelegationSqliteLikeWpdb::class, true );
		/** @var DelegationSqliteLikeWpdb $inner */
		$inner         = $this->installed;
		$inner->queries = [ 'first' ];

		$proxy->queries[] = 'second';

		self::assertSame( [ 'first', 'second' ], $inner->queries );
	}

	public function test_methods_the_driver_adds_are_forwarded(): void {
		$proxy = $this->install( DelegationSqliteLikeWpdb::class, true );

		self::assertSame( 'sqlite-3.0', $proxy->sqlite_engine_version() );
		$this->expectException( \Error::class );
		$proxy->not_a_method();
	}

	public function test_the_guarded_handle_is_still_a_wpdb_for_strict_type_checks(): void {
		$proxy = $this->install( DelegationSqliteLikeWpdb::class, true );

		self::assertInstanceOf( \wpdb::class, $proxy );
		self::assertInstanceOf( DelegationStrictConsumer::class, new DelegationStrictConsumer( $proxy ) );
	}

	public function test_every_public_wpdb_method_is_forwarded_not_inherited(): void {
		// Public methods of the stock class in the supported WordPress releases.
		$stock = [
			'init_charset', 'determine_charset', 'set_charset', 'set_sql_mode', 'set_prefix', 'set_blog_id',
			'get_blog_prefix', 'tables', 'select', '_weak_escape', '_real_escape', '_escape', 'escape',
			'escape_by_ref', 'quote_identifier', 'prepare', 'esc_like', 'print_error', 'show_errors',
			'hide_errors', 'suppress_errors', 'flush', 'db_connect', 'parse_db_host', 'check_connection',
			'query', 'log_query', 'placeholder_escape', 'add_placeholder_escape', 'remove_placeholder_escape',
			'insert', 'replace', '_insert_replace_helper', 'update', 'delete', 'get_var', 'get_row', 'get_col',
			'get_results', 'get_col_charset', 'get_col_length', 'strip_invalid_text_for_column', 'get_col_info',
			'timer_start', 'timer_stop', 'bail', 'close', 'check_database_version', 'supports_collation',
			'get_charset_collate', 'has_cap', 'get_caller', 'db_version', 'db_server_info',
			'__get', '__set', '__isset', '__unset',
		];
		$loaded = array_map(
			static fn( \ReflectionMethod $method ): string => $method->getName(),
			array_filter(
				( new \ReflectionClass( \wpdb::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ),
				static fn( \ReflectionMethod $method ): bool => ! $method->isConstructor() && ! $method->isStatic()
			)
		);

		foreach ( array_unique( array_merge( $stock, $loaded ) ) as $name ) {
			self::assertTrue( method_exists( ProtectedWpdbProxy::class, $name ), $name . ' exists on the proxy' );
			$declaring = ( new \ReflectionMethod( ProtectedWpdbProxy::class, $name ) )->getDeclaringClass()->getName();
			self::assertSame( ProtectedWpdbProxy::class, $declaring, $name . ' is forwarded by the proxy, not inherited' );
		}
	}

	// -------------------------------------------------------------------------
	// The guard blocks the same writes as before, on every driver.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider drivers
	 * @param class-string<DelegationPlainWpdb> $class
	 */
	public function test_read_only_blocks_every_write_path( string $class ): void {
		$proxy = $this->install( $class, true );
		/** @var DelegationPlainWpdb $inner */
		$inner = $this->installed;

		$attempts = [
			'query'                   => static fn() => $proxy->query( 'UPDATE wp_custom SET x = 1' ),
			'insert'                  => static fn() => $proxy->insert( 'wp_custom', [ 'a' => 1 ] ),
			'replace'                 => static fn() => $proxy->replace( 'wp_custom', [ 'a' => 1 ] ),
			'update'                  => static fn() => $proxy->update( 'wp_custom', [ 'a' => 1 ], [ 'id' => 1 ] ),
			'delete'                  => static fn() => $proxy->delete( 'wp_custom', [ 'id' => 1 ] ),
			'insert helper'           => static fn() => $proxy->_insert_replace_helper( 'wp_custom', [ 'a' => 1 ] ),
			'write through get_var'   => static fn() => $proxy->get_var( 'DELETE FROM wp_custom' ),
			'write through get_row'   => static fn() => $proxy->get_row( 'DELETE FROM wp_custom' ),
			'write through get_col'   => static fn() => $proxy->get_col( 'DELETE FROM wp_custom' ),
			'write through results'   => static fn() => $proxy->get_results( 'TRUNCATE TABLE wp_custom' ),
		];
		foreach ( $attempts as $label => $attempt ) {
			try {
				$attempt();
				self::fail( 'Expected a blocked write for ' . $label . '.' );
			} catch ( GuardedRuntimeWriteException $exception ) {
				self::assertSame( 'stonewright_php_read_only_violation', $exception->wp_error_code(), $label );
			}
		}
		self::assertSame( [], $inner->statements, 'No blocked statement reached the real handle.' );

		// Reads still pass.
		self::assertSame( 'var', $proxy->get_var( 'SELECT 1' ) );
		self::assertSame( [ 'SELECT 1' ], $inner->statements );
	}

	/**
	 * @dataProvider drivers
	 * @param class-string<DelegationPlainWpdb> $class
	 */
	public function test_core_tables_and_protected_meta_stay_blocked_when_writes_are_allowed( string $class ): void {
		$proxy = $this->install( $class, false );
		/** @var DelegationPlainWpdb $inner */
		$inner = $this->installed;

		$core = [
			'update posts'        => static fn() => $proxy->update( 'wp_posts', [ 'post_title' => 'x' ], [ 'ID' => 1 ] ),
			'insert options'      => static fn() => $proxy->insert( 'wp_options', [ 'option_name' => 'x' ] ),
			'delete users'        => static fn() => $proxy->delete( 'wp_users', [ 'ID' => 1 ] ),
			'replace usermeta'    => static fn() => $proxy->replace( 'wp_usermeta', [ 'meta_key' => 'x' ] ),
			'helper postmeta'     => static fn() => $proxy->_insert_replace_helper( 'wp_postmeta', [ 'meta_value' => 'x' ] ),
			'raw sql'             => static fn() => $proxy->query( 'DELETE FROM wp_posts WHERE ID = 1' ),
			'sql through results' => static fn() => $proxy->get_results( 'UPDATE wp_options SET option_value = 1' ),
		];
		foreach ( $core as $label => $attempt ) {
			try {
				$attempt();
				self::fail( 'Expected a blocked write for ' . $label . '.' );
			} catch ( GuardedRuntimeWriteException $exception ) {
				self::assertSame( 'stonewright_php_core_table_write_blocked', $exception->wp_error_code(), $label );
			}
		}

		$meta_key = '_elementor' . '_data';
		$meta     = [
			'update meta row'     => static fn() => $proxy->update( 'wp_custom', [ 'meta_value' => '[]' ], [ 'meta_key' => $meta_key ] ),
			'insert meta row'     => static fn() => $proxy->insert( 'wp_custom', [ 'meta_key' => $meta_key ] ),
			'raw sql with key'    => static fn() => $proxy->query( "INSERT INTO wp_custom (k) VALUES ('" . $meta_key . "')" ),
			'results with key'    => static fn() => $proxy->get_results( "UPDATE wp_custom SET k = '" . $meta_key . "'" ),
		];
		foreach ( $meta as $label => $attempt ) {
			try {
				$attempt();
				self::fail( 'Expected a blocked write for ' . $label . '.' );
			} catch ( GuardedRuntimeWriteException $exception ) {
				self::assertNotSame( '', $exception->wp_error_code(), $label );
			}
		}
		self::assertSame( [], $inner->statements, 'No blocked statement reached the real handle.' );
	}

	/**
	 * @dataProvider drivers
	 * @param class-string<DelegationPlainWpdb> $class
	 */
	public function test_custom_table_writes_pass_when_writes_are_allowed( string $class ): void {
		$proxy = $this->install( $class, false );
		/** @var DelegationPlainWpdb $inner */
		$inner = $this->installed;

		self::assertSame( 1, $proxy->insert( 'wp_custom_log', [ 'msg' => 'ok' ] ) );
		self::assertSame( 1, $proxy->update( 'wp_custom_log', [ 'msg' => 'ok' ], [ 'id' => 1 ] ) );
		self::assertSame( 1, $proxy->delete( 'wp_custom_log', [ 'id' => 1 ] ) );
		self::assertSame( 1, $proxy->query( 'INSERT INTO wp_custom_log (msg) VALUES ("ok")' ) );
		self::assertCount( 4, $inner->statements );
	}

	public function test_uninstall_restores_the_original_handle_unchanged(): void {
		$this->install( DelegationSqliteLikeWpdb::class, false );
		$original = $this->installed;
		$GLOBALS['wpdb']->query( 'SELECT 1' );

		ProtectedWpdbWriteGuard::uninstall( $original );
		$this->installed = null;

		self::assertSame( $original, $GLOBALS['wpdb'] );
		self::assertSame( 'SELECT 1', $original->last_query );
	}
}
