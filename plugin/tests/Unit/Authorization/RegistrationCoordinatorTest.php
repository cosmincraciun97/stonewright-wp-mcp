<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Exchange\RegistrationCoordinator;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\TransportPolicy;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryClientDirectory;

final class RegistrationCoordinatorTest extends TestCase {

	public static function policy(): array {
		return [ 'authentication_methods' => [ 'none' ], 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ], 'scopes_supported' => [ 'mcp', 'read' ], 'native_clients' => true ];
	}

	public static function request( array $changes = [] ): string {
		return json_encode( array_replace( [ 'redirect_uris' => [ 'http://127.0.0.1/callback' ], 'token_endpoint_auth_method' => 'none', 'scope' => 'mcp read', 'client_id' => 'attacker-chosen-id', 'client_secret' => 'synthetic-secret-marker', 'unknown' => 'ignored' ], $changes ), JSON_THROW_ON_ERROR );
	}

	public function test_registration_persists_only_accepted_metadata_and_returns_an_assigned_identifier(): void {
		$directory = new MemoryClientDirectory();
		$result = ( new RegistrationCoordinator( $directory, self::policy(), 4096 ) )->register( self::request() );
		self::assertSame( 201, $result['status'] );
		self::assertSame( 'no-store', $result['headers']['Cache-Control'] );
		self::assertSame( 'assigned-client-a', $result['body']['client_id'] );
		self::assertSame( 'none', $result['body']['token_endpoint_auth_method'] );
		self::assertArrayNotHasKey( 'client_id', $directory->accepted );
		self::assertArrayNotHasKey( 'client_secret', $result['body'] );
		self::assertArrayNotHasKey( 'unknown', $result['body'] );
		self::assertArrayNotHasKey( 'unrelated_private_field', $result['body'] );
	}

	public function test_invalid_scope_creates_no_client(): void {
		$directory = new MemoryClientDirectory();
		$this->expectException( OAuthFault::class );
		try {
			( new RegistrationCoordinator( $directory, self::policy(), 4096 ) )->register( self::request( [ 'scope' => 'mcp write' ] ) );
		} finally {
			self::assertNull( $directory->accepted );
		}
	}

	public function test_unsafe_client_link_creates_no_client_and_loopback_links_follow_the_transport_policy(): void {
		$directory = new MemoryClientDirectory();
		try {
			( new RegistrationCoordinator( $directory, self::policy(), 4096 ) )->register( self::request( [ 'client_uri' => 'javascript:alert(1)' ] ) );
			self::fail( 'An unsafe client link must fail.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_client_metadata', $fault->error() );
			self::assertNull( $directory->accepted );
		}
		$result = ( new RegistrationCoordinator( $directory, self::policy(), 4096, new TransportPolicy( true ) ) )->register( self::request( [ 'client_uri' => 'http://127.0.0.1:8080/client' ] ) );
		self::assertSame( 'http://127.0.0.1:8080/client', $result['body']['client_uri'] );
	}

	public function test_storage_failure_delivers_no_registration_response(): void {
		$directory = new MemoryClientDirectory();
		$directory->fail = true;
		$this->expectException( \RuntimeException::class );
		( new RegistrationCoordinator( $directory, self::policy(), 4096 ) )->register( self::request() );
	}
}
