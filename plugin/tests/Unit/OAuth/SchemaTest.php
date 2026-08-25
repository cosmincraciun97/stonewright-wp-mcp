<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Schema;

final class SchemaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
	}

	public function test_schema_uses_stonewright_names_and_non_autoload_version(): void {
		self::assertSame( 'stonewright_oauth_schema_version', Schema::SCHEMA_VERSION_OPTION );
		self::assertSame( '4', Schema::CURRENT_SCHEMA_VERSION );

		Schema::maybe_install();

		self::assertSame(
			Schema::CURRENT_SCHEMA_VERSION,
			$GLOBALS['stonewright_test_options'][ Schema::SCHEMA_VERSION_OPTION ] ?? null
		);
	}

	public function test_schema_v4_adds_family_observability_columns(): void {
		Schema::maybe_install();
		$captured_sql = implode( "\n", $GLOBALS['stonewright_test_dbdelta_queries'] ?? [] );

		$expected = [
			'client_id',
			'user_id',
			'parent_identifier_hash',
			'family_expires_at',
			'consumed_at',
			'revoked_reason',
		];
		$client_expected = [ 'registration_purpose', 'registration_expires_at' ];
		self::assertSame( '4', Schema::CURRENT_SCHEMA_VERSION );
		foreach ( $expected as $column ) {
			self::assertStringContainsString( $column, $captured_sql );
		}
		foreach ( $client_expected as $column ) {
			self::assertStringContainsString( $column, $captured_sql );
		}
	}

	public function test_schedules_and_unschedules_oauth_garbage_collection(): void {
		Schema::schedule_gc();
		self::assertArrayHasKey( Schema::GC_HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );

		Schema::unschedule_gc();
		self::assertArrayNotHasKey( Schema::GC_HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}
}
