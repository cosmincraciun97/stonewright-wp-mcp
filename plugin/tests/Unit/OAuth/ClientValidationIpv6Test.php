<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/ClientValidationIpv6Test.php
// Source SHA-256: 34f4429fcc2df3387d15d36f8de58395992df6e7d656874b93440a035c547401

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\ClientValidation;

final class ClientValidationIpv6Test extends TestCase {

	public function test_bracketed_ipv6_loopback_requires_dev_mode_for_https(): void {
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://[::1]/cb', false ) );
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'https://[::1]/cb', true ) );
	}

	public function test_private_link_local_and_mapped_ipv6_are_denied(): void {
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://[fd00::1]/cb' ) );
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://[fe80::1]/cb' ) );
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://[::ffff:127.0.0.1]/cb' ) );
		self::assertTrue( ClientValidation::is_ipv4_mapped_ipv6( '::ffff:127.0.0.1' ) );
		self::assertTrue( ClientValidation::is_ipv4_mapped_ipv6( '::ffff:93.184.216.34' ) );
		self::assertFalse( ClientValidation::is_ipv4_mapped_ipv6( '2001:4860:4860::8888' ) );
	}

	public function test_public_ipv6_is_allowed_for_https(): void {
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'https://[2001:4860:4860::8888]/cb' ) );
	}

	public function test_http_ipv6_allows_only_loopback(): void {
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'http://[::1]/cb' ) );
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'http://[2001:4860:4860::8888]/cb' ) );
	}
}
