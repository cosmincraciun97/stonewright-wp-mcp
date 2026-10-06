<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\ClientMetadataRules;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;

final class ClientMetadataTest extends TestCase {

	private function profile(): array {
		return [ 'redirect_uris' => [ 'http://127.0.0.1/callback' ], 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ], 'token_endpoint_auth_method' => 'none', 'client_name' => 'Synthetic client', 'unknown_extension' => true ];
	}

	private function policy(): array {
		return [ 'authentication_methods' => [ 'none' ], 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ], 'native_clients' => true, 'maximum_redirects' => 2, 'maximum_text_bytes' => 128 ];
	}

	public function test_public_registration_does_not_create_a_secret_or_preserve_unknown_metadata(): void {
		$accepted = ( new ClientMetadataRules() )->accept( $this->profile(), $this->policy() );
		self::assertSame( 'none', $accepted['token_endpoint_auth_method'] );
		self::assertArrayNotHasKey( 'client_secret', $accepted );
		self::assertArrayNotHasKey( 'unknown_extension', $accepted );
	}

	public function test_an_explicit_policy_substitutes_the_omitted_authentication_method(): void {
		$profile = $this->profile();
		unset( $profile['token_endpoint_auth_method'] );
		$accepted = ( new ClientMetadataRules() )->accept( $profile, [ 'omitted_authentication_method' => 'none' ] + $this->policy() );
		self::assertSame( 'none', $accepted['token_endpoint_auth_method'] );

		$profile['token_endpoint_auth_method'] = 'client_secret_basic';
		try {
			( new ClientMetadataRules() )->accept( $profile, [ 'omitted_authentication_method' => 'none' ] + $this->policy() );
			self::fail( 'A requested unsupported method is not replaced.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_client_metadata', $fault->error() );
		}
	}

	public function test_real_client_callbacks_register(): void {
		$redirects = [ 'http://localhost:8787/callback', 'http://127.0.0.1:33418', 'http://[::1]:8123/callback', 'https://editor.example.test/redirect', 'http://localhost:54321/oauth/callback', 'https://assistant.example.test/api/mcp/auth_callback', 'https://chat.example.test/connector/oauth_redirect' ];
		$accepted = ( new ClientMetadataRules() )->accept( [ 'redirect_uris' => $redirects ] + $this->profile(), [ 'maximum_redirects' => 10 ] + $this->policy() );
		self::assertSame( $redirects, $accepted['redirect_uris'] );
	}

	public function test_omitted_authentication_does_not_silently_become_public(): void {
		$profile = $this->profile();
		unset( $profile['token_endpoint_auth_method'] );
		try {
			( new ClientMetadataRules() )->accept( $profile, $this->policy() );
			self::fail( 'Unsupported RFC default authentication must fail.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_client_metadata', $fault->error() );
		}
	}

	/** @dataProvider invalid_metadata */
	public function test_understood_metadata_is_bounded_and_consistent( array $change, string $error ): void {
		try {
			( new ClientMetadataRules() )->accept( array_replace( $this->profile(), $change ), $this->policy() );
			self::fail( 'Invalid metadata must fail.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( $error, $fault->error() );
		}
	}

	public function invalid_metadata(): array {
		return [ [ [ 'redirect_uris' => [ 'https://example.test/cb#x' ] ], 'invalid_redirect_uri' ], [ [ 'redirect_uris' => [] ], 'invalid_redirect_uri' ], [ [ 'redirect_uris' => 'https://example.test/cb' ], 'invalid_client_metadata' ], [ [ 'grant_types' => [ 'refresh_token' ] ], 'invalid_client_metadata' ], [ [ 'response_types' => [ 'token' ] ], 'invalid_client_metadata' ], [ [ 'client_name' => [ 'wrong type' ] ], 'invalid_client_metadata' ], [ [ 'client_name' => str_repeat( 'a', 129 ) ], 'invalid_client_metadata' ], [ [ 'redirect_uris' => [ 'https://example.test/a', 'https://example.test/b', 'https://example.test/c' ] ], 'invalid_redirect_uri' ] ];
	}

	public function test_informational_links_are_kept_when_they_are_absolute_https_urls(): void {
		$links = [ 'client_uri' => 'https://example.test/client', 'logo_uri' => 'https://[2001:db8::1]/logo.png', 'tos_uri' => 'https://example.test/terms?lang=en#section-2', 'policy_uri' => 'https://example.test:8443/privacy' ];
		$accepted = ( new ClientMetadataRules() )->accept( $links + $this->profile(), $this->policy() );
		self::assertSame( $links, array_intersect_key( $accepted, $links ) );
	}

	/** @dataProvider unsafe_links */
	public function test_unsafe_informational_links_are_invalid_client_metadata( string $field, string $uri ): void {
		try {
			( new ClientMetadataRules() )->accept( [ $field => $uri ] + $this->profile(), $this->policy() );
			self::fail( 'An unsafe informational link must fail.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_client_metadata', $fault->error() );
		}
	}

	public function unsafe_links(): array {
		$cases = [];
		foreach ( [ 'client_uri', 'logo_uri', 'tos_uri', 'policy_uri' ] as $field ) {
			$cases[ $field . ' script scheme' ] = [ $field, 'javascript:alert(1)' ];
			$cases[ $field . ' data scheme' ] = [ $field, 'data:text/html;base64,PHNjcmlwdD4=' ];
		}
		foreach ( [ 'ftp://example.test/client', 'http://example.test/client', '//example.test/client', '/client', 'https:client', 'https://user@example.test/client', 'https://example.test/"onmouseover=x', 'https://example.test/<script>', 'https://example.test/a b', 'https://example.test/%zz', 'https://example.test/a#b#c', 'https://example.test/a[1]', 'https://example.test/?q=[1]', 'http://127.0.0.1:8080/client', '' ] as $uri ) {
			$cases[ 'client_uri ' . $uri ] = [ 'client_uri', $uri ];
		}
		return $cases;
	}

	public function test_loopback_http_links_need_the_development_transport_policy(): void {
		$profile = [ 'logo_uri' => 'http://127.0.0.1:8080/logo.png' ] + $this->profile();
		$accepted = ( new ClientMetadataRules( new TransportPolicy( true ) ) )->accept( $profile, $this->policy() );
		self::assertSame( 'http://127.0.0.1:8080/logo.png', $accepted['logo_uri'] );
		$this->expectException( OAuthFault::class );
		( new ClientMetadataRules( new TransportPolicy( true ) ) )->accept( [ 'logo_uri' => 'http://example.test/logo.png' ] + $this->profile(), $this->policy() );
	}
}
