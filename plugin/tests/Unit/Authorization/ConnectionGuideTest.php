<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Presentation\ConnectionGuide;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;

final class ConnectionGuideTest extends TestCase {

	public function test_codex_guide_uses_explicit_endpoint_facts_and_separate_add_and_login_steps(): void {
		$url = 'https://example.test/index.php?rest_route=/mcp';
		$guide = ( new ConnectionGuide() )->describe( 'codex', 'site-a', $url, true );
		self::assertSame( [ 'mcp_servers' => [ 'site-a' => [ 'url' => $url ] ] ], $guide['configuration'] );
		self::assertSame( [ [ 'codex', 'mcp', 'add', 'site-a', '--url', $url ], [ 'codex', 'mcp', 'login', 'site-a' ] ], $guide['command_arguments'] );
		self::assertSame( 'stonewright-task-start', $guide['first_tool'] );
		self::assertSame( 'https://developers.openai.com/codex/mcp', $guide['documentation_url'] );
		self::assertArrayNotHasKey( 'headers', $guide['configuration']['mcp_servers']['site-a'] );
		self::assertStringNotContainsString( 'token', json_encode( $guide['configuration'] ) );
	}

	public function test_unavailable_authentication_is_presented_without_success_claims_or_commands(): void {
		$guide = ( new ConnectionGuide() )->describe( 'codex', 'site-a', 'https://example.test/mcp', false );
		self::assertSame( 'unavailable', $guide['status'] );
		self::assertSame( [], $guide['command_arguments'] );
		self::assertSame( [], $guide['configuration'] );
	}

	public function test_generic_http_guide_does_not_claim_client_specific_compatibility(): void {
		$guide = ( new ConnectionGuide() )->describe( 'generic-mcp', 'site-a', 'https://example.test/mcp', true );
		self::assertSame( [ 'server_name' => 'site-a', 'transport' => 'streamable-http', 'url' => 'https://example.test/mcp', 'authentication' => 'oauth' ], $guide['configuration'] );
		self::assertSame( [], $guide['command_arguments'] );
		self::assertSame( 'client-support-required', $guide['status'] );
	}

	public function test_local_http_guide_is_possible_only_with_the_explicit_transport_policy(): void {
		$guide = ( new ConnectionGuide( new TransportPolicy( true ) ) )->describe( 'codex', 'site-a', 'http://127.0.0.1:8080/mcp', true );
		self::assertSame( 'http://127.0.0.1:8080/mcp', $guide['configuration']['mcp_servers']['site-a']['url'] );
		$this->expectException( OAuthFault::class );
		( new ConnectionGuide() )->describe( 'codex', 'site-a', 'http://127.0.0.1:8080/mcp', true );
	}

	public function test_server_names_cannot_inject_configuration_or_commands(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new ConnectionGuide() )->describe( 'codex', "site-a\nmalicious", 'https://example.test/mcp', true );
	}
}
