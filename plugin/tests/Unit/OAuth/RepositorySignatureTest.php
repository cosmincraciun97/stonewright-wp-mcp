<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/RepositorySignatureTest.php
// Source SHA-256: 6487f1a29832a820082eecc493a3d7b4df3453660697f558ac8085afa7ee57b0

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;

final class RepositorySignatureTest extends TestCase {

	public function test_repositories_load_without_fatal(): void {
		$plugin = dirname( __DIR__, 3 );
		$script = <<<'PHP'
			define( 'ABSPATH', __DIR__ );
			require $argv[1] . '/vendor/autoload.php';
			$classes = [
				'Stonewright\\WpMcp\\OAuth\\Repositories\\ClientRepository',
				'Stonewright\\WpMcp\\OAuth\\Repositories\\AccessTokenRepository',
				'Stonewright\\WpMcp\\OAuth\\Repositories\\AuthCodeRepository',
				'Stonewright\\WpMcp\\OAuth\\Repositories\\RefreshTokenRepository',
				'Stonewright\\WpMcp\\OAuth\\Repositories\\ScopeRepository',
				'Stonewright\\WpMcp\\OAuth\\Repositories\\UserRepository',
			];
			foreach ( $classes as $class ) {
				if ( ! class_exists( $class ) ) {
					fwrite( STDERR, 'missing:' . $class );
					exit( 1 );
				}
			}
			echo 'OK';
			PHP;

		$command = sprintf(
			'%s -r %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $script ),
			escapeshellarg( $plugin )
		);
		$output  = (string) shell_exec( $command );

		self::assertSame(
			'OK',
			trim( $output ),
			'OAuth repositories failed to load with league/oauth2-server: ' . $output
		);
	}
}
