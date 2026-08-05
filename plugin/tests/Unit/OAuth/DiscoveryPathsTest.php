<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/DiscoveryPathsTest.php
// Source SHA-256: 01cef5a228043ee94461f45efaa9b52c55b9740c3aae08a9681d43293523fe26

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Endpoints\Discovery;

final class DiscoveryPathsTest extends TestCase {

	public function test_root_install_serves_root_and_resource_paths_without_duplicates(): void {
		$paths = Discovery::discovery_paths(
			'https://example.test',
			'https://example.test/wp-json/mcp/stonewright-oauth'
		);

		self::assertContains( '/.well-known/oauth-protected-resource', $paths['protected_resource'] );
		self::assertContains(
			'/.well-known/oauth-protected-resource/wp-json/mcp/stonewright-oauth',
			$paths['protected_resource']
		);
		self::assertContains( '/.well-known/oauth-authorization-server', $paths['authorization_server'] );
		self::assertContains( '/.well-known/openid-configuration', $paths['authorization_server'] );
		self::assertSame(
			array_values( array_unique( $paths['authorization_server'] ) ),
			$paths['authorization_server']
		);
	}

	public function test_subdirectory_install_serves_append_and_insert_forms(): void {
		$paths = Discovery::discovery_paths(
			'https://example.com/subsite',
			'https://example.com/subsite/wp-json/mcp/stonewright-oauth'
		);

		self::assertContains( '/subsite/.well-known/oauth-protected-resource', $paths['protected_resource'] );
		self::assertContains(
			'/.well-known/oauth-protected-resource/subsite/wp-json/mcp/stonewright-oauth',
			$paths['protected_resource']
		);
		self::assertContains( '/subsite/.well-known/openid-configuration', $paths['authorization_server'] );
		self::assertContains(
			'/.well-known/oauth-authorization-server/subsite',
			$paths['authorization_server']
		);
	}

	public function test_probes_use_origin_without_double_prefixing_subdirectory(): void {
		$probes = Discovery::discovery_probes(
			'https://example.com/subsite',
			'https://example.com/subsite/wp-json/mcp/stonewright-oauth'
		);

		foreach ( $probes as $probe ) {
			self::assertStringNotContainsString(
				'/subsite/.well-known/oauth-protected-resource/subsite',
				$probe['url']
			);
		}
	}

	public function test_documents_advertise_stonewright_endpoints_and_offline_access(): void {
		$authorization = Discovery::authorization_server_document();
		$resource      = Discovery::protected_resource_document();

		self::assertSame( 'https://example.test/', $authorization['issuer'] );
		self::assertStringContainsString( 'page=stonewright-oauth-authorize', $authorization['authorization_endpoint'] );
		self::assertContains( 'offline_access', $authorization['scopes_supported'] );
		self::assertSame( 'https://example.test/wp-json/mcp/stonewright-oauth', $resource['resource'] );
		self::assertSame( [ 'mcp' ], $resource['scopes_supported'] );
	}
}
