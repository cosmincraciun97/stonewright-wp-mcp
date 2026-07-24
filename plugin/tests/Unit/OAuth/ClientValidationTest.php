<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/ClientValidationTest.php
// Source SHA-256: 0de3b6f279d4f187d9cc18ed4b5fbec846d23bc1ec832fc9d72080787da98251

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\ClientValidation;

final class ClientValidationTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_environment_type'] );
	}

	public function test_https_allowed(): void {
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'https://93.184.216.34/cb' ) );
	}

	public function test_supported_custom_schemes_allowed(): void {
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'claude://callback' ) );
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'cursor://callback' ) );
	}

	public function test_http_non_loopback_and_unknown_scheme_denied(): void {
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'http://example.com/cb' ) );
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'evil://x' ) );
	}

	public function test_private_ipv4_ranges_denied(): void {
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://10.0.0.5/cb' ) );
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://192.168.1.1/cb' ) );
	}

	public function test_loopback_requires_dev_mode_for_https(): void {
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://127.0.0.1/cb', false ) );
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'https://127.0.0.1/cb', true ) );
	}

	public function test_fragments_denied_but_encoded_hash_allowed(): void {
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'https://93.184.216.34/cb#frag' ) );
		self::assertFalse( ClientValidation::is_allowed_redirect_uri( 'claude://callback#x' ) );
		self::assertTrue( ClientValidation::is_allowed_redirect_uri( 'https://93.184.216.34/cb%23x' ) );
	}

	public function test_local_redirect_exception_depends_on_environment_not_debug_mode(): void {
		$GLOBALS['stonewright_test_environment_type'] = 'production';
		self::assertFalse( ClientValidation::local_redirects_allowed() );

		$GLOBALS['stonewright_test_environment_type'] = 'local';
		self::assertTrue( ClientValidation::local_redirects_allowed() );
	}
}
