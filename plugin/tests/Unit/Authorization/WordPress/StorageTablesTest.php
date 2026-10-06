<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\StorageFailure;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\StorageTables
 */
final class StorageTablesTest extends TestCase {

	private FakeTables $space;
	private FakeWpdb $wpdb;
	private Database $db;
	private int $delta_calls = 0;
	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		$this->space = new FakeTables();
		$this->wpdb = new FakeWpdb( $this->space );
		$this->db = new Database( $this->wpdb );
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-schema-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
	}

	private function tables( bool $apply = true ): StorageTables {
		return new StorageTables(
			$this->db,
			function ( $sql ) use ( $apply ): array {
				++$this->delta_calls;
				return $apply ? $this->space->apply_ddl( $sql ) : [];
			}
		);
	}

	private function name( string $suffix ): string {
		return 'wptests_stonewright_oauth_' . $suffix;
	}

	public function test_fresh_install_creates_the_six_observed_tables_and_the_additions(): void {
		self::assertTrue( $this->tables()->install() );

		foreach ( LegacyRows::OBSERVED_COLUMNS as $suffix => $columns ) {
			$actual = $this->space->columns( $this->name( $suffix ) );
			self::assertSame( $columns, array_slice( $actual, 0, count( $columns ) ), $suffix . ' keeps the observed column order' );
		}
		self::assertTrue( $this->space->has( $this->name( 'families' ) ) );
		self::assertTrue( $this->space->has( $this->name( 'consents' ) ) );
		self::assertSame( [ 'access_token_hash' ], $this->space->keys( $this->name( 'refresh_tokens' ) )['access_token_hash'] );
		self::assertSame( [ 'metric_bucket', 'window_started', 'fingerprint_key' ], $this->space->keys( $this->name( 'rate_metrics' ) )['PRIMARY'] );
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		self::assertSame( '1', (string) get_option( StorageTables::RATE_LIMIT_SCHEMA_OPTION ) );
	}

	public function test_statements_follow_dbdelta_formatting_rules(): void {
		foreach ( $this->tables()->statements() as $statement ) {
			self::assertStringStartsWith( 'CREATE TABLE wptests_stonewright_oauth_', $statement );
			self::assertStringContainsString( 'PRIMARY KEY  (', $statement, 'dbDelta needs two spaces after PRIMARY KEY' );
			self::assertStringNotContainsString( '`', $statement );
			self::assertDoesNotMatchRegularExpression( '/\bINDEX\b/', $statement );
		}
		$refresh = implode( "\n", array_filter( $this->tables()->statements(), static fn ( string $sql ): bool => str_contains( $sql, 'refresh_tokens (' ) ) );
		self::assertStringContainsString( "revoked tinyint(1) NOT NULL DEFAULT '0'", $refresh );
		self::assertStringContainsString( 'KEY access_token_hash (access_token_hash)', $refresh );
	}

	public function test_upgrade_from_version_four_adds_columns_and_keeps_existing_rows(): void {
		LegacyRows::install_version_four( $this->space );
		$legacy = new LegacyRows( $this->space, StorageRig::T );
		$legacy->client();
		$family = $legacy->family( 'f', 3 );
		update_option( StorageTables::VERSION_OPTION, '4' );
		$before = $this->space->rows( $this->name( 'refresh_tokens' ) );

		self::assertTrue( $this->tables()->maybe_upgrade() );

		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		$after = $this->space->rows( $this->name( 'refresh_tokens' ) );
		self::assertCount( count( $before ), $after );
		foreach ( $before as $index => $row ) {
			self::assertSame( $row, array_intersect_key( $after[ $index ], $row ), 'existing values are untouched' );
			self::assertNull( $after[ $index ]['credential_key'] );
		}
		self::assertArrayHasKey( 'access_token_hash', $this->space->keys( $this->name( 'refresh_tokens' ) ) );
		self::assertContains( 'family_key', $this->space->columns( $this->name( 'access_tokens' ) ) );
		self::assertContains( 'code_challenge', $this->space->columns( $this->name( 'auth_codes' ) ) );
		self::assertContains( 'client_metadata', $this->space->columns( $this->name( 'clients' ) ) );
		self::assertTrue( $this->space->has( $this->name( 'families' ) ) );
		self::assertSame( [], $this->space->rows( $this->name( 'families' ) ), 'families from the earlier version are adopted lazily' );
		self::assertSame( $family['heads'][0], $after[ count( $after ) - 1 ]['identifier_hash'] );
	}

	public function test_current_version_skips_dbdelta(): void {
		self::assertTrue( $this->tables()->install() );
		$this->delta_calls = 0;

		self::assertTrue( $this->tables()->maybe_upgrade() );
		self::assertSame( 0, $this->delta_calls );
	}

	public function test_failed_verification_keeps_the_previous_version(): void {
		update_option( StorageTables::VERSION_OPTION, '4' );

		self::assertFalse( $this->tables( false )->maybe_upgrade() );
		self::assertSame( '4', (string) get_option( StorageTables::VERSION_OPTION ) );
		self::assertFalse( $this->tables( false )->healthy() );
		self::assertStringContainsString( 'oauth_schema_install_failed', (string) file_get_contents( $this->log_file ) );
	}

	public function test_a_newer_stored_version_is_left_alone(): void {
		update_option( StorageTables::VERSION_OPTION, '9' );

		self::assertTrue( $this->tables()->maybe_upgrade() );
		self::assertSame( 0, $this->delta_calls );
		self::assertSame( '9', (string) get_option( StorageTables::VERSION_OPTION ) );
	}

	public function test_a_failed_upgrade_is_not_repeated_within_the_back_off(): void {
		update_option( StorageTables::VERSION_OPTION, '4' );

		self::assertFalse( $this->tables( false )->maybe_upgrade() );
		$statements = count( $this->wpdb->statements );
		self::assertFalse( $this->tables( false )->maybe_upgrade() );
		self::assertFalse( $this->tables( false )->maybe_upgrade() );

		self::assertSame( 1, $this->delta_calls, 'dbDelta runs once, not on every request' );
		self::assertCount( $statements, $this->wpdb->statements, 'no column checks while backing off' );
		self::assertSame( 1, substr_count( (string) file_get_contents( $this->log_file ), 'oauth_schema_install_failed' ) );
		self::assertSame( 3600, StorageTables::BACKOFF_SECONDS );
		self::assertSame( StorageTables::BACKOFF_SECONDS, $GLOBALS['stonewright_test_transient_ttls'][ StorageTables::BACKOFF_TRANSIENT ] );
		self::assertSame( '4', (string) get_option( StorageTables::VERSION_OPTION ) );
	}

	public function test_the_upgrade_is_attempted_again_once_the_back_off_has_lapsed(): void {
		update_option( StorageTables::VERSION_OPTION, '4' );
		self::assertFalse( $this->tables( false )->maybe_upgrade() );

		// The transient stub keeps entries for ever; deleting one is what its expiry does.
		delete_transient( StorageTables::BACKOFF_TRANSIENT );

		self::assertTrue( $this->tables()->maybe_upgrade() );
		self::assertSame( 2, $this->delta_calls );
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
	}

	public function test_install_ignores_the_back_off_and_a_success_clears_it(): void {
		set_transient( StorageTables::BACKOFF_TRANSIENT, time(), StorageTables::BACKOFF_SECONDS );

		self::assertTrue( $this->tables()->install() );

		self::assertSame( 1, $this->delta_calls );
		self::assertFalse( get_transient( StorageTables::BACKOFF_TRANSIENT ) );
	}

	public function test_a_failed_install_starts_the_back_off(): void {
		self::assertFalse( $this->tables( false )->install() );

		self::assertNotFalse( get_transient( StorageTables::BACKOFF_TRANSIENT ) );
		self::assertFalse( $this->tables()->maybe_upgrade(), 'the next request waits' );
		self::assertSame( 1, $this->delta_calls );
	}

	public function test_an_exception_from_the_schema_step_also_starts_the_back_off(): void {
		$tables = new StorageTables(
			$this->db,
			function (): array {
				++$this->delta_calls;
				throw new \RuntimeException( 'Synthetic database failure.' );
			}
		);

		try {
			$tables->maybe_upgrade();
			self::fail( 'The failure must reach the caller.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Synthetic database failure.', $failure->getMessage() );
		}

		self::assertFalse( $tables->maybe_upgrade() );
		self::assertSame( 1, $this->delta_calls );
	}

	public function test_a_current_schema_is_unaffected_by_the_back_off(): void {
		update_option( StorageTables::VERSION_OPTION, '5' );
		set_transient( StorageTables::BACKOFF_TRANSIENT, time(), StorageTables::BACKOFF_SECONDS );

		self::assertTrue( $this->tables()->maybe_upgrade() );
		self::assertSame( 0, $this->delta_calls );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function state_rows(): array {
		$at = '2026-01-01 00:00:00';
		$hash = str_repeat( 'a', 64 );
		return [
			'a registered client'     => [ 'clients', [ 'client_id' => 'c1', 'client_name' => 'Synthetic', 'redirect_uris' => '[]', 'created_at' => $at, 'registered_by_ip_hash' => $hash ] ],
			'an authorization code'   => [ 'auth_codes', [ 'identifier_hash' => $hash, 'client_id' => 'c1', 'user_id' => '7', 'expires_at' => $at, 'scopes' => '["mcp"]', 'redirect_uri' => 'http://127.0.0.1/cb' ] ],
			'an access credential'    => [ 'access_tokens', [ 'identifier_hash' => $hash, 'client_id' => 'c1', 'user_id' => '7', 'expires_at' => $at, 'scopes' => '["mcp"]' ] ],
			'a refresh credential'    => [ 'refresh_tokens', [ 'identifier_hash' => $hash, 'access_token_hash' => $hash, 'grant_family_hash' => $hash, 'expires_at' => $at ] ],
			'a grant family'          => [ 'families', [ 'family_hash' => $hash, 'family_key' => 'f1', 'client_id' => 'c1', 'user_id' => '7', 'scopes' => '["mcp"]', 'resources' => '[]', 'family_expires_at' => $at, 'created_at' => $at, 'updated_at' => $at ] ],
			'a pending consent'       => [ 'consents', [ 'consent_hash' => $hash, 'user_id' => '7', 'client_id' => 'c1', 'request_json' => '{}', 'created_at' => $at, 'expires_at' => $at ] ],
		];
	}

	/**
	 * @dataProvider state_rows
	 * @param array<string, string> $row
	 */
	public function test_a_client_or_credential_row_counts_as_oauth_state( string $suffix, array $row ): void {
		self::assertTrue( $this->tables()->install() );
		self::assertFalse( $this->tables()->holds_state(), 'fresh tables hold nothing' );

		self::assertIsArray( $this->space->insert_row( $this->name( $suffix ), $row ) );

		self::assertTrue( $this->tables()->holds_state() );
	}

	public function test_rate_limit_counters_are_not_oauth_state(): void {
		self::assertTrue( $this->tables()->install() );
		$at = '2026-01-01 00:00:00';
		self::assertIsArray( $this->space->insert_row( $this->name( 'rate_limits' ), [ 'bucket_key' => str_repeat( 'b', 64 ), 'window_started' => '1', 'updated_at' => $at ] ) );
		self::assertIsArray( $this->space->insert_row( $this->name( 'rate_metrics' ), [ 'metric_bucket' => str_repeat( 'c', 64 ), 'window_started' => '1', 'fingerprint_key' => str_repeat( 'd', 64 ), 'updated_at' => $at ] ) );

		self::assertFalse( $this->tables()->holds_state() );
	}

	public function test_an_unreadable_table_is_reported_instead_of_read_as_empty(): void {
		self::assertTrue( $this->tables()->install() );
		$this->wpdb->fail_when = static fn ( string $sql ): bool => str_contains( $sql, 'stonewright_oauth_families' );

		$this->expectException( StorageFailure::class );

		$this->tables()->holds_state();
	}
}
