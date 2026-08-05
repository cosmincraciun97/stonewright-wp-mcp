<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Schema;

final class SchemaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
	}

	public function test_schema_uses_stonewright_names_and_non_autoload_version(): void {
		self::assertSame( 'stonewright_oauth_schema_version', Schema::SCHEMA_VERSION_OPTION );
		self::assertSame( '3', Schema::CURRENT_SCHEMA_VERSION );

		Schema::maybe_install();

		self::assertSame(
			Schema::CURRENT_SCHEMA_VERSION,
			$GLOBALS['stonewright_test_options'][ Schema::SCHEMA_VERSION_OPTION ] ?? null
		);
	}

	public function test_schedules_and_unschedules_oauth_garbage_collection(): void {
		Schema::schedule_gc();
		self::assertArrayHasKey( Schema::GC_HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );

		Schema::unschedule_gc();
		self::assertArrayNotHasKey( Schema::GC_HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}
}
