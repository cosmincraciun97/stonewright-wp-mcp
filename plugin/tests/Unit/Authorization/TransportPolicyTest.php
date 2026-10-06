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

	public function test_a_declared_local_site_origin_may_publish_plain_http_endpoints(): void {
		$policy = new TransportPolicy( false, [ 'http://Site.Test:8080' ] );
		$policy->require_secure( 'http://site.test:8080' );
		$policy->require_secure( 'http://site.test:8080/index.php?rest_route=/stonewright/v1/oauth/token' );
		$document = ( new MetadataDocuments( $policy ) )->authorization_server(
			[
				'issuer'                 => 'http://site.test:8080',
				'authorization_endpoint' => 'http://site.test:8080/wp-admin/admin.php?page=stonewright-oauth-authorize',
				'token_endpoint'         => 'http://site.test:8080/wp-json/stonewright/v1/oauth/token',
			],
			[]
		);
		self::assertSame( 'http://site.test:8080', $document['issuer'] );
		$challenge = ( new OAuthResponses( $policy ) )->challenge( 'http://site.test:8080/.well-known/oauth-protected-resource', [ 'mcp' ], null );
		self::assertStringContainsString( 'http://site.test:8080/.well-known/oauth-protected-resource', $challenge['headers']['WWW-Authenticate'] );
	}

	/** @dataProvider other_origins */
	public function test_the_local_origin_exception_covers_exactly_that_origin( string $url ): void {
		$this->expectException( OAuthFault::class );
		( new TransportPolicy( false, [ 'http://site.test' ] ) )->require_secure( $url );
	}

	public function other_origins(): array {
		return [ [ 'http://other.test/metadata' ], [ 'http://site.test:8080/metadata' ], [ 'http://sub.site.test/metadata' ], [ 'http://site.test.example.test/metadata' ], [ 'http://user@site.test/metadata' ], [ 'http://site.test/metadata#fragment' ], [ 'ftp://site.test/metadata' ], [ "http://site.test/meta\ndata" ] ];
	}

	/** @dataProvider invalid_origins */
	public function test_declared_origins_must_be_plain_http_origins( string $origin ): void {
		$this->expectException( \InvalidArgumentException::class );
		new TransportPolicy( false, [ $origin ] );
	}

	public function invalid_origins(): array {
		return [ [ 'https://site.test' ], [ 'http://site.test/' ], [ 'http://site.test/path' ], [ 'site.test' ], [ 'http://user@site.test' ], [ 'http://site.test?x=1' ], [ '' ] ];
	}
}
