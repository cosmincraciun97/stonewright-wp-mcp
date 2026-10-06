<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\Database;
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
	private Database $db;
	private int $delta_calls = 0;
	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->space = new FakeTables();
		$this->db = new Database( new FakeWpdb( $this->space ) );
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-schema-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
		StorageRig::reset_globals();
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
}
