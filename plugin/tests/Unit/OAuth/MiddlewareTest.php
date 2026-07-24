<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/MiddlewareTest.php
// Source SHA-256: 4dc6a0bbcb7d59c743dc0385ce1c9de6f0a2ed5ea7e826622a08c9cf6a4e8d43

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Middleware;
use WP_REST_Request;
use WP_REST_Response;

final class MiddlewareTest extends TestCase {

	protected function tearDown(): void {
		$_GET   = [];
		$_SERVER = [];
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_challenge_advertises_metadata_and_scope(): void {
		self::assertSame(
			'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource", scope="mcp"',
			Middleware::www_authenticate_header()
		);
	}

	public function test_route_isolation_preserves_application_password_server(): void {
		self::assertTrue( Middleware::is_mcp_route( '/mcp/stonewright-oauth' ) );
		self::assertTrue( Middleware::is_mcp_route( '/mcp/stonewright-oauth/tools/list' ) );
		self::assertFalse( Middleware::is_mcp_route( '/mcp/stonewright' ) );
		self::assertFalse( Middleware::is_mcp_route( '/mcp/stonewright/tools/list' ) );
		self::assertFalse( Middleware::is_mcp_route( '/mcp/mcp-adapter-default-server' ) );
	}

	public function test_challenge_applies_only_to_oauth_server(): void {
		self::assertNull(
			Middleware::challenge_unauthenticated( null, null, new WP_REST_Request( 'GET', '/mcp/stonewright' ) )
		);

		$result = Middleware::challenge_unauthenticated(
			null,
			null,
			new WP_REST_Request( 'GET', '/mcp/stonewright-oauth' )
		);
		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 401, $result->get_status() );
		self::assertArrayHasKey( 'WWW-Authenticate', $result->get_headers() );
	}

	public function test_detects_pretty_and_query_routes(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/stonewright-oauth/tools/list';
		self::assertTrue( Middleware::request_targets_mcp_route() );

		$_GET['rest_route']      = '/mcp/stonewright-oauth';
		$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fmcp%2Fstonewright-oauth';
		self::assertTrue( Middleware::request_targets_mcp_route() );

		$_GET                    = [];
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/stonewright/tools/list';
		self::assertFalse( Middleware::request_targets_mcp_route() );
	}

	public function test_bearer_parsing_and_scope_contract(): void {
		self::assertTrue( Middleware::has_bearer_authorization( 'Bearer abc.def' ) );
		self::assertTrue( Middleware::has_bearer_authorization( 'bearer abc.def' ) );
		self::assertFalse( Middleware::has_bearer_authorization( 'Basic abc.def' ) );
		self::assertSame( 'Bearer abc.def', Middleware::normalize_bearer_authorization( 'bearer abc.def' ) );
		self::assertTrue( Middleware::has_mcp_scope( [ 'profile', 'mcp' ] ) );
		self::assertTrue( Middleware::has_mcp_scope( 'profile mcp' ) );
		self::assertFalse( Middleware::has_mcp_scope( [ 'profile' ] ) );
	}

	public function test_current_user_must_retain_management_capability(): void {
		self::assertFalse( Middleware::current_user_can_access_mcp() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( Middleware::current_user_can_access_mcp() );
	}
}
