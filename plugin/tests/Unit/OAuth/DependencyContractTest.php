<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;

final class DependencyContractTest extends TestCase {

	public function test_oauth_runtime_dependencies_are_installed(): void {
		self::assertTrue(
			class_exists( \League\OAuth2\Server\AuthorizationServer::class ),
			'league/oauth2-server must be installed for the plugin-native OAuth server.'
		);
		self::assertTrue(
			class_exists( \Nyholm\Psr7\ServerRequest::class ),
			'nyholm/psr7 must be installed for the WordPress-to-PSR-7 bridge.'
		);
	}
}
