<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\MetadataDocuments;
use Stonewright\WpMcp\Authorization\Protocol\OAuthResponses;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;

final class TransportPolicyTest extends TestCase {

	/** @dataProvider loopback_urls */
	public function test_loopback_http_requires_an_explicit_development_policy( string $url ): void {
		$policy = new TransportPolicy( true );
		$response = ( new OAuthResponses( $policy ) )->challenge( $url . '/metadata', [ 'mcp' ], null );
		self::assertSame( 401, $response['status'] );
		self::assertStringContainsString( $url . '/metadata', $response['headers']['WWW-Authenticate'] );
		$metadata = ( new MetadataDocuments( $policy ) )->protected_resource( $url . '/mcp', [ $url ], [ 'mcp' ] );
		self::assertSame( $url . '/mcp', $metadata['resource'] );
		$this->expectException( OAuthFault::class );
		( new OAuthResponses() )->challenge( $url . '/metadata', [ 'mcp' ], null );
	}

	public function loopback_urls(): array {
		return [ [ 'http://127.0.0.1:8080' ], [ 'http://[::1]:8080' ], [ 'http://localhost:8080' ] ];
	}

	/** @dataProvider remote_http_urls */
	public function test_development_policy_does_not_enable_remote_http( string $url ): void {
		$this->expectException( OAuthFault::class );
		( new OAuthResponses( new TransportPolicy( true ) ) )->challenge( $url, [ 'mcp' ], null );
	}

	public function remote_http_urls(): array {
		return [ [ 'http://example.test/metadata' ], [ 'http://localhost.example.test/metadata' ], [ 'http://127.0.0.2/metadata' ], [ 'http://2130706433/metadata' ], [ 'http://[::ffff:127.0.0.1]/metadata' ], [ 'http://user@localhost/metadata' ] ];
	}
}
