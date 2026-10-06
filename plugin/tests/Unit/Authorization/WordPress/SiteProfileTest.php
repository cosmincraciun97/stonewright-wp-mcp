<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\SiteProfile;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\SiteProfile
 */
final class SiteProfileTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
		unset( $GLOBALS['stonewright_test_environment_type'], $GLOBALS['stonewright_test_home_url'] );
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		unset( $GLOBALS['stonewright_test_environment_type'], $GLOBALS['stonewright_test_home_url'] );
	}

	public function test_https_sites_publish_the_observed_metadata_with_the_new_capabilities(): void {
		$site = HttpRig::site();

		self::assertTrue( $site->available() );
		self::assertSame(
			[
				'issuer'                                         => 'https://example.test',
				'authorization_endpoint'                         => 'https://example.test/wp-admin/admin.php?page=stonewright-oauth-authorize',
				'token_endpoint'                                 => 'https://example.test/wp-json/stonewright/v1/oauth/token',
				'registration_endpoint'                          => 'https://example.test/wp-json/stonewright/v1/oauth/register',
				'revocation_endpoint'                            => 'https://example.test/wp-json/stonewright/v1/oauth/revoke',
				'introspection_endpoint'                         => 'https://example.test/wp-json/stonewright/v1/oauth/introspect',
				'response_types_supported'                       => [ 'code' ],
				'grant_types_supported'                          => [ 'authorization_code', 'refresh_token' ],
				'token_endpoint_auth_methods_supported'          => [ 'none' ],
				'revocation_endpoint_auth_methods_supported'     => [ 'none' ],
				'code_challenge_methods_supported'               => [ 'S256' ],
				'scopes_supported'                               => [ 'mcp', 'read', 'write', 'offline_access' ],
				'introspection_endpoint_auth_methods_supported'  => [ 'client_secret_basic' ],
				'authorization_response_iss_parameter_supported' => true,
				'client_id_metadata_document_supported'          => true,
			],
			$site->authorization_server_metadata()
		);
		self::assertSame(
			[
				'resource'                 => 'https://example.test/wp-json/mcp/stonewright-oauth',
				'authorization_servers'    => [ 'https://example.test' ],
				'bearer_methods_supported' => [ 'header' ],
				'scopes_supported'         => [ 'mcp' ],
			],
			$site->protected_resource_metadata()
		);
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource', $site->protected_resource_metadata_url() );
	}

	public function test_plain_permalinks_change_only_the_rest_urls(): void {
		$site = HttpRig::site( true, 'production', 'plain' );
		$metadata = $site->authorization_server_metadata();

		self::assertSame( 'https://example.test', $metadata['issuer'] );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-oauth-authorize', $metadata['authorization_endpoint'] );
		self::assertSame( 'https://example.test/index.php?rest_route=/stonewright/v1/oauth/token', $metadata['token_endpoint'] );
		self::assertSame( 'https://example.test/index.php?rest_route=/stonewright/v1/oauth/introspect', $metadata['introspection_endpoint'] );
		self::assertSame( 'https://example.test/index.php?rest_route=/mcp/stonewright-oauth', $site->protected_resource_metadata()['resource'] );
		self::assertSame( 'https://example.test/.well-known/oauth-protected-resource', $site->protected_resource_metadata_url() );
	}

	public function test_oauth_is_unavailable_until_the_operator_enables_it(): void {
		self::assertFalse( HttpRig::site( false )->available() );
		self::assertTrue( HttpRig::site( false )->transport_allowed() );
	}

	/** @dataProvider plain_http_sites */
	public function test_plain_http_needs_a_local_environment_or_a_development_loopback_site( string $home, string $environment, bool $allowed ): void {
		$site = HttpRig::site( true, $environment, 'pretty', $home );

		self::assertSame( $allowed, $site->transport_allowed() );
		self::assertSame( $allowed, $site->available() );
		if ( $allowed ) {
			self::assertSame( $home, $site->authorization_server_metadata()['issuer'] );
			self::assertSame( $home . '/.well-known/oauth-protected-resource', $site->protected_resource_metadata_url() );
		}
	}

	public function plain_http_sites(): array {
		return [
			'local laragon host'          => [ 'http://site.test', 'local', true ],
			'local loopback with port'    => [ 'http://127.0.0.1:7093', 'local', true ],
			'development loopback'        => [ 'http://127.0.0.1:7093', 'development', true ],
			'development localhost'       => [ 'http://localhost:8080', 'development', true ],
			'development named host'      => [ 'http://site.test', 'development', false ],
			'production loopback'         => [ 'http://127.0.0.1:7093', 'production', false ],
			'staging named host'          => [ 'http://site.test', 'staging', false ],
			'production public http host' => [ 'http://example.test', 'production', false ],
		];
	}

	public function test_discovery_locations_cover_root_and_path_suffixed_documents(): void {
		$site = HttpRig::site();

		self::assertSame( 'resource', $site->discovery_document( '/.well-known/oauth-protected-resource', '' ) );
		self::assertSame( 'resource', $site->discovery_document( '/.well-known/oauth-protected-resource/wp-json/mcp/stonewright-oauth', '' ) );
		self::assertSame( 'server', $site->discovery_document( '/.well-known/oauth-authorization-server', '' ) );
		self::assertSame( 'server', $site->discovery_document( '/.well-known/openid-configuration', '' ) );
		self::assertSame( 'server', $site->discovery_document( '/.well-known/oauth-authorization-server', 'cache=1' ) );
		self::assertNull( $site->discovery_document( '/.well-known/oauth-protected-resource/', '' ) );
		self::assertNull( $site->discovery_document( '/.well-known/oauth-authorization-server/wp-json/mcp/stonewright-oauth', '' ) );
		self::assertNull( $site->discovery_document( '/.well-known/oauth-protected-resource/wp-json/mcp/stonewright', '' ) );
		self::assertNull( $site->discovery_document( '/.well-known/jwks.json', '' ) );
		self::assertNull( $site->discovery_document( '/.WELL-KNOWN/oauth-authorization-server', '' ) );
	}

	public function test_plain_permalinks_serve_the_query_form_of_the_resource_suffix(): void {
		$site = HttpRig::site( true, 'production', 'plain' );

		self::assertSame( 'resource', $site->discovery_document( '/.well-known/oauth-protected-resource/index.php', 'rest_route=/mcp/stonewright-oauth' ) );
		self::assertNull( $site->discovery_document( '/.well-known/oauth-protected-resource/index.php', 'rest_route=/mcp/stonewright' ) );
		self::assertNull( $site->discovery_document( '/.well-known/oauth-protected-resource/wp-json/mcp/stonewright-oauth', '' ) );
		self::assertSame( 'resource', $site->discovery_document( '/.well-known/oauth-protected-resource', '' ) );
	}

	public function test_a_subdirectory_site_serves_issuer_relative_and_path_suffixed_metadata(): void {
		$site = HttpRig::site( true, 'production', 'pretty', 'https://example.test/blog' );

		self::assertSame( 'server', $site->discovery_document( '/blog/.well-known/oauth-authorization-server', '' ) );
		self::assertSame( 'server', $site->discovery_document( '/.well-known/oauth-authorization-server/blog', '' ) );
		self::assertSame( 'server', $site->discovery_document( '/blog/.well-known/openid-configuration', '' ) );
		self::assertSame( 'server', $site->discovery_document( '/.well-known/openid-configuration/blog', '' ) );
		self::assertSame( 'resource', $site->discovery_document( '/blog/.well-known/oauth-protected-resource', '' ) );
		self::assertSame( 'resource', $site->discovery_document( '/.well-known/oauth-protected-resource/blog/wp-json/mcp/stonewright-oauth', '' ) );
		self::assertNull( $site->discovery_document( '/.well-known/oauth-authorization-server', '' ) );
		self::assertSame( 'https://example.test/blog/.well-known/oauth-protected-resource', $site->protected_resource_metadata_url() );
		self::assertSame( 'https://example.test/blog', $site->authorization_server_metadata()['issuer'] );
	}

	public function test_the_wordpress_profile_reads_enablement_environment_and_urls(): void {
		$GLOBALS['stonewright_test_environment_type'] = 'production';
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';

		$site = SiteProfile::wordpress();

		self::assertTrue( $site->available() );
		self::assertSame( 'https://example.test', $site->issuer() );
		self::assertSame( 'https://example.test/wp-json/mcp/stonewright-oauth', $site->resource() );
		self::assertContains( 'https://example.test/index.php?rest_route=/mcp/stonewright-oauth', $site->resources() );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-oauth-authorize', $site->endpoint( 'authorization' ) );
		self::assertSame( 'https://example.test/wp-json/stonewright/v1/oauth/token', $site->endpoint( 'token' ) );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-oauth-consent&token=0123456789abcdef0123456789abcdef', $site->consent_url( '0123456789abcdef0123456789abcdef' ) );

		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '';
		self::assertFalse( SiteProfile::wordpress()->available() );
	}

	public function test_a_domain_lock_mismatch_turns_oauth_off(): void {
		$GLOBALS['stonewright_test_environment_type'] = 'production';
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$GLOBALS['stonewright_test_options']['stonewright_locked_domain'] = 'https://other.example.test/';

		self::assertFalse( SiteProfile::wordpress()->available() );
	}
}
