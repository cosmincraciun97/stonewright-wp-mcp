<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\MetadataDocuments;

final class MetadataDocumentsTest extends TestCase {

	public function test_plain_permalink_endpoints_are_advertised_without_invented_features(): void {
		$endpoints = [ 'issuer' => 'https://example.test', 'authorization_endpoint' => 'https://example.test/wp-admin/admin.php?page=stonewright-oauth-authorize' ];
		foreach ( [ 'token', 'registration', 'revocation', 'introspection' ] as $operation ) {
			$suffix = [ 'registration' => 'register', 'revocation' => 'revoke', 'introspection' => 'introspect' ][ $operation ] ?? $operation;
			$endpoints[ $operation . '_endpoint' ] = 'https://example.test/index.php?rest_route=/stonewright/v1/oauth/' . $suffix;
		}
		$document = ( new MetadataDocuments() )->authorization_server( $endpoints, [ 'scopes_supported' => [ 'mcp', 'read', 'write', 'offline_access' ] ] );
		self::assertSame( 'https://example.test/index.php?rest_route=/stonewright/v1/oauth/token', $document['token_endpoint'] );
		self::assertSame( 'https://example.test', $document['issuer'] );
		self::assertSame( [ 'code' ], $document['response_types_supported'] );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $document['grant_types_supported'] );
		self::assertSame( [ 'none' ], $document['token_endpoint_auth_methods_supported'] );
		self::assertSame( [ 'S256' ], $document['code_challenge_methods_supported'] );
		self::assertArrayNotHasKey( 'client_id_metadata_document_supported', $document );
		self::assertArrayNotHasKey( 'authorization_response_iss_parameter_supported', $document );
	}

	public function test_revocation_authentication_methods_match_the_token_endpoint(): void {
		$endpoints = [ 'issuer' => 'https://example.test', 'authorization_endpoint' => 'https://example.test/authorize', 'token_endpoint' => 'https://example.test/token', 'revocation_endpoint' => 'https://example.test/revoke' ];
		$document = ( new MetadataDocuments() )->authorization_server( $endpoints, [] );
		self::assertSame( [ 'none' ], $document['revocation_endpoint_auth_methods_supported'] );
		self::assertSame( $document['token_endpoint_auth_methods_supported'], $document['revocation_endpoint_auth_methods_supported'] );
		unset( $endpoints['revocation_endpoint'] );
		self::assertArrayNotHasKey( 'revocation_endpoint_auth_methods_supported', ( new MetadataDocuments() )->authorization_server( $endpoints, [] ) );
	}

	public function test_issuer_responses_client_documents_and_introspection_methods_are_declared_when_implemented(): void {
		$endpoints = [ 'issuer' => 'https://example.test', 'authorization_endpoint' => 'https://example.test/authorize', 'token_endpoint' => 'https://example.test/token', 'revocation_endpoint' => 'https://example.test/revoke', 'introspection_endpoint' => 'https://example.test/introspect' ];
		$capabilities = [
			'scopes_supported'                               => [ 'mcp', 'read', 'write', 'offline_access' ],
			'authorization_response_iss_parameter_supported' => true,
			'client_id_metadata_document_supported'          => true,
			'introspection_endpoint_auth_methods_supported'  => [ 'client_secret_basic' ],
		];
		$document = ( new MetadataDocuments() )->authorization_server( $endpoints, $capabilities );
		self::assertTrue( $document['authorization_response_iss_parameter_supported'] );
		self::assertTrue( $document['client_id_metadata_document_supported'] );
		self::assertSame( [ 'client_secret_basic' ], $document['introspection_endpoint_auth_methods_supported'] );
		self::assertSame( [ 'none' ], $document['revocation_endpoint_auth_methods_supported'] );
		self::assertSame( [ 'mcp', 'read', 'write', 'offline_access' ], $document['scopes_supported'] );

		unset( $endpoints['introspection_endpoint'] );
		self::assertArrayNotHasKey( 'introspection_endpoint_auth_methods_supported', ( new MetadataDocuments() )->authorization_server( $endpoints, $capabilities ) );
		$capabilities['client_id_metadata_document_supported'] = false;
		self::assertArrayNotHasKey( 'client_id_metadata_document_supported', ( new MetadataDocuments() )->authorization_server( $endpoints, $capabilities ) );
	}

	/** @dataProvider invalid_capabilities */
	public function test_capability_declarations_are_validated( array $capabilities ): void {
		$endpoints = [ 'issuer' => 'https://example.test', 'authorization_endpoint' => 'https://example.test/authorize', 'token_endpoint' => 'https://example.test/token', 'introspection_endpoint' => 'https://example.test/introspect' ];
		$this->expectException( OAuthFault::class );
		( new MetadataDocuments() )->authorization_server( $endpoints, $capabilities );
	}

	public function invalid_capabilities(): array {
		return [ [ [ 'authorization_response_iss_parameter_supported' => 'yes' ] ], [ [ 'client_id_metadata_document_supported' => 1 ] ], [ [ 'introspection_endpoint_auth_methods_supported' => [] ] ], [ [ 'introspection_endpoint_auth_methods_supported' => [ "basic\r\nx" ] ] ] ];
	}

	public function test_resource_identity_and_issuer_list_are_supplied_explicitly(): void {
		$document = ( new MetadataDocuments() )->protected_resource( 'https://example.test/wp-json/mcp/stonewright-oauth', [ 'https://example.test' ], [ 'mcp' ] );
		self::assertSame( 'https://example.test/wp-json/mcp/stonewright-oauth', $document['resource'] );
		self::assertSame( [ 'https://example.test' ], $document['authorization_servers'] );
		$this->expectException( OAuthFault::class );
		( new MetadataDocuments() )->protected_resource( 'https://example.test/mcp', [], [ 'mcp' ] );
	}
}
