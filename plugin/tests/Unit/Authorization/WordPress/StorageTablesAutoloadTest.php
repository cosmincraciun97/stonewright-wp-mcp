<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The schema version is read on every request, so it belongs in the autoloaded option set.
 * Every test runs in its own process: the WordPress 6.4 functions and the update_option()
 * recorder (Fixtures/OptionFunctions.php) cannot be unloaded, and a process that has
 * already called into StorageTables would not see the recorder.
 *
 * @covers \Stonewright\WpMcp\Authorization\WordPress\StorageTables
 */
final class StorageTablesAutoloadTest extends TestCase {

	private FakeTables $space;
	private int $delta_calls = 0;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_option_writes'] = [];
		$GLOBALS['stonewright_test_alloptions'] = [];
		$GLOBALS['stonewright_test_autoload_switches'] = [];
		$this->space = new FakeTables();
	}

	private function tables(): StorageTables {
		return new StorageTables(
			new Database( new FakeWpdb( $this->space ) ),
			function ( $sql ): array {
				++$this->delta_calls;
				return $this->space->apply_ddl( $sql );
			}
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_stores_the_schema_version_autoloaded_and_the_rate_limit_schema_not(): void {
		require_once __DIR__ . '/Fixtures/OptionFunctions.php';

		self::assertTrue( $this->tables()->install() );

		self::assertSame( [ true ], $GLOBALS['stonewright_test_option_writes'][ StorageTables::VERSION_OPTION ] );
		self::assertSame( [ false ], $GLOBALS['stonewright_test_option_writes'][ StorageTables::RATE_LIMIT_SCHEMA_OPTION ], 'it is read only while installing, so it stays out of the startup load' );
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_older_schema_is_installed_with_the_option_autoloaded_and_needs_no_switch(): void {
		require_once __DIR__ . '/Fixtures/OptionFunctions.php';
		$GLOBALS['stonewright_test_options'][ StorageTables::VERSION_OPTION ] = '4';

		self::assertTrue( $this->tables()->maybe_upgrade() );

		self::assertSame( [ true ], $GLOBALS['stonewright_test_option_writes'][ StorageTables::VERSION_OPTION ] );
		self::assertSame( [], $GLOBALS['stonewright_test_autoload_switches'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_version_option_that_is_not_autoloaded_is_switched_once(): void {
		require_once __DIR__ . '/Fixtures/OptionFunctions.php';
		// An earlier build stored it without autoload: it exists but is not in the autoloaded set.
		$GLOBALS['stonewright_test_options'][ StorageTables::VERSION_OPTION ] = '5';

		self::assertTrue( $this->tables()->maybe_upgrade() );
		self::assertTrue( $this->tables()->maybe_upgrade() );
		self::assertTrue( $this->tables()->maybe_upgrade() );

		self::assertSame( [ [ StorageTables::VERSION_OPTION, true ] ], $GLOBALS['stonewright_test_autoload_switches'], 'one switch, not one per request' );
		self::assertSame( 0, $this->delta_calls );
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_version_option_that_is_already_autoloaded_is_not_touched(): void {
		require_once __DIR__ . '/Fixtures/OptionFunctions.php';
		$GLOBALS['stonewright_test_options'][ StorageTables::VERSION_OPTION ] = '5';
		$GLOBALS['stonewright_test_alloptions'][ StorageTables::VERSION_OPTION ] = '5';

		self::assertTrue( $this->tables()->maybe_upgrade() );

		self::assertSame( [], $GLOBALS['stonewright_test_autoload_switches'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_wp_set_option_autoload_the_stored_option_is_left_as_it_is(): void {
		self::assertFalse( function_exists( 'wp_set_option_autoload' ), 'this process has no WordPress 6.4 function' );
		$GLOBALS['stonewright_test_options'][ StorageTables::VERSION_OPTION ] = '5';

		self::assertTrue( $this->tables()->maybe_upgrade() );

		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		self::assertSame( 0, $this->delta_calls );
	}
}
