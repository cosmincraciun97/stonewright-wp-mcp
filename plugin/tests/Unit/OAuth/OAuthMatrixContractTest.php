<?php
/**
 * Deterministic OAuth discovery / PKCE / error / challenge matrix.
 *
 * Cross-cuts unit contracts that client and proxy certifications rely on.
 * Prefer these over live browser flows for CI.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stonewright\WpMcp\OAuth\Bootstrap;
use Stonewright\WpMcp\OAuth\Endpoints\Authorize;
use Stonewright\WpMcp\OAuth\Endpoints\Discovery;
use Stonewright\WpMcp\OAuth\Endpoints\Revoke;
use Stonewright\WpMcp\OAuth\Endpoints\Token;
use Stonewright\WpMcp\OAuth\Middleware;
use WP_REST_Request;
use WP_REST_Response;

final class OAuthMatrixContractTest extends TestCase {

	protected function setUp(): void {
		$_GET                                    = [];
		$_SERVER                                 = [];
		$GLOBALS['stonewright_test_home_url']    = 'https://example.test/';
		$GLOBALS['stonewright_test_user_caps']   = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_transients']  = [];
		$GLOBALS['stonewright_test_rest_routes'] = [];
	}

	protected function tearDown(): void {
		$_GET                              = [];
		$_SERVER                           = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	/**
	 * @return array<string, array{0:string,1:string,2:list<string>,3:list<string>}>
	 */
	public static function well_known_path_matrix(): array {
		return [
			'root_protected_resource' => [
				'https://example.test',
				'https://example.test/wp-json/mcp/stonewright-oauth',
				[
					'/.well-known/oauth-protected-resource',
					'/.well-known/oauth-protected-resource/wp-json/mcp/stonewright-oauth',
				],
				[
					'/.well-known/oauth-authorization-server',
					'/.well-known/openid-configuration',
				],
			],
			'subdirectory_protected_resource' => [
				'https://example.com/blog',
				'https://example.com/blog/wp-json/mcp/stonewright-oauth',
				[
					'/blog/.well-known/oauth-protected-resource',
					'/.well-known/oauth-protected-resource/blog/wp-json/mcp/stonewright-oauth',
				],
				[
					'/blog/.well-known/oauth-authorization-server',
					'/.well-known/oauth-authorization-server/blog',
					'/blog/.well-known/openid-configuration',
					'/.well-known/openid-configuration/blog',
				],
			],
		];
	}

	/**
	 * @dataProvider well_known_path_matrix
	 *
	 * @param list<string> $expected_pr
	 * @param list<string> $expected_as
	 */
	public function test_well_known_paths_cover_append_and_insert_forms(
		string $home,
		string $resource,
		array $expected_pr,
		array $expected_as
	): void {
		$paths = Discovery::discovery_paths( $home, $resource );
		foreach ( $expected_pr as $path ) {
			self::assertContains( $path, $paths['protected_resource'] );
		}
		foreach ( $expected_as as $path ) {
			self::assertContains( $path, $paths['authorization_server'] );
		}
		self::assertSame(
			array_values( array_unique( $paths['authorization_server'] ) ),
			$paths['authorization_server'],
			'authorization_server discovery paths must be unique'
		);
	}

	public function test_authorization_server_document_advertises_pkce_s256_only(): void {
		$doc = Discovery::authorization_server_document();

		self::assertSame( [ 'S256' ], $doc['code_challenge_methods_supported'] );
		self::assertSame( [ 'code' ], $doc['response_types_supported'] );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $doc['grant_types_supported'] );
		self::assertSame( [ 'none' ], $doc['token_endpoint_auth_methods_supported'] );
		self::assertContains( 'offline_access', $doc['scopes_supported'] );
		self::assertContains( 'mcp', $doc['scopes_supported'] );
		self::assertStringContainsString( '/oauth/token', (string) $doc['token_endpoint'] );
		self::assertStringContainsString( '/oauth/revoke', (string) $doc['revocation_endpoint'] );
		self::assertStringContainsString( 'page=stonewright-oauth-authorize', (string) $doc['authorization_endpoint'] );
	}

	public function test_protected_resource_document_is_minimal_mcp_scope(): void {
		$doc = Discovery::protected_resource_document();

		self::assertSame( Bootstrap::resource_identifier(), $doc['resource'] );
		self::assertSame( [ 'mcp' ], $doc['scopes_supported'] );
		self::assertSame( [ 'header' ], $doc['bearer_methods_supported'] );
		self::assertNotContains( 'offline_access', $doc['scopes_supported'] );
	}

	/**
	 * @return array<string, array{0:array<string,string>,1:string}>
	 */
	public static function pkce_rejection_matrix(): array {
		return [
			'missing_code_challenge' => [
				[
					'response_type'         => 'code',
					'client_id'             => 'client-example',
					'redirect_uri'          => 'https://client.example/callback',
					'code_challenge_method' => 'S256',
				],
				'code_challenge is required',
			],
			'plain_pkce_rejected' => [
				[
					'response_type'         => 'code',
					'client_id'             => 'client-example',
					'redirect_uri'          => 'https://client.example/callback',
					'code_challenge'        => str_repeat( 'A', 43 ),
					'code_challenge_method' => 'plain',
				],
				'code_challenge_method must be',
			],
			'empty_challenge_method' => [
				[
					'response_type'  => 'code',
					'client_id'      => 'client-example',
					'redirect_uri'   => 'https://client.example/callback',
					'code_challenge' => str_repeat( 'B', 43 ),
				],
				'code_challenge_method must be',
			],
			'implicit_response_type_rejected' => [
				[
					'response_type'         => 'token',
					'client_id'             => 'client-example',
					'redirect_uri'          => 'https://client.example/callback',
					'code_challenge'        => str_repeat( 'C', 43 ),
					'code_challenge_method' => 'S256',
				],
				'response_type must be',
			],
		];
	}

	/**
	 * @dataProvider pkce_rejection_matrix
	 *
	 * @param array<string, string> $params
	 */
	public function test_authorize_rejects_non_compliant_pkce_matrix( array $params, string $needle ): void {
		$_GET = $params;

		try {
			Authorize::handle();
			self::fail( 'Expected authorize rejection via wp_die.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( $needle, $exception->getMessage() );
		}
	}

	/**
	 * @return array<string, array{0:?string,1:string}>
	 */
	public static function www_authenticate_matrix(): array {
		return [
			'no_error' => [
				null,
				'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", scope="mcp"',
			],
			'invalid_token' => [
				'invalid_token',
				'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", error="invalid_token", scope="mcp"',
			],
			'insufficient_scope' => [
				'insufficient_scope',
				'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", error="insufficient_scope", scope="mcp"',
			],
		];
	}

	/**
	 * @dataProvider www_authenticate_matrix
	 */
	public function test_www_authenticate_header_matrix( ?string $error, string $expected ): void {
		self::assertSame( $expected, Middleware::www_authenticate_header( $error ) );
	}

	public function test_unauthenticated_oauth_route_returns_json_error_and_challenge(): void {
		$result = Middleware::challenge_unauthenticated(
			null,
			null,
			new WP_REST_Request( 'GET', '/mcp/stonewright-oauth' )
		);

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 401, $result->get_status() );
		self::assertSame( 'rest_oauth_required', $result->get_data()['code'] );
		self::assertArrayHasKey( 'WWW-Authenticate', $result->get_headers() );
		self::assertStringContainsString(
			'resource_metadata=',
			(string) $result->get_headers()['WWW-Authenticate']
		);
	}

	/**
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function resource_binding_matrix(): array {
		return [
			'empty_allowed'                      => [ '', true ],
			'exact_match'                        => [ 'https://example.test/wp-json/mcp/stonewright-oauth', true ],
			'trailing_slash_tolerant'            => [ 'https://example.test/wp-json/mcp/stonewright-oauth/', true ],
			'foreign_audience_rejected'          => [ 'https://foreign.example/mcp', false ],
			'application_password_route_rejected' => [ 'https://example.test/wp-json/mcp/stonewright', false ],
		];
	}

	/**
	 * @dataProvider resource_binding_matrix
	 */
	public function test_resource_request_binding_matrix( string $requested, bool $allowed ): void {
		$expected = Bootstrap::resource_identifier();
		self::assertSame( $allowed, Bootstrap::resource_request_allowed( $requested, $expected ) );
	}

	public function test_token_endpoint_json_error_for_foreign_resource(): void {
		$request = new WP_REST_Request(
			'POST',
			'/oauth/token',
			[ 'resource' => 'https://foreign.example/wp-json/mcp/stonewright-oauth' ]
		);
		$result  = Token::handle( $request );

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 400, $result->get_status() );
		self::assertSame(
			[
				'error'             => 'invalid_target',
				'error_description' => 'The requested resource is not served here.',
			],
			$result->get_data()
		);
		self::assertSame( 'no-store', $result->get_headers()['Cache-Control'] );
		self::assertSame( 'no-cache', $result->get_headers()['Pragma'] );
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function refresh_rejection_reason_matrix(): array {
		return [
			'expired'              => [ 'invalid_grant', 'The refresh token has expired', 'refresh_token_expired' ],
			'revoked'              => [ 'invalid_grant', 'Token has been revoked', 'refresh_token_revoked' ],
			'already_used'         => [ 'invalid_grant', 'Refresh token was already used', 'refresh_token_revoked' ],
			'generic_invalid'      => [ 'invalid_grant', 'Cannot decrypt the refresh token', 'refresh_token_invalid' ],
			'invalid_request_as_grant' => [ 'invalid_request', 'Missing refresh token', 'refresh_token_invalid' ],
		];
	}

	/**
	 * @dataProvider refresh_rejection_reason_matrix
	 */
	public function test_refresh_rejection_json_error_matrix(
		string $error_type,
		string $message,
		string $expected_reason
	): void {
		$exception = new OAuthServerException( $message, 0, $error_type, 400 );
		$method    = ( new ReflectionClass( Token::class ) )->getMethod( 'refresh_rejection_response' );

		/** @var WP_REST_Response|null $response */
		$response = $method->invoke(
			null,
			$exception,
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'opaque-refresh-token',
			]
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'invalid_grant', $response->get_data()['error'] );
		self::assertSame( $expected_reason, $response->get_data()['reason'] );
		self::assertSame(
			'The refresh token is no longer valid.',
			$response->get_data()['error_description']
		);
	}

	public function test_refresh_rejection_skips_non_refresh_grants(): void {
		$exception = new OAuthServerException( 'bad code', 0, 'invalid_grant', 400 );
		$method    = ( new ReflectionClass( Token::class ) )->getMethod( 'refresh_rejection_response' );

		self::assertNull(
			$method->invoke(
				null,
				$exception,
				[
					'grant_type' => 'authorization_code',
					'code'       => 'abc',
				]
			)
		);
	}

	public function test_empty_revoke_is_idempotent_success_json(): void {
		$result = Revoke::handle( new WP_REST_Request( 'POST', '/oauth/revoke' ) );

		self::assertSame( 200, $result->get_status() );
		self::assertNull( $result->get_data() );
	}
}
