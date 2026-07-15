<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 */
final class ErrorPatternsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_two_matching_errors_group_as_recurring(): void {
		$args = [
			'_meta' => [
				'error_code'    => 'validation_failed',
				'error_message' => 'Spec rejected: missing sections array for layout.',
			],
		];

		ErrorPatterns::observe( 'stonewright/design-apply', 'error', $args );
		ErrorPatterns::observe( 'stonewright/design-apply', 'error', $args );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 2, $recurring[0]['count'] );
		self::assertSame( 'stonewright/design-apply', $recurring[0]['ability'] );
		self::assertStringContainsString( 'Spec rejected', $recurring[0]['message'] );
		self::assertNotEmpty( $recurring[0]['last_seen'] );
	}

	public function test_ok_status_is_ignored(): void {
		ErrorPatterns::observe( 'stonewright/memory-save', 'ok', [] );
		self::assertSame( [], ErrorPatterns::recurring() );
	}

	public function test_dismiss_hides_pattern(): void {
		$args = [ '_meta' => [ 'error_code' => 'x', 'error_message' => 'boom once twice' ] ];
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		$sig = ErrorPatterns::signature( 'stonewright/php-execute', $args );
		self::assertTrue( ErrorPatterns::dismiss( $sig ) );
		self::assertSame( [], ErrorPatterns::recurring() );
	}
}
