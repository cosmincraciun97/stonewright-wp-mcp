<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\Environment;
use Stonewright\WpMcp\Security\StaticAnalysis;

/**
 * The warning about PHP functions that can run commands is written when the set of enabled
 * functions changes and at most once a day otherwise, and the list stays readable through the
 * site environment ability.
 *
 * @covers \Stonewright\WpMcp\Security\StaticAnalysis
 * @covers \Stonewright\WpMcp\Abilities\Site\Environment
 */
final class StaticAnalysisTest extends TestCase {

	private string $log_file;

	private string|false $previous_log;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$this->log_file                     = tempnam( sys_get_temp_dir(), 'sw-log-' ) ?: '';
		$this->previous_log                 = ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		if ( false !== $this->previous_log ) {
			ini_set( 'error_log', $this->previous_log );
		}
		if ( '' !== $this->log_file && is_file( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	private function warnings(): int {
		return substr_count( (string) file_get_contents( $this->log_file ), 'stonewright.dangerous_php_functions_enabled' );
	}

	public function test_the_warning_is_written_once_for_the_same_functions(): void {
		$now = 1_800_000_000;

		self::assertTrue( StaticAnalysis::notify( [ 'exec', 'system' ], $now ) );
		self::assertFalse( StaticAnalysis::notify( [ 'exec', 'system' ], $now + 5 ) );
		self::assertFalse( StaticAnalysis::notify( [ 'exec', 'system' ], $now + 3600 ) );

		self::assertSame( 1, $this->warnings() );
	}

	public function test_the_warning_is_written_again_when_the_functions_change(): void {
		$now = 1_800_000_000;

		StaticAnalysis::notify( [ 'exec' ], $now );
		self::assertTrue( StaticAnalysis::notify( [ 'exec', 'popen' ], $now + 10 ) );

		self::assertSame( 2, $this->warnings() );
	}

	public function test_the_warning_is_written_again_after_a_day(): void {
		$now = 1_800_000_000;

		StaticAnalysis::notify( [ 'exec' ], $now );
		self::assertFalse( StaticAnalysis::notify( [ 'exec' ], $now + 86399 ) );
		self::assertTrue( StaticAnalysis::notify( [ 'exec' ], $now + 86400 ) );

		self::assertSame( 2, $this->warnings() );
	}

	public function test_nothing_is_written_when_no_function_is_enabled_and_a_later_change_is_reported(): void {
		$now = 1_800_000_000;

		self::assertFalse( StaticAnalysis::notify( [], $now ) );
		self::assertSame( 0, $this->warnings() );

		StaticAnalysis::notify( [ 'exec' ], $now + 10 );
		StaticAnalysis::notify( [], $now + 20 );
		self::assertTrue( StaticAnalysis::notify( [ 'exec' ], $now + 30 ) );
		self::assertSame( 2, $this->warnings() );
	}

	public function test_the_warning_names_the_functions(): void {
		StaticAnalysis::notify( [ 'exec', 'popen' ], 1_800_000_000 );

		self::assertStringContainsString( '"functions":["exec","popen"]', (string) file_get_contents( $this->log_file ) );
	}

	public function test_the_enabled_functions_are_listed_by_the_environment_ability(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'read' => true ];

		$result = ( new Environment() )->execute( [] );
		$GLOBALS['stonewright_test_user_caps'] = [];

		self::assertSame( StaticAnalysis::enabled_functions(), $result['dangerous_php_functions'] );
		self::assertContains( 'dangerous_php_functions', array_keys( ( new Environment() )->output_schema()['properties'] ) );
	}
}
