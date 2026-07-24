<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/TransportSecurityTest.php
// Source SHA-256: 8cf95ba712ab021734aa858c80cd9c05aa718ab77636507eb3bea3f7377a6ad3

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Transport;

final class TransportSecurityTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['stonewright_test_home_url'],
			$GLOBALS['stonewright_test_environment_type']
		);
	}

	/**
	 * @dataProvider transport_cases
	 */
	public function test_oauth_transport_allowed( string $home, string $environment, bool $expected ): void {
		$GLOBALS['stonewright_test_home_url']         = $home;
		$GLOBALS['stonewright_test_environment_type'] = $environment;

		self::assertSame( $expected, Transport::allowed() );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:bool}>
	 */
	public static function transport_cases(): array {
		return [
			'https public, production'  => [ 'https://example.com', 'production', true ],
			'https uppercase scheme'    => [ 'HTTPS' . '://example.com', 'production', true ],
			'https local-style host'    => [ 'https://mysite.test', 'production', true ],
			'http public, production'   => [ 'http://example.com', 'production', false ],
			'http localhost, production' => [ 'http://localhost', 'production', false ],
			'http loopback, production' => [ 'http://127.0.0.1:8888', 'production', false ],
			'http dot-test, production' => [ 'http://mysite.test', 'production', false ],
			'http private, production'  => [ 'http://192.168.1.10', 'production', false ],
			'http localhost, local'     => [ 'http://localhost', 'local', true ],
			'http dot-test, local'      => [ 'http://mysite.test', 'local', true ],
			'http private, local'       => [ 'http://192.168.1.10', 'local', true ],
		];
	}
}
