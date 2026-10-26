<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Support\LateLoad;

/**
 * maybe_upgrade() runs on init for every request. It must settle "nothing to do" from two autoloaded options
 * before it asks whether the plugin's files are still there: that check is a few file stats a request that has
 * nothing to do must not pay. A stream wrapper stands in for the plugin folder and counts the stats.
 *
 * @covers \Stonewright\WpMcp\Core\RescueInstaller::maybe_upgrade
 */
final class RescueInstallerLateLoadTest extends TestCase {

	/** @var array<string, mixed> */
	private array $saved_options = [];

	protected function setUp(): void {
		$this->saved_options = $GLOBALS['stonewright_test_options'] ?? [];
		StatCountingWrapper::$stats = 0;
		stream_wrapper_register( 'swspy', StatCountingWrapper::class );
		LateLoad::set_root( 'swspy://plugin' );
	}

	protected function tearDown(): void {
		LateLoad::set_root( null );
		stream_wrapper_unregister( 'swspy' );
		$GLOBALS['stonewright_test_options'] = $this->saved_options;
	}

	private static function current_plugin(): string {
		$method = new \ReflectionMethod( RescueInstaller::class, 'plugin_basename' );
		return (string) $method->invoke( null );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_with_nothing_to_upgrade_reads_no_file(): void {
		$GLOBALS['stonewright_test_options'][ RescueInstaller::OPTION_VERSION ] = STONEWRIGHT_VERSION;
		$GLOBALS['stonewright_test_options'][ RescueInstaller::OPTION_PLUGIN ]  = self::current_plugin();

		RescueInstaller::maybe_upgrade();

		self::assertSame( 0, StatCountingWrapper::$stats, 'the plugin folder was not looked at' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upgrade_that_is_due_still_returns_when_the_plugin_files_are_gone(): void {
		$GLOBALS['stonewright_test_options'][ RescueInstaller::OPTION_VERSION ] = '0.0.0-before';

		RescueInstaller::maybe_upgrade();

		self::assertGreaterThan( 0, StatCountingWrapper::$stats, 'the folder was checked once the options said an upgrade is due' );
		self::assertSame( '0.0.0-before', $GLOBALS['stonewright_test_options'][ RescueInstaller::OPTION_VERSION ], 'nothing was installed and nothing was recorded' );
	}
}

/** A stream wrapper under which no file exists, counting how often PHP asks about one. */
final class StatCountingWrapper {

	public static int $stats = 0;

	/** @var resource|null */
	public $context;

	/** @return array<string, mixed>|false */
	public function url_stat( string $path, int $flags ) {
		unset( $path, $flags );
		++self::$stats;
		return false;
	}
}
