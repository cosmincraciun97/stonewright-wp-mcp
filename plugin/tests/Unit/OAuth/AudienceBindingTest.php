<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/AudienceBindingTest.php
// Source SHA-256: 1d46ab879da5564dce85bfbe48175337620e2a5ce6715dac8237d005765c696b

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Bootstrap;

final class AudienceBindingTest extends TestCase {

	public function test_resource_request_is_allowed_only_for_this_resource(): void {
		$expected = Bootstrap::resource_identifier();

		self::assertSame( 'https://example.test/wp-json/mcp/stonewright-oauth', $expected );
		self::assertTrue( Bootstrap::resource_request_allowed( '', $expected ) );
		self::assertTrue( Bootstrap::resource_request_allowed( $expected, $expected ) );
		self::assertTrue( Bootstrap::resource_request_allowed( $expected . '/', $expected ) );
		self::assertFalse(
			Bootstrap::resource_request_allowed(
				'https://evil.test/wp-json/mcp/stonewright-oauth',
				$expected
			)
		);
		self::assertFalse(
			Bootstrap::resource_request_allowed(
				'https://example.test/wp-json/mcp/stonewright',
				$expected
			)
		);
	}

	public function test_supported_scopes_match_upstream_and_refresh_compatibility(): void {
		self::assertSame( [ 'mcp', 'read', 'write', 'offline_access' ], Bootstrap::supported_scopes() );
	}
}
