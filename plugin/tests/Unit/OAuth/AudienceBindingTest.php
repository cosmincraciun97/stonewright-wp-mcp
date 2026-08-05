<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from tests/oauth/AudienceBindingTest.php
// Source SHA-256: 1d46ab879da5564dce85bfbe48175337620e2a5ce6715dac8237d005765c696b

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use DateTimeImmutable;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Bootstrap;
use Stonewright\WpMcp\OAuth\Repositories\AccessTokenEntity;

require_once dirname( __DIR__, 3 ) . '/includes/OAuth/Repositories/AccessTokenRepository.php';

final class AudienceBindingTest extends TestCase {

	public function test_resource_request_is_allowed_only_for_this_resource(): void {
		$expected = Bootstrap::resource_identifier();

		self::assertSame( 'https://example.test/wp-json/mcp/stonewright-oauth', $expected );
		self::assertTrue( Bootstrap::resource_request_allowed( '', $expected ) );
		self::assertTrue( Bootstrap::resource_request_allowed( $expected, $expected ) );
		self::assertTrue( Bootstrap::resource_request_allowed( $expected . '/', $expected ) );
		self::assertFalse(
			Bootstrap::resource_request_allowed(
				'https://evil.test/wp-json/mcp/stonewright-oauth',
				$expected
			)
		);
		self::assertFalse(
			Bootstrap::resource_request_allowed(
				'https://example.test/wp-json/mcp/stonewright',
				$expected
			)
		);
	}

	public function test_supported_scopes_match_upstream_and_refresh_compatibility(): void {
		self::assertSame( [ 'mcp', 'read', 'write', 'offline_access' ], Bootstrap::supported_scopes() );
		self::assertSame( [ 'mcp' ], Bootstrap::resource_scopes() );
	}

	public function test_access_token_audience_is_resource_not_client(): void {
		$resource = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		self::assertNotFalse( $resource );
		$pem = '';
		openssl_pkey_export( $resource, $pem );

		$entity = new AccessTokenEntity();
		$entity->setPrivateKey( new CryptKey( $pem, null, false ) );
		$entity->setIdentifier( 'tok_test' );
		$entity->setUserIdentifier( '42' );
		$entity->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$entity->addScope( self::scope( 'mcp' ) );

		$audience = self::jwt_payload( (string) $entity )['aud'] ?? null;
		if ( is_array( $audience ) ) {
			$audience = $audience[0] ?? null;
		}

		self::assertSame( Bootstrap::resource_identifier(), $audience );
	}

	private static function scope( string $identifier ): ScopeEntityInterface {
		return new class( $identifier ) implements ScopeEntityInterface {
			public function __construct( private string $identifier ) {
			}

			public function getIdentifier(): string {
				return $this->identifier;
			}

			public function jsonSerialize(): mixed {
				return $this->identifier;
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function jwt_payload( string $jwt ): array {
		$parts   = explode( '.', $jwt );
		$json    = base64_decode( strtr( $parts[1] ?? '', '-_', '+/' ), true );
		$decoded = json_decode( false !== $json ? $json : '', true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
