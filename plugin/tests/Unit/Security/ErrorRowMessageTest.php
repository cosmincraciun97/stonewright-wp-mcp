<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;

/**
 * Every failed, blocked or retryable row carries a readable message, even when
 * the caller supplied none; successful rows carry none.
 *
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 */
final class ErrorRowMessageTest extends TestCase {

	public function test_failed_row_without_a_message_gets_one_naming_the_error_code(): void {
		$event = AuditEvent::normalize( 'stonewright/example-content-update', [ '_meta' => [ 'error_code' => 'stonewright_example_write_failed' ] ], 'error' );

		self::assertNotSame( '', $event['public_message'] );
		self::assertStringContainsString( 'stonewright_example_write_failed', $event['public_message'] );
		self::assertSame( $event['public_message'], $event['redacted_details']['error_message'] ?? null );
	}

	public function test_failed_row_without_code_or_message_still_says_it_failed(): void {
		$event = AuditEvent::normalize( 'stonewright/example-content-update', [], 'error' );

		self::assertNotSame( '', $event['public_message'] );
	}

	public function test_supplied_message_is_kept(): void {
		$event = AuditEvent::normalize(
			'stonewright/example-content-update',
			[ '_meta' => [ 'error_code' => 'stonewright_example_write_failed', 'error_message' => 'The post is locked by another editor.' ] ],
			'error'
		);

		self::assertSame( 'The post is locked by another editor.', $event['public_message'] );
	}

	public function test_successful_row_has_no_error_message(): void {
		$event = AuditEvent::normalize( 'stonewright/example-content-update', [], 'ok' );

		self::assertSame( AuditEvent::OUTCOME_SUCCESS, $event['outcome'] );
		self::assertArrayNotHasKey( 'error_message', $event['redacted_details'] );
	}
}
