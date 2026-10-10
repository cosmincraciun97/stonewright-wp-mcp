<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\DiscoveryDocuments;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\DiscoveryDocuments
 */
final class DiscoveryDocumentsTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		StorageRig::reset_globals();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}

	public function test_pretty_permalink_sites_serve_every_document_without_rewrite_rules(): void {
		$site = HttpRig::site();
		$documents = new DiscoveryDocuments( $site );

		$resource = $documents->respond( 'GET', '/.well-known/oauth-protected-resource' );
		self::assertNotNull( $resource );
		self::assertSame( 200, $resource->status );
		self::assertSame( $site->protected_resource_metadata(), $resource->body );
		self::assertSame( 'application/json; charset=UTF-8', $resource->headers['Content-Type'] );
		self::assertSame( '*', $resource->headers['Access-Control-Allow-Origin'] );
		self::assertArrayNotHasKey( 'Cache-Control', $resource->headers );

		self::assertSame( $site->protected_resource_metadata(), $documents->respond( 'GET', '/.well-known/oauth-protected-resource/wp-json/mcp/stonewright-oauth' )?->body );
		$server = $documents->respond( 'GET', '/.well-known/oauth-authorization-server' );
		self::assertSame( $site->authorization_server_metadata(), $server?->body );
		self::assertSame( $server?->body, $documents->respond( 'GET', '/.well-known/openid-configuration' )?->body );
		self::assertSame( $server?->body, $documents->respond( 'POST', '/.well-known/oauth-authorization-server?ignored=1' )?->body );
	}

	public function test_plain_permalink_sites_publish_query_urls(): void {
		$documents = new DiscoveryDocuments( HttpRig::site( true, 'production', 'plain' ) );

		self::assertSame( 'https://example.test/index.php?rest_route=/mcp/stonewright-oauth', $documents->respond( 'GET', '/.well-known/oauth-protected-resource' )?->body['resource'] );
		self::assertSame( 'https://example.test/index.php?rest_route=/stonewright/v1/oauth/token', $documents->respond( 'GET', '/.well-known/oauth-authorization-server' )?->body['token_endpoint'] );
		self::assertNotNull( $documents->respond( 'GET', '/.well-known/oauth-protected-resource/index.php?rest_route=/mcp/stonewright-oauth' ) );
	}

	public function test_a_subdirectory_site_answers_its_path_suffixed_metadata(): void {
		$documents = new DiscoveryDocuments( HttpRig::site( true, 'production', 'pretty', 'https://example.test/blog' ) );

		self::assertSame( 'https://example.test/blog', $documents->respond( 'GET', '/.well-known/oauth-authorization-server/blog' )?->body['issuer'] );
		self::assertSame( 'https://example.test/blog', $documents->respond( 'GET', '/blog/.well-known/openid-configuration' )?->body['issuer'] );
	}

	/** @dataProvider other_paths */
	public function test_other_paths_fall_through_to_wordpress( string $uri ): void {
		self::assertNull( ( new DiscoveryDocuments( HttpRig::site() ) )->respond( 'GET', $uri ) );
	}

	public function other_paths(): array {
		return [ [ '/.well-known/oauth-protected-resource/' ], [ '/.well-known/oauth-authorization-server/wp-json/mcp/stonewright-oauth' ], [ '/.well-known/jwks.json' ], [ '/.well-known/mcp.json' ], [ '/wp-json/mcp/stonewright-oauth' ], [ '/' ], [ '' ] ];
	}

	public function test_nothing_is_published_while_oauth_is_unavailable(): void {
		self::assertNull( ( new DiscoveryDocuments( HttpRig::site( false ) ) )->respond( 'GET', '/.well-known/oauth-authorization-server' ) );
		self::assertNull( ( new DiscoveryDocuments( HttpRig::site( true, 'production', 'pretty', 'http://example.test' ) ) )->respond( 'GET', '/.well-known/oauth-protected-resource' ) );
	}

	public function test_a_local_http_site_publishes_its_own_origin(): void {
		$documents = new DiscoveryDocuments( HttpRig::site( true, 'local', 'pretty', 'http://site.test' ) );

		self::assertSame( [ 'http://site.test' ], $documents->respond( 'GET', '/.well-known/oauth-protected-resource' )?->body['authorization_servers'] );
	}

	public function test_a_cross_origin_preflight_is_answered_without_a_body(): void {
		$reply = ( new DiscoveryDocuments( HttpRig::site() ) )->respond( 'OPTIONS', '/.well-known/oauth-authorization-server' );

		self::assertSame( 204, $reply?->status );
		self::assertNull( $reply?->body );
		self::assertSame( '*', $reply?->headers['Access-Control-Allow-Origin'] );
		self::assertStringContainsString( 'GET', (string) $reply?->headers['Access-Control-Allow-Methods'] );
	}

	public function test_the_request_answer_reads_the_server_variables(): void {
		HttpSurface::use_site( HttpRig::site() );
		$_SERVER['REQUEST_URI'] = '/.well-known/openid-configuration';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		self::assertSame( 'https://example.test', DiscoveryDocuments::answer()?->body['issuer'] );

		$_SERVER['REQUEST_URI'] = '/sample-page/';
		self::assertNull( DiscoveryDocuments::answer() );
	}
}
