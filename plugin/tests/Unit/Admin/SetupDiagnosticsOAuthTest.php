<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SetupDiagnostics;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\RegistrationEndpoint;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * OAuth checks of the setup report: transport, resource, discovery URL, the bearer
 * challenge probe and the registration self-test with its cleanup count.
 *
 * @covers \Stonewright\WpMcp\Admin\SetupDiagnostics
 */
final class SetupDiagnosticsOAuthTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$this->http = new HttpRig();
		HttpSurface::use_site( $this->http->site );
		AuthorizationLifecycle::use_storage( $this->http->storage );
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** @return array<string, array<string, mixed>> Check id => check. */
	private static function checks( array $args ): array {
		$report = SetupDiagnostics::report( $args + [ 'endpoint' => 'https://example.test/wp-json/mcp/stonewright' ] );
		$checks = [];
		foreach ( $report['checks'] as $check ) {
			$checks[ (string) $check['id'] ] = $check;
		}
		return $checks;
	}

	public function test_the_report_names_the_oauth_resource_and_discovery_document(): void {
		$checks = self::checks( [ 'method' => 'oauth-http' ] );

		self::assertSame( 'ok', $checks['oauth_transport']['status'] );
		self::assertSame( 'ok', $checks['oauth_endpoint']['status'] );
		self::assertStringContainsString( 'https://example.test/wp-json/mcp/stonewright-oauth', (string) $checks['oauth_endpoint']['summary'] );
		self::assertSame( 'ok', $checks['oauth_discovery']['status'] );
		self::assertStringContainsString( 'https://example.test/.well-known/oauth-protected-resource', (string) $checks['oauth_discovery']['summary'] );
	}

	public function test_a_public_plain_http_site_reports_the_transport_problem(): void {
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'http://example.test' ) );

		self::assertSame( 'problem', self::checks( [ 'method' => 'oauth-http' ] )['oauth_transport']['status'] );
	}

	public function test_probes_see_the_challenge_and_a_self_test_registration_that_leaves_no_client(): void {
		$requests = [];
		$http = function ( string $method, string $url, array $request ) use ( &$requests ): array {
			$requests[] = [ $method, $url, $request ];
			if ( str_ends_with( $url, '/stonewright/v1/oauth/register' ) ) {
				$reply = ( new RegistrationEndpoint( $this->http->storage->clients(), $this->http->site, $this->http->rig->clock ) )->handle( $this->http->json( (array) json_decode( (string) $request['body'], true ), [ 'x-stonewright-self-test' => (string) $request['headers']['x-stonewright-self-test'] ] ) );
				return [ 'response' => [ 'code' => $reply->status ], 'body' => (string) json_encode( $reply->body ), 'headers' => [] ];
			}
			if ( str_ends_with( $url, '/mcp/stonewright-oauth' ) ) {
				return [ 'response' => [ 'code' => 401 ], 'body' => '', 'headers' => [ 'www-authenticate' => 'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", scope="mcp"' ] ];
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}', 'headers' => [] ];
		};

		$checks = self::checks(
			[
				'method'   => 'oauth-http',
				'probe'    => true,
				'http'     => $http,
				'loopback' => static fn (): array => [ 'ok' => true, 'steps' => [] ],
			]
		);

		self::assertSame( 'ok', $checks['oauth_challenge']['status'], (string) json_encode( $checks['oauth_challenge'] ) );
		self::assertSame( 'ok', $checks['oauth_registration']['status'], (string) json_encode( $checks['oauth_registration'] ) );
		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
		self::assertSame( 0, $this->http->storage->clients()->self_test_clients() );
	}
}
