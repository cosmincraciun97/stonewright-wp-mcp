<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/EndpointRateLimitTest.php
// Source SHA-256: 0b39f0fd679dc1cdec7651d4144500692a33b0849cca137993e4fdb1b56ec15e

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\ClientValidation;

final class EndpointRateLimitTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_blocks_after_cap(): void {
		for ( $i = 0; $i < ClientValidation::ENDPOINT_RATE_LIMIT_PER_MINUTE; $i++ ) {
			self::assertTrue( ClientValidation::within_endpoint_rate_limit( 'token', '203.0.113.5' ) );
		}
		self::assertFalse( ClientValidation::within_endpoint_rate_limit( 'token', '203.0.113.5' ) );
	}

	public function test_buckets_and_ips_are_independent(): void {
		for ( $i = 0; $i < ClientValidation::ENDPOINT_RATE_LIMIT_PER_MINUTE; $i++ ) {
			ClientValidation::within_endpoint_rate_limit( 'token', '203.0.113.5' );
		}
		self::assertFalse( ClientValidation::within_endpoint_rate_limit( 'token', '203.0.113.5' ) );
		self::assertTrue( ClientValidation::within_endpoint_rate_limit( 'revoke', '203.0.113.5' ) );
		self::assertTrue( ClientValidation::within_endpoint_rate_limit( 'token', '198.51.100.9' ) );
	}

	public function test_empty_ip_is_not_throttled(): void {
		for ( $i = 0; $i < ClientValidation::ENDPOINT_RATE_LIMIT_PER_MINUTE + 5; $i++ ) {
			self::assertTrue( ClientValidation::within_endpoint_rate_limit( 'token', '' ) );
		}
	}
}
