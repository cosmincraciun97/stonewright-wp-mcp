<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\ServerFactory;

final class ServerFactoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_builds_authorization_and_resource_servers(): void {
		self::assertInstanceOf( AuthorizationServer::class, ServerFactory::authorization_server() );
		self::assertInstanceOf( ResourceServer::class, ServerFactory::resource_server() );
	}
}
