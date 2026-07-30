<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Context\ContextToken;

/**
 * @covers \Stonewright\WpMcp\Context\ContextToken
 */
final class ContextTokenErrorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 11;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_verify_with_bad_token_names_task_start(): void {
		$result = ContextToken::verify( 'not-a-valid-token', 'stonewright/test-write' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_context_required', $result->get_error_code() );
		self::assertStringContainsString( 'stonewright-task-start', $result->get_error_message() );
		self::assertStringContainsString( 'stonewright/task-start', $result->get_error_message() );
		self::assertStringContainsString( 'stonewright-context-bootstrap', $result->get_error_message() );
	}

	public function test_verify_with_missing_transient_names_task_start(): void {
		$result = ContextToken::verify( 'swctx_' . str_repeat( 'ab', 24 ), 'stonewright/test-write' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( 'stonewright-task-start', $result->get_error_message() );
	}
}
