<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Schema;

final class SchemaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_schema_uses_stonewright_names_and_non_autoload_version(): void {
		self::assertSame( 'stonewright_oauth_schema_version', Schema::SCHEMA_VERSION_OPTION );
		self::assertSame( '2', Schema::CURRENT_SCHEMA_VERSION );

		Schema::maybe_install();

		self::assertSame(
			Schema::CURRENT_SCHEMA_VERSION,
			$GLOBALS['stonewright_test_options'][ Schema::SCHEMA_VERSION_OPTION ] ?? null
		);
	}
}
