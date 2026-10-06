<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use Defuse\Crypto\Crypto;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\TokenCodec;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\TokenCodec
 * @covers \Stonewright\WpMcp\Authorization\WordPress\JsonWebSignature
 * @covers \Stonewright\WpMcp\Authorization\WordPress\PayloadCipher
 */
final class TokenCodecTest extends TestCase {

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	/** @return array<string, mixed> */
	private static function segment( string $jwt, int $index ): array {
		return json_decode( (string) base64_decode( strtr( explode( '.', $jwt )[ $index ], '-_', '+/' ) ), true );
	}

	private function rejects( string $credential ): void {
		try {
			$this->rig->codec->inspect( $credential );
			self::fail( 'The credential must be refused.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_grant', $fault->error() );
		}
	}

	private function legacy_family( int $rotations = 0, ?int $issued = null ): array {
		$legacy = new LegacyRows( $this->rig->space, StorageRig::T );
		$legacy->client();
		return $legacy->family( 'f', $rotations, null, $issued );
	}

	public function test_access_token_keeps_the_observed_shape(): void {
		[ $client, $pair ] = $this->rig->connect();

		self::assertSame( [ 'typ' => 'JWT', 'alg' => 'RS256' ], self::segment( $pair->access_token, 0 ) );
		$claims = self::segment( $pair->access_token, 1 );
		self::assertSame( [ 'aud', 'jti', 'iat', 'nbf', 'exp', 'sub', 'scopes', 'iss', 'client_id' ], array_keys( $claims ) );
		self::assertSame( StorageRig::RESOURCE, $claims['aud'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{80}$/D', $claims['jti'] );
		self::assertSame( StorageRig::T, $claims['iat'] );
		self::assertSame( $claims['iat'], $claims['nbf'] );
		self::assertSame( 3600, $claims['exp'] - $claims['iat'] );
		self::assertSame( '7', $claims['sub'] );
		self::assertSame( [ 'mcp' ], $claims['scopes'] );
		self::assertSame( StorageRig::ISSUER, $claims['iss'] );
		self::assertSame( $client, $claims['client_id'] );
		self::assertSame( 342, strlen( explode( '.', $pair->access_token )[2] ) );
		self::assertSame( 3600, $pair->expires_in );

		$row = $this->rig->row( 'access_tokens', 'identifier_hash', hash( 'sha256', $claims['jti'] ) );
		self::assertSame( $client, $row['client_id'] );
		self::assertSame( '7', $row['user_id'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 3600 ), $row['expires_at'] );
		self::assertSame( '["mcp"]', $row['scopes'] );
		self::assertSame( '0', $row['revoked'] );
	}

	public function test_refresh_token_is_an_encrypted_payload_with_the_paired_access_id(): void {
		[ $client, $pair, $outcome ] = $this->rig->connect();

		self::assertMatchesRegularExpression( '/^def50200[0-9a-f]+$/D', $pair->refresh_token );
		$payload = json_decode( Crypto::decryptWithPassword( $pair->refresh_token, (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ), true );
		self::assertSame( $client, $payload['client_id'] );
		self::assertSame( $outcome->issuance->refresh_key, $payload['refresh_token_id'] );
		self::assertSame( $outcome->issuance->access_key, $payload['access_token_id'] );
		self::assertSame( [ 'mcp' ], $payload['scopes'] );
		self::assertSame( '7', $payload['user_id'] );
		self::assertSame( $outcome->issuance->refresh_deadline, $payload['expire_time'] );
		self::assertSame( $outcome->issuance->family_key, $payload['family_id'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $payload['binding'] );
		$row = $this->rig->row( 'refresh_tokens', 'identifier_hash', RowKeys::refresh( $outcome->issuance->refresh_key ) );
		self::assertSame( hash( 'sha256', $outcome->issuance->access_key ), $row['access_token_hash'] );
	}

	public function test_inspect_returns_facts_for_each_kind(): void {
		[ $client, $pair, $outcome ] = $this->rig->connect();
		$code = $this->rig->authorize( $client );

		$access = $this->rig->codec->inspect( $pair->access_token );
		self::assertSame( TokenCodec::KIND_ACCESS, $access->kind );
		self::assertSame( $outcome->issuance->access_key, $access->credential_key );
		self::assertSame( $outcome->issuance->family_key, $access->family_key );
		self::assertSame( $client, $access->client_key );
		self::assertSame( '7', $access->subject_key );
		self::assertSame( [ 'mcp' ], $access->scopes );
		self::assertSame( [ StorageRig::RESOURCE ], $access->resources );
		self::assertSame( StorageRig::T + 3600, $access->expires_at );

		$refresh = $this->rig->codec->inspect( $pair->refresh_token );
		self::assertSame( TokenCodec::KIND_REFRESH, $refresh->kind );
		self::assertSame( $outcome->issuance->refresh_key, $refresh->credential_key );
		self::assertSame( $outcome->issuance->family_key, $refresh->family_key );
		self::assertSame( $client, $refresh->client_key );
		self::assertSame( $outcome->issuance->refresh_deadline, $refresh->expires_at );

		$facts = $this->rig->codec->inspect( $code );
		self::assertSame( TokenCodec::KIND_CODE, $facts->kind );
		self::assertNull( $facts->family_key );
		self::assertSame( $client, $facts->client_key );
		self::assertSame( [ StorageRig::RESOURCE ], $facts->resources );
		self::assertSame( StorageRig::T + 60, $facts->expires_at );
		self::assertMatchesRegularExpression( '/^def50200[0-9a-f]+$/D', $code );
	}

	public function test_foreign_or_altered_access_tokens_are_refused(): void {
		[ , $pair, $outcome ] = $this->rig->connect();
		$jti = $outcome->issuance->access_key;
		[ $other_key ] = CredentialKeys::generate_rsa( CredentialKeys::default_configurations() );

		$this->rejects( LegacyRows::access_token( $jti, (string) $other_key, StorageRig::T ) );
		$this->rejects( LegacyRows::access_token( $jti, StorageRig::private_key(), StorageRig::T, [ 'aud' => 'https://elsewhere.example.test/mcp' ] ) );
		$this->rejects( LegacyRows::access_token( $jti, StorageRig::private_key(), StorageRig::T, [ 'nbf' => StorageRig::T + 600 ] ) );
		$this->rejects( LegacyRows::access_token( $jti, StorageRig::private_key(), StorageRig::T, [ 'sub' => '8' ] ) );
		$this->rejects( LegacyRows::access_token( str_repeat( 'c', 80 ), StorageRig::private_key(), StorageRig::T ) );
		$this->rejects( LegacyRows::access_token( $jti, StorageRig::private_key(), StorageRig::T, [], [ 'typ' => 'JWT', 'alg' => 'RS512' ] ) );
		[ $header, $claims ] = explode( '.', $pair->access_token );
		$this->rejects( $header . '.' . $claims . '.' );
		$none = rtrim( strtr( base64_encode( '{"typ":"JWT","alg":"none"}' ), '+/', '-_' ), '=' );
		$this->rejects( $none . '.' . $claims . '.' );
		$public = (string) $this->rig->keys->public_key();
		$hs_header = rtrim( strtr( base64_encode( '{"typ":"JWT","alg":"HS256"}' ), '+/', '-_' ), '=' );
		$hs_signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $hs_header . '.' . $claims, $public, true ) ), '+/', '-_' ), '=' );
		$this->rejects( $hs_header . '.' . $claims . '.' . $hs_signature );
		$tampered = self::segment( $pair->access_token, 1 );
		$tampered['scopes'] = [ 'mcp', 'write' ];
		$this->rejects( $header . '.' . rtrim( strtr( base64_encode( (string) json_encode( $tampered ) ), '+/', '-_' ), '=' ) . '.' . explode( '.', $pair->access_token )[2] );
		foreach ( [ '', 'Bearer', 'def50200', 'def50200zz', str_repeat( 'a', 9000 ), 'a.b.c' ] as $garbage ) {
			$this->rejects( $garbage );
		}
	}

	public function test_refresh_payloads_are_bound_to_the_server_secret(): void {
		[ , $pair ] = $this->rig->connect();
		$password = (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION );
		$payload = json_decode( Crypto::decryptWithPassword( $pair->refresh_token, $password ), true );

		$payload['binding'] = str_repeat( '0', 64 );
		$this->rejects( Crypto::encryptWithPassword( (string) json_encode( $payload ), $password ) );

		$this->rig->binding_secret = 'rotated-binding-secret';
		$this->rejects( $pair->refresh_token );
	}

	public function test_access_tokens_from_the_earlier_version_are_accepted(): void {
		$family = $this->legacy_family( 1 );
		$jti = $family['jtis'][1];

		$facts = $this->rig->codec->inspect( LegacyRows::access_token( $jti, StorageRig::private_key(), $family['issued'] + 1 ) );

		self::assertSame( TokenCodec::KIND_ACCESS, $facts->kind );
		self::assertSame( $jti, $facts->credential_key );
		self::assertSame( $family['family_hash'], $facts->family_key );
		self::assertSame( LegacyRows::CLIENT, $facts->client_key );
		self::assertSame( '7', $facts->subject_key );
		self::assertSame( $family['issued'] + 1 + 3600, $facts->expires_at );
	}

	public function test_refresh_payloads_from_the_earlier_version_decode_with_either_key_variant(): void {
		$family = $this->legacy_family( 1 );
		$stored = (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION );

		foreach ( [ $stored, (string) base64_decode( $stored ) ] as $password ) {
			$facts = $this->rig->codec->inspect( LegacyRows::refresh_payload( $family, 1, $password ) );

			self::assertSame( TokenCodec::KIND_REFRESH, $facts->kind );
			self::assertSame( $family['rows'][1], $facts->credential_key );
			self::assertSame( $family['family_hash'], $facts->family_key );
			self::assertSame( LegacyRows::CLIENT, $facts->client_key );
			self::assertSame( '7', $facts->subject_key );
			self::assertSame( $family['deadline'], $facts->expires_at );
		}
		$adopted = $this->rig->row( 'families', 'family_hash', $family['family_hash'] );
		self::assertSame( $family['family_hash'], $adopted['family_key'] );
		self::assertSame( LegacyRows::CLIENT, $adopted['client_id'] );
		self::assertSame( '7', $adopted['user_id'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $family['deadline'] ), $adopted['family_expires_at'] );
		self::assertSame( '0', $adopted['revision'] );
	}

	public function test_earlier_refresh_payload_must_match_its_row(): void {
		$family = $this->legacy_family( 1 );
		$stored = (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION );

		$this->rejects( LegacyRows::refresh_payload( $family, 1, $stored, 'ffffffffffffffffffffffffffffffff' ) );
		$this->rejects( LegacyRows::refresh_payload( [ 'jtis' => [ LegacyRows::hex( 40 ) ], 'deadline' => $family['deadline'] ], 0, $stored ) );
		$this->rejects( LegacyRows::refresh_payload( $family, 1, 'another-password' ) );
	}

	public function test_codes_from_the_earlier_version_are_not_accepted(): void {
		$this->rejects( LegacyRows::code_payload( (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ) );
	}

	public function test_an_unconsumed_key_can_be_encoded_again(): void {
		[ , $pair, $outcome ] = $this->rig->connect();
		$issuance = $outcome->issuance;
		$again = new IssuanceIntent( $issuance->family_key, str_repeat( 'd', 80 ), $issuance->refresh_key, $issuance->access_deadline, $issuance->refresh_deadline, $issuance->access_scopes, $issuance->resources, $issuance->revision, true );

		$second = $this->rig->codec->encode( $again );

		self::assertNotSame( $pair->refresh_token, $second->refresh_token );
		$facts = $this->rig->codec->inspect( $second->refresh_token );
		self::assertSame( $issuance->refresh_key, $facts->credential_key );
		self::assertSame( $issuance->family_key, $facts->family_key );
	}

	public function test_earlier_heads_cannot_be_encoded_again(): void {
		$family = $this->legacy_family( 0 );
		$this->rig->codec->inspect( LegacyRows::refresh_payload( $family, 0, (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ) );
		$intent = new IssuanceIntent( $family['family_hash'], str_repeat( 'd', 80 ), $family['rows'][0], StorageRig::T + 3600, $family['deadline'], [ 'mcp' ], [ StorageRig::RESOURCE ], 0, true );

		try {
			$this->rig->codec->encode( $intent );
			self::fail( 'An earlier head has no re-deliverable encoding.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'server_error', $fault->error() );
			self::assertSame( 500, $fault->status() );
		}
	}

	public function test_missing_keys_fail_closed(): void {
		[ , , $outcome ] = $this->rig->connect();
		delete_option( CredentialKeys::PRIVATE_KEY_OPTION );

		try {
			$this->rig->codec->encode( $outcome->issuance );
			self::fail( 'Encoding needs the signing key.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'server_error', $fault->error() );
		}
	}
}
