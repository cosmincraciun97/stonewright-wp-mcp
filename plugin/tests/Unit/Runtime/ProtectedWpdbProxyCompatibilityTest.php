<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Security\GuardedRuntimeWriteException;
use Stonewright\WpMcp\Security\ProtectedWpdbWriteGuard;

/**
 * Strict type consumer used to prove the guarded handle remains a real wpdb.
 */
final class StrictWpdbConsumer {
	public function __construct( public \wpdb $database ) {}
}

/**
 * Test double that records query state the way a live wpdb does.
 */
#[\AllowDynamicProperties]
final class CompatibilityWpdb extends \wpdb {
	/** @var list<array<string, mixed>|object> */
	public array $seeded_rows = [];

	public int $next_insert_id = 42;

	/** @var list<array{sql: string, suppress_errors: mixed}> */
	public array $query_calls = [];

	public function __construct() {
		$this->prefix   = 'wp_';
		$this->posts    = 'wp_posts';
		$this->postmeta = 'wp_postmeta';
		$this->options  = 'wp_options';
		$this->users    = 'wp_users';
		$this->usermeta = 'wp_usermeta';
		$this->dbh      = 'compat-db-handle';
	}

	public function prepare( $query, ...$args ): string {
		if ( [] === $args ) {
			return (string) $query;
		}

		$formatted = [];
		foreach ( $args as $arg ) {
			$formatted[] = is_int( $arg ) || is_float( $arg )
				? (string) $arg
				: "'" . str_replace( "'", "''", (string) $arg ) . "'";
		}

		$index = 0;
		return (string) preg_replace_callback(
			'/%[sdf]/',
			static function () use ( &$index, $formatted ): string {
				return $formatted[ $index++ ] ?? '';
			},
			(string) $query
		);
	}

	public function query( $query ) {
		$sql = (string) $query;
		$this->query_calls[] = [
			'sql'             => $sql,
			'suppress_errors' => $this->suppress_errors ?? false,
		];
		$this->last_query = $sql;

		if ( str_starts_with( strtoupper( ltrim( $sql ) ), 'ERROR' ) ) {
			$this->last_error  = 'synthetic query failed';
			$this->last_result = [];
			$this->num_rows    = 0;
			return false;
		}

		$this->last_error = '';
		if ( 1 === preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
			$this->insert_id     = $this->next_insert_id;
			$this->num_rows      = 1;
			$this->rows_affected = 1;
			$this->last_result   = [];
			return 1;
		}

		$this->last_result = $this->seeded_rows;
		$this->num_rows    = count( $this->seeded_rows );
		return $this->num_rows;
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->query( $query );
		$row = $this->last_result[ $y ] ?? null;
		if ( is_array( $row ) ) {
			$values = array_values( $row );
			return $values[ $x ] ?? null;
		}
		if ( is_object( $row ) ) {
			$values = array_values( get_object_vars( $row ) );
			return $values[ $x ] ?? null;
		}
		return null;
	}

	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		$this->query( $query );
		return $this->last_result[ $y ] ?? null;
	}

	public function get_col( $query = null, $x = 0 ): array {
		$this->query( $query );
		$column = [];
		foreach ( $this->last_result ?? [] as $row ) {
			if ( is_array( $row ) ) {
				$values   = array_values( $row );
				$column[] = $values[ $x ] ?? null;
			} elseif ( is_object( $row ) ) {
				$values   = array_values( get_object_vars( $row ) );
				$column[] = $values[ $x ] ?? null;
			}
		}
		return $column;
	}

	public function get_results( $query = null, $output = 'OBJECT' ) {
		$this->query( $query );
		return $this->last_result;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $data, $format );
		return $this->query( 'INSERT INTO ' . $table );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $data, $where, $format, $where_format );
		return $this->query( 'UPDATE ' . $table . ' SET x = 1' );
	}

	/**
	 * @param array<string, mixed> $where
	 */
	public function delete( $table, $where, $where_format = null ) {
		unset( $where, $where_format );
		return $this->query( 'DELETE FROM ' . $table );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function replace( $table, $data, $format = null ) {
		unset( $data, $format );
		return $this->query( 'REPLACE INTO ' . $table );
	}
}

/**
 * @covers \Stonewright\WpMcp\Security\ProtectedWpdbProxy
 * @covers \Stonewright\WpMcp\Security\ProtectedWpdbWriteGuard
 */
final class ProtectedWpdbProxyCompatibilityTest extends TestCase {

	private mixed $original_wpdb;

	private ?\wpdb $installed = null;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new CompatibilityWpdb();
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [
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
		$GLOBALS['wpdb'] = $this->original_wpdb;
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_transients']   = [];
	}

	public function test_install_yields_wpdb_instance_accepted_by_strict_consumer(): void {
		$original        = $GLOBALS['wpdb'];
		$this->installed = ProtectedWpdbWriteGuard::install( true );

		self::assertInstanceOf( \wpdb::class, $GLOBALS['wpdb'] );
		self::assertInstanceOf( StrictWpdbConsumer::class, new StrictWpdbConsumer( $GLOBALS['wpdb'] ) );
		self::assertSame( $original->prefix, $GLOBALS['wpdb']->prefix );
		self::assertSame( 'compat-db-handle', $GLOBALS['wpdb']->dbh );

		ProtectedWpdbWriteGuard::uninstall( $this->installed );
		$this->installed = null;
		self::assertSame( $original, $GLOBALS['wpdb'] );
	}

	public function test_prepare_and_read_helpers_return_fake_query_state(): void {
		$inner              = $GLOBALS['wpdb'];
		$inner->seeded_rows = [
			[ 'id' => 7, 'name' => 'alpha' ],
			[ 'id' => 8, 'name' => 'beta' ],
		];
		$this->installed    = ProtectedWpdbWriteGuard::install( true );
		$db                 = $GLOBALS['wpdb'];

		$sql = $db->prepare( 'SELECT %s, %d', 'alpha', 7 );
		self::assertSame( "SELECT 'alpha', 7", $sql );

		self::assertSame( 7, $db->get_var( 'SELECT id FROM wp_custom' ) );
		self::assertSame( [ 'id' => 7, 'name' => 'alpha' ], $db->get_row( 'SELECT * FROM wp_custom' ) );
		self::assertSame( [ 7, 8 ], $db->get_col( 'SELECT id FROM wp_custom' ) );
		self::assertSame( $inner->seeded_rows, $db->get_results( 'SELECT * FROM wp_custom' ) );
	}

	public function test_last_result_and_counters_synchronize_after_query(): void {
		$inner              = $GLOBALS['wpdb'];
		$inner->seeded_rows = [
			(object) [ 'id' => 3, 'title' => 'row' ],
		];
		$this->installed    = ProtectedWpdbWriteGuard::install( false );
		$db                 = $GLOBALS['wpdb'];

		$db->query( 'SELECT * FROM wp_custom' );

		self::assertSame( 'SELECT * FROM wp_custom', $db->last_query );
		self::assertSame( $inner->seeded_rows, $db->last_result );
		self::assertSame( 1, $db->num_rows );
		self::assertSame( '', $db->last_error );

		$db->query( 'INSERT INTO wp_custom (name) VALUES ("ok")' );
		self::assertSame( 42, $db->insert_id );
		self::assertSame( 1, $db->num_rows );

		self::assertFalse( $db->query( 'ERROR boom' ) );
		self::assertSame( 'synthetic query failed', $db->last_error );
	}

	public function test_suppress_errors_is_copied_to_inner_before_query(): void {
		$inner           = $GLOBALS['wpdb'];
		$this->installed = ProtectedWpdbWriteGuard::install( true );
		$db              = $GLOBALS['wpdb'];

		$db->suppress_errors = true;
		$db->query( 'SELECT 1' );

		self::assertTrue( (bool) ( $inner->query_calls[0]['suppress_errors'] ?? false ) );
	}

	public function test_read_only_raw_sql_write_is_blocked(): void {
		$this->installed = ProtectedWpdbWriteGuard::install( true );

		try {
			$GLOBALS['wpdb']->query( 'UPDATE wp_custom SET x = 1' );
			self::fail( 'Expected GuardedRuntimeWriteException.' );
		} catch ( GuardedRuntimeWriteException $exception ) {
			self::assertSame( 'stonewright_php_read_only_violation', $exception->wp_error_code() );
		}
	}

	public function test_read_only_row_writes_are_blocked(): void {
		$this->installed = ProtectedWpdbWriteGuard::install( true );
		$db              = $GLOBALS['wpdb'];

		foreach ( [ 'insert', 'update', 'delete', 'replace' ] as $method ) {
			try {
				match ( $method ) {
					'insert'  => $db->insert( 'wp_custom_log', [ 'msg' => 'x' ] ),
					'update'  => $db->update( 'wp_custom_log', [ 'msg' => 'x' ], [ 'id' => 1 ] ),
					'delete'  => $db->delete( 'wp_custom_log', [ 'id' => 1 ] ),
					'replace' => $db->replace( 'wp_custom_log', [ 'msg' => 'x' ] ),
				};
				self::fail( 'Expected GuardedRuntimeWriteException for ' . $method . '.' );
			} catch ( GuardedRuntimeWriteException $exception ) {
				self::assertSame( 'stonewright_php_read_only_violation', $exception->wp_error_code(), $method );
			}
		}
	}

	public function test_write_enabled_core_table_writes_are_blocked(): void {
		$this->installed = ProtectedWpdbWriteGuard::install( false );

		try {
			$GLOBALS['wpdb']->update( 'wp_posts', [ 'post_title' => 'x' ], [ 'ID' => 1 ] );
			self::fail( 'Expected GuardedRuntimeWriteException.' );
		} catch ( GuardedRuntimeWriteException $exception ) {
			self::assertSame( 'stonewright_php_core_table_write_blocked', $exception->wp_error_code() );
		}
	}

	public function test_write_enabled_protected_elementor_meta_is_blocked(): void {
		$this->installed = ProtectedWpdbWriteGuard::install( false );

		try {
			$GLOBALS['wpdb']->update(
				'wp_postmeta',
				[ 'meta_value' => '[]' ],
				[ 'meta_key' => '_elementor' . '_data' ]
			);
			self::fail( 'Expected GuardedRuntimeWriteException.' );
		} catch ( GuardedRuntimeWriteException $exception ) {
			self::assertContains(
				$exception->wp_error_code(),
				[ 'stonewright_php_elementor_raw_write_blocked', 'stonewright_php_core_table_write_blocked' ]
			);
		}
	}

	public function test_write_enabled_custom_table_insert_succeeds(): void {
		$this->installed = ProtectedWpdbWriteGuard::install( false );

		$result = $GLOBALS['wpdb']->insert( 'wp_custom_log', [ 'msg' => 'ok' ] );

		self::assertSame( 1, $result );
		self::assertSame( 42, $GLOBALS['wpdb']->insert_id );
	}

	public function test_uninstall_rejects_a_foreign_original(): void {
		$original        = $GLOBALS['wpdb'];
		$this->installed = ProtectedWpdbWriteGuard::install( true );
		$proxy           = $GLOBALS['wpdb'];

		ProtectedWpdbWriteGuard::uninstall( new \wpdb() );

		self::assertSame( $proxy, $GLOBALS['wpdb'] );

		ProtectedWpdbWriteGuard::uninstall( $this->installed );
		$this->installed = null;
		self::assertSame( $original, $GLOBALS['wpdb'] );
	}

	public function test_uninstall_copies_query_state_back_to_original(): void {
		$inner              = $GLOBALS['wpdb'];
		$inner->seeded_rows = [ [ 'id' => 1 ] ];
		$this->installed    = ProtectedWpdbWriteGuard::install( true );

		$GLOBALS['wpdb']->query( 'SELECT * FROM wp_custom' );

		ProtectedWpdbWriteGuard::uninstall( $this->installed );
		$this->installed = null;

		self::assertSame( $inner, $GLOBALS['wpdb'] );
		self::assertSame( 'SELECT * FROM wp_custom', $inner->last_query );
		self::assertSame( $inner->seeded_rows, $inner->last_result );
		self::assertSame( 1, $inner->num_rows );
	}

	public function test_php_execute_restores_original_after_return_and_throwable(): void {
		$original = $GLOBALS['wpdb'];

		$ok = ( new PhpExecute() )->execute( [ 'code' => 'return 1;' ] );
		self::assertIsArray( $ok );
		self::assertSame( $original, $GLOBALS['wpdb'] );

		$failed = ( new PhpExecute() )->execute( [ 'code' => 'throw new \RuntimeException("runtime failed");' ] );
		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( $original, $GLOBALS['wpdb'] );
	}

	public function test_php_execute_blocks_read_only_raw_sql_and_restores_handle(): void {
		$original = $GLOBALS['wpdb'];

		$blocked = ( new PhpExecute() )->execute(
			[
				'code'      => 'global $wpdb; $wpdb->query("INSERT INTO wp_custom_log (msg) VALUES (\"x\")"); return true;',
				'read_only' => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_php_read_only_violation', $blocked->get_error_code() );
		self::assertSame( $original, $GLOBALS['wpdb'] );
	}
}
