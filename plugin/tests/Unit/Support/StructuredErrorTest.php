<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ErrorEnvelope;
use Stonewright\WpMcp\Support\StructuredError;

/**
 * @covers \Stonewright\WpMcp\Support\StructuredError
 */
final class StructuredErrorTest extends TestCase {

	public function test_create_includes_cause_repair_retryable(): void {
		$error = StructuredError::create(
			'stonewright_rest_unreachable',
			'REST endpoint unreachable.',
			'TCP connection to the site URL failed.',
			'Confirm STONEWRIGHT_WP_URL is reachable and REST is not blocked.',
			true,
			[ 'status' => 503, 'ability' => 'stonewright/site-snapshot' ]
		);

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_rest_unreachable', $error->get_error_code() );

		$envelope = ErrorEnvelope::from_wp_error( $error );
		$data     = $envelope['error']['data'] ?? [];

		self::assertSame( 'TCP connection to the site URL failed.', $data['cause'] );
		self::assertStringContainsString( 'STONEWRIGHT_WP_URL', (string) $data['repair'] );
		self::assertTrue( $data['retryable'] );
		self::assertSame( 503, $data['status'] );
		self::assertSame( 'stonewright/site-snapshot', $data['ability'] );
	}

	public function test_to_array_matches_error_envelope(): void {
		$arr = StructuredError::to_array(
			'stonewright_permission_denied',
			'Permission denied.',
			'Caller lacks manage_options.',
			'Authenticate as an administrator Application Password user.',
			false
		);

		self::assertArrayHasKey( 'error', $arr );
		self::assertSame( 'stonewright_permission_denied', $arr['error']['code'] );
		self::assertFalse( $arr['error']['data']['retryable'] );
	}
}
