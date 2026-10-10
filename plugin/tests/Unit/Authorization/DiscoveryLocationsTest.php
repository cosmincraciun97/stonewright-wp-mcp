<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\DiscoveryLocations;

final class DiscoveryLocationsTest extends TestCase {

	public function test_well_known_path_precedes_resource_path_and_preserves_plain_permalink_query(): void {
		$locations = new DiscoveryLocations();
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource/index.php?rest_route=/mcp', $locations->protected_resource( 'https://example.test/index.php?rest_route=/mcp' ) );
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource', $locations->protected_resource( 'https://example.test/' ) );
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource?site=a', $locations->protected_resource( 'https://example.test/?site=a' ) );
		self::assertSame( 'https://example.test:8443/.well-known/oauth-authorization-server/tenant-a', $locations->authorization_server( 'https://example.test:8443/tenant-a' ) );
	}

	public function test_issuer_path_loses_terminating_slashes_before_the_well_known_segment(): void {
		$locations = new DiscoveryLocations();
		self::assertSame( 'https://example.test/.well-known/oauth-authorization-server/tenant-a', $locations->authorization_server( 'https://example.test/tenant-a/' ) );
		self::assertSame( 'https://example.test/.well-known/oauth-authorization-server/a/b', $locations->authorization_server( 'https://example.test/a/b/' ) );
		self::assertSame( 'https://example.test/.well-known/oauth-authorization-server', $locations->authorization_server( 'https://example.test/' ) );
		self::assertSame( 'https://example.test/.well-known/oauth-authorization-server', $locations->authorization_server( 'https://example.test' ) );
	}

	public function test_resource_path_loses_terminating_slashes_before_the_well_known_segment(): void {
		$locations = new DiscoveryLocations();
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource/mcp', $locations->protected_resource( 'https://example.test/mcp/' ) );
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource/index.php?rest_route=/mcp/', $locations->protected_resource( 'https://example.test/index.php?rest_route=/mcp/' ) );
	}

	public function test_issuer_with_query_is_rejected(): void {
		$this->expectException( OAuthFault::class );
		( new DiscoveryLocations() )->authorization_server( 'https://example.test/?tenant=a' );
	}
}
