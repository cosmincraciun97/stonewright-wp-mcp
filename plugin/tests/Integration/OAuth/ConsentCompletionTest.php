<?php
/**
 * OAuth consent completion integration tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration\OAuth;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Repositories\UserEntity;
use Stonewright\WpMcp\OAuth\ServerFactory;

final class ConsentCompletionTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['wpdb']                        = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
					$query       = preg_replace( '/%s/', $replacement, $query, 1 ) ?? $query;
				}
				return $query;
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				unset( $output );
				if ( ! str_contains( $query, 'stonewright_oauth_clients' ) ) {
					return null;
				}

				return [
					'client_id'       => 'chatgpt-client',
					'client_name'     => 'ChatGPT',
					'redirect_uris'   => wp_json_encode( [ 'https://chatgpt.com/connector/oauth/callback' ] ),
					'is_confidential' => 0,
				];
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<int, string>   $format
			 */
			public function insert( string $table, array $data, array $format = [] ): int {
				unset( $format );
				$GLOBALS['stonewright_test_wpdb_inserts'][] = [
					'table' => $table,
					'data'  => $data,
				];
				return 1;
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_approved_chatgpt_request_completes_with_code_and_state(): void {
		$server  = ServerFactory::authorization_server();
		$request = ( new ServerRequest( 'GET', 'https://example.test/wp-admin/' ) )->withQueryParams(
			[
				'response_type'         => 'code',
				'client_id'             => 'chatgpt-client',
				'redirect_uri'          => 'https://chatgpt.com/connector/oauth/callback',
				'code_challenge'        => str_repeat( 'A', 43 ),
				'code_challenge_method' => 'S256',
				'scope'                 => 'mcp',
				'state'                 => 'expected-state',
			]
		);

		$authorization = $server->validateAuthorizationRequest( $request );
		$user          = new UserEntity();
		$user->setIdentifier( '1' );
		$authorization->setUser( $user );
		$authorization->setAuthorizationApproved( true );

		$response = $server->completeAuthorizationRequest( $authorization, new Response() );
		$location = $response->getHeaderLine( 'Location' );

		self::assertStringStartsWith( 'https://chatgpt.com/connector/oauth/callback?', $location );
		self::assertStringContainsString( 'code=', $location );
		self::assertStringContainsString( 'state=expected-state', $location );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );

		$insert = $GLOBALS['stonewright_test_wpdb_inserts'][0];
		self::assertSame( 'wp_stonewright_oauth_auth_codes', $insert['table'] );
		self::assertSame( 'chatgpt-client', $insert['data']['client_id'] );
		self::assertSame( '1', (string) $insert['data']['user_id'] );
		self::assertSame(
			[ 'mcp' ],
			array_map(
				static fn( ScopeEntityInterface $scope ): string => $scope->getIdentifier(),
				$authorization->getScopes()
			)
		);
	}
}
