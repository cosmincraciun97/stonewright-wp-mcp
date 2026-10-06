<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\TokenPair;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Authorization\Protocol\OAuthResponses;

final class ResourceAndResponseTest extends TestCase {

	public function test_resource_selection_is_bound_to_the_original_grant(): void {
		$rules = new ResourceRules();
		self::assertSame( [ 'https://example.test/mcp' ], $rules->require_authorized( [ 'HTTPS://EXAMPLE.TEST/mcp' ], [ 'https://example.test/mcp' ] ) );
		$this->expectException( OAuthFault::class );
		$rules->require_authorized( [ 'https://example.test/other' ], [ 'https://example.test/mcp' ] );
	}

	/** @dataProvider invalid_resources */
	public function test_invalid_resources_are_never_issued( array $resources ): void {
		$this->expectException( OAuthFault::class );
		( new ResourceRules() )->require_authorized( $resources, [ 'https://example.test/mcp' ] );
	}

	public function invalid_resources(): array {
		return [ [ [] ], [ [ 'example.test/mcp' ] ], [ [ 'https://example.test/mcp#fragment' ] ], [ [ 'https://user@example.test/mcp' ] ], [ [ "https://example.test/mcp\r\n" ] ], [ [ 'localhost:8080' ] ], [ [ 'example.com:443' ] ] ];
	}

	/** @dataProvider malformed_resources */
	public function test_malformed_uri_cannot_become_valid_by_being_listed_in_consent( string $uri ): void {
		$this->expectException( OAuthFault::class );
		( new ResourceRules() )->require_authorized( [ $uri ], [ $uri ] );
	}

	public function malformed_resources(): array {
		return [ [ 'https://[no-ip]/mcp' ], [ 'https://[::1::2]/mcp' ], [ 'https://example[.test/mcp' ], [ 'https://example.test:0/mcp' ], [ 'https://example.test/{bad}' ], [ 'urn:example:<bad>' ], [ 'localhost:8080' ], [ 'example.com:443' ], [ 'localhost:80/mcp' ] ];
	}

	public function test_valid_ip_literals_and_urn_resources_are_preserved(): void {
		$uris = [ 'https://[2001:db8::1]:8443/mcp?site=a', 'urn:example:resource' ];
		self::assertSame( $uris, ( new ResourceRules() )->require_authorized( $uris, $uris ) );
	}

	public function test_token_response_and_challenges_cannot_leak_or_inject_headers(): void {
		$responses = new OAuthResponses();
		$response = $responses->token( new TokenPair( 'synthetic-access', 'synthetic-refresh', 3600 ), [ 'mcp' ] );
		self::assertSame( 200, $response['status'] );
		self::assertSame( 'no-store', $response['headers']['Cache-Control'] );
		self::assertSame( 'no-cache', $response['headers']['Pragma'] );
		self::assertSame( 3600, $response['body']['expires_in'] );
		self::assertSame( 'Bearer', $response['body']['token_type'] );
		$denied = $responses->challenge( 'https://example.test/.well-known/oauth-protected-resource', [ 'mcp' ], 'insufficient_scope' );
		self::assertSame( 403, $denied['status'] );
		self::assertStringContainsString( 'error="insufficient_scope"', $denied['headers']['WWW-Authenticate'] );
		self::assertSame( 401, $responses->challenge( 'https://example.test/metadata', [ 'mcp' ], null )['status'] );
		self::assertSame( [ 'error' => 'invalid_grant', 'error_description' => 'The authorization request could not be completed.' ], $responses->failure( new OAuthFault( 'invalid_grant' ) )['body'] );
		$this->expectException( OAuthFault::class );
		$responses->challenge( 'https://example.test/metadata', [ "mcp\r\nInjected: value" ], null );
	}
}
